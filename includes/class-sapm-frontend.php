<?php
/**
 * SAPM Frontend Optimizer
 * 
 * Manages selective plugin loading and asset optimization on the frontend.
 * Works in parallel with admin screen management but for public-facing pages.
 * 
 * Architecture:
 * - Layer 1: Plugin filtering per frontend context (homepage, single, archive, WC pages...)
 * - Layer 2: Asset manager (CSS/JS dequeue per context)
 * - Layer 3: Frontend performance sampling for auto-suggestions
 * 
 * Safety:
 * - Admin bypass (logged-in admins see full site by default)
 * - Safe mode URL parameter for emergency recovery
 * - WooCommerce critical pages protection
 * - Gradual rollout (passthrough default)
 * 
 * @package SmartAdminPluginManager
 * @since 1.3.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class SAPM_Frontend {

    private static $instance = null;

    /** @var SAPM_Core Reference to core instance */
    private $core;

    /** @var array Frontend rules configuration */
    private $rules = [];

    /** @var array Asset rules (CSS/JS dequeue) */
    private $asset_rules = [];

    /** @var string Detected frontend context */
    private $current_context = '';

    /** @var bool Whether context was confirmed using WP conditionals (after 'wp' action) */
    private $context_confirmed = false;

    /** @var array Plugins disabled on this frontend request */
    private $disabled_plugins = [];

    /** @var array Assets dequeued on this frontend request */
    private $dequeued_assets = ['scripts' => [], 'styles' => []];

    /** @var bool Whether safe mode is active */
    private $safe_mode = false;

    /** @var bool Whether admin bypass is active */
    private $admin_bypass = false;

    /** @var bool Performance sampling enabled for this request */
    private $sampling_enabled = false;

    /** @var float Sampling rate for frontend (lower than admin) */
    private const FRONTEND_SAMPLING_RATE = 0.05; // 5%

    /** @var string Option key for frontend rules */
    private const RULES_OPTION_KEY = 'sapm_frontend_rules';

    /** @var string Option key for asset rules */
    private const ASSET_RULES_OPTION_KEY = 'sapm_frontend_asset_rules';

    /** @var string Option key for safe mode secret */
    private const SAFE_MODE_KEY_OPTION = 'sapm_frontend_safe_key';

    /** @var string Transient key prefix for one-time safe mode tokens */
    private const SAFE_MODE_TOKEN_PREFIX = 'sapm_frontend_safe_token_';

    /** @var int Safe mode activation token lifetime (seconds) */
    private const SAFE_MODE_TOKEN_TTL = 300;

    /** @var string Cookie name for active safe mode session */
    private const SAFE_MODE_COOKIE = 'sapm_safe_mode';

    /** @var string Option key for frontend settings */
    private const SETTINGS_OPTION_KEY = 'sapm_frontend_settings';

    /** @var string Meta key for per-post/per-term overrides */
    private const OVERRIDE_META_KEY = '_sapm_frontend_overrides';

    /** @var string|null Override type for current request: 'post', 'term', or null */
    private $override_type = null;

    /** @var int|null Override object ID for current request */
    private $override_id = null;

    /** @var array|null Cached per-object overrides for current request */
    private $current_overrides = null;

    // ========================================
    // Frontend Context Definitions
    // ========================================

    /**
     * All available frontend contexts with detection logic.
     * Each context has: label, group, priority (higher = checked first),
     * WP conditional function, and optional sub-matcher.
     */
    private $context_definitions = [];

    /**
     * Known WooCommerce-critical contexts where WC must never be blocked.
     */
    private const WC_CRITICAL_CONTEXTS = [
        'wc_shop',
        'wc_product',
        'wc_cart',
        'wc_checkout',
        'wc_account',
        'wc_endpoint',
    ];

    /**
     * Plugins that should NEVER be blocked on frontend for safety.
     * (Core functionality plugins)
     */
    private const NEVER_BLOCK_FRONTEND = [
        // This plugin itself
        'smart-admin-plugin-manager/smart-admin-plugin-manager.php',
    ];

    // ========================================
    // Initialization
    // ========================================

    /**
     * Initialize the frontend optimizer
     */
    public static function init(SAPM_Core $core): self {
        if (self::$instance === null) {
            self::$instance = new self($core);
        }
        return self::$instance;
    }

    private function __construct(SAPM_Core $core) {
        $this->core = $core;
        $this->define_contexts();
        $this->load_rules();
        $this->load_asset_rules();
        $this->check_safe_mode();
        $this->check_admin_bypass();

        // Frontend info bar for admins (register even in safe mode / bypass)
        // Use late priority so drawer CSS is printed after theme styles (prevents unwanted overrides).
        add_action('wp_enqueue_scripts', [$this, 'maybe_enqueue_frontend_bar'], 9999);
        add_action('wp_footer', [$this, 'maybe_render_frontend_bar'], 100);

        // Admin toolbar toggle — places SAPM into the WP black admin bar on frontend pages.
        add_action('admin_bar_menu', [$this, 'frontend_admin_bar_toggle'], 999);

        // AJAX handler for frontend rule toggling
        add_action('wp_ajax_sapm_frontend_toggle_rule', [$this, 'ajax_frontend_toggle_rule']);

        // AJAX handler for resetting per-object overrides
        add_action('wp_ajax_sapm_frontend_reset_overrides', [$this, 'ajax_frontend_reset_overrides']);

        // If safe mode or admin bypass, skip all filtering
        if ($this->safe_mode || $this->admin_bypass) {
            return;
        }

        // Asset optimization hooks (CSS/JS dequeue)
        add_action('wp_enqueue_scripts', [$this, 'optimize_assets'], 999);

        // Script attributes (defer/async)
        add_filter('script_loader_tag', [$this, 'add_script_attributes'], 10, 3);

        // Frontend performance sampling
        $this->maybe_enable_frontend_sampling();

        // Asset audit hook (for discovering what plugins enqueue)
        if ($this->is_asset_audit_enabled()) {
            add_action('wp_enqueue_scripts', [$this, 'audit_enqueued_assets'], 9999);
        }
    }

    // ========================================
    // Context Definitions
    // ========================================

    /**
     * Define all frontend contexts with detection logic.
     * Ordered by priority (higher = more specific, checked first).
     */
    private function define_contexts(): void {
        $this->context_definitions = [
            // === WooCommerce Pages (highest priority) ===
            'wc_checkout' => [
                'label' => __('WooCommerce – Checkout', 'sapm'),
                'group' => 'woocommerce',
                'priority' => 100,
                'detector' => function (): bool {
                    return function_exists('is_checkout') && is_checkout() && !is_wc_endpoint_url();
                },
            ],
            'wc_cart' => [
                'label' => __('WooCommerce – Cart', 'sapm'),
                'group' => 'woocommerce',
                'priority' => 99,
                'detector' => function (): bool {
                    return function_exists('is_cart') && is_cart();
                },
            ],
            'wc_account' => [
                'label' => __('WooCommerce – My Account', 'sapm'),
                'group' => 'woocommerce',
                'priority' => 98,
                'detector' => function (): bool {
                    return function_exists('is_account_page') && is_account_page();
                },
            ],
            'wc_product' => [
                'label' => __('WooCommerce – Product', 'sapm'),
                'group' => 'woocommerce',
                'priority' => 97,
                'detector' => function (): bool {
                    return function_exists('is_product') && is_product();
                },
            ],
            'wc_shop' => [
                'label' => __('WooCommerce – Shop / Category', 'sapm'),
                'group' => 'woocommerce',
                'priority' => 96,
                'detector' => function (): bool {
                    return function_exists('is_shop') && (is_shop() || is_product_category() || is_product_tag());
                },
            ],
            'wc_endpoint' => [
                'label' => __('WooCommerce – Endpoint', 'sapm'),
                'group' => 'woocommerce',
                'priority' => 95,
                'detector' => function (): bool {
                    return function_exists('is_wc_endpoint_url') && is_wc_endpoint_url();
                },
            ],

            // === Core WordPress Pages ===
            'homepage' => [
                'label' => __('Homepage', 'sapm'),
                'group' => 'core',
                'priority' => 90,
                'detector' => function (): bool {
                    return is_front_page() || is_home();
                },
            ],
            'search' => [
                'label' => __('Search', 'sapm'),
                'group' => 'core',
                'priority' => 85,
                'detector' => function (): bool {
                    return is_search();
                },
            ],
            'error_404' => [
                'label' => __('404 Page', 'sapm'),
                'group' => 'core',
                'priority' => 84,
                'detector' => function (): bool {
                    return is_404();
                },
            ],

            // === Content Pages ===
            'single_post' => [
                'label' => __('Post (single)', 'sapm'),
                'group' => 'content',
                'priority' => 70,
                'detector' => function (): bool {
                    return is_singular('post');
                },
            ],
            'single_page' => [
                'label' => __('Page (single)', 'sapm'),
                'group' => 'content',
                'priority' => 69,
                'detector' => function (): bool {
                    return is_singular('page') && !is_front_page();
                },
            ],
            'single_cpt' => [
                'label' => __('Custom Post Type (single)', 'sapm'),
                'group' => 'content',
                'priority' => 68,
                'detector' => function (): bool {
                    return is_singular() && !is_singular(['post', 'page', 'product', 'attachment']);
                },
            ],
            'attachment' => [
                'label' => __('Attachment', 'sapm'),
                'group' => 'content',
                'priority' => 67,
                'detector' => function (): bool {
                    return is_attachment();
                },
            ],

            // === Archives ===
            'archive_category' => [
                'label' => __('Archive – Category', 'sapm'),
                'group' => 'archive',
                'priority' => 60,
                'detector' => function (): bool {
                    return is_category();
                },
            ],
            'archive_tag' => [
                'label' => __('Archive – Tag', 'sapm'),
                'group' => 'archive',
                'priority' => 59,
                'detector' => function (): bool {
                    return is_tag();
                },
            ],
            'archive_author' => [
                'label' => __('Archive – Author', 'sapm'),
                'group' => 'archive',
                'priority' => 58,
                'detector' => function (): bool {
                    return is_author();
                },
            ],
            'archive_date' => [
                'label' => __('Archive – Date', 'sapm'),
                'group' => 'archive',
                'priority' => 57,
                'detector' => function (): bool {
                    return is_date();
                },
            ],
            'archive_cpt' => [
                'label' => __('Archive – Custom Type', 'sapm'),
                'group' => 'archive',
                'priority' => 56,
                'detector' => function (): bool {
                    return is_post_type_archive() && !function_exists('is_shop');
                },
            ],
            'archive_taxonomy' => [
                'label' => __('Archive – Taxonomy', 'sapm'),
                'group' => 'archive',
                'priority' => 55,
                'detector' => function (): bool {
                    return is_tax();
                },
            ],

            // === Feeds & Special ===
            'feed' => [
                'label' => __('RSS Feed', 'sapm'),
                'group' => 'special',
                'priority' => 40,
                'detector' => function (): bool {
                    return is_feed();
                },
            ],

            // === Fallback ===
            'other' => [
                'label' => __('Other Pages', 'sapm'),
                'group' => 'other',
                'priority' => 0,
                'detector' => function (): bool {
                    return true; // Always matches as fallback
                },
            ],
        ];

        /**
         * Filter to add custom frontend contexts.
         * 
         * @param array $context_definitions Current definitions
         * @return array Modified definitions
         */
        $this->context_definitions = apply_filters('sapm_frontend_contexts', $this->context_definitions);
    }

    // ========================================
    // Context Detection
    // ========================================

    /**
     * Detect current frontend context.
     * When called before the 'wp' action, uses URL-based early detection
     * (since WP conditionals like is_front_page() are not available yet).
     * After 'wp' fires, uses proper WP conditionals and caches the result.
     * 
     * @return string Context ID
     */
    public function detect_context(): string {
        $wp_ready = did_action('wp') > 0;

        // If we already have a confirmed (post-wp) context, return it.
        if ($this->current_context !== '' && $this->context_confirmed) {
            return $this->current_context;
        }

        // If WP query is not parsed yet, use URL-based early detection.
        // Do NOT cache as confirmed — allow re-detection after 'wp'.
        if (!$wp_ready) {
            $early = $this->detect_context_early();
            // Store as unconfirmed so it will be re-detected later.
            $this->current_context = $early ?? 'other';
            $this->context_confirmed = false;
            return $this->current_context;
        }

        // WP is ready — use conditional-based detection and cache.
        // Sort by priority (highest first)
        $sorted = $this->context_definitions;
        uasort($sorted, function ($a, $b) {
            return ($b['priority'] ?? 0) - ($a['priority'] ?? 0);
        });

        foreach ($sorted as $context_id => $def) {
            if (is_callable($def['detector']) && call_user_func($def['detector'])) {
                $this->current_context = $context_id;
                $this->context_confirmed = true;

                /**
                 * Filter detected frontend context.
                 * Allows themes/plugins to override context detection.
                 * 
                 * @param string $context_id Detected context
                 * @param array $def Context definition
                 */
                $this->current_context = apply_filters('sapm_frontend_detected_context', $this->current_context, $def);

                return $this->current_context;
            }
        }

        $this->current_context = 'other';
        $this->context_confirmed = true;
        return $this->current_context;
    }

    /**
     * Early context detection from URL (before WP query).
     * Used by MU loader for plugin filtering before WordPress is fully loaded.
     * Less accurate but works at plugin loading time.
     * 
     * @return string|null Detected context or null if undetermined
     */
    public function detect_context_early(): ?string {
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        $path = parse_url($uri, PHP_URL_PATH);

        if (!$path || $path === '/') {
            return 'homepage';
        }

        // Normalize path
        $path = rtrim($path, '/');

        // RSS feed detection
        if (preg_match('#/feed/?$#', $path)) {
            return 'feed';
        }

        // WooCommerce page detection via option slugs
        if (function_exists('wc_get_page_id')) {
            $wc_pages = [
                'wc_cart' => wc_get_page_id('cart'),
                'wc_checkout' => wc_get_page_id('checkout'),
                'wc_account' => wc_get_page_id('myaccount'),
                'wc_shop' => wc_get_page_id('shop'),
            ];

            foreach ($wc_pages as $ctx => $page_id) {
                if ($page_id > 0) {
                    $page_slug = get_post_field('post_name', $page_id);
                    if ($page_slug && strpos($path, '/' . $page_slug) !== false) {
                        return $ctx;
                    }
                }
            }
        }

        // URL pattern-based rules
        $url_patterns = $this->get_url_pattern_rules();
        foreach ($url_patterns as $pattern => $context) {
            if (preg_match($pattern, $path)) {
                return $context;
            }
        }

        return null;
    }

    /**
     * Get URL pattern rules from settings.
     * 
     * @return array [regex_pattern => context_id]
     */
    private function get_url_pattern_rules(): array {
        $settings = get_option(self::SETTINGS_OPTION_KEY, []);
        return $settings['url_patterns'] ?? [];
    }

    // ========================================
    // Per-Post/Per-Term Override System
    // ========================================

    /**
     * Detect override type and ID for the current request.
     * Must be called after WP query is parsed (after 'wp' action).
     */
    private function detect_override_target(): void {
        if ($this->override_type !== null) {
            return; // Already detected
        }

        if (!did_action('wp')) {
            return; // Too early — WP query not parsed yet
        }

        $obj = get_queried_object();
        if (!$obj) {
            return;
        }

        // Singular pages: post, page, product, CPT
        if (is_singular() && isset($obj->ID) && $obj->ID > 0) {
            $this->override_type = 'post';
            $this->override_id = (int) $obj->ID;
            return;
        }

        // Term archives: category, tag, custom taxonomy
        if ((is_category() || is_tag() || is_tax()) && isset($obj->term_id) && $obj->term_id > 0) {
            $this->override_type = 'term';
            $this->override_id = (int) $obj->term_id;
            return;
        }
    }

    /**
     * Get per-object overrides for the current request.
     *
     * @return array|null Override array with 'disabled_plugins' and/or 'enabled_plugins', or null
     */
    private function get_current_overrides(): ?array {
        if ($this->current_overrides !== null) {
            return $this->current_overrides ?: null;
        }

        $this->detect_override_target();

        // Don't cache empty result if we couldn't detect target yet (too early in lifecycle)
        if ($this->override_type === null || $this->override_id === null) {
            if (!did_action('wp')) {
                return null; // Don't cache — will retry after WP query is parsed
            }
            $this->current_overrides = [];
            return null;
        }

        $overrides = $this->get_overrides_for($this->override_type, $this->override_id);
        $this->current_overrides = is_array($overrides) && !empty($overrides) ? $overrides : [];
        return $this->current_overrides ?: null;
    }

    /**
     * Read overrides for a specific post or term.
     *
     * @param string $type 'post' or 'term'
     * @param int    $id   Post ID or term ID
     * @return array|null
     */
    private function get_overrides_for(string $type, int $id): ?array {
        if ($type === 'post') {
            $meta = get_post_meta($id, self::OVERRIDE_META_KEY, true);
        } elseif ($type === 'term') {
            $meta = get_term_meta($id, self::OVERRIDE_META_KEY, true);
        } else {
            return null;
        }

        return is_array($meta) && !empty($meta) ? $meta : null;
    }

    /**
     * Save overrides for a specific post or term.
     *
     * @param string $type 'post' or 'term'
     * @param int    $id   Post ID or term ID
     * @param array  $overrides Override data
     * @return bool
     */
    private function save_overrides_for(string $type, int $id, array $overrides): bool {
        // Clean up empty override data
        $has_data = !empty($overrides['disabled_plugins']) || !empty($overrides['enabled_plugins']);

        if ($type === 'post') {
            if ($has_data) {
                return (bool) update_post_meta($id, self::OVERRIDE_META_KEY, $overrides);
            } else {
                return (bool) delete_post_meta($id, self::OVERRIDE_META_KEY);
            }
        } elseif ($type === 'term') {
            if ($has_data) {
                return (bool) update_term_meta($id, self::OVERRIDE_META_KEY, $overrides);
            } else {
                return (bool) delete_term_meta($id, self::OVERRIDE_META_KEY);
            }
        }

        return false;
    }

    /**
     * Apply per-object overrides to the context-resolved disabled/enabled lists.
     * Override logic: per-object rules take precedence over context rules.
     *
     * @param array  $context_disabled Plugins disabled by context rules
     * @param array  $context_enabled  Plugins enabled by context rules
     * @param string $mode             Current filtering mode (blacklist/whitelist)
     * @return array ['disabled' => [...], 'enabled' => [...]]
     */
    private function apply_overrides(array $context_disabled, array $context_enabled, string $mode): array {
        $overrides = $this->get_current_overrides();
        if ($overrides === null) {
            return ['disabled' => $context_disabled, 'enabled' => $context_enabled];
        }

        $override_disabled = $overrides['disabled_plugins'] ?? [];
        $override_enabled = $overrides['enabled_plugins'] ?? [];

        // Per-object disabled: add to disabled, remove from enabled
        foreach ($override_disabled as $plugin) {
            if (!in_array($plugin, $context_disabled, true)) {
                $context_disabled[] = $plugin;
            }
            $context_enabled = array_values(array_diff($context_enabled, [$plugin]));
        }

        // Per-object enabled: remove from disabled, add to enabled
        foreach ($override_enabled as $plugin) {
            $context_disabled = array_values(array_diff($context_disabled, [$plugin]));
            if (!in_array($plugin, $context_enabled, true)) {
                $context_enabled[] = $plugin;
            }
        }

        return ['disabled' => $context_disabled, 'enabled' => $context_enabled];
    }

    /**
     * Get human-readable label for the override target.
     *
     * @return string|null
     */
    private function get_override_label(): ?string {
        if ($this->override_type === 'post' && $this->override_id) {
            $post = get_post($this->override_id);
            if ($post) {
                $type_obj = get_post_type_object($post->post_type);
                $type_label = $type_obj ? $type_obj->labels->singular_name : $post->post_type;
                return sprintf('%s: %s', $type_label, wp_trim_words($post->post_title, 5, '…'));
            }
        }

        if ($this->override_type === 'term' && $this->override_id) {
            $term = get_term($this->override_id);
            if ($term && !is_wp_error($term)) {
                $tax_obj = get_taxonomy($term->taxonomy);
                $tax_label = $tax_obj ? $tax_obj->labels->singular_name : $term->taxonomy;
                return sprintf('%s: %s', $tax_label, $term->name);
            }
        }

        return null;
    }

    // ========================================
    // Plugin Filtering (Layer 1)
    // ========================================

    /**
     * Get plugins to disable for the current frontend context.
     * Called by SAPM_Core::filter_non_admin_plugins() when request_type = 'frontend'.
     * 
     * Includes per-post/per-term override logic:
     * 1. Resolve context-level rules (global + context blacklist/whitelist)
     * 2. Apply per-object overrides (post meta or term meta)
     * 3. Cascade blocking via dependencies
     * 
     * @param array $plugins Active plugins list
     * @return array Filtered plugins list
     */
    public function filter_frontend_plugins(array $plugins): array {
        // Safe mode = no filtering
        if ($this->safe_mode || $this->admin_bypass) {
            return $plugins;
        }

        $settings = $this->get_settings();

        // Check if frontend filtering is enabled globally
        if (empty($settings['enabled'])) {
            return $plugins;
        }

        // Detect context
        $context = $this->detect_context();

        // Get rules for this context
        $context_rules = $this->rules[$context] ?? [];
        $global_rules = $this->rules['_global'] ?? [];
        $mode = $context_rules['_mode'] ?? $global_rules['_mode'] ?? 'passthrough';

        if ($mode === 'passthrough') {
            return $plugins;
        }

        // Build context-level disabled/enabled lists
        $context_disabled = array_unique(array_merge(
            $global_rules['disabled_plugins'] ?? [],
            $context_rules['disabled_plugins'] ?? []
        ));
        $context_enabled = array_unique(array_merge(
            $global_rules['enabled_plugins'] ?? [],
            $context_rules['enabled_plugins'] ?? []
        ));

        // Apply per-object overrides (post/term meta)
        $resolved = $this->apply_overrides($context_disabled, $context_enabled, $mode);
        $effective_disabled = $resolved['disabled'];
        $effective_enabled = $resolved['enabled'];

        $filtered = [];

        if ($mode === 'blacklist') {
            foreach ($plugins as $plugin) {
                if ($this->is_protected_plugin($plugin, $context)) {
                    $filtered[] = $plugin;
                    continue;
                }

                if (in_array($plugin, $effective_disabled, true)) {
                    $this->disabled_plugins[$plugin] = 'context:' . $context;
                    continue;
                }
                $filtered[] = $plugin;
            }
        } elseif ($mode === 'whitelist') {
            if (in_array('*', $effective_enabled, true)) {
                return $plugins;
            }

            foreach ($plugins as $plugin) {
                if ($this->is_protected_plugin($plugin, $context)) {
                    $filtered[] = $plugin;
                    continue;
                }

                if (in_array($plugin, $effective_enabled, true)) {
                    $filtered[] = $plugin;
                } else {
                    $this->disabled_plugins[$plugin] = 'context:' . $context;
                }
            }
        }

        // Cascade blocking via dependencies
        if (!empty($this->disabled_plugins) && class_exists('SAPM_Dependencies')) {
            $deps = SAPM_Dependencies::init();
            $blocked_list = array_keys($this->disabled_plugins);
            $cascade = $deps->get_cascade_blocked($blocked_list);

            if (!empty($cascade)) {
                $new_filtered = [];
                foreach ($filtered as $plugin) {
                    if (in_array($plugin, $cascade, true)) {
                        $this->disabled_plugins[$plugin] = 'cascade:dependency';
                    } else {
                        $new_filtered[] = $plugin;
                    }
                }
                $filtered = $new_filtered;
            }
        }

        /**
         * Filter the final list of frontend-filtered plugins.
         * 
         * @param array $filtered Filtered plugins
         * @param array $plugins Original plugins
         * @param string $context Current frontend context
         * @param array $disabled_plugins Plugins that were disabled
         */
        $filtered = apply_filters('sapm_frontend_filtered_plugins', $filtered, $plugins, $context, $this->disabled_plugins);

        return $filtered;
    }

    /**
     * Check if a plugin is protected from blocking in the given context.
     * 
     * @param string $plugin Plugin file path
     * @param string $context Frontend context
     * @return bool
     */
    private function is_protected_plugin(string $plugin, string $context): bool {
        // Never block this plugin itself
        if (in_array($plugin, self::NEVER_BLOCK_FRONTEND, true)) {
            return true;
        }

        // WooCommerce critical pages protection
        if (in_array($context, self::WC_CRITICAL_CONTEXTS, true)) {
            if ($this->is_woocommerce_plugin($plugin)) {
                return true;
            }
        }

        /**
         * Filter whether a plugin is protected from frontend blocking.
         * 
         * @param bool $protected Whether the plugin is protected
         * @param string $plugin Plugin file path
         * @param string $context Frontend context
         */
        return apply_filters('sapm_frontend_plugin_protected', false, $plugin, $context);
    }

    /**
     * Check if a plugin is a WooCommerce core or extension.
     */
    private function is_woocommerce_plugin(string $plugin): bool {
        $wc_patterns = [
            'woocommerce/',
            'woocommerce-',
            'wc-',
        ];

        $plugin_dir = dirname($plugin);
        foreach ($wc_patterns as $pattern) {
            if (strpos($plugin_dir, $pattern) === 0 || $plugin_dir === 'woocommerce') {
                return true;
            }
        }

        return false;
    }

    // ========================================
    // Asset Manager (Layer 2)
    // ========================================

    /**
     * Optimize CSS/JS assets based on context rules.
     * Hooked to wp_enqueue_scripts with high priority (runs after plugins enqueue).
     */
    public function optimize_assets(): void {
        $context = $this->detect_context();
        $context_asset_rules = $this->asset_rules[$context] ?? [];
        $global_asset_rules = $this->asset_rules['_global'] ?? [];

        // Dequeue scripts
        $scripts_to_dequeue = array_unique(array_merge(
            $global_asset_rules['dequeue_scripts'] ?? [],
            $context_asset_rules['dequeue_scripts'] ?? []
        ));

        foreach ($scripts_to_dequeue as $handle) {
            if (wp_script_is($handle, 'enqueued') || wp_script_is($handle, 'registered')) {
                wp_dequeue_script($handle);
                wp_deregister_script($handle);
                $this->dequeued_assets['scripts'][] = $handle;
            }
        }

        // Dequeue styles
        $styles_to_dequeue = array_unique(array_merge(
            $global_asset_rules['dequeue_styles'] ?? [],
            $context_asset_rules['dequeue_styles'] ?? []
        ));

        foreach ($styles_to_dequeue as $handle) {
            if (wp_style_is($handle, 'enqueued') || wp_style_is($handle, 'registered')) {
                wp_dequeue_style($handle);
                wp_deregister_style($handle);
                $this->dequeued_assets['styles'][] = $handle;
            }
        }

        /**
         * Action after assets are optimized.
         * 
         * @param string $context Current frontend context
         * @param array $dequeued_assets Assets that were dequeued
         */
        do_action('sapm_frontend_assets_optimized', $context, $this->dequeued_assets);
    }

    /**
     * Add defer/async attributes to scripts.
     * 
     * @param string $tag Script HTML tag
     * @param string $handle Script handle
     * @param string $src Script source URL
     * @return string Modified tag
     */
    public function add_script_attributes(string $tag, string $handle, string $src): string {
        $context = $this->detect_context();
        $context_asset_rules = $this->asset_rules[$context] ?? [];
        $global_asset_rules = $this->asset_rules['_global'] ?? [];

        // Defer scripts
        $defer_scripts = array_unique(array_merge(
            $global_asset_rules['defer_scripts'] ?? [],
            $context_asset_rules['defer_scripts'] ?? []
        ));

        if (in_array($handle, $defer_scripts, true) || in_array('*', $defer_scripts, true)) {
            // Don't defer already deferred or inline scripts
            if (strpos($tag, 'defer') === false && strpos($tag, 'async') === false) {
                $tag = str_replace(' src=', ' defer src=', $tag);
            }
        }

        // Async scripts
        $async_scripts = array_unique(array_merge(
            $global_asset_rules['async_scripts'] ?? [],
            $context_asset_rules['async_scripts'] ?? []
        ));

        if (in_array($handle, $async_scripts, true)) {
            if (strpos($tag, 'async') === false && strpos($tag, 'defer') === false) {
                $tag = str_replace(' src=', ' async src=', $tag);
            }
        }

        return $tag;
    }

    // ========================================
    // Asset Audit (Discovery)
    // ========================================

    /**
     * Audit all enqueued assets for the current page.
     * Stores discovered handles for admin UI to display.
     */
    public function audit_enqueued_assets(): void {
        global $wp_scripts, $wp_styles;

        $context = $this->detect_context();
        $audit_data = get_option('sapm_frontend_asset_audit', []);

        // Collect scripts
        $scripts = [];
        if ($wp_scripts instanceof WP_Scripts) {
            foreach ($wp_scripts->queue as $handle) {
                $dep = $wp_scripts->registered[$handle] ?? null;
                if ($dep) {
                    $scripts[$handle] = [
                        'src' => $dep->src ?: '(inline)',
                        'deps' => $dep->deps,
                        'ver' => $dep->ver,
                        'plugin' => $this->detect_asset_plugin($dep->src),
                    ];
                }
            }
        }

        // Collect styles
        $styles = [];
        if ($wp_styles instanceof WP_Styles) {
            foreach ($wp_styles->queue as $handle) {
                $dep = $wp_styles->registered[$handle] ?? null;
                if ($dep) {
                    $styles[$handle] = [
                        'src' => $dep->src ?: '(inline)',
                        'deps' => $dep->deps,
                        'ver' => $dep->ver,
                        'plugin' => $this->detect_asset_plugin($dep->src),
                    ];
                }
            }
        }

        $audit_data[$context] = [
            'scripts' => $scripts,
            'styles' => $styles,
            'url' => $_SERVER['REQUEST_URI'] ?? '',
            'timestamp' => time(),
        ];

        // Keep only last 50 contexts
        $audit_data = array_slice($audit_data, -50, 50, true);

        update_option('sapm_frontend_asset_audit', $audit_data, false);
    }

    /**
     * Detect which plugin an asset belongs to by examining its source URL.
     * 
     * @param string $src Asset source URL
     * @return string|null Plugin slug or null
     */
    private function detect_asset_plugin(string $src): ?string {
        if (!$src || $src === '(inline)') {
            return null;
        }

        // Check if it's from wp-content/plugins/
        $plugins_url = plugins_url();
        if (strpos($src, $plugins_url) === false && strpos($src, '/wp-content/plugins/') === false) {
            // Might be a theme or WP core asset
            if (strpos($src, '/wp-content/themes/') !== false) {
                return '_theme';
            }
            if (strpos($src, '/wp-includes/') !== false || strpos($src, '/wp-admin/') !== false) {
                return '_core';
            }
            return null;
        }

        // Extract plugin directory from URL
        $pattern = '#/wp-content/plugins/([^/]+)/#';
        if (preg_match($pattern, $src, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Check if asset audit is enabled.
     */
    private function is_asset_audit_enabled(): bool {
        $settings = $this->get_settings();
        return !empty($settings['asset_audit']);
    }

    // ========================================
    // Frontend Performance Sampling (Layer 3)
    // ========================================

    /**
     * Enable performance sampling for this frontend request.
     */
    private function maybe_enable_frontend_sampling(): void {
        $settings = $this->get_settings();
        if (empty($settings['sampling_enabled'])) {
            return;
        }

        $rate = apply_filters('sapm_frontend_sampling_rate', self::FRONTEND_SAMPLING_RATE);

        // Random sampling decision
        if (mt_rand(1, 10000) / 10000 > $rate) {
            return;
        }

        $this->sampling_enabled = true;

        // Store timing data at shutdown
        add_action('shutdown', [$this, 'store_frontend_sample'], 1);
    }

    /**
     * Store frontend performance sample.
     */
    public function store_frontend_sample(): void {
        if (!$this->sampling_enabled || !class_exists('SAPM_Database')) {
            return;
        }

        $context = $this->detect_context();
        $total_time = microtime(true) - (defined('WP_START_TIMESTAMP') ? WP_START_TIMESTAMP : $_SERVER['REQUEST_TIME_FLOAT']);

        // Store overall frontend performance
        SAPM_Database::store_sample([
            'request_type' => 'frontend',
            'trigger_name' => 'context:' . $context,
            'plugin_file' => '_total',
            'load_time_ms' => round($total_time * 1000, 3),
            'query_count' => get_num_queries(),
        ]);

        // If we have disabled plugins info, store that
        foreach ($this->disabled_plugins as $plugin => $reason) {
            SAPM_Database::store_sample([
                'request_type' => 'frontend',
                'trigger_name' => 'context:' . $context,
                'plugin_file' => $plugin,
                'load_time_ms' => 0, // Not loaded
                'query_count' => 0,
            ]);
        }
    }

    // ========================================
    // Safety Mechanisms
    // ========================================

    /**
     * Check if safe mode is activated via one-time URL token.
     * Usage: ?sapm_safe=1&sapm_token=<one_time_token>
     */
    private function check_safe_mode(): void {
        // Persisted safe-mode session.
        if (!empty($_COOKIE[self::SAFE_MODE_COOKIE])) {
            $this->safe_mode = true;
        }

        if (empty($_GET['sapm_safe'])) {
            return;
        }

        $token = sanitize_text_field($_GET['sapm_token'] ?? '');

        if ($token !== '' && $this->consume_safe_mode_token($token)) {
            $this->safe_mode = true;

            // Persist safe mode for current session.
            if (!headers_sent()) {
                setcookie(self::SAFE_MODE_COOKIE, '1', [
                    'expires' => time() + HOUR_IN_SECONDS,
                    'path' => (defined('COOKIEPATH') && COOKIEPATH) ? COOKIEPATH : '/',
                    'domain' => defined('COOKIE_DOMAIN') ? COOKIE_DOMAIN : '',
                    'secure' => is_ssl(),
                    'httponly' => true,
                    'samesite' => 'Lax',
                ]);
            }
        }
    }

    /**
     * Generate a one-time safe mode activation token.
     */
    private function generate_safe_mode_token(): string {
        $token = wp_generate_password(40, false, false);
        $hash = hash_hmac('sha256', $token, $this->get_safe_mode_key());
        set_transient(self::SAFE_MODE_TOKEN_PREFIX . $token, $hash, self::SAFE_MODE_TOKEN_TTL);

        return $token;
    }

    /**
     * Validate and consume one-time safe mode token.
     */
    private function consume_safe_mode_token(string $token): bool {
        $sanitized = preg_replace('/[^A-Za-z0-9]/', '', $token);
        if (!is_string($sanitized) || $sanitized === '') {
            return false;
        }

        $transient_key = self::SAFE_MODE_TOKEN_PREFIX . $sanitized;
        $stored_hash = get_transient($transient_key);

        // One-time token semantics, always consume after lookup.
        delete_transient($transient_key);

        if (!is_string($stored_hash) || $stored_hash === '') {
            return false;
        }

        $expected_hash = hash_hmac('sha256', $sanitized, $this->get_safe_mode_key());

        return hash_equals($stored_hash, $expected_hash);
    }

    /**
     * Check if current user is an admin (bypass filtering).
     * Configurable per settings.
     */
    private function check_admin_bypass(): void {
        $settings = $this->get_settings();

        // Admin bypass is on by default
        $bypass_enabled = $settings['admin_bypass'] ?? true;
        if (!$bypass_enabled) {
            return;
        }

        // Check if user is logged in and is admin
        // Note: At MU loader time, user may not be available yet.
        // We use the auth cookie to detect admin early.
        if (function_exists('is_user_logged_in') && function_exists('current_user_can')) {
            if (is_user_logged_in() && current_user_can('manage_options')) {
                $this->admin_bypass = true;
                return;
            }
        }

        // Early detection via cookie (before user is resolved)
        // This is an optimization hint, not a security check
        $has_logged_in_cookie = false;
        if (defined('LOGGED_IN_COOKIE') && !empty($_COOKIE[LOGGED_IN_COOKIE])) {
            $has_logged_in_cookie = true;
        } elseif (!empty($_COOKIE) && is_array($_COOKIE)) {
            foreach ($_COOKIE as $cookie_name => $cookie_value) {
                if (!is_string($cookie_name) || $cookie_value === '') {
                    continue;
                }
                if (strpos($cookie_name, 'wordpress_logged_in_') === 0) {
                    $has_logged_in_cookie = true;
                    break;
                }
            }
        }

        if ($has_logged_in_cookie) {
            // Only set bypass if we can verify the user later
            // For now, mark as potential bypass (actual verification happens at template_redirect)
            add_action('template_redirect', function () {
                if (is_user_logged_in() && current_user_can('manage_options')) {
                    $this->admin_bypass = true;
                }
            }, 1);
        }
    }

    /**
     * Get safe mode key for display in admin.
     */
    public function get_safe_mode_key(): string {
        $key = get_option(self::SAFE_MODE_KEY_OPTION, '');
        if (empty($key)) {
            $key = wp_generate_password(32, false);
            update_option(self::SAFE_MODE_KEY_OPTION, $key, false);
        }
        return $key;
    }

    /**
     * Get safe mode URL for current site.
     */
    public function get_safe_mode_url(): string {
        $token = $this->generate_safe_mode_token();

        return add_query_arg([
            'sapm_safe' => '1',
            'sapm_token' => $token,
        ], home_url('/'));
    }

    // ========================================
    // Settings & Rules Management
    // ========================================

    /**
     * Load frontend rules from database.
     */
    private function load_rules(): void {
        $this->rules = get_option(self::RULES_OPTION_KEY, $this->get_default_rules());
    }

    /**
     * Load asset rules from database.
     */
    private function load_asset_rules(): void {
        $this->asset_rules = get_option(self::ASSET_RULES_OPTION_KEY, []);
    }

    /**
     * Get default frontend rules.
     */
    private function get_default_rules(): array {
        return [
            '_global' => [
                '_mode' => 'passthrough',
                'disabled_plugins' => [],
                'enabled_plugins' => [],
            ],
        ];
    }

    /**
     * Save frontend rules.
     */
    public function save_rules(array $rules): bool {
        $this->rules = $rules;
        // update_option returns false when value is unchanged — not an error
        $current = get_option(self::RULES_OPTION_KEY);
        if ($current === $rules) {
            return true;
        }
        return update_option(self::RULES_OPTION_KEY, $rules, true);
    }

    /**
     * Save asset rules.
     */
    public function save_asset_rules(array $rules): bool {
        $this->asset_rules = $rules;
        $current = get_option(self::ASSET_RULES_OPTION_KEY);
        if ($current === $rules) {
            return true;
        }
        return update_option(self::ASSET_RULES_OPTION_KEY, $rules, true);
    }

    /**
     * Get frontend settings.
     */
    public function get_settings(): array {
        return get_option(self::SETTINGS_OPTION_KEY, [
            'enabled' => false,                // Frontend filtering disabled by default
            'admin_bypass' => true,            // Admins bypass filtering
            'sampling_enabled' => false,       // Sampling disabled by default
            'asset_audit' => false,            // Asset audit disabled by default
            'wc_protection' => true,           // WooCommerce protection enabled
            'url_patterns' => [],              // Custom URL pattern rules
        ]);
    }

    /**
     * Save frontend settings.
     */
    public function save_settings(array $settings): bool {
        // Sanitize
        $clean = [
            'enabled' => !empty($settings['enabled']),
            'admin_bypass' => !empty($settings['admin_bypass']),
            'sampling_enabled' => !empty($settings['sampling_enabled']),
            'asset_audit' => !empty($settings['asset_audit']),
            'wc_protection' => !empty($settings['wc_protection']),
            'url_patterns' => $this->sanitize_url_patterns($settings['url_patterns'] ?? []),
        ];

        // update_option returns false when value is unchanged — not an error
        $current = get_option(self::SETTINGS_OPTION_KEY);
        if ($current === $clean) {
            return true;
        }
        return update_option(self::SETTINGS_OPTION_KEY, $clean, true);
    }

    /**
     * Sanitize URL pattern rules.
     */
    private function sanitize_url_patterns(array $patterns): array {
        $clean = [];
        foreach ($patterns as $pattern => $context) {
            // Validate regex
            $pattern = trim($pattern);
            if ($pattern === '' || @preg_match($pattern, '') === false) {
                continue;
            }

            // Validate context
            $context = sanitize_key($context);
            if (isset($this->context_definitions[$context])) {
                $clean[$pattern] = $context;
            }
        }
        return $clean;
    }

    // ========================================
    // Getters (for Admin UI)
    // ========================================

    /**
     * Get current frontend context ID.
     */
    public function get_current_context(): string {
        return $this->current_context;
    }

    /**
     * Get all context definitions.
     */
    public function get_context_definitions(): array {
        return $this->context_definitions;
    }

    /**
     * Get current frontend rules.
     */
    public function get_rules(): array {
        return $this->rules;
    }

    /**
     * Get current asset rules.
     */
    public function get_asset_rules(): array {
        return $this->asset_rules;
    }

    /**
     * Get plugins disabled in current request.
     */
    public function get_disabled_plugins(): array {
        return $this->disabled_plugins;
    }

    /**
     * Get assets dequeued in current request.
     */
    public function get_dequeued_assets(): array {
        return $this->dequeued_assets;
    }

    /**
     * Is safe mode active?
     */
    public function is_safe_mode(): bool {
        return $this->safe_mode;
    }

    /**
     * Is admin bypass active?
     */
    public function is_admin_bypass(): bool {
        return $this->admin_bypass;
    }

    // ========================================
    // AJAX: Frontend Rule Toggle
    // ========================================

    /**
     * AJAX handler for toggling a plugin rule from the frontend drawer.
     * Supports three scopes:
     *   - 'context': saves to context-level rules (sapm_frontend_rules option)
     *   - 'global':  saves to _global rules (sapm_frontend_rules option)
     *   - 'override': saves per-post/per-term override (post/term meta)
     */
    public function ajax_frontend_toggle_rule(): void {
        check_ajax_referer('sapm_frontend_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Insufficient permissions'], 403);
        }

        $context  = sanitize_key($_POST['context'] ?? '');
        $plugin   = wp_normalize_path(wp_unslash($_POST['plugin'] ?? ''));
        $plugin   = sanitize_text_field($plugin);
        $action   = sanitize_key($_POST['rule_action'] ?? ''); // block, allow, default
        $scope    = sanitize_key($_POST['scope'] ?? 'context'); // context, global, override

        if ($context === '' || $plugin === '' || strpos($plugin, '..') !== false) {
            wp_send_json_error(['message' => 'Invalid data'], 400);
        }

        $allowed_actions = ['block', 'allow', 'default'];
        if (!in_array($action, $allowed_actions, true)) {
            wp_send_json_error(['message' => 'Invalid action: ' . $action], 400);
        }

        // Handle per-object override scope
        if ($scope === 'override') {
            $override_type = sanitize_key($_POST['override_type'] ?? '');
            $override_id   = absint($_POST['override_id'] ?? 0);

            if (!in_array($override_type, ['post', 'term'], true) || $override_id <= 0) {
                wp_send_json_error(['message' => 'Invalid override target'], 400);
            }

            $overrides = $this->get_overrides_for($override_type, $override_id);
            if (!is_array($overrides)) {
                $overrides = ['disabled_plugins' => [], 'enabled_plugins' => []];
            }
            if (!isset($overrides['disabled_plugins'])) {
                $overrides['disabled_plugins'] = [];
            }
            if (!isset($overrides['enabled_plugins'])) {
                $overrides['enabled_plugins'] = [];
            }

            if ($action === 'block') {
                if (!in_array($plugin, $overrides['disabled_plugins'], true)) {
                    $overrides['disabled_plugins'][] = $plugin;
                }
                $overrides['enabled_plugins'] = array_values(
                    array_diff($overrides['enabled_plugins'], [$plugin])
                );
            } elseif ($action === 'allow') {
                if (!in_array($plugin, $overrides['enabled_plugins'], true)) {
                    $overrides['enabled_plugins'][] = $plugin;
                }
                $overrides['disabled_plugins'] = array_values(
                    array_diff($overrides['disabled_plugins'], [$plugin])
                );
            } elseif ($action === 'default') {
                $overrides['disabled_plugins'] = array_values(
                    array_diff($overrides['disabled_plugins'], [$plugin])
                );
                $overrides['enabled_plugins'] = array_values(
                    array_diff($overrides['enabled_plugins'], [$plugin])
                );
            }

            $this->save_overrides_for($override_type, $override_id, $overrides);

            // Refresh cached overrides for simulation
            $this->override_type = $override_type;
            $this->override_id = $override_id;
            $this->current_overrides = $overrides;

            // Re-simulate
            $sim = $this->simulate_disabled_plugins();
            $rules = get_option(self::RULES_OPTION_KEY, $this->get_default_rules());
            $context_rules_new = $rules[$context] ?? [];
            $global_rules_new  = $rules['_global'] ?? [];
            $new_mode = $context_rules_new['_mode'] ?? $global_rules_new['_mode'] ?? 'passthrough';

            wp_send_json_success([
                'context'         => $context,
                'scope'           => 'override',
                'overrideType'    => $override_type,
                'overrideId'      => $override_id,
                'mode'            => $new_mode,
                'disabledPlugins' => array_keys($sim),
                'contextRules'    => $rules[$context] ?? [],
                'globalRules'     => $rules['_global'] ?? [],
                'overrides'       => $overrides,
                'allPlugins'      => $this->get_all_plugins_state($context),
            ]);
        }

        // Handle context or global scope (original behavior)
        $use_global = ($scope === 'global') || !empty($_POST['apply_global']);
        $rule_key   = $use_global ? '_global' : $context;

        $rules = get_option(self::RULES_OPTION_KEY, $this->get_default_rules());
        if (!is_array($rules)) {
            $rules = $this->get_default_rules();
        }

        // Ensure context entry exists
        if (!isset($rules[$rule_key]) || !is_array($rules[$rule_key])) {
            $rules[$rule_key] = ['_mode' => 'blacklist'];
        }

        // Get current mode
        $mode = $rules[$rule_key]['_mode'] ?? $rules['_global']['_mode'] ?? 'passthrough';

        // If mode is passthrough and we're being asked to block, auto-switch to blacklist
        if ($mode === 'passthrough' && $action === 'block') {
            $rules[$rule_key]['_mode'] = 'blacklist';
            $mode = 'blacklist';
        }

        // Apply action based on mode
        if ($action === 'block') {
            if (!isset($rules[$rule_key]['disabled_plugins'])) {
                $rules[$rule_key]['disabled_plugins'] = [];
            }
            if (!in_array($plugin, $rules[$rule_key]['disabled_plugins'], true)) {
                $rules[$rule_key]['disabled_plugins'][] = $plugin;
            }
            if (isset($rules[$rule_key]['enabled_plugins'])) {
                $rules[$rule_key]['enabled_plugins'] = array_values(
                    array_diff($rules[$rule_key]['enabled_plugins'], [$plugin])
                );
            }
        } elseif ($action === 'allow') {
            if (!isset($rules[$rule_key]['enabled_plugins'])) {
                $rules[$rule_key]['enabled_plugins'] = [];
            }
            if (!in_array($plugin, $rules[$rule_key]['enabled_plugins'], true)) {
                $rules[$rule_key]['enabled_plugins'][] = $plugin;
            }
            if (isset($rules[$rule_key]['disabled_plugins'])) {
                $rules[$rule_key]['disabled_plugins'] = array_values(
                    array_diff($rules[$rule_key]['disabled_plugins'], [$plugin])
                );
            }
        } elseif ($action === 'default') {
            if (isset($rules[$rule_key]['disabled_plugins'])) {
                $rules[$rule_key]['disabled_plugins'] = array_values(
                    array_diff($rules[$rule_key]['disabled_plugins'], [$plugin])
                );
            }
            if (isset($rules[$rule_key]['enabled_plugins'])) {
                $rules[$rule_key]['enabled_plugins'] = array_values(
                    array_diff($rules[$rule_key]['enabled_plugins'], [$plugin])
                );
            }
        }

        // Save
        $this->save_rules($rules);

        // Re-simulate to return updated data
        $this->rules = $rules;
        $sim = $this->simulate_disabled_plugins();
        $context_rules_new = $rules[$context] ?? [];
        $global_rules_new  = $rules['_global'] ?? [];
        $new_mode = $context_rules_new['_mode'] ?? $global_rules_new['_mode'] ?? 'passthrough';

        wp_send_json_success([
            'context'         => $context,
            'scope'           => $scope,
            'ruleKey'         => $rule_key,
            'mode'            => $new_mode,
            'disabledPlugins' => array_keys($sim),
            'contextRules'    => $rules[$context] ?? [],
            'globalRules'     => $rules['_global'] ?? [],
            'allPlugins'      => $this->get_all_plugins_state($context),
        ]);
    }

    /**
     * AJAX handler for resetting all per-object overrides.
     */
    public function ajax_frontend_reset_overrides(): void {
        check_ajax_referer('sapm_frontend_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Insufficient permissions'], 403);
        }

        $override_type = sanitize_key($_POST['override_type'] ?? '');
        $override_id   = absint($_POST['override_id'] ?? 0);
        $context       = sanitize_key($_POST['context'] ?? '');

        if (!in_array($override_type, ['post', 'term'], true) || $override_id <= 0) {
            wp_send_json_error(['message' => 'Invalid override target'], 400);
        }

        // Delete override meta
        $this->save_overrides_for($override_type, $override_id, []);

        // Refresh cached state
        $this->override_type = $override_type;
        $this->override_id = $override_id;
        $this->current_overrides = [];

        wp_send_json_success([
            'context'    => $context,
            'allPlugins' => $context !== '' ? $this->get_all_plugins_state($context) : [],
        ]);
    }

    // ========================================
    // Admin Preview Simulation
    // ========================================

    /**
     * Get all active plugins with their current rule state for a given context.
     * Returns array suitable for frontend rule management UI.
     * Includes per-object override state information.
     *
     * @param string $context Current frontend context
     * @return array Array of {file, name, state, source, effective, protected, overrideState}
     */
    private function get_all_plugins_state(string $context): array {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $all_plugins = get_plugins();
        $active_plugins = get_option('active_plugins', []);

        $context_rules = $this->rules[$context] ?? [];
        $global_rules  = $this->rules['_global'] ?? [];
        $mode          = $context_rules['_mode'] ?? $global_rules['_mode'] ?? 'passthrough';

        $context_disabled = $context_rules['disabled_plugins'] ?? [];
        $context_enabled  = $context_rules['enabled_plugins'] ?? [];
        $global_disabled  = $global_rules['disabled_plugins'] ?? [];
        $global_enabled   = $global_rules['enabled_plugins'] ?? [];

        // Per-object overrides
        $overrides = $this->get_current_overrides();
        $override_disabled = $overrides['disabled_plugins'] ?? [];
        $override_enabled  = $overrides['enabled_plugins'] ?? [];

        $result = [];
        foreach ($active_plugins as $file) {
            $name = isset($all_plugins[$file]) ? ($all_plugins[$file]['Name'] ?? $file) : $file;
            $is_protected = $this->is_protected_plugin($file, $context);

            // Determine context-level state (without overrides)
            $state  = 'default';
            $source = 'none';

            if (in_array($file, $context_disabled, true)) {
                $state  = 'blocked';
                $source = 'context';
            } elseif (in_array($file, $context_enabled, true)) {
                $state  = 'allowed';
                $source = 'context';
            } elseif (in_array($file, $global_disabled, true)) {
                $state  = 'blocked';
                $source = 'global';
            } elseif (in_array($file, $global_enabled, true)) {
                $state  = 'allowed';
                $source = 'global';
            }

            // Determine per-object override state
            $override_state = 'default'; // no override
            if (in_array($file, $override_disabled, true)) {
                $override_state = 'blocked';
            } elseif (in_array($file, $override_enabled, true)) {
                $override_state = 'allowed';
            }

            // Compute effective state (override takes precedence)
            if ($override_state !== 'default') {
                $effective = $override_state;
            } elseif ($state === 'default') {
                $effective = ($mode === 'whitelist') ? 'blocked' : 'allowed';
            } else {
                $effective = $state;
            }

            $result[] = [
                'file'           => $file,
                'name'           => $name,
                'state'          => $state,
                'source'         => $source,
                'effective'      => $effective,
                'protected'      => $is_protected,
                'overrideState'  => $override_state,
            ];
        }

        // Sort: blocked first, then allowed, then default; within each group alphabetically
        usort($result, function ($a, $b) {
            $order = ['blocked' => 0, 'default' => 1, 'allowed' => 2];
            $diff = ($order[$a['effective']] ?? 1) - ($order[$b['effective']] ?? 1);
            if ($diff !== 0) return $diff;
            return strcasecmp($a['name'], $b['name']);
        });

        return $result;
    }

    /**
     * Simulate which plugins WOULD be disabled for non-admin visitors.
     * Used in admin preview mode to show real optimization data.
     * Includes per-object override logic.
     *
     * @return array Map of plugin => reason
     */
    private function simulate_disabled_plugins(): array {
        $settings = $this->get_settings();
        if (empty($settings['enabled'])) {
            return [];
        }

        $context = $this->detect_context();
        $context_rules = $this->rules[$context] ?? [];
        $global_rules = $this->rules['_global'] ?? [];
        $mode = $context_rules['_mode'] ?? $global_rules['_mode'] ?? 'passthrough';

        if ($mode === 'passthrough') {
            return [];
        }

        // Build context-level lists
        $context_disabled = array_unique(array_merge(
            $global_rules['disabled_plugins'] ?? [],
            $context_rules['disabled_plugins'] ?? []
        ));
        $context_enabled = array_unique(array_merge(
            $global_rules['enabled_plugins'] ?? [],
            $context_rules['enabled_plugins'] ?? []
        ));

        // Apply per-object overrides
        $resolved = $this->apply_overrides($context_disabled, $context_enabled, $mode);
        $effective_disabled = $resolved['disabled'];
        $effective_enabled = $resolved['enabled'];

        $plugins = get_option('active_plugins', []);
        $simulated = [];

        if ($mode === 'blacklist') {
            foreach ($plugins as $plugin) {
                if ($this->is_protected_plugin($plugin, $context)) {
                    continue;
                }
                if (in_array($plugin, $effective_disabled, true)) {
                    $simulated[$plugin] = 'context:' . $context;
                }
            }
        } elseif ($mode === 'whitelist') {
            if (in_array('*', $effective_enabled, true)) {
                return [];
            }
            foreach ($plugins as $plugin) {
                if ($this->is_protected_plugin($plugin, $context)) {
                    continue;
                }
                if (!in_array($plugin, $effective_enabled, true)) {
                    $simulated[$plugin] = 'context:' . $context;
                }
            }
        }

        // Cascade via dependencies
        if (!empty($simulated) && class_exists('SAPM_Dependencies')) {
            $deps = SAPM_Dependencies::init();
            $cascade = $deps->get_cascade_blocked(array_keys($simulated));
            foreach ($cascade as $plugin) {
                if (!isset($simulated[$plugin])) {
                    $simulated[$plugin] = 'cascade:dependency';
                }
            }
        }

        return $simulated;
    }

    /**
     * Simulate which assets WOULD be dequeued for non-admin visitors.
     *
     * @return array{scripts: string[], styles: string[]}
     */
    private function simulate_dequeued_assets(): array {
        $settings = $this->get_settings();
        if (empty($settings['enabled'])) {
            return ['scripts' => [], 'styles' => []];
        }

        $context = $this->detect_context();
        $context_rules = $this->asset_rules[$context] ?? [];
        $global_rules = $this->asset_rules['_global'] ?? [];

        $scripts = array_unique(array_merge(
            $global_rules['dequeue_scripts'] ?? [],
            $context_rules['dequeue_scripts'] ?? []
        ));

        $styles = array_unique(array_merge(
            $global_rules['dequeue_styles'] ?? [],
            $context_rules['dequeue_styles'] ?? []
        ));

        return [
            'scripts' => array_values($scripts),
            'styles'  => array_values($styles),
        ];
    }

    /**
     * Get the asset audit data for a specific context (or all).
     */
    public function get_asset_audit(?string $context = null): array {
        $audit = get_option('sapm_frontend_asset_audit', []);
        if ($context !== null) {
            return $audit[$context] ?? [];
        }
        return $audit;
    }

    /**
     * Get frontend performance summary from sampling data.
     */
    public function get_frontend_performance_summary(): array {
        if (!class_exists('SAPM_Database')) {
            return [];
        }

        return SAPM_Database::get_request_type_summary('frontend');
    }

    /**
     * Get per-plugin performance payload for the frontend drawer.
     * Mirrors admin's get_perf_payload() but uses sampling data.
     *
     * @param array  $plugin_names Map of plugin_file => display name
     * @param string $context      Current frontend context ID
     * @return array|null Perf payload or null if no data
     */
    private function get_frontend_perf_payload(array $plugin_names, string $context): ?array {
        $live_payload = $this->get_live_perf_payload($plugin_names);
        if ($live_payload !== null) {
            return $live_payload;
        }

        if (!class_exists('SAPM_Database')) {
            return null;
        }

        $summary = SAPM_Database::get_request_type_summary('frontend');
        if (empty($summary)) {
            return null;
        }

        // Try exact context match first, then fallback to any frontend data
        $trigger_key = 'context:' . $context;
        $data = $summary[$trigger_key] ?? null;

        // Fallback: pick the trigger with the most samples
        if (!$data) {
            $best = null;
            $best_samples = 0;
            foreach ($summary as $trigger => $tdata) {
                foreach ($tdata['plugins'] ?? [] as $pstats) {
                    if (($pstats['samples'] ?? 0) > $best_samples) {
                        $best_samples = $pstats['samples'];
                        $best = $tdata;
                    }
                }
            }
            $data = $best;
        }

        if (!$data || empty($data['plugins'])) {
            return null;
        }

        $plugins = $data['plugins'];

        // Sort by avg_load_ms descending
        uasort($plugins, function ($a, $b) {
            return ($b['avg_load_ms'] ?? 0) <=> ($a['avg_load_ms'] ?? 0);
        });

        $items = [];
        $all_items = [];
        $total_ms = 0;
        $total_queries = 0;
        $count = 0;

        foreach ($plugin_names as $plugin_file => $plugin_name) {
            $stats = $plugins[$plugin_file] ?? [];
            $all_items[$plugin_file] = [
                'plugin'  => $plugin_file,
                'name'    => $plugin_name,
                'ms'      => round((float) ($stats['avg_load_ms'] ?? 0), 2),
                'queries' => isset($stats['avg_queries']) ? round((float) $stats['avg_queries'], 1) : 0,
                'samples' => (int) ($stats['samples'] ?? 0),
            ];
        }

        foreach ($plugins as $plugin_file => $stats) {
            if ($plugin_file === '_total') {
                $total_ms = $stats['avg_load_ms'] ?? 0;
                $total_queries = (int) ($stats['avg_queries'] ?? 0);
                continue;
            }
            $count++;
            if ($count > 15) {
                break;
            }
            $ms = round($stats['avg_load_ms'] ?? 0, 2);
            $queries = isset($stats['avg_queries']) ? round($stats['avg_queries'], 1) : null;
            $items[] = [
                'plugin'  => $plugin_file,
                'name'    => $plugin_names[$plugin_file] ?? $plugin_file,
                'ms'      => $ms,
                'queries' => $queries,
                'samples' => $stats['samples'] ?? 0,
            ];
        }

        if (empty($items)) {
            return null;
        }

        return [
            'total_ms'      => round($total_ms, 2),
            'total_queries'  => $total_queries,
            'context'        => $context,
            'items'          => $items,
            'all_items'      => $all_items,
        ];
    }

    /**
     * Build frontend perf payload from the latest live SAPM transient snapshot.
     * This mirrors backend drawer behavior and provides non-zero ms values immediately.
     */
    private function get_live_perf_payload(array $plugin_names): ?array {
        $payload = null;

        if (is_object($this->core) && method_exists($this->core, 'get_runtime_perf_snapshot')) {
            $runtime_payload = $this->core->get_runtime_perf_snapshot();
            if (is_array($runtime_payload) && !empty($runtime_payload['plugins'])) {
                $payload = $runtime_payload;
            }
        }

        if (!is_array($payload) || empty($payload['plugins'])) {
            $payload = get_transient('sapm_perf_last');
        }

        if (!is_array($payload) || empty($payload['plugins']) || !is_array($payload['plugins'])) {
            return null;
        }

        $plugins = (array) $payload['plugins'];
        arsort($plugins);

        $query_counts = is_array($payload['query_counts'] ?? null) ? (array) $payload['query_counts'] : [];

        $items = [];
        $all_items = [];
        $count = 0;

        foreach ($plugins as $plugin_file => $seconds) {
            $count++;
            if ($count > 15) {
                break;
            }

            $items[] = [
                'plugin'  => $plugin_file,
                'name'    => $plugin_names[$plugin_file] ?? $plugin_file,
                'ms'      => round(((float) $seconds) * 1000, 2),
                'queries' => (int) ($query_counts[$plugin_file] ?? 0),
                'samples' => 1,
            ];
        }

        foreach ($plugin_names as $plugin_file => $plugin_name) {
            $seconds = (float) ($plugins[$plugin_file] ?? 0.0);
            $all_items[$plugin_file] = [
                'plugin'  => $plugin_file,
                'name'    => $plugin_name,
                'ms'      => round($seconds * 1000, 2),
                'queries' => (int) ($query_counts[$plugin_file] ?? 0),
                'samples' => isset($plugins[$plugin_file]) ? 1 : 0,
            ];
        }

        if (empty($all_items)) {
            return null;
        }

        $total_queries = isset($payload['query_total'])
            ? (int) $payload['query_total']
            : array_sum($query_counts);

        return [
            'total_ms'        => isset($payload['total_ms']) ? (float) $payload['total_ms'] : round(array_sum($plugins) * 1000, 2),
            'total_queries'   => $total_queries,
            'captured_at'     => !empty($payload['captured_at'])
                ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), (int) $payload['captured_at'])
                : '',
            'context'         => (string) ($payload['context'] ?? ''),
            'matches_current' => $this->is_live_payload_for_current_request($payload),
            'items'           => $items,
            'all_items'       => $all_items,
        ];
    }

    /**
     * Check whether a live perf payload belongs to the current request path.
     */
    private function is_live_payload_for_current_request(array $payload): bool {
        $saved_uri = (string) ($payload['uri'] ?? '');
        $current_uri = (string) ($_SERVER['REQUEST_URI'] ?? '');

        if ($saved_uri === '' || $current_uri === '') {
            return false;
        }

        $saved_path = parse_url($saved_uri, PHP_URL_PATH);
        $current_path = parse_url($current_uri, PHP_URL_PATH);

        if (!is_string($saved_path) || !is_string($current_path)) {
            return false;
        }

        return untrailingslashit($saved_path) === untrailingslashit($current_path);
    }

    /**
     * Get auto-suggestions for frontend based on sampling data.
     * 
     * Suggests which plugins can be safely blocked per context based on:
     * - Low usage/load time (plugin not needed on that context)
     * - Known frontend-only patterns
     * - Asset audit (plugins only loading CSS/JS, no critical functionality)
     */
    public function get_auto_suggestions(): array {
        $suggestions = [];

        // Known plugins safe to block on specific frontend contexts
        $known_safe = [
            // Admin-only plugins - safe to block everywhere on frontend
            '_global' => [
                'loco-translate/*',
                'index-wp-mysql-for-speed/*',
                'code-profiler/*',
                'query-monitor/*',
                'wp-optimize/*',
                'updraftplus/*',
                'duplicator/*',
                'wp-all-export/*',
                'wp-all-import/*',
                'woo-preview-emails/*',
                'order-import-export/*',
            ],
            // Plugins safe to block on non-WC pages
            'single_post' => [
                'woocommerce/*',       // If not using WC blocks in posts
            ],
            'archive_category' => [
                'woocommerce/*',       // If not using WC blocks in archives
            ],
            'error_404' => [
                'woocommerce/*',
                'contact-form-7/*',
                'wpforms-lite/*',
            ],
            'feed' => [
                'woocommerce/*',
                'contact-form-7/*',
                'wpforms-lite/*',
                'complianz-gdpr/*',
                'cookie-notice/*',
            ],
        ];

        // Merge with sampling data suggestions
        $sampling_suggestions = $this->generate_sampling_suggestions();

        return array_merge_recursive($known_safe, $sampling_suggestions);
    }

    /**
     * Generate suggestions from sampling data analysis.
     */
    private function generate_sampling_suggestions(): array {
        // Will be populated from SAPM_Database sampling data
        // when enough frontend samples are collected
        if (!class_exists('SAPM_Database')) {
            return [];
        }

        $summary = SAPM_Database::get_request_type_summary('frontend');
        $suggestions = [];

        // Analyze per-context plugin performance
        foreach ($summary as $trigger => $data) {
            $context_id = str_replace('context:', '', $trigger);
            if (!isset($this->context_definitions[$context_id])) {
                continue;
            }

            // Find plugins with zero contribution (not needed)
            foreach ($data['plugins'] ?? [] as $plugin => $stats) {
                if (($stats['avg_load_ms'] ?? 0) < 0.1 && ($stats['avg_queries'] ?? 0) < 1) {
                    $suggestions[$context_id][] = $plugin;
                }
            }
        }

        return $suggestions;
    }

    // ========================================
    // Frontend Info Bar (for logged-in admins)
    // ========================================

    /**
     * Add SAPM toggle item to the WP admin toolbar on frontend pages.
     * Clicking the item opens/closes the SAPM drawer sidebar.
     * Hooked to admin_bar_menu with priority 999.
     */
    public function frontend_admin_bar_toggle(WP_Admin_Bar $wp_admin_bar): void {
        // Frontend only — admin pages use the admin drawer toggle in class-sapm-admin.php
        if (is_admin() || !is_user_logged_in() || !current_user_can('manage_options')) {
            return;
        }

        // Compute badge count (mirrors maybe_render_frontend_bar logic)
        if ($this->admin_bypass) {
            $disabled_list = array_keys($this->simulate_disabled_plugins());
            $dq            = $this->simulate_dequeued_assets();
        } else {
            $disabled_list = array_keys($this->disabled_plugins);
            $dq            = $this->dequeued_assets;
        }
        $count = count($disabled_list) + count($dq['scripts'] ?? []) + count($dq['styles'] ?? []);

        if ($count > 0) {
            $badge = ' <span class="sapm-adminbar-badge sapm-adminbar-badge--active">' . intval($count) . '</span>';
        } else {
            $badge = ' <span class="sapm-adminbar-badge">SAPM</span>';
        }

        $wp_admin_bar->add_node([
            'id'    => 'sapm-frontend',
            'title' => '<span class="sapm-adminbar-label">⚡SAPM</span>' . $badge,
            'href'  => '#sapm-fe-drawer',
            'meta'  => ['class' => 'sapm-adminbar-frontend-toggle'],
        ]);
    }

    /**
     * Enqueue frontend bar assets for admins.
     * Hooked to wp_enqueue_scripts with low priority.
     */
    public function maybe_enqueue_frontend_bar(): void {
        if (!is_user_logged_in() || !current_user_can('manage_options')) {
            return;
        }

        wp_enqueue_style(
            'sapm-drawer',
            SAPM_PLUGIN_URL . 'assets/drawer.css',
            [],
            SAPM_VERSION . '.' . time()
        );

        wp_enqueue_script(
            'sapm-frontend-bar',
            SAPM_PLUGIN_URL . 'assets/frontend-bar.js',
            [],
            SAPM_VERSION . '.' . time(),
            true
        );
    }

    /**
     * Render the frontend info bar HTML for admins.
     * Hooked to wp_footer.
     */
    public function maybe_render_frontend_bar(): void {
        if (!is_user_logged_in() || !current_user_can('manage_options')) {
            return;
        }

        // Capture page-level metrics BEFORE any SAPM rendering overhead queries.
        $captured_total_queries = get_num_queries();
        $captured_total_time = defined('WP_START_TIMESTAMP')
            ? round((microtime(true) - WP_START_TIMESTAMP) * 1000) . ' ms'
            : null;

        // Also freeze perf_query_total so the payload doesn't include bar-rendering queries.
        if (is_object($this->core) && method_exists($this->core, 'freeze_query_total')) {
            $this->core->freeze_query_total();
        }

        $context = $this->detect_context();
        $context_def = $this->context_definitions[$context] ?? [];
        $settings = $this->get_settings();

        // Build plugin names map
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $all_installed = get_plugins();
        $active_files  = get_option('active_plugins', []);
        $plugin_names  = [];
        foreach ($active_files as $file) {
            $plugin_names[$file] = $all_installed[$file]['Name'] ?? $file;
        }

        // If admin bypass is active, simulate what visitors would see
        $is_preview = $this->admin_bypass && !$this->safe_mode;
        if ($is_preview) {
            $sim_disabled  = $this->simulate_disabled_plugins();
            $sim_assets    = $this->simulate_dequeued_assets();
            $disabled_list = array_keys($sim_disabled);
            $dq_scripts    = $sim_assets['scripts'];
            $dq_styles     = $sim_assets['styles'];
        } else {
            $disabled_list = array_keys($this->disabled_plugins);
            $dq_scripts    = $this->dequeued_assets['scripts'] ?? [];
            $dq_styles     = $this->dequeued_assets['styles'] ?? [];
        }

        $disabled_count = count($disabled_list)
            + count($dq_scripts)
            + count($dq_styles);

        // Determine filtering mode for context
        $context_rules = $this->rules[$context] ?? [];
        $global_rules_mode = $this->rules['_global'] ?? [];
        $current_mode = $context_rules['_mode'] ?? $global_rules_mode['_mode'] ?? 'passthrough';
        $drawer_css_url = add_query_arg('ver', SAPM_VERSION . '.' . time(), SAPM_PLUGIN_URL . 'assets/drawer.css');

        // Detect per-object override target
        $this->detect_override_target();
        $override_data = null;
        if ($this->override_type !== null && $this->override_id !== null) {
            $override_data = [
                'type'      => $this->override_type,
                'id'        => $this->override_id,
                'label'     => $this->get_override_label(),
                'hasRules'  => $this->get_current_overrides() !== null,
                'overrides' => $this->get_current_overrides() ?: ['disabled_plugins' => [], 'enabled_plugins' => []],
            ];
        }

        $bar_data = [
            'context'          => $context,
            'contextLabel'     => $context_def['label'] ?? $context,
            'enabled'          => !empty($settings['enabled']),
            'safeMode'         => $this->safe_mode,
            'adminBypass'      => false,
            'isPreview'        => $is_preview,
            'filteringMode'    => $current_mode,
            'disabledPlugins'  => $disabled_list,
            'dequeuedScripts'  => $dq_scripts,
            'dequeuedStyles'   => $dq_styles,
            'pluginNames'      => $plugin_names,
            'totalTime'        => $captured_total_time,
            'totalQueries'     => $captured_total_queries,
            'activePlugins'    => count(get_option('active_plugins', [])),
            'perf'             => $this->get_frontend_perf_payload($plugin_names, $context),
            'ajaxUrl'          => admin_url('admin-ajax.php'),
            'nonce'            => wp_create_nonce('sapm_frontend_nonce'),
            'drawerCssUrl'     => esc_url_raw($drawer_css_url),
            'allPlugins'       => $this->get_all_plugins_state($context),
            'override'         => $override_data,
            'settingsUrl'      => admin_url('options-general.php?page=smart-admin-plugin-manager'),
            'strings'          => [
                'safeMode'          => __('Safe Mode is active — filtering disabled', 'sapm'),
                'adminBypass'       => __('Admin Bypass — full site without restrictions', 'sapm'),
                'preview'           => __('Preview — showing what visitors see', 'sapm'),
                'contextLabel'      => __('Page context', 'sapm'),
                'disabledTitle'     => __('Blocked plugins', 'sapm'),
                'assetsTitle'       => __('Dequeued assets', 'sapm'),
                'assetsHint'        => __('Shows CSS/JS handles removed on this page by dequeue rules.', 'sapm'),
                'assetsHowTo'       => __('To see data here, create dequeue rules for this page context in settings, then reload this page.', 'sapm'),
                'assetsOpenSettings'=> __('Open Asset Rules', 'sapm'),
                'jsLabel'           => __('JS', 'sapm'),
                'cssLabel'          => __('CSS', 'sapm'),
                'perfTitle'         => __('Performance', 'sapm'),
                'noDisabled'        => __('No plugins are blocked on this page', 'sapm'),
                'noAssets'          => __('No assets are dequeued on this page', 'sapm'),
                'filteringInactive' => __('Filtering is not active', 'sapm'),
                'modePassthrough'   => __('Passthrough — nothing is filtered', 'sapm'),
                'modeBlacklist'     => __('Blacklist — selected plugins are blocked', 'sapm'),
                'modeWhitelist'     => __('Whitelist — only selected plugins are allowed', 'sapm'),
                'queries'           => __('queries', 'sapm'),
                'plugins'           => __('plugins', 'sapm'),
                'rulesTitle'        => __('Plugin Management', 'sapm'),
                'actionEnable'      => __('Enable', 'sapm'),
                'actionDisable'     => __('Block', 'sapm'),
                'actionDefault'     => __('Default', 'sapm'),
                'block'             => __('Block', 'sapm'),
                'allow'             => __('Allow', 'sapm'),
                'default'           => __('Default', 'sapm'),
                'blocked'           => __('Blocked', 'sapm'),
                'allowed'           => __('Enabled', 'sapm'),
                'stateEnabled'      => __('Enabled', 'sapm'),
                'stateDisabled'     => __('Blocked', 'sapm'),
                'stateDefault'      => __('Default', 'sapm'),
                'protectedPlugin'   => __('Protected', 'sapm'),
                'global'            => __('global', 'sapm'),
                'contextRule'       => __('context', 'sapm'),
                'saving'            => __('Saving…', 'sapm'),
                'saved'             => __('Saved ✓', 'sapm'),
                'errorSaving'       => __('Error while saving', 'sapm'),
                'reloadNotice'      => __('Changes take effect after page reload', 'sapm'),
                'perfTotal'         => __('Plugin load', 'sapm'),
                'perfQueries'       => __('Queries', 'sapm'),
                'perfQueriesShort'  => __('queries', 'sapm'),
                'perfEmpty'         => __('No performance data yet. Enable sampling to collect data.', 'sapm'),
                'perfCapturedAt'    => __('Captured', 'sapm'),
                'perfInlineHint'    => __('Per-plugin load times shown in Plugin Management below.', 'sapm'),
                'perfSamples'       => __('samples', 'sapm'),
                'scopeContext'      => __('All of this type', 'sapm'),
                'scopeOverride'     => __('Only this page', 'sapm'),
                'scopeLabel'        => __('Apply to', 'sapm'),
                'overrideActive'    => __('Per-page override active', 'sapm'),
                'overrideSource'    => __('override', 'sapm'),
                'resetOverride'     => __('Reset per-page overrides', 'sapm'),
                'resetOverrideDone' => __('Overrides cleared', 'sapm'),
                'overrideHint'      => __('Per-page rules override context rules for this specific content', 'sapm'),
            ],
        ];

        // Localize data for JS
        echo '<script>window.SAPM_FRONTEND_BAR = ' . wp_json_encode($bar_data) . ';</script>' . "\n";

        // Render drawer HTML (side panel matching admin drawer pattern)
        ?>
        <div id="sapm-fe-drawer" class="sapm-fe-drawer sapm-admin-drawer<?php echo is_admin_bar_showing() ? ' sapm-has-adminbar' : ''; ?>" aria-hidden="true">
            <div class="sapm-fe-drawer-backdrop"></div>
            <button class="sapm-fe-drawer-toggle sapm-drawer-toggle" aria-expanded="false" title="SAPM Frontend Optimizer">
                <span class="sapm-fe-drawer-toggle-icon">⚡</span>
                <span class="sapm-drawer-toggle-label">SAPM</span>
                <span class="sapm-fe-drawer-toggle-badge"><?php echo intval($disabled_count); ?></span>
            </button>
            <div class="sapm-fe-drawer-panel sapm-drawer-panel" role="dialog" aria-label="SAPM Frontend Optimizer">
                <div class="sapm-fe-drawer-header sapm-drawer-header">
                    <div class="sapm-fe-drawer-title sapm-drawer-title">
                        <span class="sapm-fe-drawer-title-icon">⚡</span>
                        SAPM Frontend Optimizer
                    </div>
                    <button class="sapm-fe-drawer-close sapm-drawer-close" aria-label="<?php esc_attr_e('Close', 'sapm'); ?>">&times;</button>
                </div>
                <div class="sapm-fe-drawer-context sapm-drawer-context">
                    <span class="sapm-fe-drawer-context-label"><?php esc_html_e('Page context', 'sapm'); ?>:</span>
                    <span class="sapm-fe-drawer-context-value"><?php echo esc_html($context_def['label'] ?? $context); ?></span>
                </div>
                <div class="sapm-fe-drawer-body">
                    <!-- Populated by frontend-bar.js -->
                </div>
                <div class="sapm-fe-drawer-footer sapm-drawer-footer">
                    <a href="<?php echo esc_url(admin_url('options-general.php?page=smart-admin-plugin-manager')); ?>" class="sapm-fe-drawer-settings-link sapm-drawer-settings">
                        ⚙ <?php esc_html_e('Settings', 'sapm'); ?>
                    </a>
                </div>
            </div>
        </div>
        <?php
    }
}
