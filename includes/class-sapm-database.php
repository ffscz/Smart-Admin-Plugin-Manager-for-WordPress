<?php
/**
 * SAPM Database Handler
 * 
 * Manages custom database tables for sampling data storage
 * with 30-day retention policy.
 *
 * @package SmartAdminPluginManager
 * @since 1.2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class SAPM_Database {

    /** @var string Table name for sampling data */
    private static $table_name = 'sapm_sampling_data';

    /** @var int Maximum retention days */
    private const RETENTION_DAYS = 30;

    /** @var int Maximum samples per trigger */
    private const MAX_SAMPLES_PER_TRIGGER = 100;

    /** @var int Maximum triggers per request type */
    private const MAX_TRIGGERS_PER_TYPE = 50;

    /** @var bool|null Cached result of tables_exist check (per-request) */
    private static $tables_exist_cache = null;

    /** @var array Cached cleanup status per request_type (per-request) */
    private static $cleanup_checked = [];

    /**
     * Plugins that are safe to block for AJAX/REST/Cron requests
     * These plugins typically only need to run on frontend or specific admin screens
     * 
     * Key = plugin slug pattern, Value = request types where safe to block
     * @var array<string, array<string>>
     */
    private static $safe_to_block_patterns = [
        // Translation plugins - no AJAX/REST/Cron needed
        'loco-translate' => ['ajax', 'rest', 'cron'],
        'translatepress' => ['ajax', 'rest', 'cron'],
        
        // Font optimization - frontend only
        'host-webfonts-local' => ['ajax', 'rest', 'cron'], // OMGF
        'omgf' => ['ajax', 'rest', 'cron'], // OMGF alternate slug
        'font-awesome' => ['ajax', 'rest', 'cron'],
        
        // Database tools - no realtime processing needed
        'index-wp-mysql-for-speed' => ['ajax', 'rest', 'cron'],
        'wp-optimize' => ['ajax', 'rest'], // Keep cron for scheduled optimization
        
        // Frontend-only plugins
        'gallery-lightbox' => ['ajax', 'rest', 'cron'],
        'instagram-feed' => ['ajax', 'rest', 'cron'],
        'wp-reviews-plugin-for-google' => ['ajax', 'rest', 'cron'],
        'complianz' => ['ajax', 'rest', 'cron'], // GDPR consent - frontend only
        'cookie-notice' => ['ajax', 'rest', 'cron'],
        
        // Analytics/tracking - frontend only  
        'duracelltomi-google-tag-manager' => ['ajax', 'rest', 'cron'], // GTM4WP
        'google-analytics' => ['ajax', 'rest', 'cron'],
        'facebook-pixel' => ['ajax', 'cron'], // Keep REST for FB integration
        'enhanced-e-commerce-for-woocommerce-store' => ['ajax', 'rest', 'cron'], // Conversios.io - heavy, frontend analytics only
        'enhanced-e-commerce' => ['ajax', 'rest', 'cron'], // Generic enhanced e-commerce
        'conversios' => ['ajax', 'rest', 'cron'], // Conversios.io plugin
        'woo-ecommerce-tracking' => ['ajax', 'rest', 'cron'], // E-commerce tracking
        
        // Email marketing - only needs cron for sync
        'ecomail' => ['ajax', 'rest'],
        'mailchimp' => ['ajax', 'rest'],
        
        // Backup plugins - only need cron
        'updraftplus' => ['ajax', 'rest'],
        'duplicator' => ['ajax', 'rest'],
        'backwpup' => ['ajax', 'rest'],
        
        // Dev/debug tools - not needed in production requests
        'code-profiler' => ['ajax', 'rest', 'cron'],
        'query-monitor' => ['cron'], // Keep AJAX/REST for debugging
        
        // Preview/utility tools
        'woo-preview-emails' => ['ajax', 'rest', 'cron'],
        'order-import-export' => ['ajax', 'rest', 'cron'],
        'woo-order-export-lite' => ['ajax', 'rest', 'cron'],
        'wp-all-export' => ['ajax', 'rest', 'cron'],
        'wp-all-import' => ['ajax', 'rest', 'cron'],
        
        // Social embeds - frontend only
        'smash-balloon' => ['ajax', 'rest', 'cron'],
        'feeds-for-youtube' => ['ajax', 'rest', 'cron'],
        
        // Shipping plugins - only need specific WC hooks, not general AJAX/REST
        'packeta' => ['rest', 'cron'], // Keep AJAX for checkout widget
        'zasilkovna' => ['rest', 'cron'], // Czech name for Packeta
        
        // Points/rewards plugins - typically frontend only
        'points-and-rewards-for-woocommerce' => ['ajax', 'rest', 'cron'],
        'simple-points-and-rewards' => ['ajax', 'rest', 'cron'],
        'wc-points-rewards' => ['ajax', 'rest', 'cron'],
        
        // SEO plugins - only need admin and frontend
        'wp-seopress' => ['cron'], // Keep AJAX/REST for admin
        'seopress' => ['cron'],
    ];

    /**
     * Plugins that MUST run for specific request types (never suggest blocking)
     * @var array<string, array<string>>
     */
    private static $required_plugins = [
        // WooCommerce core - needs AJAX for cart, REST for app, cron for scheduled tasks
        'woocommerce' => [], // Empty = analyze via sampling (can be heavy but needed)
        
        // AJAX search - obviously needs AJAX!
        'ajax-search-for-woocommerce' => ['rest', 'cron'], // Block these, but NEVER ajax
        'fibosearch' => ['rest', 'cron'],
        'searchwp' => ['rest', 'cron'],
        
        // Form plugins - need AJAX for submissions
        'fluentform' => ['cron'],
        'wpforms' => ['cron'],
        'contact-form-7' => ['cron'],
        'gravityforms' => ['cron'],
        
        // Cache plugins - need to run everywhere for purging
        'litespeed-cache' => [],
        'wp-rocket' => [],
        'wp-super-cache' => [],
        'w3-total-cache' => [],
        
        // Security plugins - need cron for scans, block AJAX/REST for performance
        'wordfence' => ['ajax', 'rest'], // Block AJAX/REST, keep cron for security scans
        'sucuri' => ['ajax', 'rest'], // Block AJAX/REST, keep cron
        'ithemes-security' => ['ajax', 'rest'], // Block AJAX/REST, keep cron
        
        // This plugin itself
        'smart-admin-plugin-manager' => [],
    ];

    /**
     * Check if a plugin is safe to block for a given request type
     * Based on known plugin patterns and their actual requirements
     * 
     * @param string $plugin_file Plugin file path (e.g., 'loco-translate/loco.php')
     * @param string $request_type Request type (ajax, rest, cron, cli)
     * @return array{safe: bool, reason: string|null}
     */
    public static function is_plugin_safe_to_block(string $plugin_file, string $request_type): array {
        $plugin_slug = strtolower(dirname($plugin_file));
        if ($plugin_slug === '.') {
            $plugin_slug = strtolower(basename($plugin_file, '.php'));
        }
        
        /**
         * Filter to extend safe_to_block patterns
         * 
         * @param array $patterns Array of plugin patterns => request types
         * @return array Modified patterns
         */
        $safe_patterns = apply_filters('sapm_safe_to_block_patterns', self::$safe_to_block_patterns);
        
        /**
         * Filter to extend required plugins list
         * 
         * @param array $required Array of required plugin patterns => blockable request types
         * @return array Modified required plugins
         */
        $required_plugins = apply_filters('sapm_required_plugins', self::$required_plugins);
        
        // Sort patterns by length descending to match more specific patterns first
        // e.g., 'ajax-search-for-woocommerce' before 'woocommerce'
        uksort($required_plugins, fn($a, $b) => strlen($b) - strlen($a));
        
        // Check if plugin is in the "required" list and request type is NOT in blockable types
        foreach ($required_plugins as $pattern => $blockable_types) {
            // Use more precise matching: pattern must match the start or be a separate word
            $matches = (
                $plugin_slug === $pattern ||                                    // Exact match
                strpos($plugin_slug, $pattern . '/') === 0 ||                   // Starts with pattern/
                strpos($plugin_slug, $pattern . '-') === 0 ||                   // Starts with pattern-
                preg_match('/^' . preg_quote($pattern, '/') . '(?:[\/\-]|$)/', $plugin_slug)  // Pattern at start
            );
            
            if ($matches) {
                // Empty array = never suggest blocking
                if (empty($blockable_types)) {
                    return [
                        'safe' => false, 
                        'reason' => sprintf(__('Plugin "%s" is critical and should not be blocked', 'smart-admin-plugin-manager'), $pattern)
                    ];
                }
                // If request_type is in blockable_types, it's safe to block
                if (in_array($request_type, $blockable_types, true)) {
                    return [
                        'safe' => true,
                        'reason' => sprintf(__('Plugin "%s" does not need to run for %s requests', 'smart-admin-plugin-manager'), $pattern, strtoupper($request_type))
                    ];
                }
                // Otherwise, don't block
                return [
                    'safe' => false,
                    'reason' => sprintf(__('Plugin "%s" needs to run for %s requests', 'smart-admin-plugin-manager'), $pattern, strtoupper($request_type))
                ];
            }
        }
        
        // Check if plugin matches "safe to block" patterns
        foreach ($safe_patterns as $pattern => $safe_request_types) {
            if (stripos($plugin_slug, $pattern) !== false) {
                if (in_array($request_type, $safe_request_types, true)) {
                    return [
                        'safe' => true,
                        'reason' => sprintf(__('Plugin "%s" is typically frontend-only and can be safely blocked for %s', 'smart-admin-plugin-manager'), $pattern, strtoupper($request_type))
                    ];
                }
            }
        }
        
        // Default: analyze via sampling data
        return ['safe' => null, 'reason' => null];
    }

    /**
     * Get full table name with prefix
     */
    public static function get_table_name(): string {
        global $wpdb;
        return $wpdb->prefix . self::$table_name;
    }

    /**
     * Create database tables on plugin activation
     */
    public static function create_tables(): void {
        global $wpdb;
        
        $table_name = self::get_table_name();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            request_type VARCHAR(20) NOT NULL,
            trigger_name VARCHAR(255) NOT NULL,
            plugin_file VARCHAR(255) NOT NULL,
            avg_ms DECIMAL(10,2) NOT NULL DEFAULT 0,
            avg_queries DECIMAL(10,2) NOT NULL DEFAULT 0,
            sample_count INT UNSIGNED NOT NULL DEFAULT 0,
            total_ms_sum DECIMAL(12,2) NOT NULL DEFAULT 0,
            total_queries_sum INT UNSIGNED NOT NULL DEFAULT 0,
            first_sample DATETIME NOT NULL,
            last_sample DATETIME NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY idx_trigger_plugin (request_type, trigger_name, plugin_file),
            KEY idx_request_type (request_type),
            KEY idx_trigger_name (trigger_name),
            KEY idx_last_sample (last_sample),
            KEY idx_plugin_file (plugin_file)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        // Store DB version
        update_option('sapm_db_version', '1.0.0', false);
        
        // Reset cache since table now definitely exists
        self::$tables_exist_cache = true;
    }

    /**
     * Drop tables on plugin uninstall
     */
    public static function drop_tables(): void {
        global $wpdb;
        $table_name = self::get_table_name();
        $wpdb->query("DROP TABLE IF EXISTS {$table_name}");
        delete_option('sapm_db_version');
        self::reset_cache();
    }

    /**
     * Check if tables exist (cached per-request)
     * 
     * @param bool $force_check Force fresh check, bypassing cache
     */
    public static function tables_exist(bool $force_check = false): bool {
        // Return cached result if available (saves 11+ DB queries per request)
        if (!$force_check && self::$tables_exist_cache !== null) {
            return self::$tables_exist_cache;
        }

        global $wpdb;
        $table_name = self::get_table_name();
        $result = $wpdb->get_var("SHOW TABLES LIKE '{$table_name}'");
        self::$tables_exist_cache = ($result === $table_name);
        
        return self::$tables_exist_cache;
    }

    /**
     * Reset caches (useful for testing or after table operations)
     */
    public static function reset_cache(): void {
        self::$tables_exist_cache = null;
        self::$cleanup_checked = [];
    }

    /**
     * Store or update a sampling record
     * 
     * @param array $sample Single plugin sample with keys:
     *   - request_type: string (ajax, rest, cron, cli, admin)
     *   - trigger_name: string (e.g., "ajax:heartbeat")
     *   - plugin_file: string (plugin basename)
     *   - load_time_ms: float (milliseconds)
     *   - query_count: int
     */
    public static function store_sample(array $sample): bool {
        global $wpdb;
        
        if (!self::tables_exist()) {
            self::create_tables();
        }

        $table_name = self::get_table_name();
        $request_type = sanitize_key($sample['request_type'] ?? 'unknown');
        $trigger = sanitize_text_field($sample['trigger_name'] ?? 'unknown');
        $plugin_file = sanitize_text_field($sample['plugin_file'] ?? 'unknown');
        $ms = floatval($sample['load_time_ms'] ?? 0);
        $queries = intval($sample['query_count'] ?? 0);
        $now = current_time('mysql');

        // Check trigger count limit
        self::maybe_cleanup_old_triggers($request_type);

        // Try to update existing record
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT id, sample_count, total_ms_sum, total_queries_sum 
             FROM `{$table_name}` 
             WHERE request_type = %s AND trigger_name = %s AND plugin_file = %s",
            $request_type,
            $trigger,
            $plugin_file
        ));

        if ($existing) {
            // Update existing record
            $new_sample_count = $existing->sample_count + 1;
            $new_total_ms = $existing->total_ms_sum + $ms;
            $new_total_queries = $existing->total_queries_sum + $queries;

            // Apply scaling if exceeding max samples
            if ($new_sample_count > self::MAX_SAMPLES_PER_TRIGGER) {
                $scale = self::MAX_SAMPLES_PER_TRIGGER / $new_sample_count;
                $new_sample_count = self::MAX_SAMPLES_PER_TRIGGER;
                $new_total_ms *= $scale;
                $new_total_queries = (int) round($new_total_queries * $scale);
            }

            $avg_ms = $new_sample_count > 0 ? $new_total_ms / $new_sample_count : 0;
            $avg_queries = $new_sample_count > 0 ? $new_total_queries / $new_sample_count : 0;

            $wpdb->update(
                $table_name,
                [
                    'sample_count' => $new_sample_count,
                    'total_ms_sum' => $new_total_ms,
                    'total_queries_sum' => $new_total_queries,
                    'avg_ms' => round($avg_ms, 2),
                    'avg_queries' => round($avg_queries, 2),
                    'last_sample' => $now,
                ],
                ['id' => $existing->id],
                ['%d', '%f', '%d', '%f', '%f', '%s'],
                ['%d']
            );
        } else {
            // Insert new record
            $wpdb->insert(
                $table_name,
                [
                    'request_type' => $request_type,
                    'trigger_name' => $trigger,
                    'plugin_file' => $plugin_file,
                    'sample_count' => 1,
                    'total_ms_sum' => $ms,
                    'total_queries_sum' => $queries,
                    'avg_ms' => $ms,
                    'avg_queries' => $queries,
                    'first_sample' => $now,
                    'last_sample' => $now,
                ],
                ['%s', '%s', '%s', '%d', '%f', '%d', '%f', '%f', '%s', '%s']
            );
        }

        return true;
    }

    /**
     * Remove old triggers when limit is reached (cached per request_type per request)
     * 
     * This check only needs to run once per request_type per HTTP request,
     * not for every plugin sample stored.
     */
    private static function maybe_cleanup_old_triggers(string $request_type): void {
        // Skip if already checked for this request_type in this request
        if (isset(self::$cleanup_checked[$request_type])) {
            return;
        }
        self::$cleanup_checked[$request_type] = true;

        global $wpdb;
        $table_name = self::get_table_name();

        // Count distinct triggers for this request type
        $trigger_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT trigger_name) FROM `{$table_name}` WHERE request_type = %s",
            $request_type
        ));

        if ($trigger_count >= self::MAX_TRIGGERS_PER_TYPE) {
            // Find oldest trigger
            $oldest_trigger = $wpdb->get_var($wpdb->prepare(
                "SELECT trigger_name FROM `{$table_name}` 
                 WHERE request_type = %s 
                 GROUP BY trigger_name 
                 ORDER BY MAX(last_sample) ASC 
                 LIMIT 1",
                $request_type
            ));

            if ($oldest_trigger) {
                $wpdb->delete(
                    $table_name,
                    ['request_type' => $request_type, 'trigger_name' => $oldest_trigger],
                    ['%s', '%s']
                );
            }
        }
    }

    /**
     * Get all sampling data grouped by request type and trigger
     */
    public static function get_sampling_data(?string $request_type = null): array {
        global $wpdb;
        
        if (!self::tables_exist()) {
            return [];
        }

        $table_name = self::get_table_name();
        
        $where = '';
        $params = [];
        
        if ($request_type !== null) {
            $where = 'WHERE request_type = %s';
            $params[] = $request_type;
        }

        $query = "SELECT * FROM `{$table_name}` {$where} ORDER BY request_type, trigger_name, avg_ms DESC";
        
        if (!empty($params)) {
            $query = $wpdb->prepare($query, ...$params);
        }

        $rows = $wpdb->get_results($query, ARRAY_A);
        
        if (empty($rows)) {
            return [];
        }

        // Group data
        $grouped = [];
        foreach ($rows as $row) {
            $rt = $row['request_type'];
            $trigger = $row['trigger_name'];
            
            if (!isset($grouped[$rt])) {
                $grouped[$rt] = [];
            }
            
            if (!isset($grouped[$rt][$trigger])) {
                $grouped[$rt][$trigger] = [
                    'trigger' => $trigger,
                    'first_sample' => $row['first_sample'],
                    'last_sample' => $row['last_sample'],
                    'plugins' => [],
                ];
            }

            // Update first/last sample times
            if ($row['first_sample'] < $grouped[$rt][$trigger]['first_sample']) {
                $grouped[$rt][$trigger]['first_sample'] = $row['first_sample'];
            }
            if ($row['last_sample'] > $grouped[$rt][$trigger]['last_sample']) {
                $grouped[$rt][$trigger]['last_sample'] = $row['last_sample'];
            }

            $grouped[$rt][$trigger]['plugins'][] = [
                'plugin' => $row['plugin_file'],
                'avg_ms' => floatval($row['avg_ms']),
                'avg_queries' => floatval($row['avg_queries']),
                'samples' => intval($row['sample_count']),
            ];
        }

        // Calculate totals per trigger
        foreach ($grouped as $rt => &$triggers) {
            foreach ($triggers as $trigger => &$data) {
                $total_ms = array_sum(array_column($data['plugins'], 'avg_ms'));
                $total_queries = array_sum(array_column($data['plugins'], 'avg_queries'));
                $max_samples = max(array_column($data['plugins'], 'samples'));
                
                $data['avg_total_ms'] = round($total_ms, 2);
                $data['avg_total_queries'] = round($total_queries, 1);
                $data['samples'] = $max_samples;
                
                // Sort plugins by avg_ms descending
                usort($data['plugins'], fn($a, $b) => $b['avg_ms'] <=> $a['avg_ms']);
            }
            
            // Sort triggers by last_sample descending
            uasort($triggers, fn($a, $b) => strtotime($b['last_sample']) <=> strtotime($a['last_sample']));
        }

        return $grouped;
    }

    /**
     * Get formatted data for UI display
     */
    public static function get_sampling_data_formatted(?string $request_type = null): array {
        return self::get_sampling_data($request_type);
    }

    /**
     * Clear sampling data
     */
    public static function clear_sampling_data(?string $request_type = null): bool {
        global $wpdb;
        
        if (!self::tables_exist()) {
            return true;
        }

        $table_name = self::get_table_name();

        if ($request_type === null) {
            return $wpdb->query("TRUNCATE TABLE `{$table_name}`") !== false;
        }

        return $wpdb->delete($table_name, ['request_type' => $request_type], ['%s']) !== false;
    }

    /**
     * Cleanup old data based on retention policy
     */
    public static function cleanup_old_data(): int {
        global $wpdb;
        
        if (!self::tables_exist()) {
            return 0;
        }

        $table_name = self::get_table_name();
        $cutoff_date = date('Y-m-d H:i:s', strtotime('-' . self::RETENTION_DAYS . ' days'));

        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM `{$table_name}` WHERE last_sample < %s",
            $cutoff_date
        ));

        return $deleted !== false ? $deleted : 0;
    }

    /**
     * Get statistics about stored data
     */
    public static function get_stats(): array {
        global $wpdb;
        
        if (!self::tables_exist()) {
            return [
                'total_records' => 0,
                'request_types' => [],
                'oldest_sample' => null,
                'newest_sample' => null,
                'table_size_kb' => 0,
            ];
        }

        $table_name = self::get_table_name();

        // Total records
        $total = $wpdb->get_var("SELECT COUNT(*) FROM `{$table_name}`");

        // Per request type
        $by_type = $wpdb->get_results(
            "SELECT request_type, 
                    COUNT(DISTINCT trigger_name) as triggers, 
                    COUNT(*) as records,
                    SUM(sample_count) as total_samples
             FROM `{$table_name}` 
             GROUP BY request_type",
            ARRAY_A
        );

        $request_types = [];
        foreach ($by_type as $row) {
            $request_types[$row['request_type']] = [
                'triggers' => intval($row['triggers']),
                'records' => intval($row['records']),
                'total_samples' => intval($row['total_samples']),
            ];
        }

        // Date range
        $oldest = $wpdb->get_var("SELECT MIN(first_sample) FROM `{$table_name}`");
        $newest = $wpdb->get_var("SELECT MAX(last_sample) FROM `{$table_name}`");

        // Table size
        $size_result = $wpdb->get_row(
            "SELECT ROUND((DATA_LENGTH + INDEX_LENGTH) / 1024, 2) AS size_kb 
             FROM information_schema.TABLES 
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$table_name}'"
        );
        $table_size = $size_result ? floatval($size_result->size_kb) : 0;

        return [
            'total_records' => intval($total),
            'request_types' => $request_types,
            'oldest_sample' => $oldest,
            'newest_sample' => $newest,
            'table_size_kb' => $table_size,
            'retention_days' => self::RETENTION_DAYS,
        ];
    }

    /**
     * Get plugin usage summary across all triggers
     */
    public static function get_plugin_usage_summary(): array {
        global $wpdb;
        
        if (!self::tables_exist()) {
            return [];
        }

        $table_name = self::get_table_name();

        $results = $wpdb->get_results(
            "SELECT plugin_file,
                    request_type,
                    COUNT(DISTINCT trigger_name) as trigger_count,
                    SUM(sample_count) as total_samples,
                    AVG(avg_ms) as overall_avg_ms,
                    AVG(avg_queries) as overall_avg_queries
             FROM `{$table_name}`
             GROUP BY plugin_file, request_type
             ORDER BY overall_avg_ms DESC",
            ARRAY_A
        );

        $summary = [];
        foreach ($results as $row) {
            $plugin = $row['plugin_file'];
            if (!isset($summary[$plugin])) {
                $summary[$plugin] = [
                    'plugin' => $plugin,
                    'request_types' => [],
                    'total_trigger_count' => 0,
                    'total_samples' => 0,
                    'overall_avg_ms' => 0,
                    'overall_avg_queries' => 0,
                ];
            }

            $summary[$plugin]['request_types'][$row['request_type']] = [
                'triggers' => intval($row['trigger_count']),
                'samples' => intval($row['total_samples']),
                'avg_ms' => round(floatval($row['overall_avg_ms']), 2),
                'avg_queries' => round(floatval($row['overall_avg_queries']), 2),
            ];
            
            $summary[$plugin]['total_trigger_count'] += intval($row['trigger_count']);
            $summary[$plugin]['total_samples'] += intval($row['total_samples']);
        }

        // Calculate overall averages
        foreach ($summary as &$plugin_data) {
            $total_ms = 0;
            $total_queries = 0;
            $count = 0;
            foreach ($plugin_data['request_types'] as $rt_data) {
                $total_ms += $rt_data['avg_ms'];
                $total_queries += $rt_data['avg_queries'];
                $count++;
            }
            if ($count > 0) {
                $plugin_data['overall_avg_ms'] = round($total_ms / $count, 2);
                $plugin_data['overall_avg_queries'] = round($total_queries / $count, 2);
            }
        }

        // Sort by overall_avg_ms descending
        uasort($summary, fn($a, $b) => $b['overall_avg_ms'] <=> $a['overall_avg_ms']);

        return array_values($summary);
    }

    /**
     * Get auto-generated rules based on sampling data
     */
    public static function generate_auto_rules(float $confidence_threshold = 0.7): array {
        global $wpdb;
        
        if (!self::tables_exist()) {
            return [];
        }

        $table_name = self::get_table_name();
        $min_samples = 1; // Minimum samples for confidence (lowered from 5 to allow early suggestions)

        // Get plugins that are rarely used per trigger (exclude admin - handled separately)
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT 
                request_type,
                trigger_name,
                plugin_file,
                sample_count,
                avg_ms,
                avg_queries,
                (SELECT COUNT(DISTINCT t2.trigger_name) 
                 FROM `{$table_name}` t2 
                 WHERE t2.request_type = t1.request_type 
                 AND t2.plugin_file = t1.plugin_file) as trigger_presence
             FROM `{$table_name}` t1
             WHERE sample_count >= %d
             AND request_type IN ('ajax', 'rest', 'cron', 'cli')
             ORDER BY request_type, trigger_name, avg_ms DESC",
            $min_samples
        ), ARRAY_A);

        $auto_rules = [
            'ajax' => ['suggested_blocks' => [], 'suggested_whitelist' => []],
            'rest' => ['suggested_blocks' => [], 'suggested_whitelist' => []],
            'cron' => ['suggested_blocks' => [], 'suggested_whitelist' => []],
            'cli' => ['suggested_blocks' => [], 'suggested_whitelist' => []],
        ];

        // Analyze patterns
        $plugin_trigger_map = [];
        foreach ($results as $row) {
            $rt = $row['request_type'];
            $plugin = $row['plugin_file'];
            $trigger = $row['trigger_name'];
            $avg_ms = floatval($row['avg_ms']);
            $samples = intval($row['sample_count']);

            if (!isset($plugin_trigger_map[$rt][$plugin])) {
                $plugin_trigger_map[$rt][$plugin] = [
                    'triggers' => [],
                    'total_avg_ms' => 0,
                    'count' => 0,
                ];
            }

            $plugin_trigger_map[$rt][$plugin]['triggers'][$trigger] = [
                'avg_ms' => $avg_ms,
                'samples' => $samples,
            ];
            $plugin_trigger_map[$rt][$plugin]['total_avg_ms'] += $avg_ms;
            $plugin_trigger_map[$rt][$plugin]['count']++;
        }

        // Generate suggestions
        foreach ($plugin_trigger_map as $rt => $plugins) {
            // Calculate average load time across all plugins for this request type
            $all_avg_ms = array_map(fn($p) => $p['count'] > 0 ? $p['total_avg_ms'] / $p['count'] : 0, $plugins);
            $global_avg_ms = count($all_avg_ms) > 0 ? array_sum($all_avg_ms) / count($all_avg_ms) : 0;
            
            foreach ($plugins as $plugin => $data) {
                $avg_ms = $data['count'] > 0 ? $data['total_avg_ms'] / $data['count'] : 0;
                $trigger_count = count($data['triggers']);
                $total_triggers = count($wpdb->get_col($wpdb->prepare(
                    "SELECT DISTINCT trigger_name FROM `{$table_name}` WHERE request_type = %s",
                    $rt
                )));

                // Calculate confidence
                $presence_ratio = $total_triggers > 0 ? $trigger_count / $total_triggers : 0;
                
                // === NEW: Check contextual relevance first ===
                $safe_check = self::is_plugin_safe_to_block($plugin, $rt);
                
                // If plugin is known to be safe to block, suggest with high confidence
                if ($safe_check['safe'] === true) {
                    // Only add if not already suggested and has meaningful load time
                    if ($avg_ms >= 1 && !in_array($plugin, array_column($auto_rules[$rt]['suggested_blocks'], 'plugin'))) {
                        $auto_rules[$rt]['suggested_blocks'][] = [
                            'plugin' => $plugin,
                            'confidence' => 0.90, // High confidence for known patterns
                            'reason' => $safe_check['reason'] ?? sprintf(
                                __('Plugin can be safely blocked for %s requests (savings: %.1fms)', 'smart-admin-plugin-manager'),
                                strtoupper($rt),
                                $avg_ms
                            ),
                            'savings_ms' => round($avg_ms, 2),
                            'source' => 'contextual', // Mark as contextually determined
                        ];
                    }
                    continue; // Skip further analysis for this plugin
                }
                
                // If plugin is known to be required, skip blocking suggestions
                if ($safe_check['safe'] === false) {
                    // Don't add to whitelist automatically - let sampling decide
                    continue;
                }
                
                // === Sampling-based analysis for unknown plugins ===
                
                // Strategy 1: Plugin with high load time but low presence = good candidate for blocking
                // UPDATED: Relaxed thresholds - avg_ms > 5ms and presence_ratio < 0.5 (50%)
                if ($avg_ms > 5 && $presence_ratio < 0.5) {
                    $confidence = min(0.95, (1 - $presence_ratio) * ($avg_ms / 30));
                    if ($confidence >= $confidence_threshold) {
                        $auto_rules[$rt]['suggested_blocks'][] = [
                            'plugin' => $plugin,
                            'confidence' => round($confidence, 2),
                            'reason' => sprintf(
                                __('Plugin has %.1fms average load time, but appears in only %d%% of %s triggers', 'smart-admin-plugin-manager'),
                                $avg_ms,
                                round($presence_ratio * 100),
                                $rt
                            ),
                            'savings_ms' => round($avg_ms, 2),
                            'source' => 'sampling',
                        ];
                    }
                }
                
                // Strategy 2: Heavy plugin (high avg_ms) - good optimization candidate
                // Plugin is 1.5x heavier than average = optimization candidate (relaxed from 2x)
                $is_heavy = $avg_ms > 8 && ($global_avg_ms > 0 && $avg_ms > $global_avg_ms * 1.5);
                if ($is_heavy && !in_array($plugin, array_column($auto_rules[$rt]['suggested_blocks'], 'plugin'))) {
                    // Calculate confidence based on how much heavier than average
                    $weight_factor = $global_avg_ms > 0 ? $avg_ms / $global_avg_ms : 1;
                    $confidence = min(0.90, 0.5 + ($weight_factor - 1.5) * 0.15);
                    
                    if ($confidence >= $confidence_threshold) {
                        $auto_rules[$rt]['suggested_blocks'][] = [
                            'plugin' => $plugin,
                            'confidence' => round($confidence, 2),
                            'reason' => sprintf(
                                __('Plugin has %.1fms average load time (%.1f× more than average %.1fms) - optimization candidate', 'smart-admin-plugin-manager'),
                                $avg_ms,
                                round($weight_factor, 1),
                                round($global_avg_ms, 1)
                            ),
                            'savings_ms' => round($avg_ms, 2),
                            'source' => 'sampling',
                        ];
                    }
                }

                // === UPDATED: Stricter whitelist criteria ===
                // Only whitelist if:
                // 1. Very high presence (>90% instead of 80%)
                // 2. Very low load time (<3ms instead of 5ms)
                // 3. Sufficient sample count (at least 3 triggers)
                // 4. Not in "safe to block" list
                $min_trigger_count = max(3, min(5, $total_triggers));
                if ($presence_ratio > 0.9 && $trigger_count >= $min_trigger_count && $avg_ms < 3) {
                    $confidence = min(0.95, $presence_ratio);
                    $auto_rules[$rt]['suggested_whitelist'][] = [
                        'plugin' => $plugin,
                        'confidence' => round($confidence, 2),
                        'reason' => sprintf(
                            __('Plugin appears in %d%% of %s triggers and has minimal load (%.1fms) - likely needed', 'smart-admin-plugin-manager'),
                            round($presence_ratio * 100),
                            $rt,
                            $avg_ms
                        ),
                    ];
                }
            }

            // Sort by confidence
            usort($auto_rules[$rt]['suggested_blocks'], fn($a, $b) => $b['confidence'] <=> $a['confidence']);
            usort($auto_rules[$rt]['suggested_whitelist'], fn($a, $b) => $b['confidence'] <=> $a['confidence']);
        }

        // Generate admin screen suggestions (per-screen blocking)
        $admin_suggestions = self::generate_admin_screen_suggestions($confidence_threshold);
        if (!empty($admin_suggestions)) {
            $auto_rules['admin_screens'] = $admin_suggestions;
        }

        return $auto_rules;
    }

    /**
     * Generate per-screen plugin blocking suggestions based on admin sampling data
     * 
     * @param float $confidence_threshold Minimum confidence for suggestions
     * @return array Screen-based suggestions
     */
    public static function generate_admin_screen_suggestions(float $confidence_threshold = 0.5): array {
        global $wpdb;
        
        if (!self::tables_exist()) {
            return [];
        }

        $table_name = self::get_table_name();
        
        // Get admin screen data
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT 
                trigger_name,
                plugin_file,
                sample_count,
                avg_ms,
                avg_queries
             FROM `{$table_name}`
             WHERE request_type = 'admin' 
             AND trigger_name LIKE 'screen:%%'
             AND sample_count >= %d
             ORDER BY trigger_name, avg_ms DESC",
            1
        ), ARRAY_A);

        if (empty($results)) {
            return [];
        }

        // Group by screen
        $screens = [];
        $all_plugins = [];
        
        foreach ($results as $row) {
            $screen = str_replace('screen:', '', $row['trigger_name']);
            $plugin = $row['plugin_file'];
            $avg_ms = floatval($row['avg_ms']);
            $samples = intval($row['sample_count']);
            
            if (!isset($screens[$screen])) {
                $screens[$screen] = [
                    'plugins' => [],
                    'total_ms' => 0,
                    'samples' => 0,
                ];
            }
            
            $screens[$screen]['plugins'][$plugin] = [
                'avg_ms' => $avg_ms,
                'avg_queries' => floatval($row['avg_queries']),
                'samples' => $samples,
            ];
            $screens[$screen]['total_ms'] += $avg_ms;
            $screens[$screen]['samples'] = max($screens[$screen]['samples'], $samples);
            
            // Track global plugin usage
            if (!isset($all_plugins[$plugin])) {
                $all_plugins[$plugin] = ['screens' => [], 'total_ms' => 0, 'count' => 0];
            }
            $all_plugins[$plugin]['screens'][] = $screen;
            $all_plugins[$plugin]['total_ms'] += $avg_ms;
            $all_plugins[$plugin]['count']++;
        }

        $total_screens = count($screens);
        $suggestions = [];
        
        // Analyze each screen for optimization opportunities
        foreach ($screens as $screen => $data) {
            $screen_suggestions = [
                'screen' => $screen,
                'screen_label' => self::get_screen_label($screen),
                'total_load_ms' => round($data['total_ms'], 2),
                'samples' => $data['samples'],
                'suggested_blocks' => [],
                'suggested_defer' => [],
            ];
            
            // Calculate screen average
            $plugin_count = count($data['plugins']);
            $screen_avg_ms = $plugin_count > 0 ? $data['total_ms'] / $plugin_count : 0;
            
            foreach ($data['plugins'] as $plugin => $plugin_data) {
                $avg_ms = $plugin_data['avg_ms'];
                $plugin_screen_count = count($all_plugins[$plugin]['screens'] ?? []);
                $presence_ratio = $total_screens > 0 ? $plugin_screen_count / $total_screens : 1;
                
                // Strategy 1: Heavy plugin not needed on this screen
                // Plugin has high load time but appears on few screens = candidate for selective blocking
                // UPDATED: Relaxed thresholds - avg_ms > 8ms and presence_ratio < 0.7 (70%)
                if ($avg_ms > 8 && $presence_ratio < 0.7) {
                    // Higher confidence if plugin is very heavy and rarely used
                    $confidence = min(0.90, 0.4 + ($avg_ms / 80) + (1 - $presence_ratio) * 0.4);
                    
                    if ($confidence >= $confidence_threshold) {
                        $screen_suggestions['suggested_blocks'][] = [
                            'plugin' => $plugin,
                            'confidence' => round($confidence, 2),
                            'reason' => sprintf(
                                __('Plugin has %.1fms load on this screen and is only needed on %d%% of screens', 'smart-admin-plugin-manager'),
                                $avg_ms,
                                round($presence_ratio * 100)
                            ),
                            'savings_ms' => round($avg_ms, 2),
                            'action' => 'block',
                        ];
                    }
                }
                
                // Strategy 2: Heavy plugin that might benefit from defer
                // Plugin is heavy (>10ms) but used on most screens = defer candidate (relaxed from >20ms)
                if ($avg_ms > 10 && $presence_ratio > 0.5 && $avg_ms > $screen_avg_ms * 1.3) {
                    $weight_factor = $screen_avg_ms > 0 ? $avg_ms / $screen_avg_ms : 1;
                    $confidence = min(0.85, 0.35 + ($weight_factor - 1.3) * 0.25);
                    
                    if ($confidence >= $confidence_threshold) {
                        $screen_suggestions['suggested_defer'][] = [
                            'plugin' => $plugin,
                            'confidence' => round($confidence, 2),
                            'reason' => sprintf(
                                __('Plugin has %.1fms load (%.1f× more than average) - consider deferring loading', 'smart-admin-plugin-manager'),
                                $avg_ms,
                                round($weight_factor, 1)
                            ),
                            'savings_ms' => round($avg_ms * 0.7, 2), // Defer saves ~70% of initial load
                            'action' => 'defer',
                        ];
                    }
                }
                
                // Strategy 3: Plugin that is not contextually relevant for this screen type
                // If plugin name doesn't match screen context AND has measurable load, suggest blocking
                if ($avg_ms > 3 && !self::is_plugin_contextually_relevant($plugin, $screen)) {
                    // Skip if already in suggested_blocks
                    $already_suggested = array_filter($screen_suggestions['suggested_blocks'], fn($s) => $s['plugin'] === $plugin);
                    if (empty($already_suggested)) {
                        $confidence = min(0.80, 0.45 + ($avg_ms / 60));
                        
                        if ($confidence >= $confidence_threshold) {
                            $screen_suggestions['suggested_blocks'][] = [
                                'plugin' => $plugin,
                                'confidence' => round($confidence, 2),
                                'reason' => sprintf(
                                    __('Plugin is probably not needed on this screen (%.1fms load)', 'smart-admin-plugin-manager'),
                                    $avg_ms
                                ),
                                'savings_ms' => round($avg_ms, 2),
                                'action' => 'block',
                            ];
                        }
                    }
                }
            }
            
            // Only include screens with suggestions
            if (!empty($screen_suggestions['suggested_blocks']) || !empty($screen_suggestions['suggested_defer'])) {
                // Sort by savings
                usort($screen_suggestions['suggested_blocks'], fn($a, $b) => $b['savings_ms'] <=> $a['savings_ms']);
                usort($screen_suggestions['suggested_defer'], fn($a, $b) => $b['savings_ms'] <=> $a['savings_ms']);
                $suggestions[] = $screen_suggestions;
            }
        }
        
        // Sort screens by total potential savings
        usort($suggestions, function($a, $b) {
            $savings_a = array_sum(array_column($a['suggested_blocks'], 'savings_ms')) + 
                        array_sum(array_column($a['suggested_defer'], 'savings_ms'));
            $savings_b = array_sum(array_column($b['suggested_blocks'], 'savings_ms')) + 
                        array_sum(array_column($b['suggested_defer'], 'savings_ms'));
            return $savings_b <=> $savings_a;
        });
        
        return $suggestions;
    }

    /**
     * Check if a plugin is contextually relevant for a given screen
     * 
     * @param string $plugin_file Plugin file path
     * @param string $screen_id Screen ID (e.g., 'edit-product', 'dashboard')
     * @return bool True if plugin is likely relevant for this screen
     */
    private static function is_plugin_contextually_relevant(string $plugin_file, string $screen_id): bool {
        $plugin_slug = strtolower(dirname($plugin_file));
        if ($plugin_slug === '.') {
            $plugin_slug = strtolower(basename($plugin_file, '.php'));
        }
        
        // Define context mappings: screen patterns => relevant plugin keywords
        $context_map = [
            // WooCommerce product screens
            'product' => ['woocommerce', 'woo', 'product', 'shop', 'ecommerce', 'commerce', 'cart', 'checkout', 'payment', 'shipping', 'inventory', 'pricing', 'stock', 'packeta', 'dobirka', 'heureka', 'zbozi', 'feed'],
            'shop_order' => ['woocommerce', 'woo', 'order', 'payment', 'shipping', 'invoice', 'delivery', 'checkout', 'packeta', 'dobirka', 'dpd', 'ppl', 'zasilkovna'],
            'shop_coupon' => ['woocommerce', 'woo', 'coupon', 'discount', 'promotion', 'voucher'],
            
            // WooCommerce settings and admin screens (wc-settings, wc-admin, wc-reports, etc.)
            'wc-' => ['woocommerce', 'woo', 'shop', 'ecommerce', 'commerce', 'cart', 'checkout', 'payment', 'shipping', 'order', 'product', 'stock', 'tax', 'email', 'packeta', 'dobirka', 'heureka', 'zbozi'],
            'woocommerce' => ['woocommerce', 'woo', 'shop', 'ecommerce', 'commerce', 'payment', 'shipping', 'order'],
            
            // Content screens
            'post' => ['seo', 'yoast', 'rank-math', 'aioseo', 'editor', 'gutenberg', 'content', 'meta', 'schema', 'social', 'elementor', 'beaver', 'divi', 'bricks', 'acf', 'custom-fields'],
            'page' => ['seo', 'yoast', 'rank-math', 'aioseo', 'editor', 'gutenberg', 'content', 'meta', 'schema', 'social', 'elementor', 'beaver', 'divi', 'bricks', 'acf', 'custom-fields'],
            'edit-post' => ['seo', 'yoast', 'rank-math', 'aioseo', 'bulk', 'quick-edit'],
            'edit-page' => ['seo', 'yoast', 'rank-math', 'aioseo', 'bulk', 'quick-edit'],
            
            // Media
            'upload' => ['media', 'image', 'gallery', 'optimization', 'smush', 'imagify', 'shortpixel', 'compress', 'webp'],
            
            // Users
            'users' => ['user', 'member', 'profile', 'role', 'permission', 'login', 'security'],
            'profile' => ['user', 'profile', 'author', 'bio'],
            
            // Comments
            'edit-comments' => ['comment', 'spam', 'akismet', 'antispam', 'discussion'],
            
            // Settings
            'options-general' => ['settings', 'options', 'config'],
            'options-permalink' => ['permalink', 'url', 'seo', 'redirect'],
            
            // Dashboard - most plugins should show here
            'dashboard' => ['dashboard', 'analytics', 'stats', 'monitor', 'health', 'performance'],
            
            // Theme/Appearance
            'themes' => ['theme', 'template', 'design', 'style'],
            'widgets' => ['widget', 'sidebar'],
            'nav-menus' => ['menu', 'navigation', 'mega-menu'],
            'customize' => ['customizer', 'theme', 'design', 'style'],
            
            // Plugins management
            'plugins' => ['plugin', 'update', 'manage'],
            
            // LiteSpeed Cache - relevant screens
            'litespeed' => ['litespeed', 'cache', 'performance', 'optimization', 'cdn', 'quic'],
            
            // Elementor screens
            'elementor' => ['elementor', 'builder', 'widget', 'template', 'design'],
        ];
        
        // Find matching context for this screen
        $relevant_keywords = [];
        foreach ($context_map as $screen_pattern => $keywords) {
            if (stripos($screen_id, $screen_pattern) !== false || $screen_id === $screen_pattern) {
                $relevant_keywords = array_merge($relevant_keywords, $keywords);
            }
        }
        
        // If no context found, assume plugin might be relevant
        if (empty($relevant_keywords)) {
            return true;
        }
        
        $relevant_keywords = array_unique($relevant_keywords);
        
        // Check if plugin slug contains any relevant keyword
        foreach ($relevant_keywords as $keyword) {
            if (stripos($plugin_slug, $keyword) !== false) {
                return true;
            }
        }
        
        // Known plugins that should always be allowed on certain screen types
        $always_relevant = [
            'query-monitor' => true, // Debug plugin
            'debug-bar' => true,
            'user-switching' => true,
            'health-check' => true,
            'wp-crontrol' => true,
        ];
        
        if (isset($always_relevant[$plugin_slug])) {
            return true;
        }
        
        // Check if screen ID contains plugin slug (plugin's own pages)
        // e.g., 'toplevel_page_packeta-home' should allow 'packeta/packeta.php'
        if (stripos($screen_id, $plugin_slug) !== false) {
            return true;
        }
        
        // Also check if plugin file contains screen identifier
        // e.g., 'toplevel_page_woocommerce' should match 'woocommerce/woocommerce.php'
        $screen_words = preg_split('/[_\-\s]+/', $screen_id);
        foreach ($screen_words as $word) {
            if (strlen($word) > 3 && stripos($plugin_slug, $word) !== false) {
                return true;
            }
        }
        
        // Plugin doesn't match any relevant keyword for this screen
        return false;
    }

    /**
     * Get human-readable screen label
     */
    public static function get_screen_label(string $screen_id): string {
        $labels = [
            'dashboard' => __('Dashboard', 'smart-admin-plugin-manager'),
            'edit-post' => __('Posts (List)', 'smart-admin-plugin-manager'),
            'post' => __('Post Editor', 'smart-admin-plugin-manager'),
            'edit-page' => __('Pages (List)', 'smart-admin-plugin-manager'),
            'page' => __('Page Editor', 'smart-admin-plugin-manager'),
            'upload' => __('Media', 'smart-admin-plugin-manager'),
            'edit-comments' => __('Comments', 'smart-admin-plugin-manager'),
            'themes' => __('Appearance - Themes', 'smart-admin-plugin-manager'),
            'customize' => __('Customizer', 'smart-admin-plugin-manager'),
            'widgets' => __('Widgets', 'smart-admin-plugin-manager'),
            'nav-menus' => __('Menu', 'smart-admin-plugin-manager'),
            'plugins' => __('Plugins', 'smart-admin-plugin-manager'),
            'users' => __('Users', 'smart-admin-plugin-manager'),
            'profile' => __('Profile', 'smart-admin-plugin-manager'),
            'tools' => __('Tools', 'smart-admin-plugin-manager'),
            'options-general' => __('Settings - General', 'smart-admin-plugin-manager'),
            'options-writing' => __('Settings - Writing', 'smart-admin-plugin-manager'),
            'options-reading' => __('Settings - Reading', 'smart-admin-plugin-manager'),
            'options-discussion' => __('Settings - Discussion', 'smart-admin-plugin-manager'),
            'options-media' => __('Settings - Media', 'smart-admin-plugin-manager'),
            'options-permalink' => __('Settings - Permalinks', 'smart-admin-plugin-manager'),
        ];
        
        // Check for custom post type screens
        if (strpos($screen_id, 'edit-') === 0) {
            $post_type = substr($screen_id, 5);
            if (!isset($labels[$screen_id])) {
                return sprintf(__('List: %s', 'smart-admin-plugin-manager'), $post_type);
            }
        }
        
        return $labels[$screen_id] ?? $screen_id;
    }

    /**
     * Schedule cleanup cron
     */
    public static function schedule_cleanup(): void {
        if (!wp_next_scheduled('sapm_daily_cleanup')) {
            wp_schedule_event(time(), 'daily', 'sapm_daily_cleanup');
        }
    }

    /**
     * Unschedule cleanup cron
     */
    public static function unschedule_cleanup(): void {
        $timestamp = wp_next_scheduled('sapm_daily_cleanup');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'sapm_daily_cleanup');
        }
    }

    /**
     * Run scheduled cleanup
     */
    public static function run_scheduled_cleanup(): void {
        $deleted = self::cleanup_old_data();
        if ($deleted > 0 && defined('WP_DEBUG') && WP_DEBUG) {
            error_log("[SAPM] Cleaned up {$deleted} old sampling records");
        }
    }
}
