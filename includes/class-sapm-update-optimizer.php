<?php
/**
 * SAPM Update Optimizer
 * 
 * Reduces WordPress update check overhead while preserving update badge counts.
 * 
 * Strategies:
 * 1. Transient TTL Extension - Extends update check cache from 12h to configurable duration
 * 2. Page-Specific Updates - Only run update checks on update-core.php and plugins.php
 * 3. Cron-Only Updates - Move all update checks to background WP-Cron (most aggressive)
 *
 * @package SmartAdminPluginManager
 * @since 1.3.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class SAPM_Update_Optimizer {

    /** @var self|null Singleton instance */
    private static $instance = null;

    /** @var array Configuration options */
    private $config = [];

    /** @var array Default configuration */
    private const DEFAULTS = [
        'enabled' => false,
        'strategy' => 'cron_only', // 'ttl_extension', 'page_specific', 'cron_only'
        'ttl_hours' => 24, // Extended TTL in hours
        'cron_interval' => 'twicedaily', // 'hourly', 'twicedaily', 'daily'
        'allowed_pages' => ['update-core.php', 'plugins.php', 'themes.php', 'update.php'],
        'blocked_endpoints' => [
            'api.wordpress.org/core/version-check',
            'api.wordpress.org/plugins/update-check',
            'api.wordpress.org/themes/update-check',
            'api.wordpress.org/translations',
        ],
        'plugin_updater_whitelist' => [
            // Critical plugins that should always check for updates (security)
        ],
        'plugin_updater_blacklist' => [
            // Known heavy/frequent updaters to block
            'api.zizicache.com',
            'translations.duplicator.com',
            // WooCommerce/WooPayments unnecessary API calls
            'public-api.wordpress.com/wpcom/v2/wcpay/incentives',
        ],
        'show_stale_indicator' => true,
        'force_check_on_plugins_page' => true,
    ];

    /** @var array Blocked request URLs this request */
    private $blocked_requests = [];

    /** @var bool Flag to track if we're on allowed page */
    private $is_allowed_page = null;

    /** @var float Start time for performance tracking */
    private $start_time = 0;

    /**
     * Private constructor (singleton)
     */
    private function __construct() {
        $this->start_time = microtime(true);
        $this->load_config();
    }

    /**
     * Get singleton instance
     */
    public static function get_instance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Initialize the optimizer (call early in WordPress load)
     */
    public static function init(): void {
        $instance = self::get_instance();
        
        if (!$instance->is_enabled()) {
            return;
        }

        // Early initialization - before any update checks
        add_action('plugins_loaded', [$instance, 'setup_hooks'], 1);
        
        // Hook for immediate update check (scheduled when transient is stale)
        add_action('sapm_immediate_update_check', [$instance, 'run_cron_update_check']);
    }

    /**
     * Setup all necessary hooks
     */
    public function setup_hooks(): void {
        $strategy = $this->config['strategy'] ?? 'page_specific';

        switch ($strategy) {
            case 'ttl_extension':
                $this->setup_ttl_extension();
                break;
            
            case 'page_specific':
                $this->setup_page_specific();
                break;
            
            case 'cron_only':
                $this->setup_cron_only();
                break;
        }

        // Common hooks
        if ($this->config['show_stale_indicator']) {
            add_action('admin_notices', [$this, 'maybe_show_stale_indicator']);
        }

        // Debug logging in admin
        if (defined('WP_DEBUG') && WP_DEBUG) {
            add_action('admin_footer', [$this, 'debug_output']);
        }
    }

    /**
     * Strategy 1: TTL Extension
     * Extends the transient TTL to reduce check frequency
     */
    private function setup_ttl_extension(): void {
        // Extend plugin update transient TTL
        add_filter('pre_set_site_transient_update_plugins', [$this, 'extend_transient_ttl'], 1);
        add_filter('pre_set_site_transient_update_themes', [$this, 'extend_transient_ttl'], 1);
        add_filter('pre_set_site_transient_update_core', [$this, 'extend_transient_ttl'], 1);
    }

    /**
     * Strategy 2: Page-Specific Updates
     * Only allow update checks on specific pages
     */
    private function setup_page_specific(): void {
        // Check page early
        add_action('admin_init', [$this, 'check_allowed_page'], 1);
        
        // Block HTTP requests on non-allowed pages
        add_filter('pre_http_request', [$this, 'maybe_block_update_request'], 5, 3);
        
        // Still use TTL extension as backup
        add_filter('pre_set_site_transient_update_plugins', [$this, 'extend_transient_ttl'], 1);
        add_filter('pre_set_site_transient_update_themes', [$this, 'extend_transient_ttl'], 1);
    }

    /**
     * Strategy 3: Cron-Only Updates
     * All update checks happen via WP-Cron, admin pages read from cache
     */
    private function setup_cron_only(): void {
        // Block ALL update HTTP requests in admin (not cron)
        if (!wp_doing_cron()) {
            add_filter('pre_http_request', [$this, 'block_all_update_requests'], 5, 3);
        }

        // Ensure cron job exists
        $this->ensure_update_cron();
        
        // Check if transient is stale and needs immediate refresh
        // This handles cases where admin logs in after long period of inactivity
        if (is_admin() && !wp_doing_ajax() && !wp_doing_cron()) {
            add_action('admin_init', [$this, 'maybe_refresh_stale_transient'], 5);
            
            // Force check on plugins page if configured
            if ($this->config['force_check_on_plugins_page'] ?? false) {
                add_action('admin_init', [$this, 'check_allowed_page'], 1);
            }
        }
    }
    
    /**
     * Check if update transient is stale and refresh if needed
     * This runs once per admin session to ensure accurate update counts
     */
    public function maybe_refresh_stale_transient(): void {
        // Only run once per hour to avoid excessive refreshes
        $last_stale_check = get_transient('sapm_stale_check_done');
        if ($last_stale_check) {
            return;
        }
        
        $transient = get_site_transient('update_plugins');
        
        // If transient doesn't exist or is missing last_checked, refresh
        if (!$transient || !isset($transient->last_checked)) {
            $this->schedule_immediate_refresh();
            set_transient('sapm_stale_check_done', true, HOUR_IN_SECONDS);
            return;
        }
        
        // Calculate max stale time based on cron interval
        $interval = $this->config['cron_interval'] ?? 'twicedaily';
        $max_stale_hours = match($interval) {
            'hourly' => 2,      // Max 2 hours stale for hourly
            'twicedaily' => 14, // Max 14 hours stale for twicedaily  
            'daily' => 26,      // Max 26 hours stale for daily
            default => 14,
        };
        
        $hours_ago = (time() - $transient->last_checked) / HOUR_IN_SECONDS;
        
        // If transient is too stale, schedule immediate refresh
        if ($hours_ago > $max_stale_hours) {
            $this->schedule_immediate_refresh();
        }
        
        set_transient('sapm_stale_check_done', true, HOUR_IN_SECONDS);
    }
    
    /**
     * Schedule immediate one-time update check
     */
    private function schedule_immediate_refresh(): void {
        // Use one-time event to avoid blocking current request
        if (!wp_next_scheduled('sapm_immediate_update_check')) {
            wp_schedule_single_event(time() + 5, 'sapm_immediate_update_check');
            add_action('sapm_immediate_update_check', [$this, 'run_cron_update_check']);
        }
    }

    /**
     * Check if current page is allowed for updates
     */
    public function check_allowed_page(): void {
        global $pagenow;
        
        $allowed = $this->config['allowed_pages'] ?? self::DEFAULTS['allowed_pages'];
        $this->is_allowed_page = in_array($pagenow, $allowed, true);
        
        // Force check on plugins page if configured
        if ($this->is_allowed_page && 
            $this->config['force_check_on_plugins_page'] && 
            $pagenow === 'plugins.php') {
            // Clear update cache to force fresh check
            // Only do this once per session
            if (!get_transient('sapm_plugins_page_checked')) {
                delete_site_transient('update_plugins');
                set_transient('sapm_plugins_page_checked', true, 60); // 1 minute cooldown
            }
        }
    }

    /**
     * Check if URL is an update endpoint
     */
    private function is_update_endpoint(string $url): bool {
        $blocked = $this->config['blocked_endpoints'] ?? self::DEFAULTS['blocked_endpoints'];
        
        foreach ($blocked as $pattern) {
            if (strpos($url, $pattern) !== false) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Check if URL is from whitelisted plugin updater
     */
    private function is_whitelisted_updater(string $url): bool {
        $whitelist = $this->config['plugin_updater_whitelist'] ?? [];
        
        foreach ($whitelist as $pattern) {
            if (stripos($url, $pattern) !== false) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Check if URL is from blacklisted plugin updater
     * 
     * Now blocks ALL known plugin updater endpoints automatically,
     * unless they are in the whitelist.
     */
    private function is_blacklisted_updater(string $url): bool {
        // Get all known plugin updater endpoints (block all automatically)
        $all_known = array_keys(self::get_known_plugin_updaters());
        
        // Check against all known endpoints
        foreach ($all_known as $pattern) {
            if (stripos($url, $pattern) !== false) {
                return true;
            }
        }
        
        // Also check custom blacklist entries from config
        $custom_blacklist = $this->config['plugin_updater_blacklist'] ?? [];
        $all_known_patterns = $all_known; // Already have this
        
        // Only check custom entries that are not in known list
        $custom_only = array_diff($custom_blacklist, $all_known_patterns);
        foreach ($custom_only as $pattern) {
            if (stripos($url, $pattern) !== false) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Maybe block update request (page-specific strategy)
     * 
     * @param mixed $preempt False to continue, response array to short-circuit
     * @param array $args Request arguments
     * @param string $url Request URL
     * @return mixed
     */
    public function maybe_block_update_request($preempt, array $args, string $url) {
        // If already blocked, return that
        if ($preempt !== false) {
            return $preempt;
        }

        // Check if it's an update endpoint
        if (!$this->is_update_endpoint($url)) {
            // Check if it's a blacklisted plugin updater
            if ($this->is_blacklisted_updater($url)) {
                return $this->create_cached_response($url, 'blacklisted');
            }
            return $preempt;
        }

        // Check if whitelisted
        if ($this->is_whitelisted_updater($url)) {
            return $preempt;
        }

        // If on allowed page, let it through
        if ($this->is_allowed_page === true) {
            return $preempt;
        }

        // Block and return cached/empty response
        return $this->create_cached_response($url, 'page_specific');
    }

    /**
     * Block all update requests (cron-only strategy)
     */
    public function block_all_update_requests($preempt, array $args, string $url) {
        if ($preempt !== false) {
            return $preempt;
        }

        // Block all update endpoints
        if ($this->is_update_endpoint($url)) {
            return $this->create_cached_response($url, 'cron_only');
        }

        // Block blacklisted plugin updaters
        if ($this->is_blacklisted_updater($url)) {
            return $this->create_cached_response($url, 'blacklisted');
        }

        return $preempt;
    }

    /**
     * Create a cached/empty response for blocked requests
     */
    private function create_cached_response(string $url, string $reason): array {
        // Log blocked request
        $this->blocked_requests[] = [
            'url' => $url,
            'reason' => $reason,
            'time' => microtime(true),
        ];

        // Determine response body based on endpoint type
        // WordPress expects specific structure for different update endpoints
        $body = $this->get_cached_response_body($url);
        
        // WooPayments incentives expects 204 No Content when no incentives available
        if (strpos($url, 'wcpay/incentives') !== false) {
            return [
                'headers' => [
                    'content-type' => 'application/json',
                    'cache-for' => (string) DAY_IN_SECONDS, // Cache for 1 day
                ],
                'body' => '', // 204 has no body
                'response' => [
                    'code' => 204,
                    'message' => 'No Content (SAPM Cached)',
                ],
                'cookies' => [],
                'filename' => null,
            ];
        }

        return [
            'headers' => [
                'content-type' => 'application/json',
            ],
            'body' => json_encode($body),
            'response' => [
                'code' => 200,
                'message' => 'OK (SAPM Cached)',
            ],
            'cookies' => [],
            'filename' => null,
        ];
    }

    /**
     * Get appropriate cached response body based on URL
     * 
     * @param string $url The request URL
     * @return array Response body structure
     */
    private function get_cached_response_body(string $url): array {
        // Plugin update check - WordPress expects specific structure
        if (strpos($url, 'plugins/update-check') !== false) {
            return [
                'plugins' => [],      // No updates available
                'translations' => [], // No translation updates
                'no_update' => [],    // No plugins need updating
            ];
        }
        
        // Theme update check
        if (strpos($url, 'themes/update-check') !== false) {
            return [
                'themes' => [],       // No theme updates
                'translations' => [], // No translation updates
                'no_update' => [],    // No themes need updating
            ];
        }
        
        // Core version check
        if (strpos($url, 'core/version-check') !== false) {
            global $wp_version;
            return [
                'offers' => [],
                'translations' => [],
            ];
        }
        
        // Translation updates
        if (strpos($url, '/translations') !== false) {
            return [
                'translations' => [],
            ];
        }
        
        // WooPayments incentives check - return empty incentives
        // This saves ~0.6s per admin page load
        if (strpos($url, 'wcpay/incentives') !== false) {
            return [];
        }
        
        // Generic third-party plugin updater - return empty valid response
        // Most expect either empty array, false, or simple structure
        return [];
    }

    /**
     * Extend transient TTL
     * 
     * @param mixed $transient The transient value being set
     * @return mixed
     */
    public function extend_transient_ttl($transient) {
        if (!$transient || !is_object($transient)) {
            return $transient;
        }

        // Get configured TTL
        $ttl_hours = $this->config['ttl_hours'] ?? self::DEFAULTS['ttl_hours'];
        
        // Calculate new check time — trick WordPress into thinking the check
        // happened recently so it won't issue a new HTTP check until TTL expires.
        $new_check_time = time() + ($ttl_hours * HOUR_IN_SECONDS) - (12 * HOUR_IN_SECONDS);
        
        // WordPress uses 'last_checked' property
        if (isset($transient->last_checked)) {
            // Store original for comparison
            $original_last_checked = $transient->last_checked;
            
            // Set a marker so we know this was modified
            if (!isset($transient->sapm_extended)) {
                $transient->sapm_extended = true;
                $transient->sapm_original_check = $original_last_checked;
                // Apply the extended TTL
                $transient->last_checked = $new_check_time;
            }
        }

        return $transient;
    }

    /**
     * Ensure update cron job exists (for cron-only strategy)
     */
    private function ensure_update_cron(): void {
        $interval = $this->config['cron_interval'] ?? 'twicedaily';
        $hook = 'sapm_cron_update_check';
        
        if (!wp_next_scheduled($hook)) {
            wp_schedule_event(time() + 300, $interval, $hook); // Start in 5 minutes
        }
        
        add_action($hook, [$this, 'run_cron_update_check']);
    }

    /**
     * Unschedule cron job
     */
    public function unschedule_cron(): void {
        $timestamp = wp_next_scheduled('sapm_cron_update_check');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'sapm_cron_update_check');
        }
    }

    /**
     * Run update check via cron
     */
    public function run_cron_update_check(): void {
        // Force update check
        delete_site_transient('update_plugins');
        delete_site_transient('update_themes');
        delete_site_transient('update_core');
        
        wp_update_plugins();
        wp_update_themes();
        wp_version_check();
        
        // Log the check
        update_option('sapm_last_cron_update_check', time(), false);
    }

    /**
     * Maybe show stale indicator in admin
     */
    public function maybe_show_stale_indicator(): void {
        // Only show on relevant pages
        global $pagenow;
        if (!in_array($pagenow, ['plugins.php', 'update-core.php', 'themes.php'])) {
            return;
        }

        $transient = get_site_transient('update_plugins');
        if (!$transient || !isset($transient->last_checked)) {
            return;
        }

        $last_checked = $transient->last_checked;
        $hours_ago = round((time() - $last_checked) / HOUR_IN_SECONDS, 1);
        
        // Only show if older than 1 hour
        if ($hours_ago < 1) {
            return;
        }

        $message = sprintf(
            '<span class="sapm-update-indicator" style="color: #666; font-style: italic;">%s</span>',
            sprintf(
                /* translators: %s: hours ago */
                esc_html__('Updates checked %s hours ago', 'sapm'),
                number_format($hours_ago, 1)
            )
        );

        // Add refresh link if not on allowed page
        if (!$this->is_allowed_page) {
            $refresh_url = admin_url('update-core.php?force-check=1');
            $message .= sprintf(
                ' | <a href="%s">%s</a>',
                esc_url($refresh_url),
                esc_html__('Check now', 'sapm')
            );
        }

        echo '<div class="notice notice-info is-dismissible"><p>' . $message . '</p></div>';
    }

    /**
     * Debug output in admin footer
     */
    public function debug_output(): void {
        if (empty($this->blocked_requests)) {
            return;
        }

        echo "\n<!-- SAPM Update Optimizer Debug:\n";
        echo "Strategy: " . ($this->config['strategy'] ?? 'unknown') . "\n";
        echo "Is Allowed Page: " . ($this->is_allowed_page ? 'Yes' : 'No') . "\n";
        echo "Blocked Requests:\n";
        foreach ($this->blocked_requests as $request) {
            echo "  - [{$request['reason']}] {$request['url']}\n";
        }
        echo "-->\n";
    }

    /**
     * Load configuration from options
     */
    private function load_config(): void {
        $saved = get_option('sapm_update_optimizer_config', []);
        $this->config = array_merge(self::DEFAULTS, $saved);
    }

    /**
     * Save configuration
     */
    public function save_config(array $config): bool {
        $old_strategy = $this->config['strategy'] ?? 'cron_only';
        $old_interval = $this->config['cron_interval'] ?? 'twicedaily';
        $old_config = $this->config;
        
        $this->config = array_merge($this->config, $config);
        
        // Handle cron rescheduling if strategy or interval changed
        $new_strategy = $this->config['strategy'] ?? 'cron_only';
        $new_interval = $this->config['cron_interval'] ?? 'twicedaily';
        
        if ($old_strategy !== $new_strategy || $old_interval !== $new_interval) {
            $this->unschedule_cron();
            if ($new_strategy === 'cron_only' && $this->is_enabled()) {
                $this->ensure_update_cron();
            }
        }
        
        // update_option returns false if value unchanged - that's not an error
        // We use serialize comparison to check if actually different
        $current_saved = get_option('sapm_update_optimizer_config', []);
        if (serialize($current_saved) === serialize($this->config)) {
            // Config unchanged - return true (not an error)
            return true;
        }
        
        return update_option('sapm_update_optimizer_config', $this->config, false);
    }

    /**
     * Get current configuration
     */
    public function get_config(): array {
        return $this->config;
    }

    /**
     * Check if optimizer is enabled
     */
    public function is_enabled(): bool {
        return !empty($this->config['enabled']);
    }

    /**
     * Enable optimizer
     */
    public function enable(): bool {
        return $this->save_config(['enabled' => true]);
    }

    /**
     * Disable optimizer
     */
    public function disable(): bool {
        return $this->save_config(['enabled' => false]);
    }

    /**
     * Get statistics about blocked requests
     */
    public function get_stats(): array {
        $transient = get_site_transient('update_plugins');
        $theme_transient = get_site_transient('update_themes');
        $breakdown = self::get_update_breakdown();
        $next_cron = wp_next_scheduled('sapm_cron_update_check');
        $last_cron = get_option('sapm_last_cron_update_check', 0);
        
        return [
            'enabled' => $this->is_enabled(),
            'strategy' => $this->config['strategy'] ?? 'unknown',
            'blocked_this_request' => count($this->blocked_requests),
            'blocked_details' => $this->blocked_requests,
            'plugin_updates_cached' => $breakdown['plugins'],
            'theme_updates_cached' => $breakdown['themes'],
            'translation_updates_cached' => $breakdown['translations'],
            'core_updates_cached' => $breakdown['core'],
            'last_plugin_check' => $transient->last_checked ?? null,
            'last_theme_check' => $theme_transient->last_checked ?? null,
            'last_cron_check' => $last_cron,
            'next_cron_check' => $next_cron,
            'config' => $this->config,
        ];
    }

    /**
     * Force update check now (for manual refresh)
     */
    public function force_update_check(): array {
        // Temporarily disable our hooks
        remove_filter('pre_http_request', [$this, 'maybe_block_update_request'], 5);
        remove_filter('pre_http_request', [$this, 'block_all_update_requests'], 5);
        
        // Clear transients
        delete_site_transient('update_plugins');
        delete_site_transient('update_themes');
        delete_site_transient('update_core');
        
        // Run checks
        $plugin_result = wp_update_plugins();
        $theme_result = wp_update_themes();
        $core_result = wp_version_check();
        
        // Re-enable hooks
        $strategy = $this->config['strategy'] ?? 'page_specific';
        if ($strategy === 'page_specific') {
            add_filter('pre_http_request', [$this, 'maybe_block_update_request'], 5, 3);
        } elseif ($strategy === 'cron_only' && !wp_doing_cron()) {
            add_filter('pre_http_request', [$this, 'block_all_update_requests'], 5, 3);
        }
        
        return [
            'success' => true,
            'plugins' => $plugin_result,
            'themes' => $theme_result,
            'core' => $core_result,
            'timestamp' => time(),
        ];
    }

    /**
     * Get badge count without triggering update check
     * This is what the admin menu uses
     */
    public static function get_update_count(): int {
        $breakdown = self::get_update_breakdown();

        return $breakdown['total'];
    }

    /**
     * Get detailed breakdown of available updates from cached site transients.
     *
     * @return array{plugins:int,themes:int,core:int,translations:int,total:int,total_with_translations:int}
     */
    public static function get_update_breakdown(): array {
        $plugin_transient = get_site_transient('update_plugins');
        $theme_transient = get_site_transient('update_themes');
        $core_transient = get_site_transient('update_core');

        $plugins = self::count_transient_items($plugin_transient, 'response');
        $themes = self::count_transient_items($theme_transient, 'response');
        $translations = self::count_transient_items($plugin_transient, 'translations')
            + self::count_transient_items($theme_transient, 'translations')
            + self::count_transient_items($core_transient, 'translations');
        $core = self::count_core_updates($core_transient);
        $total = $plugins + $themes + $core;

        return [
            'plugins' => $plugins,
            'themes' => $themes,
            'core' => $core,
            'translations' => $translations,
            'total' => $total,
            'total_with_translations' => $total + $translations,
        ];
    }

    /**
     * Count array items in a transient object property.
     */
    private static function count_transient_items($transient, string $property): int {
        if (!is_object($transient) || !isset($transient->{$property}) || !is_array($transient->{$property})) {
            return 0;
        }

        return count($transient->{$property});
    }

    /**
     * Count available core updates from update_core transient.
     */
    private static function count_core_updates($core_transient): int {
        if (!is_object($core_transient) || !isset($core_transient->updates) || !is_array($core_transient->updates)) {
            return 0;
        }

        $count = 0;
        foreach ($core_transient->updates as $update) {
            $response = is_object($update)
                ? ($update->response ?? null)
                : (is_array($update) ? ($update['response'] ?? null) : null);

            if ($response === 'upgrade') {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Get ALL known plugin update endpoints (for internal use/blocking)
     * Returns: [endpoint => display_name]
     */
    public static function get_known_plugin_updaters(): array {
        // Derive from the canonical mapping to avoid duplicate maintenance
        return array_map(
            fn(array $info): string => $info['name'],
            self::get_known_plugin_updaters_with_mapping()
        );
    }

    /**
     * Get known plugin updaters with plugin file mapping
     * Returns: [endpoint => ['name' => display_name, 'plugin_file' => plugin_file_pattern]]
     */
    public static function get_known_plugin_updaters_with_mapping(): array {
        return [
            'api.zizicache.com' => [
                'name' => 'ZiziCache',
                'plugin_file' => 'zizi-cache/zizi-cache.php',
            ],
            'translations.duplicator.com' => [
                'name' => 'Duplicator Pro',
                'plugin_file' => 'duplicator-pro/duplicator-pro.php',
            ],
            'developer.flavor.dev' => [
                'name' => 'FlyingPress',
                'plugin_file' => 'flying-press/flying-press.php',
            ],
            'flavor.dev' => [
                'name' => 'FlyingPress (alt)',
                'plugin_file' => 'flying-press/flying-press.php',
            ],
            'public-api.wordpress.com/wpcom/v2/wcpay' => [
                'name' => 'WooCommerce Payments',
                'plugin_file' => 'woocommerce-payments/woocommerce-payments.php',
            ],
            'my.elementor.com' => [
                'name' => 'Elementor Pro',
                'plugin_file' => 'elementor-pro/elementor-pro.php',
            ],
            'developer.flavor.dev/perfmatters' => [
                'name' => 'Perfmatters',
                'plugin_file' => 'perfmatters/perfmatters.php',
            ],
            'developer.flavor.dev/wpgrid' => [
                'name' => 'WP Grid Builder',
                'plugin_file' => 'wp-grid-builder/wp-grid-builder.php',
            ],
            'developer.flavor.dev/metabox' => [
                'name' => 'Meta Box',
                'plugin_file' => 'meta-box/meta-box.php',
            ],
            'developer.flavor.dev/generateblocks' => [
                'name' => 'GenerateBlocks',
                'plugin_file' => 'generateblocks/generateblocks.php',
            ],
            'developer.flavor.dev/generatepress' => [
                'name' => 'GeneratePress Premium',
                'plugin_file' => 'gp-premium/gp-premium.php',
            ],
        ];
    }

    /**
     * Get known plugin updaters filtered by installed plugins (for UI display)
     * Only shows plugins that are actually installed
     * 
     * @param bool $include_all If true, returns all known updaters (for advanced users)
     * @return array [endpoint => display_name] filtered by installed plugins
     */
    public static function get_installed_plugin_updaters(bool $include_all = false): array {
        if ($include_all) {
            return self::get_known_plugin_updaters();
        }

        $all_updaters = self::get_known_plugin_updaters_with_mapping();
        $installed = [];
        
        // Get list of all installed plugins
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $all_plugins = get_plugins();
        $installed_plugin_files = array_keys($all_plugins);
        
        foreach ($all_updaters as $endpoint => $info) {
            $plugin_file = $info['plugin_file'];
            
            // Check if plugin is installed
            if (in_array($plugin_file, $installed_plugin_files, true)) {
                $installed[$endpoint] = $info['name'];
            }
        }
        
        return $installed;
    }

    /**
     * Get uninstalled plugin updaters (for "show all" toggle)
     * 
     * @return array [endpoint => display_name] for plugins NOT installed
     */
    public static function get_uninstalled_plugin_updaters(): array {
        $all_updaters = self::get_known_plugin_updaters_with_mapping();
        $uninstalled = [];
        
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $all_plugins = get_plugins();
        $installed_plugin_files = array_keys($all_plugins);
        
        foreach ($all_updaters as $endpoint => $info) {
            $plugin_file = $info['plugin_file'];
            
            if (!in_array($plugin_file, $installed_plugin_files, true)) {
                $uninstalled[$endpoint] = $info['name'];
            }
        }
        
        return $uninstalled;
    }
}

// Initialize on plugins_loaded (after SAPM Core) — only in admin/cron context
if (is_admin() || wp_doing_cron() || (defined('WP_CLI') && WP_CLI)) {
    add_action('plugins_loaded', ['SAPM_Update_Optimizer', 'init'], 0);
}
