<?php
/**
 * SAPM Dependency Manager
 * 
 * Handles plugin dependencies detection and cascading blocks.
 * When a parent plugin (e.g., WooCommerce) is blocked, dependent plugins
 * are automatically blocked to prevent dependency warnings.
 * 
 * @package Smart_Admin_Plugin_Manager
 * @since 1.2.1
 */

if (!defined('ABSPATH')) {
    exit;
}

// DEBUG: Mark that file was loaded
if (!defined('SAPM_DEPS_LOADED')) {
    define('SAPM_DEPS_LOADED', true);
    // Immediate debug to verify file load
    error_log('[SAPM_DEPS] File loaded at ' . date('Y-m-d H:i:s'));
}

class SAPM_Dependencies {

    private static $instance = null;

    /** @var array Known plugin dependencies (plugin_slug => [parent_slugs]) */
    private $dependency_map = [];

    /** @var array Cached plugin headers */
    private $plugin_headers_cache = [];

    /** @var array Plugins blocked due to dependency cascade */
    private $cascade_blocked = [];

    /** @var bool Whether cascade blocking is enabled */
    private $cascade_enabled = true;

    /**
     * Known WooCommerce dependent plugins (slug patterns)
     * These are plugins that commonly require WooCommerce
     */
    private const KNOWN_WC_DEPENDENTS = [
        // Core WooCommerce extensions
        'woocommerce-' => true,      // Any woocommerce-* plugin
        'wc-' => true,               // Any wc-* plugin
        'yith-' => true,             // YITH plugins (most are WC)
        
        // Specific plugins (by directory name)
        'facebook-for-woocommerce' => true,
        'fibo-search' => true,
        'ajax-search-for-woocommerce' => true,
        'dpd-' => true,              // DPD shipping plugins
        'packeta' => true,           // Packeta (shipping)
        'zasilkovna' => true,
        'toret-' => true,            // Toret plugins (Czech)
        'woolab-' => true,           // WooLab plugins
        'mailpoet' => false,         // MailPoet works without WC
        'wpify-' => true,            // WPify plugins (Czech)
        'pay-for-payment' => true,
        'product-feed-' => true,     // Product feed plugins
        'google-listings-and-ads' => true,
        'klarna-' => true,
        'stripe-' => true,
        'paypal-' => true,
        'square-' => true,
        'checkout-' => true,
        'cart-' => true,
        'order-' => true,
        'shipping-' => true,
        'payment-' => true,
        'heureka-' => true,          // Heureka.cz
        'zbozi-' => true,            // Zbozi.cz
        'ceska-posta' => true,
        'balikovna' => true,
        'gls-' => true,
        'ppl-' => true,
        'ulozenka' => true,
        'compari' => true,
        'shoptet' => true,
        'eet-' => true,              // EET (electronic receipts CZ)
        'fakturoid' => true,
        'superfaktura' => true,
        'dobirka' => true,
        'cod-' => true,              // Cash on delivery
        'stock-' => true,
        'inventory-' => true,
        'product-' => true,          // Careful - many product plugins
        'variation-' => true,
        'attribute-' => true,
        'category-' => true,         // WC category plugins
        'flexible-shipping' => true,
        'table-rate-' => true,
        'flat-rate-' => true,
    ];

    /**
     * Parent plugins that trigger cascade blocking
     */
    private const PARENT_PLUGINS = [
        'woocommerce/woocommerce.php' => 'woocommerce',
        'elementor/elementor.php' => 'elementor',
        'elementor-pro/elementor-pro.php' => 'elementor-pro',
        'advanced-custom-fields/acf.php' => 'acf',
        'advanced-custom-fields-pro/acf.php' => 'acf',
    ];

    /**
     * Known dependencies for specific plugins
     * Key: plugin file, Value: array of parent plugin files
     */
    private const EXPLICIT_DEPENDENCIES = [
        // Elementor addons
        'essential-addons-for-elementor-lite/essential_adons_elementor.php' => ['elementor/elementor.php'],
        'unlimited-elements-for-elementor/unlimited_elements.php' => ['elementor/elementor.php'],
        'premium-addons-for-elementor/premium-addons-for-elementor.php' => ['elementor/elementor.php'],
        'elementor-pro/elementor-pro.php' => ['elementor/elementor.php'],
        
        // ACF addons
        'advanced-custom-fields-font-awesome/acf-font-awesome.php' => ['advanced-custom-fields/acf.php', 'advanced-custom-fields-pro/acf.php'],
        'acf-extended/acf-extended.php' => ['advanced-custom-fields/acf.php', 'advanced-custom-fields-pro/acf.php'],
    ];

    /**
     * Singleton
     */
    public static function init(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        error_log('[SAPM_DEPS] Constructor called');
        $this->cascade_enabled = apply_filters('sapm_cascade_blocking_enabled', true);
        
        // Build dependency map immediately for early access
        // (filter_plugins runs before plugins_loaded)
        if ($this->cascade_enabled) {
            $this->build_dependency_map();
            error_log('[SAPM_DEPS] Dependency map built: ' . count($this->dependency_map) . ' entries');
        }
        
        // Debug: show dependency map info if requested (via admin_init hook for reliability)
        add_action('admin_init', [$this, 'maybe_add_debug_notice']);
    }
    
    /**
     * Add debug notice hook if requested
     */
    public function maybe_add_debug_notice(): void {
        if (isset($_GET['sapm_deps_debug'])) {
            add_action('admin_notices', [$this, 'show_debug_notice']);
        }
    }
    
    /**
     * Show debug notice with dependency info
     */
    public function show_debug_notice(): void {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        echo '<div class="notice notice-info"><pre style="font-size:11px; max-height:500px; overflow:auto;">';
        echo "<strong>=== SAPM Dependencies Debug ===</strong>\n\n";
        echo "Cascade enabled: " . ($this->cascade_enabled ? 'YES' : 'NO') . "\n";
        echo "Dependency map count: " . count($this->dependency_map) . "\n\n";
        
        echo "<strong>Dependency Map:</strong>\n";
        foreach ($this->dependency_map as $plugin => $parents) {
            echo "  - $plugin => [" . implode(', ', $parents) . "]\n";
        }
        
        echo "\n<strong>Test cascade with WooCommerce blocked:</strong>\n";
        $cascade = $this->get_cascade_blocked(['woocommerce/woocommerce.php']);
        echo "  Cascade count: " . count($cascade) . "\n";
        foreach ($cascade as $p) {
            echo "  - $p\n";
        }
        
        echo '</pre></div>';
    }

    /**
     * Build dependency map from plugin headers and known patterns
     */
    public function build_dependency_map(): void {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $all_plugins = get_plugins();
        
        foreach ($all_plugins as $plugin_file => $plugin_data) {
            $dependencies = $this->detect_plugin_dependencies($plugin_file, $plugin_data);
            
            if (!empty($dependencies)) {
                $this->dependency_map[$plugin_file] = $dependencies;
            }
        }
    }

    /**
     * Detect dependencies for a specific plugin
     * 
     * @param string $plugin_file Plugin file path
     * @param array $plugin_data Plugin header data
     * @return array Array of parent plugin files
     */
    private function detect_plugin_dependencies(string $plugin_file, array $plugin_data): array {
        $dependencies = [];
        $plugin_dir = dirname($plugin_file);
        $plugin_slug = ($plugin_dir !== '.') ? $plugin_dir : pathinfo($plugin_file, PATHINFO_FILENAME);

        // 1. Check explicit dependencies first
        if (isset(self::EXPLICIT_DEPENDENCIES[$plugin_file])) {
            $dependencies = array_merge($dependencies, self::EXPLICIT_DEPENDENCIES[$plugin_file]);
        }

        // 2. Check WordPress 6.5+ Requires Plugins header
        if (!empty($plugin_data['RequiresPlugins'])) {
            $required = array_map('trim', explode(',', $plugin_data['RequiresPlugins']));
            foreach ($required as $req_slug) {
                $parent = $this->find_plugin_by_slug($req_slug);
                if ($parent) {
                    $dependencies[] = $parent;
                }
            }
        }

        // 3. Check WC requires header
        if (!empty($plugin_data['WC requires at least']) || 
            !empty($plugin_data['WC tested up to'])) {
            $dependencies[] = 'woocommerce/woocommerce.php';
        }

        // 4. Check known WooCommerce dependent patterns
        if ($this->matches_wc_dependent_pattern($plugin_slug)) {
            if (!in_array('woocommerce/woocommerce.php', $dependencies, true)) {
                $dependencies[] = 'woocommerce/woocommerce.php';
            }
        }

        // 5. Check plugin name for common patterns
        $name = strtolower($plugin_data['Name'] ?? '');
        if (strpos($name, 'for woocommerce') !== false || 
            strpos($name, 'woocommerce') !== false) {
            if (!in_array('woocommerce/woocommerce.php', $dependencies, true)) {
                $dependencies[] = 'woocommerce/woocommerce.php';
            }
        }

        if (strpos($name, 'for elementor') !== false) {
            if (!in_array('elementor/elementor.php', $dependencies, true)) {
                $dependencies[] = 'elementor/elementor.php';
            }
        }

        return array_unique($dependencies);
    }

    /**
     * Check if plugin slug matches known WC dependent patterns
     */
    private function matches_wc_dependent_pattern(string $slug): bool {
        $slug_lower = strtolower($slug);
        
        foreach (self::KNOWN_WC_DEPENDENTS as $pattern => $is_wc) {
            if (!$is_wc) {
                continue;
            }
            
            // Pattern ending with - means prefix match
            if (substr($pattern, -1) === '-') {
                if (strpos($slug_lower, rtrim($pattern, '-')) === 0 || 
                    strpos($slug_lower, rtrim($pattern, '-')) !== false) {
                    return true;
                }
            } else {
                // Exact match or contains
                if ($slug_lower === $pattern || strpos($slug_lower, $pattern) !== false) {
                    return true;
                }
            }
        }
        
        return false;
    }

    /**
     * Find plugin file by slug
     */
    private function find_plugin_by_slug(string $slug): ?string {
        $possible_files = [
            $slug . '/' . $slug . '.php',
            $slug . '/plugin.php',
            $slug . '.php',
        ];
        
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        
        $all_plugins = get_plugins();
        
        foreach ($possible_files as $file) {
            if (isset($all_plugins[$file])) {
                return $file;
            }
        }
        
        // Search by directory
        foreach ($all_plugins as $plugin_file => $data) {
            if (dirname($plugin_file) === $slug) {
                return $plugin_file;
            }
        }
        
        return null;
    }

    /**
     * Get plugins that should be cascade-blocked based on blocked parents
     * 
     * @param array $blocked_plugins Array of plugin files that are blocked
     * @return array Array of additional plugins to block
     */
    public function get_cascade_blocked(array $blocked_plugins): array {
        if (!$this->cascade_enabled || empty($blocked_plugins)) {
            return [];
        }

        // Build map if not built yet (safety net)
        if (empty($this->dependency_map)) {
            $this->build_dependency_map();
        }

        if (empty($this->dependency_map)) {
            return [];
        }

        $cascade_blocked = [];
        
        foreach ($this->dependency_map as $child_plugin => $parents) {
            // Check if any parent is blocked
            foreach ($parents as $parent) {
                if (in_array($parent, $blocked_plugins, true)) {
                    $cascade_blocked[$child_plugin] = $parent;
                    break;
                }
            }
        }
        
        $this->cascade_blocked = $cascade_blocked;
        
        return array_keys($cascade_blocked);
    }

    /**
     * Check if a specific plugin should be cascade-blocked
     * 
     * @param string $plugin_file Plugin file to check
     * @param array $blocked_plugins Currently blocked plugins
     * @return bool|string False if not blocked, parent plugin file if blocked
     */
    public function is_cascade_blocked(string $plugin_file, array $blocked_plugins) {
        if (!$this->cascade_enabled || empty($blocked_plugins)) {
            return false;
        }

        // Build map if not built yet
        if (empty($this->dependency_map)) {
            $this->build_dependency_map();
        }

        if (!isset($this->dependency_map[$plugin_file])) {
            return false;
        }

        foreach ($this->dependency_map[$plugin_file] as $parent) {
            if (in_array($parent, $blocked_plugins, true)) {
                return $parent;
            }
        }

        return false;
    }

    /**
     * Get dependency map for debugging/admin display
     */
    public function get_dependency_map(): array {
        if (empty($this->dependency_map)) {
            $this->build_dependency_map();
        }
        return $this->dependency_map;
    }

    /**
     * Get cascade-blocked plugins for the current request
     */
    public function get_current_cascade_blocked(): array {
        return $this->cascade_blocked;
    }

    /**
     * Check if cascade blocking is enabled
     */
    public function is_cascade_enabled(): bool {
        return $this->cascade_enabled;
    }

    /**
     * Get parent plugins list
     */
    public static function get_parent_plugins(): array {
        $parents = self::PARENT_PLUGINS;
        /**
         * Filter protected parent plugins that should not be auto-blocked.
         *
         * @param array $parents Map of plugin file => slug.
         */
        return apply_filters('sapm_protected_parent_plugins', $parents);
    }

    /**
     * Check if plugin is a protected parent (e.g., WooCommerce)
     */
    public static function is_parent_plugin(string $plugin_file): bool {
        $parents = self::get_parent_plugins();
        return isset($parents[$plugin_file]);
    }

    /**
     * Get human-readable name for parent plugin
     */
    public static function get_parent_name(string $plugin_file): string {
        $names = [
            'woocommerce/woocommerce.php' => 'WooCommerce',
            'elementor/elementor.php' => 'Elementor',
            'elementor-pro/elementor-pro.php' => 'Elementor Pro',
            'advanced-custom-fields/acf.php' => 'ACF',
            'advanced-custom-fields-pro/acf.php' => 'ACF PRO',
        ];
        
        return $names[$plugin_file] ?? basename(dirname($plugin_file));
    }
}
