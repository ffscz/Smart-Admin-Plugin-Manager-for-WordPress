<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('SAPM_VERSION')) {
    define('SAPM_VERSION', '1.2.0');
}
if (!defined('SAPM_OPTION_KEY')) {
    define('SAPM_OPTION_KEY', 'sapm_plugin_rules');
}
if (!defined('SAPM_MENU_SNAPSHOT_OPTION')) {
    define('SAPM_MENU_SNAPSHOT_OPTION', 'sapm_menu_snapshots');
}
if (!defined('SAPM_DEBUG')) {
    define('SAPM_DEBUG', defined('WP_DEBUG') && WP_DEBUG);
}

class SAPM_Core {

    private static $instance = null;

    /** @var array Rules configuration */
    private $rules = [];

    /** @var string Current admin screen */
    private $current_screen_id = '';

    /** @var array Plugins disabled in this request */
    private $disabled_this_request = [];

    /** @var array Plugins deferred (defer) in this request */
    private $deferred_this_request = [];

    /** @var array Plugins safelisted (AJAX/REST/Cron) */
    private $safelisted = [];

    /** @var string Current request type */
    private $request_type = 'admin';

    /** @var array Rules for request types (AJAX/REST/Cron/CLI) */
    private $request_type_rules = [];

    /** @var string|null AJAX action for smart detection */
    private $ajax_action = null;

    /** @var string|null REST namespace for smart detection */
    private $rest_namespace = null;

    /**
     * Definitions of admin screen patterns
     * Key = internal ID, value = [label, pattern callback]
     */
    private $screen_definitions = [];

    /** @var bool Protection against double bootstrap */
    private $bootstrapped = false;

    /** @var bool Performance measurement enabled */
    private $perf_enabled = false;

    /** @var float */
    private $perf_start = 0.0;

    /** @var array */
    private $perf_times = [];

    /** @var array */
    private $perf_loaded = [];

    /** @var array */
    private $perf_deferred_loaded = [];

    /** @var array */
    private $perf_query_counts = [];

    /** @var int */
    private $perf_query_total = 0;

    /** @var array */
    private $perf_plugin_dir_map = [];

    /** @var string */
    private $perf_plugins_root = '';

    /** @var bool */
    private $perf_snapshot_saved = false;

    // ========================================
    // Request Type Performance Sampling
    // ========================================

    /** @var float Sampling rate for non-admin requests (0.0-1.0, e.g., 0.10 = 10%) */
    private const REQUEST_TYPE_SAMPLING_RATE = 0.10;

    /** @var int Maximum samples to store per trigger */
    private const MAX_SAMPLES_PER_TRIGGER = 100;

    /** @var int Maximum triggers to track per request type */
    private const MAX_TRIGGERS_PER_TYPE = 50;

    /** @var bool Whether this request is sampled for performance tracking */
    private $is_sampled_request = false;

    /** @var string|null Current trigger identifier (action/endpoint/hook/command) */
    private $current_trigger = null;

    /** @var string|null Current cron hook name */
    private $cron_hook = null;

    /** @var string|null Current CLI command */
    private $cli_command = null;

    // ========================================
    // Mode: Manual vs Auto
    // ========================================

    /** @var string Current mode: 'manual' or 'auto' */
    private $mode = 'manual';

    /** @var string Option key for mode setting */
    private const MODE_OPTION_KEY = 'sapm_mode';

    /** @var float Confidence threshold for auto-rules (0.0-1.0) */
    private const AUTO_CONFIDENCE_THRESHOLD = 0.7;

    /**
     * Singleton
     */
    public static function init(): self {
        if (self::$instance === null) {
            self::$instance = new self();
            self::$instance->bootstrap();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->define_screens();
        $this->load_rules();
        $this->load_request_type_rules();
        $this->load_mode();
        $this->detect_request_type();
    }

    private function bootstrap(): void {
        if ($this->bootstrapped) {
            return;
        }
        $this->bootstrapped = true;

        // Main filter - runs very early
        add_filter('option_active_plugins', [$this, 'filter_plugins'], 1);
        add_filter('site_option_active_sitewide_plugins', [$this, 'filter_plugins_multisite'], 1);

        // Load deferred plugins (defer)
        add_action('plugins_loaded', [$this, 'load_deferred_plugins'], 999);

        // Prevent accidental deactivation caused by filtered plugin loads
        add_filter('pre_update_option_active_plugins', [$this, 'prevent_auto_deactivation'], 5, 3);

        // Performance tracking (admin requests)
        $this->maybe_enable_perf_tracking();

        // Sampling-based performance tracking for AJAX/REST/Cron/CLI
        $this->maybe_enable_request_type_sampling();
    }

    /**
     * Enable performance tracking (admin only)
     */
    private function maybe_enable_perf_tracking(): void {
        if ($this->request_type !== 'admin') {
            return;
        }

        $enabled = apply_filters('sapm_perf_enabled', true);
        if (!$enabled) {
            return;
        }

        $this->perf_enabled = true;
        $this->perf_start = microtime(true);
        $this->perf_plugin_dir_map = $this->build_perf_plugin_dir_map();
        $this->perf_plugins_root = str_replace('\\', '/', WP_PLUGIN_DIR) . '/';

        add_filter('query', [$this, 'track_db_query'], 0);
        add_action('plugin_loaded', [$this, 'track_plugin_loaded'], 0, 1);
        add_action('plugins_loaded', [$this, 'store_perf_snapshot'], PHP_INT_MAX);
    }

    /**
     * Plugin map for DB query attribution
     */
    private function build_perf_plugin_dir_map(): array {
        $plugins_from_db = $this->get_active_plugins_raw();

        if (is_multisite()) {
            $has_filter = has_filter('site_option_active_sitewide_plugins', [$this, 'filter_plugins_multisite']);
            if ($has_filter) {
                remove_filter('site_option_active_sitewide_plugins', [$this, 'filter_plugins_multisite'], 1);
            }
            $network_plugins = (array) get_site_option('active_sitewide_plugins', []);
            if ($has_filter) {
                add_filter('site_option_active_sitewide_plugins', [$this, 'filter_plugins_multisite'], 1);
            }
            if (!empty($network_plugins)) {
                $plugins_from_db = array_merge($plugins_from_db, array_keys($network_plugins));
            }
        }

        $map = [];
        foreach ((array) $plugins_from_db as $plugin_file) {
            $plugin_dir = dirname($plugin_file);
            $plugin_key = $plugin_dir !== '.' ? $plugin_dir : basename($plugin_file);
            if ($plugin_key !== '') {
                $map[$plugin_key] = $plugin_file;
            }
        }

        return $map;
    }

    /**
     * DB query tracking per plugin
     */
    public function track_db_query(string $query): string {
        if (!$this->perf_enabled || $this->perf_plugins_root === '') {
            return $query;
        }

        $this->perf_query_total++;

        $plugin_key = '';
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 15);
        foreach ($trace as $frame) {
            if (empty($frame['file'])) {
                continue;
            }
            $file = str_replace('\\', '/', $frame['file']);
            if (strpos($file, $this->perf_plugins_root) !== 0) {
                continue;
            }
            $relative = ltrim(substr($file, strlen($this->perf_plugins_root)), '/');
            if ($relative === '') {
                continue;
            }
            $parts = explode('/', $relative);
            $dir = $parts[0] ?? '';
            if ($dir !== '' && isset($this->perf_plugin_dir_map[$dir])) {
                $plugin_key = $this->perf_plugin_dir_map[$dir];
                break;
            }
            if (count($parts) === 1 && isset($this->perf_plugin_dir_map[$parts[0]])) {
                $plugin_key = $this->perf_plugin_dir_map[$parts[0]];
                break;
            }
        }

        if ($plugin_key !== '') {
            if (!isset($this->perf_query_counts[$plugin_key])) {
                $this->perf_query_counts[$plugin_key] = 0;
            }
            $this->perf_query_counts[$plugin_key]++;
        }

        return $query;
    }

    /**
     * Track plugin loads
     */
    public function track_plugin_loaded(string $plugin): void {
        if (!$this->perf_enabled) {
            return;
        }

        $now = microtime(true);
        $plugin_key = function_exists('plugin_basename') ? plugin_basename($plugin) : $plugin;

        $this->perf_times[$plugin_key] = $now - $this->perf_start;
        $this->perf_loaded[] = $plugin_key;
        $this->perf_start = $now;
    }

    /**
     * Save performance snapshot
     */
    public function store_perf_snapshot(): void {
        if (!$this->perf_enabled || $this->perf_snapshot_saved) {
            return;
        }

        if (empty($this->perf_times)) {
            return;
        }

        $context = $this->build_perf_context_label();

        $payload = [
            'captured_at' => time(),
            'request_type' => defined('REQUEST_ADMIN') ? REQUEST_ADMIN : $this->request_type,
            'context' => $context,
            'uri' => $_SERVER['REQUEST_URI'] ?? '',
            'plugins' => $this->perf_times,
            'total_ms' => round(array_sum($this->perf_times) * 1000, 3),
            'query_counts' => $this->perf_query_counts,
            'query_total' => $this->perf_query_total,
            'loaded_plugins' => array_values(array_unique($this->perf_loaded)),
            'deferred_loaded' => array_values(array_unique($this->perf_deferred_loaded)),
        ];

        set_transient('sapm_perf_last', $payload, 10 * MINUTE_IN_SECONDS);

        $log = get_transient('sapm_perf_log');
        if (!is_array($log)) {
            $log = [];
        }

        $log = array_values($log);
        foreach ($log as $idx => $entry) {
            if (!is_array($entry)) {
                continue;
            }
            if (($entry['context'] ?? '') === ($payload['context'] ?? '') && ($entry['uri'] ?? '') === ($payload['uri'] ?? '')) {
                unset($log[$idx]);
            }
        }

        array_unshift($log, $payload);
        $log = array_slice(array_values($log), 0, 30);

        set_transient('sapm_perf_log', $log, 10 * MINUTE_IN_SECONDS);

        // Store admin screen sampling data to database for Auto mode suggestions
        // Use proper screen ID (not build_perf_context_label format) for consistent mapping
        $proper_screen_id = $this->get_current_screen_early();
        $this->store_admin_screen_sampling($proper_screen_id, $payload);

        $this->perf_snapshot_saved = true;
    }

    /**
     * Store admin screen sampling data to database for Auto mode suggestions
     * 
     * @param string $screen_id The screen identifier (e.g., 'dashboard', 'edit-post', 'options-general')
     * @param array $payload Performance data from store_perf_snapshot
     */
    private function store_admin_screen_sampling(string $screen_id, array $payload): void {
        // Skip if SAPM_Database class doesn't exist
        if (!class_exists('SAPM_Database')) {
            return;
        }

        // Skip empty data
        if (empty($payload['plugins'])) {
            return;
        }

        // Store each plugin's performance for this screen
        foreach ($payload['plugins'] as $plugin_file => $load_time) {
            $query_count = $payload['query_counts'][$plugin_file] ?? 0;
            
            // Convert load_time from seconds to milliseconds
            $load_time_ms = $load_time * 1000;

            SAPM_Database::store_sample([
                'request_type' => 'admin',
                'trigger_name' => 'screen:' . $screen_id,
                'plugin_file' => $plugin_file,
                'load_time_ms' => $load_time_ms,
                'query_count' => $query_count,
            ]);
        }
    }

    // ========================================
    // Request Type Performance Sampling Methods
    // ========================================

    /**
     * Enable sampling-based performance tracking for AJAX/REST/Cron/CLI requests
     */
    private function maybe_enable_request_type_sampling(): void {
        // Only for non-admin request types
        if (!in_array($this->request_type, ['ajax', 'rest', 'cron', 'cli'], true)) {
            return;
        }

        // Check if sampling is enabled via filter
        $sampling_enabled = apply_filters('sapm_request_type_sampling_enabled', true);
        if (!$sampling_enabled) {
            return;
        }

        // Determine sampling rate (can be overridden via filter)
        $sampling_rate = apply_filters('sapm_request_type_sampling_rate', self::REQUEST_TYPE_SAMPLING_RATE);

        // Force sampling for debug mode
        if (defined('SAPM_FORCE_SAMPLING') && SAPM_FORCE_SAMPLING) {
            $sampling_rate = 1.0;
        }

        // Random sampling decision
        if (mt_rand(1, 10000) / 10000 > $sampling_rate) {
            return;
        }

        // This request is selected for sampling
        $this->is_sampled_request = true;
        $this->current_trigger = $this->detect_current_trigger();

        // Enable performance tracking for this request
        $this->perf_enabled = true;
        $this->perf_start = microtime(true);
        $this->perf_plugin_dir_map = $this->build_perf_plugin_dir_map();
        $this->perf_plugins_root = str_replace('\\', '/', WP_PLUGIN_DIR) . '/';

        add_filter('query', [$this, 'track_db_query'], 0);
        add_action('plugin_loaded', [$this, 'track_plugin_loaded'], 0, 1);
        add_action('shutdown', [$this, 'store_request_type_perf_sample'], 1);
    }

    /**
     * Detect current trigger identifier based on request type
     */
    private function detect_current_trigger(): string {
        switch ($this->request_type) {
            case 'ajax':
                $action = $this->get_ajax_action();
                return $action !== null && $action !== '' ? 'ajax:' . $action : 'ajax:unknown';

            case 'rest':
                $namespace = $this->get_rest_namespace();
                $endpoint = $this->get_rest_endpoint();
                if ($namespace) {
                    return 'rest:' . $namespace . ($endpoint ? '/' . $endpoint : '');
                }
                return 'rest:unknown';

            case 'cron':
                $hook = $this->get_cron_hook();
                return $hook !== null && $hook !== '' ? 'cron:' . $hook : 'cron:unknown';

            case 'cli':
                $command = $this->get_cli_command();
                return $command !== null && $command !== '' ? 'cli:' . $command : 'cli:unknown';

            default:
                return $this->request_type . ':unknown';
        }
    }

    /**
     * Get REST endpoint (route after namespace)
     */
    private function get_rest_endpoint(): ?string {
        if ($this->request_type !== 'rest') {
            return null;
        }

        $rest_prefix = rest_get_url_prefix();
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);

        // Extract route after /wp-json/namespace/v1/
        if (preg_match('#/' . preg_quote($rest_prefix, '#') . '/[^/]+(?:/v\d+)?/(.+?)(?:\?|$)#', $path, $matches)) {
            // Limit to first segment to avoid too many unique triggers
            $route = trim($matches[1], '/');
            $parts = explode('/', $route);
            // Keep only first part (e.g., "products" from "products/123")
            return $parts[0] ?? null;
        }

        return null;
    }

    /**
     * Get current cron hook name
     */
    private function get_cron_hook(): ?string {
        if ($this->cron_hook !== null) {
            return $this->cron_hook;
        }

        // Try to detect from doing_action
        if (function_exists('current_action') && $this->request_type === 'cron') {
            $action = current_action();
            if ($action && $action !== 'shutdown') {
                $this->cron_hook = $action;
                return $this->cron_hook;
            }
        }

        // Try to get from $_GET or doing_cron
        if (!empty($_GET['doing_wp_cron'])) {
            // We'll get the hook later via shutdown
            $this->cron_hook = 'wp_cron_batch';
        }

        // Try to detect scheduled event
        $crons = _get_cron_array();
        if (is_array($crons)) {
            $time = time();
            foreach ($crons as $timestamp => $cronhooks) {
                if ($timestamp > $time + 60) {
                    break;
                }
                foreach ($cronhooks as $hook => $args) {
                    $this->cron_hook = $hook;
                    return $this->cron_hook;
                }
            }
        }

        return $this->cron_hook;
    }

    /**
     * Get current CLI command
     */
    private function get_cli_command(): ?string {
        if ($this->cli_command !== null) {
            return $this->cli_command;
        }

        if (!defined('WP_CLI') || !WP_CLI) {
            return null;
        }

        // Try to get from global argv
        global $argv;
        if (is_array($argv) && count($argv) > 1) {
            // Skip "wp" and get command + subcommand
            $parts = array_slice($argv, 1, 2);
            // Filter out flags
            $parts = array_filter($parts, fn($p) => strpos($p, '-') !== 0);
            $this->cli_command = implode(' ', array_slice($parts, 0, 2));
            return $this->cli_command;
        }

        return null;
    }

    /**
     * Store request type performance sample on shutdown
     * Uses SAPM_Database for storage instead of wp_options
     */
    public function store_request_type_perf_sample(): void {
        if (!$this->is_sampled_request || empty($this->perf_times)) {
            return;
        }

        $trigger = $this->current_trigger ?: $this->request_type . ':unknown';

        // Store each plugin sample to database
        foreach ($this->perf_times as $plugin => $time_sec) {
            $sample = [
                'request_type' => $this->request_type,
                'trigger_name' => $trigger,
                'plugin_file' => $plugin,
                'load_time_ms' => round($time_sec * 1000, 2),
                'query_count' => $this->perf_query_counts[$plugin] ?? 0,
            ];

            SAPM_Database::store_sample($sample);
        }
    }

    // Note: aggregate_request_type_sample() removed in v1.2.0
    // Aggregation now handled by SAPM_Database::store_sample() directly

    /**
     * Get aggregated performance data for request types
     * Now uses SAPM_Database for storage
     */
    public static function get_request_type_performance(): array {
        return SAPM_Database::get_sampling_data();
    }

    /**
     * Get formatted performance data for display
     * Now uses SAPM_Database for storage
     */
    public static function get_request_type_performance_formatted(): array {
        return SAPM_Database::get_sampling_data_formatted();
    }

    /**
     * Clear request type performance data
     * Now uses SAPM_Database for storage
     */
    public static function clear_request_type_performance(?string $request_type = null): bool {
        return SAPM_Database::clear_sampling_data($request_type);
    }

    /**
     * Check if current request is being sampled
     */
    public function is_sampled_request(): bool {
        return $this->is_sampled_request;
    }

    /**
     * Get current trigger identifier
     */
    public function get_current_trigger(): ?string {
        return $this->current_trigger;
    }

    private function build_perf_context_label(): string {
        $context = 'admin';

        if (!empty($GLOBALS['pagenow'])) {
            $context .= ':' . sanitize_text_field($GLOBALS['pagenow']);
        }

        if (!empty($_GET['post_type'])) {
            $context .= ' (post_type ' . sanitize_key($_GET['post_type']) . ')';
        }

        if (!empty($_GET['post']) && is_numeric($_GET['post'])) {
            $context .= ' (post #' . intval($_GET['post']) . ')';
        }

        return $context;
    }

    /**
     * Get rules
     */
    public function get_rules(): array {
        return $this->rules;
    }

    /**
     * Set rules
     */
    public function set_rules(array $rules): void {
        $this->rules = $rules;
    }

    /**
     * Get current screen ID
     */
    public function get_current_screen_id(): string {
        return $this->current_screen_id;
    }

    /**
     * Get screen definitions
     */
    public function get_screen_definitions(): array {
        return $this->screen_definitions;
    }

    /**
     * Get disabled plugins this request
     */
    public function get_disabled_this_request(): array {
        return array_values(array_keys($this->disabled_this_request));
    }

    /**
     * Get deferred plugins this request
     */
    public function get_deferred_this_request(): array {
        return array_values(array_keys($this->deferred_this_request));
    }

    /**
     * Get request type
     */
    public function get_request_type(): string {
        return $this->request_type;
    }

    /**
     * Prevent unintended plugin deactivation during filtered requests
     */
    public function prevent_auto_deactivation($value, $old_value, $option) {
        if ($option !== 'active_plugins') {
            return $value;
        }

        // Only guard when SAPM is actively filtering plugins
        if (empty($this->disabled_this_request) && empty($this->deferred_this_request)) {
            return $value;
        }

        // Allow explicit plugin management actions
        if ($this->is_explicit_plugin_management_action()) {
            return $value;
        }

        $new_plugins = is_array($value) ? $value : [];
        $old_plugins = is_array($old_value) ? $old_value : [];

        $removed = array_diff($old_plugins, $new_plugins);
        if (!empty($removed)) {
            if (defined('SAPM_DEBUG') && SAPM_DEBUG) {
                error_log('[SAPM] Prevented active_plugins update during filtered request. Removed: ' . implode(', ', $removed));
            }
            return $old_value;
        }

        return $value;
    }

    /**
     * Detect explicit plugin management actions (activate/deactivate/update/delete)
     */
    private function is_explicit_plugin_management_action(): bool {
        $action = sanitize_key($_REQUEST['action'] ?? '');
        $action2 = sanitize_key($_REQUEST['action2'] ?? '');
        $pagenow = $GLOBALS['pagenow'] ?? '';

        $allowed_actions = [
            'activate',
            'activate-selected',
            'deactivate',
            'deactivate-selected',
            'delete-selected',
            'delete-plugin',
            'update-plugin',
            'update-selected',
            'upgrade-plugin',
            'enable-auto-update',
            'disable-auto-update',
        ];

        if (in_array($action, $allowed_actions, true) || in_array($action2, $allowed_actions, true)) {
            return true;
        }

        // Allow on plugin management pages when explicit action is present
        if (in_array($pagenow, ['plugins.php', 'update.php', 'update-core.php'], true)) {
            if ($action !== '' || $action2 !== '') {
                return true;
            }
        }

        return false;
    }

    // ========================================
    // Mode Management: Manual vs Auto
    // ========================================

    /**
     * Get current mode
     */
    public function get_mode(): string {
        return $this->mode;
    }

    /**
     * Set mode (manual or auto)
     */
    public function set_mode(string $mode): bool {
        if (!in_array($mode, ['manual', 'auto'], true)) {
            return false;
        }
        $this->mode = $mode;
        return update_option(self::MODE_OPTION_KEY, $mode, false);
    }

    /**
     * Load mode from database
     */
    private function load_mode(): void {
        $this->mode = get_option(self::MODE_OPTION_KEY, 'manual');
    }

    /**
     * Get auto-generated rule suggestions
     */
    public function get_auto_suggestions(float $confidence_threshold = null): array {
        if ($confidence_threshold === null) {
            $confidence_threshold = self::AUTO_CONFIDENCE_THRESHOLD;
        }
        return SAPM_Database::generate_auto_rules($confidence_threshold);
    }

    /**
     * Apply auto-generated rules (merge with existing)
     */
    public function apply_auto_rules(float $confidence_threshold = null): array {
        $suggestions = $this->get_auto_suggestions($confidence_threshold);
        $applied = ['blocks' => [], 'whitelist' => []];

        if ($this->mode !== 'auto') {
            return $applied;
        }

        $current_rules = $this->get_request_type_rules();

        foreach (['ajax', 'rest', 'cron', 'cli'] as $type) {
            // Add suggested blocks
            foreach ($suggestions[$type]['suggested_blocks'] ?? [] as $suggestion) {
                $plugin = $suggestion['plugin'];
                if (!in_array($plugin, $current_rules[$type]['block'] ?? [], true) &&
                    !in_array($plugin, $current_rules[$type]['whitelist'] ?? [], true)) {
                    $current_rules[$type]['block'][] = $plugin;
                    $applied['blocks'][] = [
                        'type' => $type,
                        'plugin' => $plugin,
                        'confidence' => $suggestion['confidence'],
                        'reason' => $suggestion['reason'],
                    ];
                }
            }

            // Add suggested whitelist
            foreach ($suggestions[$type]['suggested_whitelist'] ?? [] as $suggestion) {
                $plugin = $suggestion['plugin'];
                if (!in_array($plugin, $current_rules[$type]['whitelist'] ?? [], true) &&
                    !in_array($plugin, $current_rules[$type]['block'] ?? [], true)) {
                    $current_rules[$type]['whitelist'][] = $plugin;
                    $applied['whitelist'][] = [
                        'type' => $type,
                        'plugin' => $plugin,
                        'confidence' => $suggestion['confidence'],
                        'reason' => $suggestion['reason'],
                    ];
                }
            }
        }

        // Save updated rules
        if (!empty($applied['blocks']) || !empty($applied['whitelist'])) {
            $this->save_request_type_rules($current_rules);
            $this->request_type_rules = $current_rules;
        }

        return $applied;
    }

    /**
     * Get sampling statistics
     */
    public static function get_sampling_stats(): array {
        return SAPM_Database::get_stats();
    }

    /**
     * Get plugin usage summary
     */
    public static function get_plugin_usage_summary(): array {
        return SAPM_Database::get_plugin_usage_summary();
    }

    /**
     * Definitions of admin screens with patterns
     */
    private function define_screens(): void {
        $this->screen_definitions = [
            // === CORE WORDPRESS ===
            'dashboard' => [
                'label' => __('Dashboard', 'sapm'),
                'group' => 'core',
                'matcher' => fn($s) => $s === 'dashboard',
            ],
            'posts_list' => [
                'label' => __('Posts - list', 'sapm'),
                'group' => 'content',
                'matcher' => fn($s) => $s === 'edit-post',
            ],
            'post_edit' => [
                'label' => __('Post - edit', 'sapm'),
                'group' => 'content',
                'matcher' => fn($s) => $s === 'post',
            ],
            'pages_list' => [
                'label' => __('Pages - list', 'sapm'),
                'group' => 'content',
                'matcher' => fn($s) => $s === 'edit-page',
            ],
            'page_edit' => [
                'label' => __('Page - edit', 'sapm'),
                'group' => 'content',
                'matcher' => fn($s) => $s === 'page',
            ],
            'media_library' => [
                'label' => __('Media - library', 'sapm'),
                'group' => 'content',
                'matcher' => fn($s) => $s === 'upload',
            ],
            'media_edit' => [
                'label' => __('Media - edit', 'sapm'),
                'group' => 'content',
                'matcher' => fn($s) => $s === 'attachment',
            ],
            'comments' => [
                'label' => __('Comments', 'sapm'),
                'group' => 'core',
                'matcher' => fn($s) => $s === 'edit-comments',
            ],
            'users_list' => [
                'label' => __('Users - list', 'sapm'),
                'group' => 'core',
                'matcher' => fn($s) => $s === 'users',
            ],
            'user_edit' => [
                'label' => __('User - edit', 'sapm'),
                'group' => 'core',
                'matcher' => fn($s) => in_array($s, ['user-edit', 'profile'], true),
            ],
            'plugins_page' => [
                'label' => __('Plugins', 'sapm'),
                'group' => 'core',
                'matcher' => fn($s) => $s === 'plugins',
                'always_all' => true, // Always load all plugins
            ],
            'themes' => [
                'label' => __('Appearance - themes', 'sapm'),
                'group' => 'core',
                'matcher' => fn($s) => $s === 'themes',
            ],
            'customizer' => [
                'label' => __('Customizer', 'sapm'),
                'group' => 'core',
                'matcher' => fn($s) => $s === 'customize',
            ],
            'widgets' => [
                'label' => __('Widgets', 'sapm'),
                'group' => 'core',
                'matcher' => fn($s) => $s === 'widgets',
            ],
            'menus' => [
                'label' => __('Menu', 'sapm'),
                'group' => 'core',
                'matcher' => fn($s) => $s === 'nav-menus',
            ],
            'settings_general' => [
                'label' => __('Settings - general', 'sapm'),
                'group' => 'settings',
                'matcher' => fn($s) => $s === 'options-general',
            ],
            'settings_other' => [
                'label' => __('Settings - other', 'sapm'),
                'group' => 'settings',
                'matcher' => fn($s) => strpos($s, 'options-') === 0 && $s !== 'options-general',
            ],
            'tools' => [
                'label' => __('Tools', 'sapm'),
                'group' => 'core',
                'matcher' => fn($s) => in_array($s, ['tools', 'import', 'export', 'site-health'], true),
            ],

            // === WOOCOMMERCE ===
            'woo_products_list' => [
                'label' => __('WooCommerce - products list', 'sapm'),
                'group' => 'woocommerce',
                'matcher' => fn($s) => $s === 'edit-product',
            ],
            'woo_product_edit' => [
                'label' => __('WooCommerce - product edit', 'sapm'),
                'group' => 'woocommerce',
                'matcher' => fn($s) => $s === 'product',
            ],
            'woo_orders_list' => [
                'label' => __('WooCommerce - orders list', 'sapm'),
                'group' => 'woocommerce',
                'matcher' => fn($s) => in_array($s, ['edit-shop_order', 'woocommerce_page_wc-orders'], true),
            ],
            'woo_order_edit' => [
                'label' => __('WooCommerce - order edit', 'sapm'),
                'group' => 'woocommerce',
                'matcher' => fn($s) => $s === 'shop_order',
            ],
            'woo_settings' => [
                'label' => __('WooCommerce - settings', 'sapm'),
                'group' => 'woocommerce',
                'matcher' => fn($s) => strpos($s, 'woocommerce_page_wc-') === 0,
            ],
            'woo_other' => [
                'label' => __('WooCommerce - other', 'sapm'),
                'group' => 'woocommerce',
                'matcher' => fn($s) => strpos($s, 'woocommerce') !== false || strpos($s, 'edit-product_') === 0,
            ],

            // === CUSTOM POST TYPES (dynamic) ===
            'cpt_list' => [
                'label' => __('Custom Post Type - list', 'sapm'),
                'group' => 'cpt',
                'matcher' => fn($s) => preg_match('/^edit-(?!post$|page$|product$|shop_order$|attachment$)/', $s),
                'dynamic' => true,
            ],
            'cpt_edit' => [
                'label' => __('Custom Post Type - edit', 'sapm'),
                'group' => 'cpt',
                'matcher' => fn($s) => !in_array($s, ['post', 'page', 'product', 'shop_order', 'attachment'], true)
                                       && $this->is_post_type_screen($s),
                'dynamic' => true,
            ],

            // === PLUGIN SPECIFIC PAGES ===
            'plugin_pages' => [
                'label' => __('Plugin pages', 'sapm'),
                'group' => 'plugins',
                'matcher' => fn($s) => strpos($s, '_page_') !== false || strpos($s, 'toplevel_page_') === 0,
                'dynamic' => true,
            ],
        ];

        // Allow extensions
        $this->screen_definitions = apply_filters('sapm_screen_definitions', $this->screen_definitions);;
    }

    /**
     * Helper for detecting post type screen
     */
    private function is_post_type_screen(string $screen_id): bool {
        $post_types = get_post_types(['public' => true], 'names');
        return in_array($screen_id, $post_types, true);
    }

    /**
     * Load rules from DB
     */
    private function load_rules(): void {
        $saved = get_option(SAPM_OPTION_KEY, []);
        $this->rules = is_array($saved) ? $saved : [];
    }

    /**
     * Load rules for request types (AJAX/REST/Cron/CLI)
     */
    private function load_request_type_rules(): void {
        $saved = get_option('sapm_request_type_rules', []);
        $this->request_type_rules = is_array($saved) ? $saved : $this->get_default_request_type_rules();
    }

    /**
     * Default rules for request types
     */
    public function get_default_request_type_rules(): array {
        return [
            'ajax' => [
                '_mode' => 'passthrough',  // passthrough | blacklist | whitelist
                '_detect_by_action' => false,
                'default_plugins' => [],
                'disabled_plugins' => [],
                'known_actions' => [
                    'heartbeat' => ['enabled' => ['*']],
                    'woocommerce_*' => ['enabled' => ['woocommerce/woocommerce.php']],
                ],
            ],
            'rest' => [
                '_mode' => 'passthrough',
                '_detect_by_namespace' => false,
                'default_plugins' => [],
                'disabled_plugins' => [],
                'known_namespaces' => [
                    'wc/v3' => ['enabled' => ['woocommerce/woocommerce.php']],
                    'wp/v2' => ['enabled' => ['*']],
                ],
            ],
            'cron' => [
                '_mode' => 'passthrough',  // Cron should remain passthrough for safety
                'disabled_plugins' => [],
            ],
            'cli' => [
                '_mode' => 'passthrough',  // CLI requires all plugins
            ],
            'frontend' => [
                '_mode' => 'passthrough',  // Frontend has its own rules (out of scope)
            ],
        ];
    }

    /**
     * Get current rules for request types
     */
    public function get_request_type_rules(): array {
        return $this->request_type_rules;
    }

    /**
     * Save rules for request types
     */
    public function save_request_type_rules(array $rules): bool {
        $this->request_type_rules = $rules;
        return update_option('sapm_request_type_rules', $rules);
    }

    /**
     * Get AJAX action from request
     */
    private function get_ajax_action(): ?string {
        if ($this->ajax_action !== null) {
            return $this->ajax_action;
        }

        if ($this->request_type !== 'ajax') {
            return null;
        }

        $this->ajax_action = sanitize_key($_REQUEST['action'] ?? '');
        return $this->ajax_action;
    }

    /**
     * Get REST namespace from request
     */
    private function get_rest_namespace(): ?string {
        if ($this->rest_namespace !== null) {
            return $this->rest_namespace;
        }

        if ($this->request_type !== 'rest') {
            return null;
        }

        $rest_prefix = rest_get_url_prefix();
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);

        // /wp-json/wc/v3/products → wc/v3
        if (preg_match('#/' . preg_quote($rest_prefix, '#') . '/([^/]+/v\d+)#', $path, $matches)) {
            $this->rest_namespace = $matches[1];
            return $this->rest_namespace;
        }

        // /wp-json/namespace → namespace
        if (preg_match('#/' . preg_quote($rest_prefix, '#') . '/([^/]+)#', $path, $matches)) {
            $this->rest_namespace = $matches[1];
            return $this->rest_namespace;
        }

        return null;
    }

    /**
     * Match wildcard pattern (e.g., woocommerce_*)
     */
    private function match_pattern(string $pattern, string $value): bool {
        if ($pattern === '*') {
            return true;
        }

        if (strpos($pattern, '*') !== false) {
            $regex = '/^' . str_replace('\*', '.*', preg_quote($pattern, '/')) . '$/';
            return (bool) preg_match($regex, $value);
        }

        return $pattern === $value;
    }

    /**
     * Find matching rule for AJAX action
     */
    private function match_ajax_rule(string $action, array $rules): ?array {
        $known = $rules['known_actions'] ?? [];
        foreach ($known as $pattern => $rule) {
            if ($this->match_pattern($pattern, $action)) {
                return $rule;
            }
        }
        return null;
    }

    /**
     * Find matching rule for REST namespace
     */
    private function match_rest_rule(string $namespace, array $rules): ?array {
        $known = $rules['known_namespaces'] ?? [];
        foreach ($known as $pattern => $rule) {
            if ($this->match_pattern($pattern, $namespace)) {
                return $rule;
            }
        }
        return null;
    }

    /**
     * Filter plugins for non-admin requests (AJAX/REST/Cron/CLI/Frontend)
     */
    private function filter_non_admin_plugins(array $plugins): array {
        $type_rules = $this->request_type_rules[$this->request_type] ?? [];
        $mode = $type_rules['_mode'] ?? 'passthrough';

        // Passthrough mode - all plugins without filter
        if ($mode === 'passthrough') {
            $this->safelisted = $plugins;
            return $plugins;
        }

        // Blacklist mode - all plugins except disabled
        if ($mode === 'blacklist') {
            $disabled = $type_rules['disabled_plugins'] ?? [];
            $filtered = [];

            foreach ($plugins as $plugin) {
                if (in_array($plugin, $disabled, true)) {
                    $this->disabled_this_request[$plugin] = true;
                    continue;
                }
                $filtered[] = $plugin;
            }

            $this->safelisted = $filtered;
            return $filtered;
        }

        // Whitelist mode - only allowed plugins
        if ($mode === 'whitelist') {
            $enabled = $type_rules['default_plugins'] ?? [];

            // Smart detection for AJAX
            if ($this->request_type === 'ajax' && !empty($type_rules['_detect_by_action'])) {
                $action = $this->get_ajax_action();
                if ($action) {
                    $matched = $this->match_ajax_rule($action, $type_rules);
                    if ($matched) {
                        // Support both formats: ['plugin.php'] or ['enabled' => ['plugin.php']]
                        $matched_plugins = isset($matched['enabled']) ? $matched['enabled'] : $matched;
                        if (is_array($matched_plugins)) {
                            $enabled = array_merge($enabled, $matched_plugins);
                        }
                    }
                }
            }

            // Smart detection for REST
            if ($this->request_type === 'rest' && !empty($type_rules['_detect_by_namespace'])) {
                $namespace = $this->get_rest_namespace();
                if ($namespace) {
                    $matched = $this->match_rest_rule($namespace, $type_rules);
                    if ($matched) {
                        // Support both formats: ['plugin.php'] or ['enabled' => ['plugin.php']]
                        $matched_plugins = isset($matched['enabled']) ? $matched['enabled'] : $matched;
                        if (is_array($matched_plugins)) {
                            $enabled = array_merge($enabled, $matched_plugins);
                        }
                    }
                }
            }

            // Wildcard '*' = all plugins
            if (in_array('*', $enabled, true)) {
                $this->safelisted = $plugins;
                return $plugins;
            }

            // Filter to whitelist
            $filtered = [];
            foreach ($plugins as $plugin) {
                if (in_array($plugin, $enabled, true)) {
                    $filtered[] = $plugin;
                } else {
                    $this->disabled_this_request[$plugin] = true;
                }
            }

            $this->safelisted = $filtered;
            return $filtered;
        }

        // Fallback - passthrough
        $this->safelisted = $plugins;
        return $plugins;
    }

    /**
     * Load active plugins without own filter
     */
    public function get_active_plugins_raw(): array {
        $has_filter = has_filter('option_active_plugins', [$this, 'filter_plugins']);

        if ($has_filter) {
            remove_filter('option_active_plugins', [$this, 'filter_plugins'], 1);
        }

        $active_plugins = get_option('active_plugins', []);

        if ($has_filter) {
            add_filter('option_active_plugins', [$this, 'filter_plugins'], 1);
        }

        return is_array($active_plugins) ? $active_plugins : [];
    }

    /**
     * Get rule state for plugin (including legacy keys)
     */
    public function get_rule_state(array $rules, string $plugin): ?string {
        if (isset($rules[$plugin]) && in_array($rules[$plugin], ['enabled', 'disabled', 'defer'], true)) {
            return $rules[$plugin];
        }

        $legacy_key = sanitize_file_name($plugin);
        if ($legacy_key !== $plugin && isset($rules[$legacy_key]) && in_array($rules[$legacy_key], ['enabled', 'disabled', 'defer'], true)) {
            return $rules[$legacy_key];
        }

        return null;
    }

    /**
     * Request type detection
     */
    private function detect_request_type(): void {
        // Cron
        if (wp_doing_cron() || (defined('DOING_CRON') && DOING_CRON)) {
            $this->request_type = 'cron';
            return;
        }

        // AJAX
        if (wp_doing_ajax() || (defined('DOING_AJAX') && DOING_AJAX)) {
            $this->request_type = 'ajax';
            return;
        }

        // REST API
        if ($this->is_rest_request()) {
            $this->request_type = 'rest';
            return;
        }

        // WP-CLI
        if (defined('WP_CLI') && WP_CLI) {
            $this->request_type = 'cli';
            return;
        }

        // Frontend
        if (!is_admin()) {
            $this->request_type = 'frontend';
            return;
        }

        $this->request_type = 'admin';
    }

    private function is_sapm_settings_page(): bool {
        if ($this->request_type !== 'admin') {
            return false;
        }

        $page = sanitize_key($_GET['page'] ?? '');
        if ($page !== 'smart-admin-plugin-manager') {
            return false;
        }

        $pagenow = $GLOBALS['pagenow'] ?? '';
        if ($pagenow === '') {
            $uri_path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
            $pagenow = $uri_path ? basename($uri_path) : '';
        }

        return $pagenow === 'options-general.php';
    }

    /**
     * REST request detection
     */
    private function is_rest_request(): bool {
        if (defined('REST_REQUEST') && REST_REQUEST) {
            return true;
        }

        $rest_prefix = rest_get_url_prefix();
        $request_uri = $_SERVER['REQUEST_URI'] ?? '';

        return strpos($request_uri, '/' . $rest_prefix . '/') !== false;
    }

    /**
     * Get current screen ID (early detection)
     */
    public function get_current_screen_early(): string {
        global $pagenow;

        // If we already have WP_Screen object
        if (function_exists('get_current_screen')) {
            $screen = get_current_screen();
            if ($screen && !empty($screen->id)) {
                $this->current_screen_id = $screen->id;
                return $screen->id;
            }
        }

        // Early detection from URL
        $page = $_GET['page'] ?? '';
        $post_type = $_GET['post_type'] ?? '';
        $taxonomy = $_GET['taxonomy'] ?? '';

        // Dashboard
        if ($pagenow === 'index.php' && empty($page)) {
            $this->current_screen_id = 'dashboard';
            return 'dashboard';
        }

        // Edit post/page
        if ($pagenow === 'post.php' || $pagenow === 'post-new.php') {
            $post_id = absint($_GET['post'] ?? 0);
            if ($post_id) {
                $pt = get_post_type($post_id);
                $this->current_screen_id = $pt ?: 'post';
                return $this->current_screen_id;
            }
            $this->current_screen_id = $post_type ?: 'post';
            return $this->current_screen_id;
        }

        // Post list
        if ($pagenow === 'edit.php') {
            $this->current_screen_id = 'edit-' . ($post_type ?: 'post');
            return $this->current_screen_id;
        }

        // Media
        if ($pagenow === 'upload.php') {
            $this->current_screen_id = 'upload';
            return 'upload';
        }

        // Users
        if ($pagenow === 'users.php') {
            $this->current_screen_id = 'users';
            return 'users';
        }
        if ($pagenow === 'user-edit.php') {
            $this->current_screen_id = 'user-edit';
            return 'user-edit';
        }
        if ($pagenow === 'profile.php') {
            $this->current_screen_id = 'profile';
            return 'profile';
        }

        // Comments
        if ($pagenow === 'edit-comments.php') {
            $this->current_screen_id = 'edit-comments';
            return 'edit-comments';
        }

        // Plugins
        if ($pagenow === 'plugins.php') {
            $this->current_screen_id = 'plugins';
            return 'plugins';
        }

        // Themes
        if ($pagenow === 'themes.php' && empty($page)) {
            $this->current_screen_id = 'themes';
            return 'themes';
        }

        // Customizer
        if ($pagenow === 'customize.php') {
            $this->current_screen_id = 'customize';
            return 'customize';
        }

        // Widgets
        if ($pagenow === 'widgets.php') {
            $this->current_screen_id = 'widgets';
            return 'widgets';
        }

        // Menus
        if ($pagenow === 'nav-menus.php') {
            $this->current_screen_id = 'nav-menus';
            return 'nav-menus';
        }

        // Settings
        if (strpos($pagenow, 'options-') === 0) {
            $this->current_screen_id = str_replace('.php', '', $pagenow);
            return $this->current_screen_id;
        }

        // Tools
        if (in_array($pagenow, ['tools.php', 'import.php', 'export.php', 'site-health.php'], true)) {
            $this->current_screen_id = str_replace('.php', '', $pagenow);
            return $this->current_screen_id;
        }

        // Plugin page
        if (!empty($page)) {
            if ($pagenow === 'admin.php') {
                $this->current_screen_id = 'toplevel_page_' . $page;
                return $this->current_screen_id;
            }
            $base = str_replace('.php', '', $pagenow);
            $this->current_screen_id = $base . '_page_' . $page;
            return $this->current_screen_id;
        }

        // Taxonomy
        if ($pagenow === 'edit-tags.php' && !empty($taxonomy)) {
            $this->current_screen_id = 'edit-' . $taxonomy;
            return $this->current_screen_id;
        }

        $this->current_screen_id = $pagenow ? str_replace('.php', '', $pagenow) : 'unknown';
        return $this->current_screen_id;
    }

    /**
     * Find matching screen definition
     */
    public function match_screen_definition(string $screen_id): ?string {
        // First try direct match
        foreach ($this->screen_definitions as $def_id => $def) {
            if (($def['matcher'])($screen_id)) {
                return $def_id;
            }
        }
        
        // Handle legacy format "admin:pagenow.php" from build_perf_context_label()
        // Convert to proper screen ID and try matching again
        if (strpos($screen_id, 'admin:') === 0) {
            $normalized = $this->normalize_admin_context_to_screen_id($screen_id);
            if ($normalized !== null && $normalized !== $screen_id) {
                foreach ($this->screen_definitions as $def_id => $def) {
                    if (($def['matcher'])($normalized)) {
                        return $def_id;
                    }
                }
            }
        }
        
        return null;
    }
    
    /**
     * Normalize admin context label (admin:pagenow.php) to proper screen ID
     * 
     * @param string $context Context from build_perf_context_label() like "admin:plugins.php"
     * @return string|null Normalized screen ID like "plugins", or null if unknown
     */
    private function normalize_admin_context_to_screen_id(string $context): ?string {
        // Extract pagenow from "admin:pagenow.php" format
        if (strpos($context, 'admin:') !== 0) {
            return null;
        }
        
        $pagenow = substr($context, 6); // Remove "admin:" prefix
        
        // Map pagenow.php to proper screen IDs
        $pagenow_to_screen = [
            'index.php' => 'dashboard',
            'plugins.php' => 'plugins',
            'options-general.php' => 'options-general',
            'options-writing.php' => 'options-writing',
            'options-reading.php' => 'options-reading',
            'options-discussion.php' => 'options-discussion',
            'options-media.php' => 'options-media',
            'options-permalink.php' => 'options-permalink',
            'options-privacy.php' => 'options-privacy',
            'themes.php' => 'themes',
            'widgets.php' => 'widgets',
            'nav-menus.php' => 'nav-menus',
            'users.php' => 'users',
            'user-edit.php' => 'user-edit',
            'profile.php' => 'profile',
            'edit-comments.php' => 'edit-comments',
            'upload.php' => 'upload',
            'edit.php' => 'edit-post',
            'post.php' => 'post',
            'post-new.php' => 'post',
            'tools.php' => 'tools',
            'import.php' => 'import',
            'export.php' => 'export',
            'site-health.php' => 'site-health',
            'admin.php' => 'dashboard', // Generic admin.php maps to dashboard
            'customize.php' => 'customize',
        ];
        
        return $pagenow_to_screen[$pagenow] ?? null;
    }

    /**
     * Main plugin filter
     */
    public function filter_plugins($plugins) {
        if (!is_array($plugins)) {
            return $plugins;
        }

        if ($this->is_sapm_settings_page()) {
            return $plugins;
        }

        // Smart filtering for non-admin requests (AJAX/REST/Cron/CLI/Frontend)
        if (in_array($this->request_type, ['cron', 'ajax', 'rest', 'cli', 'frontend'], true)) {
            return $this->filter_non_admin_plugins($plugins);
        }

        // Plugins page - always all
        $screen_id = $this->get_current_screen_early();

        // Find screen definition
        $matched_def = $this->match_screen_definition($screen_id);

        // Always all plugins on this screen?
        if ($matched_def && !empty($this->screen_definitions[$matched_def]['always_all'])) {
            return $plugins;
        }

        // Apply rules - first pass: collect blocked plugins
        $filtered = [];
        $blocked_plugins = [];

        foreach ($plugins as $plugin) {
            // This plugin must always run
            if ($plugin === plugin_basename(__FILE__)) {
                $filtered[] = $plugin;
                continue;
            }

            $rule = $this->get_effective_rule($plugin, $screen_id, $matched_def);
            $state = $rule['state'] ?? null;

            if ($state === 'disabled') {
                $this->disabled_this_request[$plugin] = true;
                $blocked_plugins[] = $plugin;
                continue;
            }

            if ($state === 'defer') {
                $this->deferred_this_request[$plugin] = true;
                continue;
            }

            $filtered[] = $plugin;
        }

        // Cascade blocking: automatically block plugins dependent on blocked ones
        if (!empty($blocked_plugins) && class_exists('SAPM_Dependencies')) {
            $deps = SAPM_Dependencies::init();
            $cascade = $deps->get_cascade_blocked($blocked_plugins);
            
            // DEBUG: Log cascade blocking info
            error_log('SAPM Cascade Debug: blocked_plugins = ' . print_r($blocked_plugins, true));
            error_log('SAPM Cascade Debug: cascade = ' . print_r($cascade, true));
            error_log('SAPM Cascade Debug: dependency_map count = ' . count($deps->get_dependency_map()));
            
            if (!empty($cascade)) {
                $new_filtered = [];
                foreach ($filtered as $plugin) {
                    if (in_array($plugin, $cascade, true)) {
                        $this->disabled_this_request[$plugin] = 'cascade:' . ($deps->is_cascade_blocked($plugin, $blocked_plugins) ?: 'dependency');
                    } else {
                        $new_filtered[] = $plugin;
                    }
                }
                $filtered = $new_filtered;
            }
        }

        return $filtered;
    }

    /**
     * Filter for multisite
     */
    public function filter_plugins_multisite($plugins) {
        if (!is_array($plugins)) {
            return $plugins;
        }

        if ($this->is_sapm_settings_page()) {
            return $plugins;
        }

        // Smart filtering for non-admin requests
        if (in_array($this->request_type, ['cron', 'ajax', 'rest', 'cli', 'frontend'], true)) {
            // For multisite we must keep format [plugin => time]
            $type_rules = $this->request_type_rules[$this->request_type] ?? [];
            $mode = $type_rules['_mode'] ?? 'passthrough';
            
            if ($mode === 'passthrough') {
                return $plugins;
            }
            
            if ($mode === 'blacklist') {
                $disabled = $type_rules['disabled_plugins'] ?? [];
                foreach ($disabled as $d) {
                    if (isset($plugins[$d])) {
                        $this->disabled_this_request[$d] = true;
                        unset($plugins[$d]);
                    }
                }
                return $plugins;
            }
            
            // whitelist - keep only allowed
            $enabled = $type_rules['default_plugins'] ?? [];
            if (in_array('*', $enabled, true)) {
                return $plugins;
            }
            
            $filtered = [];
            foreach ($plugins as $plugin => $time) {
                if (in_array($plugin, $enabled, true)) {
                    $filtered[$plugin] = $time;
                } else {
                    $this->disabled_this_request[$plugin] = true;
                }
            }
            return $filtered;
        }

        $screen_id = $this->get_current_screen_early();
        $matched_def = $this->match_screen_definition($screen_id);

        if ($matched_def && !empty($this->screen_definitions[$matched_def]['always_all'])) {
            return $plugins;
        }

        $filtered = [];
        $blocked_plugins = [];

        foreach ($plugins as $plugin => $time) {
            // This plugin must always run
            if ($plugin === plugin_basename(__FILE__)) {
                $filtered[$plugin] = $time;
                continue;
            }

            $rule = $this->get_effective_rule($plugin, $screen_id, $matched_def);
            $state = $rule['state'] ?? null;

            if ($state === 'disabled') {
                $this->disabled_this_request[$plugin] = true;
                $blocked_plugins[] = $plugin;
                continue;
            }

            if ($state === 'defer') {
                $this->deferred_this_request[$plugin] = true;
                continue;
            }

            $filtered[$plugin] = $time;
        }

        // Cascade blocking: automatically block plugins dependent on blocked ones
        if (!empty($blocked_plugins) && class_exists('SAPM_Dependencies')) {
            $deps = SAPM_Dependencies::init();
            $cascade = $deps->get_cascade_blocked($blocked_plugins);
            
            if (!empty($cascade)) {
                $new_filtered = [];
                foreach ($filtered as $plugin => $time) {
                    if (in_array($plugin, $cascade, true)) {
                        $this->disabled_this_request[$plugin] = 'cascade:' . ($deps->is_cascade_blocked($plugin, $blocked_plugins) ?: 'dependency');
                    } else {
                        $new_filtered[$plugin] = $time;
                    }
                }
                $filtered = $new_filtered;
            }
        }

        return $filtered;
    }

    /**
     * Load deferred (defer) plugins after plugins_loaded
     */
    public function load_deferred_plugins(): void {
        if ($this->request_type !== 'admin') {
            return;
        }

        if (empty($this->deferred_this_request)) {
            return;
        }

        $deferred = array_values(array_unique($this->deferred_this_request));

        foreach ($deferred as $plugin) {
            $plugin = wp_normalize_path(wp_unslash($plugin));
            $plugin = sanitize_text_field($plugin);
            if ($plugin === '' || strpos($plugin, '..') !== false) {
                continue;
            }

            $path = WP_PLUGIN_DIR . '/' . $plugin;
            if (file_exists($path)) {
                if ($this->perf_enabled) {
                    $this->perf_start = microtime(true);
                }
                include_once $path;
                if (function_exists('do_action')) {
                    do_action('plugin_loaded', $plugin);
                }
                if ($this->perf_enabled) {
                    $this->perf_deferred_loaded[] = $plugin;
                }
            }
        }
    }

    /**
     * Get effective rule (state + source)
     */
    public function get_effective_rule(string $plugin, string $screen_id, ?string $matched_def): array {
        // Screen specific rule (primarily by def_id)
        $screen_rules = [];
        if ($matched_def && isset($this->rules[$matched_def]) && is_array($this->rules[$matched_def])) {
            $screen_rules = $this->rules[$matched_def];
        } elseif (isset($this->rules[$screen_id]) && is_array($this->rules[$screen_id])) {
            $screen_rules = $this->rules[$screen_id];
        }

        $screen_state = $this->get_rule_state($screen_rules, $plugin);
        if ($screen_state !== null) {
            return [
                'state' => $screen_state,
                'source' => 'screen',
            ];
        }

        // Group-level rule
        if ($matched_def) {
            $group_key = $this->get_group_rule_key($matched_def);
            $group_rules = $group_key ? ($this->rules[$group_key] ?? []) : [];
            if (empty($group_rules)) {
                $group_rules = $this->rules['_group_' . $matched_def] ?? [];
            }
            $group_state = $this->get_rule_state($group_rules, $plugin);
            if ($group_state !== null) {
                return [
                    'state' => $group_state,
                    'source' => 'group',
                ];
            }
        }

        // Global rule
        $global_rules = $this->rules['_global_admin'] ?? [];
        $global_state = $this->get_rule_state($global_rules, $plugin);
        if ($global_state !== null) {
            return [
                'state' => $global_state,
                'source' => 'global',
            ];
        }

        return [
            'state' => null,
            'source' => null,
        ];
    }

    /**
     * Get inherited state (without screen rule)
     */
    public function get_inherited_rule(string $plugin, ?string $matched_def): array {
        if ($matched_def) {
            $group_key = $this->get_group_rule_key($matched_def);
            $group_rules = $group_key ? ($this->rules[$group_key] ?? []) : [];
            if (empty($group_rules)) {
                $group_rules = $this->rules['_group_' . $matched_def] ?? [];
            }
            $group_state = $this->get_rule_state($group_rules, $plugin);
            if ($group_state !== null) {
                return [
                    'state' => $group_state,
                    'source' => 'group',
                ];
            }
        }

        $global_rules = $this->rules['_global_admin'] ?? [];
        $global_state = $this->get_rule_state($global_rules, $plugin);
        if ($global_state !== null) {
            return [
                'state' => $global_state,
                'source' => 'global',
            ];
        }

        return [
            'state' => null,
            'source' => null,
        ];
    }

    private function get_group_rule_key(string $matched_def): ?string {
        if (empty($this->screen_definitions[$matched_def]['group'])) {
            return null;
        }

        $group = sanitize_key($this->screen_definitions[$matched_def]['group']);
        if ($group === '') {
            return null;
        }

        return '_group_' . $group;
    }
}
