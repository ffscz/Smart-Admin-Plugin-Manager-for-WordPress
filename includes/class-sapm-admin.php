<?php

if (!defined('ABSPATH')) {
    exit;
}

class SAPM_Admin {

    private static $instance = null;

    private const ADMIN_THEME_OPTION = 'sapm_admin_theme';

    /** @var SAPM_Core */
    private $core;

    /** @var array */
    private $menu_targets = [];

    public static function init(SAPM_Core $core): self {
        if (self::$instance === null) {
            self::$instance = new self($core);
        }
        return self::$instance;
    }

    private function __construct(SAPM_Core $core) {
        $this->core = $core;

        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_menu', [$this, 'restore_menu_snapshots'], 9998);
        add_action('admin_menu', [$this, 'capture_menu_snapshots'], 9999);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_drawer_assets'], 20);

        add_action('wp_ajax_sapm_save_rules', [$this, 'ajax_save_rules']);
        add_action('wp_ajax_sapm_drawer_toggle_rule', [$this, 'ajax_drawer_toggle_rule']);
        add_action('wp_ajax_sapm_get_current_screen', [$this, 'ajax_get_current_screen']);
        add_action('wp_ajax_sapm_detect_plugins', [$this, 'ajax_detect_plugins']);
        add_action('wp_ajax_sapm_save_request_type_rules', [$this, 'ajax_save_request_type_rules']);
        add_action('wp_ajax_sapm_get_request_type_performance', [$this, 'ajax_get_request_type_performance']);
        add_action('wp_ajax_sapm_clear_request_type_performance', [$this, 'ajax_clear_request_type_performance']);
        add_action('wp_ajax_sapm_set_mode', [$this, 'ajax_set_mode']);
        add_action('wp_ajax_sapm_get_auto_suggestions', [$this, 'ajax_get_auto_suggestions']);
        add_action('wp_ajax_sapm_apply_auto_rules', [$this, 'ajax_apply_auto_rules']);
        add_action('wp_ajax_sapm_reset_auto_data', [$this, 'ajax_reset_auto_data']);
        add_action('wp_ajax_sapm_get_sampling_stats', [$this, 'ajax_get_sampling_stats']);
        add_action('wp_ajax_sapm_save_update_optimizer', [$this, 'ajax_save_update_optimizer']);
        add_action('wp_ajax_sapm_force_update_check', [$this, 'ajax_force_update_check']);
        add_action('wp_ajax_sapm_save_admin_theme', [$this, 'ajax_save_admin_theme']);

        // Frontend optimization AJAX handlers
        add_action('wp_ajax_sapm_save_frontend_settings', [$this, 'ajax_save_frontend_settings']);
        add_action('wp_ajax_sapm_save_frontend_rules', [$this, 'ajax_save_frontend_rules']);
        add_action('wp_ajax_sapm_save_frontend_asset_rules', [$this, 'ajax_save_frontend_asset_rules']);
        add_action('wp_ajax_sapm_get_frontend_asset_audit', [$this, 'ajax_get_frontend_asset_audit']);
        add_action('wp_ajax_sapm_get_frontend_suggestions', [$this, 'ajax_get_frontend_suggestions']);

        add_action('admin_footer', [$this, 'render_admin_drawer']);

        add_action('admin_bar_menu', [$this, 'admin_bar_info'], 999);
        add_action('admin_notices', [$this, 'show_disabled_notice']);

        if (SAPM_DEBUG) {
            add_action('admin_footer', [$this, 'debug_output']);
        }
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu(): void {
        add_options_page(
            __('Smart Plugin Manager', 'sapm'),
            __('Plugin Manager', 'sapm'),
            'manage_options',
            'smart-admin-plugin-manager',
            [$this, 'render_settings_page']
        );
    }

    /**
     * Enqueue assets for settings page
     */
    public function enqueue_assets(string $hook): void {
        if ($hook !== 'settings_page_smart-admin-plugin-manager') {
            return;
        }

        $this->enqueue_admin_assets();

        wp_localize_script('sapm-admin', 'sapmData', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('sapm_nonce'),
            'currentScreen' => $this->core->get_current_screen_id(),
            'currentMode' => $this->core->get_mode(),
            'adminTheme' => $this->get_admin_theme(),
            'strings' => [
                'saving' => __('Saving...', 'sapm'),
                'saved' => __('Saved!', 'sapm'),
                'error' => __('Error saving', 'sapm'),
                'detecting' => __('Detecting...', 'sapm'),
                'modeChanged' => __('Mode changed!', 'sapm'),
                'modeError' => __('Error changing mode', 'sapm'),
                'loading' => __('Loading...', 'sapm'),
                'noSuggestions' => __('No suggestions available yet. Sampling data is being collected.', 'sapm'),
                'suggestBlock' => __('Block', 'sapm'),
                'suggestWhitelist' => __('Whitelist', 'sapm'),
                'confidence' => __('Confidence', 'sapm'),
                'countBlock' => __('Block', 'sapm'),
                'countDelay' => __('Delay', 'sapm'),
                'countAllow' => __('Allow', 'sapm'),
                'themeSaved' => __('Theme saved!', 'sapm'),
                'themeError' => __('Error saving theme', 'sapm'),
            ],
        ]);
    }

    /**
     * Should the admin drawer be rendered?
     */
    private function should_render_admin_drawer(): bool {
        if (!is_admin() || wp_doing_ajax()) {
            return false;
        }
        if (!current_user_can('manage_options')) {
            return false;
        }
        return true;
    }

    /**
     * Enqueue assets for admin drawer
     */
    public function enqueue_drawer_assets(string $hook = ''): void {
        if (!$this->should_render_admin_drawer()) {
            return;
        }

        $data = $this->get_drawer_data();
        if (empty($data)) {
            return;
        }

        $this->enqueue_drawer_assets_files();

        wp_localize_script('sapm-drawer', 'SAPM_DRAWER', $data);
    }

    private function enqueue_admin_assets(): void {
        wp_enqueue_style(
            'sapm-admin',
            SAPM_PLUGIN_URL . 'assets/admin.css',
            [],
            SAPM_VERSION
        );

        wp_enqueue_script(
            'sapm-admin',
            SAPM_PLUGIN_URL . 'assets/admin.js',
            ['jquery'],
            SAPM_VERSION,
            true
        );
    }

    private function enqueue_drawer_assets_files(): void {
        wp_enqueue_style(
            'sapm-drawer',
            SAPM_PLUGIN_URL . 'assets/drawer.css',
            [],
            SAPM_VERSION
        );

        wp_enqueue_script(
            'sapm-drawer',
            SAPM_PLUGIN_URL . 'assets/drawer.js',
            [],
            SAPM_VERSION,
            true
        );
    }

    private function get_admin_theme(): string {
        return 'light';
    }

    /**
     * Render settings page
     */
    public function render_settings_page(): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        $screen_id = $this->core->get_current_screen_early();
        $rules = $this->core->get_rules();
        $screen_definitions = $this->core->get_screen_definitions();

        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $all_plugins = get_plugins();
        $active_plugins = $this->core->get_active_plugins_raw();

        // Filter only active plugins
        $plugins_to_show = [];
        foreach ($active_plugins as $plugin_file) {
            if (isset($all_plugins[$plugin_file])) {
                $plugins_to_show[$plugin_file] = $all_plugins[$plugin_file];
            }
        }

        // Group screen definitions by group
        $grouped_screens = [];
        foreach ($screen_definitions as $def_id => $def) {
            $group = $def['group'] ?? 'other';
            $grouped_screens[$group][$def_id] = $def;
        }

        $group_labels = [
            'core' => __('WordPress Core', 'sapm'),
            'content' => __('Content (posts, pages, media)', 'sapm'),
            'woocommerce' => __('WooCommerce', 'sapm'),
            'cpt' => __('Custom Post Types', 'sapm'),
            'settings' => __('Settings', 'sapm'),
            'plugins' => __('Plugin Pages', 'sapm'),
            'other' => __('Other', 'sapm'),
        ];

        $current_tab = sanitize_key($_GET['sapm_tab'] ?? 'admin');
        if (!in_array($current_tab, ['admin', 'frontend', 'optimizer'], true)) {
            $current_tab = 'admin';
        }

        $settings_page_url = admin_url('options-general.php?page=smart-admin-plugin-manager');
        $admin_tab_url = add_query_arg(['sapm_tab' => 'admin'], $settings_page_url);
        $frontend_tab_url = add_query_arg(['sapm_tab' => 'frontend'], $settings_page_url);
        $optimizer_tab_url = add_query_arg(['sapm_tab' => 'optimizer'], $settings_page_url);
        $admin_theme = $this->get_admin_theme();
        $wrap_classes = [
            'wrap',
            'sapm-wrap',
            'sapm-admin-tab-' . $current_tab,
        ];
        if ($current_tab === 'admin') {
            $wrap_classes[] = 'sapm-admin-theme-' . $admin_theme;
        }

        ?>
        <div class="<?php echo esc_attr(implode(' ', $wrap_classes)); ?>" data-sapm-admin-theme="<?php echo esc_attr($admin_theme); ?>">
            <h1><?php _e('Smart Admin Plugin Manager', 'sapm'); ?></h1>

            <div class="sapm-section-switch" role="navigation" aria-label="<?php esc_attr_e('Settings section switcher', 'sapm'); ?>">
                <a href="<?php echo esc_url($admin_tab_url); ?>"
                   class="sapm-section-switch__item <?php echo $current_tab === 'admin' ? 'is-active' : ''; ?>">
                    <span class="sapm-section-switch__title">
                        <span class="dashicons dashicons-admin-generic" aria-hidden="true"></span>
                        <?php _e('Administration', 'sapm'); ?>
                    </span>
                    <span class="sapm-section-switch__desc"><?php _e('Plugin rules by admin page type', 'sapm'); ?></span>
                </a>
                <a href="<?php echo esc_url($frontend_tab_url); ?>"
                   class="sapm-section-switch__item <?php echo $current_tab === 'frontend' ? 'is-active' : ''; ?>">
                    <span class="sapm-section-switch__title">
                        <span class="dashicons dashicons-admin-site-alt3" aria-hidden="true"></span>
                        <?php _e('Frontend', 'sapm'); ?>
                    </span>
                    <span class="sapm-section-switch__desc"><?php _e('Rules for public pages and assets', 'sapm'); ?></span>
                </a>
                <a href="<?php echo esc_url($optimizer_tab_url); ?>"
                   class="sapm-section-switch__item <?php echo $current_tab === 'optimizer' ? 'is-active' : ''; ?>">
                    <span class="sapm-section-switch__title">
                        <span class="dashicons dashicons-update" aria-hidden="true"></span>
                        <?php _e('Update Optimizer', 'sapm'); ?>
                    </span>
                    <span class="sapm-section-switch__desc"><?php _e('Control update checks and updater endpoints', 'sapm'); ?></span>
                </a>
            </div>

            <?php if ($current_tab === 'admin'): ?>

            <div class="sapm-current-screen">
                <strong><?php _e('Current screen:', 'sapm'); ?></strong>
                <code><?php echo esc_html($screen_id); ?></code>
                <?php
                $matched = $this->core->match_screen_definition($screen_id);
                if ($matched):
                ?>
                    → <code><?php echo esc_html($matched); ?></code>
                    (<?php echo esc_html($screen_definitions[$matched]['label']); ?>)
                <?php endif; ?>
                <br><br>
                <strong><?php _e('Disabled in this request:', 'sapm'); ?></strong>
                <?php
                $disabled = $this->core->get_disabled_this_request();
                if (empty($disabled)) {
                    echo '<em>' . __('None', 'sapm') . '</em>';
                } else {
                    foreach ($disabled as $p) {
                        $name = $all_plugins[$p]['Name'] ?? $p;
                        echo '<code>' . esc_html($name) . '</code> ';
                    }
                }
                ?>
                <br><br>
                <strong><?php _e('Deferred in this request:', 'sapm'); ?></strong>
                <?php
                $deferred = $this->core->get_deferred_this_request();
                if (empty($deferred)) {
                    echo '<em>' . __('None', 'sapm') . '</em>';
                } else {
                    foreach ($deferred as $p) {
                        $name = $all_plugins[$p]['Name'] ?? $p;
                        echo '<code>' . esc_html($name) . '</code> ';
                    }
                }
                ?>
            </div>

            <div class="sapm-notice info">
                <strong><?php _e('How it works:', 'sapm'); ?></strong>
                <?php _e('Primary rules in a group apply to all its subcategories. Subcategories only show plugins with active rules (inherited or overridden). Click a plugin to change its state for that screen. Green = always load, Red = never load, Orange = defer, Gray = default (load). Changes are saved automatically.', 'sapm'); ?>
                <br><br>
                <strong><?php _e('Safelist:', 'sapm'); ?></strong>
                <?php _e('AJAX, REST API, Cron and WP-CLI requests always load all plugins.', 'sapm'); ?>
            </div>

            <!-- Mode Switcher -->
            <div class="sapm-mode-switcher">
                <strong><?php _e('Rules mode:', 'sapm'); ?></strong>
                <?php $current_mode = $this->core->get_mode(); ?>
                <label>
                    <input type="radio" name="sapm_mode" value="manual" <?php checked($current_mode, 'manual'); ?>>
                    <?php _e('Manual', 'sapm'); ?>
                    <span>(<?php _e('manual rule settings', 'sapm'); ?>)</span>
                </label>
                <label>
                    <input type="radio" name="sapm_mode" value="auto" <?php checked($current_mode, 'auto'); ?>>
                    <?php _e('Auto', 'sapm'); ?>
                    <span>(<?php _e('automatic suggestions based on sampling data', 'sapm'); ?>)</span>
                </label>
                <span id="sapm-mode-status"></span>
                
                <div id="sapm-auto-suggestions" style="display: none;">
                    <strong><?php _e('Automatic suggestions:', 'sapm'); ?></strong>
                    <p><?php _e('Based on sampling data, the following rules are suggested. You can accept or modify them.', 'sapm'); ?></p>
                    <div id="sapm-suggestions-content"></div>
                    <div class="sapm-auto-suggestions-actions">
                        <button type="button" id="sapm-apply-suggestions" class="button button-primary"><?php _e('Apply suggestions', 'sapm'); ?></button>
                        <button type="button" id="sapm-refresh-suggestions" class="button"><?php _e('Refresh suggestions', 'sapm'); ?></button>
                        <button type="button" id="sapm-reset-auto-data" class="button"><?php _e('Reset Auto data', 'sapm'); ?></button>
                    </div>
                </div>
            </div>

            <div class="sapm-quick-actions">
                <input type="text" id="sapm-filter" class="sapm-filter-input" placeholder="<?php esc_attr_e('Filter plugins...', 'sapm'); ?>">
                <button type="button" id="sapm-reset-all" class="button"><?php _e('Reset all rules', 'sapm'); ?></button>
                <span id="sapm-save-status" style="line-height: 30px; margin-left: 10px; color: #46b450;"></span>
            </div>

            <?php foreach ($grouped_screens as $group => $screens): ?>
            <div class="sapm-screen-group">
                <div class="sapm-group-header">
                    <h3>
                        <span class="sapm-group-title-text"><?php echo esc_html($group_labels[$group] ?? ucfirst($group)); ?></span>
                        <span class="sapm-group-header-stats" aria-live="polite">
                            <span class="sapm-group-stat is-block"><strong>0</strong><em><?php esc_html_e('Block', 'sapm'); ?></em></span>
                            <span class="sapm-group-stat is-delay"><strong>0</strong><em><?php esc_html_e('Delay', 'sapm'); ?></em></span>
                            <span class="sapm-group-stat is-allow"><strong>0</strong><em><?php esc_html_e('Allow', 'sapm'); ?></em></span>
                        </span>
                        <span class="dashicons dashicons-arrow-down-alt2 sapm-collapse-icon"></span>
                    </h3>
                </div>
                <div class="sapm-group-content">
                    <?php
                        $group_key = '_group_' . sanitize_key($group);
                        $group_rules = $rules[$group_key] ?? [];
                    ?>
                    <div class="sapm-screen-row sapm-screen-row-group" data-screen-id="<?php echo esc_attr($group_key); ?>">
                        <div class="sapm-screen-name">
                            <?php echo esc_html(sprintf(__('Primary rules (%s)', 'sapm'), $group_labels[$group] ?? ucfirst($group))); ?>
                            <br><small style="color: #666;"><?php _e('Applies to all subcategories in this group', 'sapm'); ?></small>
                        </div>
                        <div class="sapm-screen-plugins">
                            <?php foreach ($plugins_to_show as $plugin_file => $plugin_data):
                                $state = $this->core->get_rule_state($group_rules, $plugin_file) ?? 'default';
                                $icon = $state === 'enabled' ? 'yes' : ($state === 'disabled' ? 'no' : ($state === 'defer' ? 'clock' : 'minus'));
                            ?>
                            <span class="sapm-plugin-tag <?php echo esc_attr($state); ?>"
                                  data-plugin="<?php echo esc_attr($plugin_file); ?>"
                                  data-state="<?php echo esc_attr($state); ?>"
                                  title="<?php echo esc_attr($plugin_file); ?>">
                                <span class="dashicons dashicons-<?php echo esc_attr($icon); ?>"></span>
                                <?php echo esc_html($plugin_data['Name']); ?>
                            </span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php foreach ($screens as $def_id => $def):
                        // For dynamic screens, skip if not relevant
                        if (!empty($def['dynamic']) && !$this->has_relevant_content($def_id)) {
                            continue;
                        }

                        $screen_rules = $rules[$def_id] ?? [];
                    ?>
                    <div class="sapm-screen-row" data-screen-id="<?php echo esc_attr($def_id); ?>">
                        <div class="sapm-screen-name">
                            <?php echo esc_html($def['label']); ?>
                            <?php if (!empty($def['always_all'])): ?>
                                <br><small style="color: #666;"><?php _e('(always all plugins)', 'sapm'); ?></small>
                            <?php endif; ?>
                        </div>
                        <div class="sapm-screen-plugins">
                            <?php if (empty($def['always_all'])): ?>
                                <?php
                                    $has_rules = false;
                                    foreach ($plugins_to_show as $plugin_file => $plugin_data):
                                        $screen_state = $this->core->get_rule_state($screen_rules, $plugin_file);
                                        $inherited = $this->core->get_inherited_rule($plugin_file, $def_id);
                                        $inherited_state = $screen_state === null ? ($inherited['state'] ?? null) : null;

                                        if ($screen_state === null && $inherited_state === null) {
                                            continue;
                                        }

                                        $has_rules = true;
                                        $display_state = $screen_state ?? $inherited_state;
                                        $icon = $display_state === 'enabled' ? 'yes' : ($display_state === 'disabled' ? 'no' : ($display_state === 'defer' ? 'clock' : 'minus'));
                                        $state_attr = $screen_state ?? 'default';
                                        $classes = 'sapm-plugin-tag';
                                        if ($screen_state !== null) {
                                            $classes .= ' ' . $screen_state;
                                        } else {
                                            $classes .= ' inherited';
                                        }
                                ?>
                                <span class="<?php echo esc_attr($classes); ?>"
                                      data-plugin="<?php echo esc_attr($plugin_file); ?>"
                                      data-state="<?php echo esc_attr($state_attr); ?>"
                                      <?php if ($inherited_state !== null): ?>data-inherited-state="<?php echo esc_attr($inherited_state); ?>"<?php endif; ?>
                                      title="<?php echo esc_attr($plugin_file); ?>">
                                    <span class="dashicons dashicons-<?php echo esc_attr($icon); ?>"></span>
                                    <?php echo esc_html($plugin_data['Name']); ?>
                                </span>
                                <?php endforeach; ?>
                                <?php if (!$has_rules): ?>
                                    <em style="color: #666;"><?php _e('No rules. Set them in the primary group or add an exception here.', 'sapm'); ?></em>
                                <?php endif; ?>
                            <?php else: ?>
                                <em style="color: #666;"><?php _e('All plugins are always loaded on this screen', 'sapm'); ?></em>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>

            <?php $this->render_request_type_settings($plugins_to_show); ?>

            <?php endif; ?>

            <?php if ($current_tab === 'frontend'): ?>
            <?php $this->render_frontend_tab(); ?>

            <?php endif; ?>

            <?php if ($current_tab === 'optimizer'): ?>

            <?php $this->render_update_optimizer_settings(); ?>

            <?php endif; ?>

            <?php if ($current_tab === 'admin'): ?>

            <div class="sapm-stats">
                <h3><?php _e('Statistics', 'sapm'); ?></h3>
                <p>
                    <strong><?php _e('Active plugins:', 'sapm'); ?></strong> <?php echo count($active_plugins); ?><br>
                    <strong><?php _e('Defined screens:', 'sapm'); ?></strong> <?php echo count($screen_definitions); ?><br>
                    <strong><?php _e('Custom rules:', 'sapm'); ?></strong> <?php echo $this->count_rules($rules); ?>
                </p>
            </div>

            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Render settings for request type (AJAX/REST/Cron/CLI)
     */
    private function render_request_type_settings(array $plugins_to_show): void {
        $request_type_rules = $this->core->get_request_type_rules();
        $types = [
            'ajax' => [
                'label' => __('AJAX Requests', 'sapm'),
                'desc' => __('Requests to admin-ajax.php', 'sapm'),
                'icon' => 'update',
                'supports_detection' => true,
                'detection_label' => __('Detect by action parameter', 'sapm'),
            ],
            'rest' => [
                'label' => __('REST API', 'sapm'),
                'desc' => __('Requests to /wp-json/*', 'sapm'),
                'icon' => 'rest-api',
                'supports_detection' => true,
                'detection_label' => __('Detect by namespace', 'sapm'),
            ],
            'cron' => [
                'label' => __('WP-Cron', 'sapm'),
                'desc' => __('Scheduled background tasks', 'sapm'),
                'icon' => 'clock',
                'supports_detection' => false,
            ],
            'cli' => [
                'label' => __('WP-CLI', 'sapm'),
                'desc' => __('Command line commands', 'sapm'),
                'icon' => 'editor-code',
                'supports_detection' => false,
            ],
        ];
        ?>
        <div class="sapm-screen-group sapm-request-types" id="sapm-request-types">
            <div class="sapm-group-header">
                <h3>
                    <span class="dashicons dashicons-networking"></span>
                    <span class="sapm-group-title-text"><?php _e('Request Type Settings (AJAX/REST/Cron/CLI)', 'sapm'); ?></span>
                    <span class="dashicons dashicons-arrow-down-alt2 sapm-collapse-icon"></span>
                </h3>
            </div>
            <div class="sapm-group-content">
                
                <div class="sapm-rt-warning-box">
                    <div class="sapm-rt-warning-icon">
                        <span class="dashicons dashicons-warning"></span>
                    </div>
                    <div class="sapm-rt-warning-content">
                         <strong><?php _e('Warning: Advanced Configuration', 'sapm'); ?></strong>
                         <p><?php _e('Incorrect settings may cause AJAX functions, REST API or cron jobs to malfunction. We recommend leaving the default "Passthrough" if you are not sure.', 'sapm'); ?></p>
                    </div>
                </div>

                <div class="sapm-rt-grid">
                <?php foreach ($types as $type_id => $type_info):
                    $type_rules = $request_type_rules[$type_id] ?? [];
                    $mode = $type_rules['_mode'] ?? 'passthrough';
                    $disabled_plugins = $type_rules['disabled_plugins'] ?? [];
                    $default_plugins = $type_rules['default_plugins'] ?? [];
                    
                    // Determine badge class and text
                    $badge_class = 'mode-' . $mode;
                    $badge_text = '';
                    switch($mode) {
                        case 'passthrough': $badge_text = __('Passthrough', 'sapm'); break;
                        case 'blacklist': $badge_text = __('Blacklist', 'sapm'); break;
                        case 'whitelist': $badge_text = __('Whitelist', 'sapm'); break;
                    }
                ?>
                <details class="sapm-rt-card">
                    <summary class="sapm-rt-card-header">
                        <div class="sapm-rt-card-title">
                            <div class="sapm-rt-icon-wrapper">
                                <span class="dashicons dashicons-<?php echo esc_attr($type_info['icon']); ?>"></span>
                            </div>
                            <div class="sapm-rt-title-text">
                                <strong><?php echo esc_html($type_info['label']); ?></strong>
                                <span class="sapm-rt-desc"><?php echo esc_html($type_info['desc']); ?></span>
                            </div>
                        </div>
                        <div class="sapm-rt-header-meta">
                            <span class="sapm-mode-pill <?php echo esc_attr($badge_class); ?>">
                                <?php echo esc_html($badge_text); ?>
                            </span>
                            <span class="dashicons dashicons-arrow-down-alt2 sapm-rt-toggle-icon"></span>
                        </div>
                    </summary>
                    
                    <div class="sapm-rt-card-body" data-request-type="<?php echo esc_attr($type_id); ?>">
                        <div class="sapm-rt-mode-selector">
                            <label class="sapm-rt-radio-option <?php echo $mode === 'passthrough' ? 'selected' : ''; ?>">
                                <input type="radio" name="sapm_rt_mode_<?php echo esc_attr($type_id); ?>" 
                                       value="passthrough" <?php checked($mode, 'passthrough'); ?>
                                       class="sapm-rt-mode" data-type="<?php echo esc_attr($type_id); ?>">
                                <div class="sapm-rt-radio-visual">
                                    <svg class="sapm-radio-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <circle cx="12" cy="12" r="10"></circle>
                                        <path class="check" d="M8 12l3 3 5-5"></path>
                                    </svg>
                                </div>
                                <div class="sapm-rt-radio-content">
                                    <strong><?php _e('Passthrough', 'sapm'); ?></strong>
                                    <span class="meta"><?php _e('Load all plugins (Default)', 'sapm'); ?></span>
                                </div>
                            </label>

                            <label class="sapm-rt-radio-option <?php echo $mode === 'blacklist' ? 'selected' : ''; ?>">
                                <input type="radio" name="sapm_rt_mode_<?php echo esc_attr($type_id); ?>" 
                                       value="blacklist" <?php checked($mode, 'blacklist'); ?>
                                       class="sapm-rt-mode" data-type="<?php echo esc_attr($type_id); ?>">
                                <div class="sapm-rt-radio-visual">
                                    <svg class="sapm-radio-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <circle cx="12" cy="12" r="10"></circle>
                                        <path class="check" d="M8 12l3 3 5-5"></path>
                                    </svg>
                                </div>
                                <div class="sapm-rt-radio-content">
                                    <strong><?php _e('Blacklist', 'sapm'); ?></strong>
                                    <span class="meta"><?php _e('Block selected plugins', 'sapm'); ?></span>
                                </div>
                            </label>

                            <label class="sapm-rt-radio-option <?php echo $mode === 'whitelist' ? 'selected' : ''; ?>">
                                <input type="radio" name="sapm_rt_mode_<?php echo esc_attr($type_id); ?>" 
                                       value="whitelist" <?php checked($mode, 'whitelist'); ?>
                                       class="sapm-rt-mode" data-type="<?php echo esc_attr($type_id); ?>">
                                <div class="sapm-rt-radio-visual">
                                    <svg class="sapm-radio-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <circle cx="12" cy="12" r="10"></circle>
                                        <path class="check" d="M8 12l3 3 5-5"></path>
                                    </svg>
                                </div>
                                <div class="sapm-rt-radio-content">
                                    <strong><?php _e('Whitelist', 'sapm'); ?></strong>
                                    <span class="meta"><?php _e('Allow only selected', 'sapm'); ?></span>
                                </div>
                            </label>
                        </div>

                        <div class="sapm-rt-config-area">
                            <div class="sapm-rt-blacklist-config" style="display: <?php echo $mode === 'blacklist' ? 'block' : 'none'; ?>;">
                                <div class="sapm-rt-config-header danger">
                                    <span class="dashicons dashicons-no-alt"></span>
                                    <?php _e('Select plugins to BLOCK:', 'sapm'); ?>
                                </div>
                                <div class="sapm-rt-plugin-list">
                                    <?php foreach ($plugins_to_show as $plugin_file => $plugin_data):
                                        $is_disabled = in_array($plugin_file, $disabled_plugins, true);
                                    ?>
                                    <label class="sapm-rt-plugin-check <?php echo $is_disabled ? 'checked' : ''; ?>">
                                        <input type="checkbox" class="sapm-rt-disabled-plugin" 
                                               data-type="<?php echo esc_attr($type_id); ?>"
                                               value="<?php echo esc_attr($plugin_file); ?>"
                                               <?php checked($is_disabled); ?>>
                                        <span class="check-box"></span>
                                        <span class="plugin-name"><?php echo esc_html($plugin_data['Name']); ?></span>
                                    </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <div class="sapm-rt-whitelist-config" style="display: <?php echo $mode === 'whitelist' ? 'block' : 'none'; ?>;">
                                <div class="sapm-rt-config-header success">
                                    <span class="dashicons dashicons-yes-alt"></span>
                                    <?php _e('Select plugins to ALLOW:', 'sapm'); ?>
                                </div>
                                <div class="sapm-rt-plugin-list">
                                    <?php foreach ($plugins_to_show as $plugin_file => $plugin_data):
                                        $is_enabled = in_array($plugin_file, $default_plugins, true);
                                    ?>
                                    <label class="sapm-rt-plugin-check <?php echo $is_enabled ? 'checked' : ''; ?>">
                                        <input type="checkbox" class="sapm-rt-enabled-plugin" 
                                               data-type="<?php echo esc_attr($type_id); ?>"
                                               value="<?php echo esc_attr($plugin_file); ?>"
                                               <?php checked($is_enabled); ?>>
                                        <span class="check-box"></span>
                                        <span class="plugin-name"><?php echo esc_html($plugin_data['Name']); ?></span>
                                    </label>
                                    <?php endforeach; ?>
                                </div>

                                <?php if (!empty($type_info['supports_detection'])): ?>
                                <div class="sapm-rt-detection-option">
                                    <label>
                                        <input type="checkbox" class="sapm-rt-detection" 
                                               data-type="<?php echo esc_attr($type_id); ?>"
                                               <?php checked(!empty($type_rules['_detect_by_action']) || !empty($type_rules['_detect_by_namespace'])); ?>>
                                        <strong><?php echo esc_html($type_info['detection_label']); ?></strong>
                                        <p><?php _e('Automatically adds required plugins based on request context (e.g. WooCommerce for woocommerce_* actions).', 'sapm'); ?></p>
                                    </label>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </details>
                <?php endforeach; ?>
                </div>

                <div class="sapm-rt-footer-actions">
                    <button type="button" id="sapm-save-request-types" class="button button-primary button-hero">
                        <span class="dashicons dashicons-saved"></span>
                        <?php _e('Save Request Type Settings', 'sapm'); ?>
                    </button>
                    <span id="sapm-rt-save-status"></span>
                </div>

                <!-- Request Type Performance Sampling Section -->
                <div class="sapm-rt-performance-card">
                    <div class="sapm-rt-perf-header">
                        <div class="sapm-rt-perf-icon">
                            <span class="dashicons dashicons-chart-bar"></span>
                        </div>
                        <div class="sapm-rt-perf-title">
                            <h4><?php _e('Per-Plugin Performance Measurement (Sampling)', 'sapm'); ?></h4>
                            <p><?php _e('Measures individual plugin performance during requests using random sampling (10% of requests). helps decide optimization.', 'sapm'); ?></p>
                        </div>
                    </div>
                    <div class="sapm-rt-perf-actions">
                        <button type="button" id="sapm-load-rt-performance" class="button button-secondary">
                            <span class="dashicons dashicons-update"></span>
                            <?php _e('Load data', 'sapm'); ?>
                        </button>
                        <button type="button" id="sapm-clear-rt-performance" class="button button-link-delete">
                            <span class="dashicons dashicons-trash"></span>
                            <?php _e('Delete data', 'sapm'); ?>
                        </button>
                    </div>
                    <div id="sapm-rt-performance-container" style="display: none;">
                        <!-- Data will be loaded via AJAX -->
                    </div>
                </div>

            </div>
        </div>
        
        <script>
        jQuery(function($) {
            // Update UI when radio changes
            $(document).on('change', '.sapm-rt-mode', function() {
                var $card = $(this).closest('.sapm-rt-card');
                var val = $(this).val();
                var $options = $card.find('.sapm-rt-radio-option');
                
                $options.removeClass('selected');
                $(this).closest('.sapm-rt-radio-option').addClass('selected');
                
                // Update badge in summary
                var $badge = $card.find('.sapm-mode-pill');
                $badge.removeClass('mode-passthrough mode-blacklist mode-whitelist').addClass('mode-' + val);
                var label = $(this).closest('label').find('strong').text();
                $badge.text(label);
                
                // Show/hide content areas
                $card.find('.sapm-rt-blacklist-config').slideUp(200);
                $card.find('.sapm-rt-whitelist-config').slideUp(200);
                
                if (val === 'blacklist') {
                    $card.find('.sapm-rt-blacklist-config').slideDown(200);
                } else if (val === 'whitelist') {
                    $card.find('.sapm-rt-whitelist-config').slideDown(200);
                }
            });
            
            // Highlight checked plugins
            $(document).on('change', '.sapm-rt-plugin-check input', function() {
                if($(this).is(':checked')) {
                    $(this).closest('.sapm-rt-plugin-check').addClass('checked');
                } else {
                    $(this).closest('.sapm-rt-plugin-check').removeClass('checked');
                }
            });
        });
        </script>
        <?php
    }

    /**
     * Render settings for Update Optimizer
     */
    private function render_update_optimizer_settings(): void {
        $optimizer = SAPM_Update_Optimizer::get_instance();
        $config = $optimizer->get_config();
        $stats = $optimizer->get_stats();
        
        $strategies = [
            'ttl_extension' => [
                'label' => __('Extended cache', 'sapm'),
                'desc' => __('Extends update cache validity from 12h to your chosen value.', 'sapm'),
            ],
            'page_specific' => [
                'label' => __('Check on selected pages', 'sapm'),
                'desc' => __('HTTP checks only on update pages (update-core.php, plugins.php).', 'sapm'),
            ],
            'cron_only' => [
                'label' => __('Background only (Cron)', 'sapm'),
                'desc' => __('All update checks run in background via WP-Cron. Most aggressive option.', 'sapm'),
            ],
        ];
        
        $cron_intervals = [
            'hourly' => __('Every hour', 'sapm'),
            'twicedaily' => __('Twice daily', 'sapm'),
            'daily' => __('Once daily', 'sapm'),
        ];
        
        // Format last check time
        $last_check = $stats['last_plugin_check'] ?? 0;
        $next_cron = $stats['next_cron_check'] ?? 0;
        ?>
        <div class="sapm-screen-group sapm-update-optimizer" id="sapm-update-optimizer">
            <div class="sapm-group-header">
                <h3>
                    <span class="dashicons dashicons-update"></span>
                    <span class="sapm-group-title-text"><?php _e('Update Optimizer', 'sapm'); ?></span>
                    <?php if ($config['enabled']): ?>
                        <span class="sapm-mode-pill mode-whitelist"><?php _e('Active', 'sapm'); ?></span>
                    <?php endif; ?>
                    <span class="dashicons dashicons-arrow-down-alt2 sapm-collapse-icon"></span>
                </h3>
            </div>
            <div class="sapm-group-content">
                <div class="sapm-notice info">
                    <strong><?php _e('💡 Tip:', 'sapm'); ?></strong>
                    <?php _e('WordPress by default checks for updates on EVERY admin page load. Update Optimizer blocks these HTTP requests outside of dedicated pages, significantly speeding up admin.', 'sapm'); ?>
                </div>

                <div class="sapm-screen-row">
                    <div class="sapm-screen-name">
                        <?php _e('Enable Update Optimizer', 'sapm'); ?>
                        <small><?php _e('Blocks update checks outside dedicated update pages.', 'sapm'); ?></small>
                    </div>
                    <div class="sapm-screen-plugins">
                        <label class="sapm-rt-plugin-check <?php echo !empty($config['enabled']) ? 'checked' : ''; ?>">
                            <input type="checkbox" id="sapm-uo-enabled" <?php checked($config['enabled']); ?>>
                            <span class="check-box"></span>
                            <span class="plugin-name"><?php _e('Enabled', 'sapm'); ?></span>
                        </label>
                    </div>
                </div>

                <div class="sapm-setting-row">
                    <h4>
                        <span class="dashicons dashicons-admin-settings"></span>
                        <?php _e('Optimization strategy', 'sapm'); ?>
                    </h4>
                    <div class="sapm-rt-mode-selector">
                        <?php foreach ($strategies as $strategy_id => $strategy_info): ?>
                        <label class="sapm-rt-radio-option sapm-uo-strategy-option <?php echo $config['strategy'] === $strategy_id ? 'selected' : ''; ?>">
                            <input type="radio" name="sapm_uo_strategy" value="<?php echo esc_attr($strategy_id); ?>"
                                   <?php checked($config['strategy'], $strategy_id); ?> class="sapm-uo-strategy">
                            <div class="sapm-rt-radio-visual">
                                <svg class="sapm-radio-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <circle cx="12" cy="12" r="10"></circle>
                                    <path class="check" d="M8 12l3 3 5-5"></path>
                                </svg>
                            </div>
                            <div class="sapm-rt-radio-content">
                                <div class="sapm-rt-radio-heading">
                                    <strong><?php echo esc_html($strategy_info['label']); ?></strong>
                                    <?php if ($strategy_id === 'cron_only'): ?>
                                        <span class="sapm-mode-pill mode-whitelist"><?php _e('Recommended', 'sapm'); ?></span>
                                    <?php endif; ?>
                                </div>
                                <span class="meta"><?php echo esc_html($strategy_info['desc']); ?></span>
                            </div>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="sapm-setting-row sapm-uo-ttl-settings" style="<?php echo ($config['strategy'] !== 'ttl_extension') ? 'display:none;' : ''; ?>">
                    <h4>
                        <span class="dashicons dashicons-clock"></span>
                        <?php _e('TTL Extension', 'sapm'); ?>
                    </h4>
                    <div class="sapm-screen-row">
                        <div class="sapm-screen-name">
                            <?php _e('Cache valid for', 'sapm'); ?>
                        </div>
                        <div class="sapm-screen-plugins">
                            <select id="sapm-uo-ttl-hours">
                            <option value="12" <?php selected($config['ttl_hours'], 12); ?>>12 <?php _e('hours', 'sapm'); ?></option>
                            <option value="24" <?php selected($config['ttl_hours'], 24); ?>>24 <?php _e('hours', 'sapm'); ?></option>
                            <option value="48" <?php selected($config['ttl_hours'], 48); ?>>48 <?php _e('hours', 'sapm'); ?></option>
                            <option value="72" <?php selected($config['ttl_hours'], 72); ?>>72 <?php _e('hours', 'sapm'); ?></option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="sapm-setting-row sapm-uo-cron-settings" style="<?php echo ($config['strategy'] !== 'cron_only') ? 'display:none;' : ''; ?>">
                    <h4>
                        <span class="dashicons dashicons-backup"></span>
                        <?php _e('Cron Check Interval', 'sapm'); ?>
                    </h4>
                    <div class="sapm-screen-row">
                        <div class="sapm-screen-name">
                            <?php _e('Check for updates', 'sapm'); ?>
                        </div>
                        <div class="sapm-screen-plugins">
                            <select id="sapm-uo-cron-interval">
                            <?php foreach ($cron_intervals as $interval_id => $interval_label): ?>
                            <option value="<?php echo esc_attr($interval_id); ?>" <?php selected($config['cron_interval'] ?? 'twicedaily', $interval_id); ?>>
                                <?php echo esc_html($interval_label); ?>
                            </option>
                            <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="sapm-setting-row sapm-updater-section">
                    <h4>
                        <span class="dashicons dashicons-shield-alt"></span>
                        <?php _e('Plugin Updater Blocking', 'sapm'); ?>
                    </h4>
                    <p>
                        <span class="dashicons dashicons-info"></span>
                        <?php _e('List of blocked endpoints for plugins installed on this site. Click to change state: red = blocked, green = allowed.', 'sapm'); ?>
                    </p>
                    <?php 
                    // Get only endpoints of installed plugins
                    $installed_updaters = SAPM_Update_Optimizer::get_installed_plugin_updaters();
                    $whitelist = $config['plugin_updater_whitelist'] ?? [];
                    ?>
                    
                    <?php if (!empty($installed_updaters)): ?>
                    <div class="sapm-updater-endpoints">
                        <?php foreach ($installed_updaters as $endpoint => $plugin_name): 
                            $is_whitelisted = in_array($endpoint, $whitelist, true);
                            $state_class = $is_whitelisted ? 'sapm-state-allowed' : 'sapm-state-blocked';
                            $icon = $is_whitelisted ? 'yes' : 'no';
                        ?>
                        <div class="sapm-updater-endpoint <?php echo esc_attr($state_class); ?>" 
                             data-endpoint="<?php echo esc_attr($endpoint); ?>"
                             data-whitelisted="<?php echo $is_whitelisted ? '1' : '0'; ?>"
                             title="<?php echo esc_attr($endpoint); ?>">
                            <span class="dashicons dashicons-<?php echo esc_attr($icon); ?>"></span>
                            <span class="sapm-updater-name"><?php echo esc_html($plugin_name); ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php else: ?>
                    <p class="sapm-updater-empty">
                        <span class="dashicons dashicons-info-outline"></span>
                        <?php _e('No known plugin updaters detected for installed plugins.', 'sapm'); ?>
                    </p>
                    <?php endif; ?>
                    
                    <!-- Custom endpoints -->
                    <div class="sapm-custom-endpoints">
                        <label>
                            <?php _e('Custom endpoints to block (one per line):', 'sapm'); ?>
                            <textarea id="sapm-uo-custom-blacklist" rows="2" placeholder="example.com/api/license"><?php 
                                $all_known_endpoints = array_keys(SAPM_Update_Optimizer::get_known_plugin_updaters());
                                $current_blacklist = $config['plugin_updater_blacklist'] ?? [];
                                $custom_endpoints = array_diff($current_blacklist, $all_known_endpoints);
                                echo esc_textarea(implode("\n", $custom_endpoints));
                            ?></textarea>
                        </label>
                        <small><?php _e('For plugins not listed above.', 'sapm'); ?></small>
                    </div>
                </div>

                <div class="sapm-screen-row">
                    <div class="sapm-screen-name">
                        <?php _e('Show "Stale data" indicator', 'sapm'); ?>
                        <small><?php _e('Displays when update data were last checked.', 'sapm'); ?></small>
                    </div>
                    <div class="sapm-screen-plugins">
                        <label class="sapm-rt-plugin-check <?php echo !empty($config['show_stale_indicator']) ? 'checked' : ''; ?>">
                            <input type="checkbox" id="sapm-uo-stale-indicator" <?php checked($config['show_stale_indicator']); ?>>
                            <span class="check-box"></span>
                            <span class="plugin-name"><?php _e('Enabled', 'sapm'); ?></span>
                        </label>
                    </div>
                </div>

                <div class="sapm-screen-group sapm-uo-stats-group">
                    <div class="sapm-group-header">
                        <h3>
                            <span class="dashicons dashicons-chart-bar"></span>
                            <span class="sapm-group-title-text"><?php _e('Statistics', 'sapm'); ?></span>
                            <span class="dashicons dashicons-arrow-down-alt2 sapm-collapse-icon"></span>
                        </h3>
                    </div>
                    <div class="sapm-group-content">
                        <div class="sapm-screen-row">
                            <div class="sapm-screen-name"><?php _e('Last check', 'sapm'); ?></div>
                            <div class="sapm-screen-plugins">
                                <span class="sapm-last-check-value">
                                <?php if ($last_check): ?>
                                    <?php echo esc_html(human_time_diff($last_check) . ' ' . __('ago', 'sapm')); ?>
                                    <small><?php echo esc_html(date_i18n('j.n.Y H:i', $last_check)); ?></small>
                                <?php else: ?>
                                    <?php _e('Never', 'sapm'); ?>
                                <?php endif; ?>
                                </span>
                            </div>
                        </div>
                        <div class="sapm-screen-row">
                            <div class="sapm-screen-name"><?php _e('Next cron check', 'sapm'); ?></div>
                            <div class="sapm-screen-plugins">
                                <?php if ($next_cron): ?>
                                    <span><?php echo esc_html(human_time_diff($next_cron)); ?></span>
                                    <small><?php echo esc_html(date_i18n('j.n.Y H:i', $next_cron)); ?></small>
                                <?php else: ?>
                                    <span><?php _e('Not scheduled', 'sapm'); ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="sapm-screen-row">
                            <div class="sapm-screen-name"><?php _e('Available updates', 'sapm'); ?></div>
                            <div class="sapm-screen-plugins">
                                <span class="sapm-updates-count"><?php echo intval($stats['plugin_updates_cached']); ?> <?php _e('plugins', 'sapm'); ?> + <?php echo intval($stats['theme_updates_cached']); ?> <?php _e('themes', 'sapm'); ?> + <?php echo intval($stats['translation_updates_cached'] ?? 0); ?> <?php _e('translations', 'sapm'); ?></span>
                            </div>
                        </div>
                        <div class="sapm-screen-row">
                            <div class="sapm-screen-name"><?php _e('Blocked this request', 'sapm'); ?></div>
                            <div class="sapm-screen-plugins"><?php echo intval($stats['blocked_this_request']); ?> <?php _e('HTTP requests', 'sapm'); ?></div>
                        </div>
                    </div>
                </div>

                <div class="sapm-rt-footer-actions">
                    <button type="button" id="sapm-uo-save" class="button button-primary button-hero">
                        <span class="dashicons dashicons-saved"></span>
                        <?php _e('Save settings', 'sapm'); ?>
                    </button>
                    <button type="button" id="sapm-uo-force-check" class="button button-secondary button-hero">
                        <span class="dashicons dashicons-update"></span>
                        <?php _e('Force update check', 'sapm'); ?>
                    </button>
                    <span id="sapm-uo-status"></span>
                </div>
            </div>
        </div>
        
        <script>
        jQuery(function($) {
            function syncOptimizerCheckboxState($input) {
                var $wrapper = $input.closest('.sapm-rt-plugin-check');
                if (!$wrapper.length) {
                    return;
                }
                $wrapper.toggleClass('checked', $input.is(':checked'));
            }

            $('#sapm-update-optimizer .sapm-rt-plugin-check input[type="checkbox"]').each(function() {
                syncOptimizerCheckboxState($(this));
            });

            $(document).on('change', '#sapm-update-optimizer .sapm-rt-plugin-check input[type="checkbox"]', function() {
                syncOptimizerCheckboxState($(this));
            });

            $('.sapm-uo-strategy').on('change', function() {
                var strategy = $(this).val();
                $('.sapm-uo-ttl-settings').toggle(strategy === 'ttl_extension');
                $('.sapm-uo-cron-settings').toggle(strategy === 'cron_only');
                $('.sapm-uo-strategy-option').removeClass('selected');
                $(this).closest('.sapm-uo-strategy-option').addClass('selected');
            });

            $(document).on('click', '.sapm-updater-endpoint', function(e) {
                e.preventDefault();
                e.stopPropagation();
                
                var $item = $(this);
                var currentValue = $item.attr('data-whitelisted');
                var isWhitelisted = currentValue === '1';
                var newState = !isWhitelisted;
                
                $item.attr('data-whitelisted', newState ? '1' : '0');
                $item.removeData('whitelisted');
                
                $item.removeClass('sapm-state-blocked sapm-state-allowed');
                $item.addClass(newState ? 'sapm-state-allowed' : 'sapm-state-blocked');
                
                var $icon = $item.find('.dashicons');
                $icon.removeClass('dashicons-yes dashicons-no');
                $icon.addClass(newState ? 'dashicons-yes' : 'dashicons-no');
            });

            $('#sapm-uo-save').on('click', function() {
                var $btn = $(this);
                var $status = $('#sapm-uo-status');
                $btn.prop('disabled', true).find('.dashicons').removeClass('dashicons-saved').addClass('dashicons-update spin');
                $status.text((sapmData.strings && sapmData.strings.saving) ? sapmData.strings.saving : 'Saving...').css('color', '#0073aa');
                
                var whitelist = [];
                $('.sapm-updater-endpoint').each(function() {
                    var $item = $(this);
                    var isWhitelisted = $item.attr('data-whitelisted') === '1';
                    if (isWhitelisted) {
                        whitelist.push($item.attr('data-endpoint'));
                    }
                });
                
                var customBlacklist = [];
                var customBlacklistText = $('#sapm-uo-custom-blacklist').val().trim();
                if (customBlacklistText) {
                    customBlacklistText.split('\n').forEach(function(line) {
                        line = line.trim();
                        if (line) customBlacklist.push(line);
                    });
                }
                
                var data = {
                    action: 'sapm_save_update_optimizer',
                    nonce: sapmData.nonce,
                    enabled: $('#sapm-uo-enabled').is(':checked') ? 1 : 0,
                    strategy: $('input[name="sapm_uo_strategy"]:checked').val(),
                    ttl_hours: parseInt($('#sapm-uo-ttl-hours').val()),
                    cron_interval: $('#sapm-uo-cron-interval').val(),
                    show_stale_indicator: $('#sapm-uo-stale-indicator').is(':checked') ? 1 : 0,
                    blacklist: customBlacklist,
                    whitelist: whitelist
                };
                
                $.post(ajaxurl, data, function(response) {
                    if (response.success) {
                        $btn.find('.dashicons').removeClass('dashicons-update spin').addClass('dashicons-yes-alt');
                        $status.text((sapmData.strings && sapmData.strings.saved) ? sapmData.strings.saved : 'Saved!').css('color', '#46b450');
                        setTimeout(function() {
                            $btn.find('.dashicons').removeClass('dashicons-yes-alt').addClass('dashicons-saved');
                            $status.fadeOut(300, function() {
                                $(this).text('').show();
                            });
                        }, 2000);
                    } else {
                        var errMsg = (response.data && response.data.message) ? response.data.message : (typeof response.data === 'string' ? response.data : 'Unknown error');
                        $status.text(errMsg).css('color', '#dc3232');
                    }
                }).fail(function() {
                    $status.text('Error while saving').css('color', '#dc3232');
                }).always(function() {
                    $btn.prop('disabled', false);
                    $btn.find('.dashicons').removeClass('spin');
                });
            });

            $('#sapm-uo-force-check').on('click', function() {
                var $btn = $(this);
                var $status = $('#sapm-uo-status');
                $btn.prop('disabled', true).find('.dashicons').addClass('spin');
                $status.text((sapmData.strings && sapmData.strings.loading) ? sapmData.strings.loading : 'Loading...').css('color', '#0073aa');
                
                $.post(ajaxurl, {
                    action: 'sapm_force_update_check',
                    nonce: sapmData.nonce
                }, function(response) {
                    if (response.success) {
                        var now = new Date();
                        var formatted = now.getDate() + '.' + (now.getMonth()+1) + '.' + now.getFullYear() + ' ' + 
                                      ('0'+now.getHours()).slice(-2) + ':' + ('0'+now.getMinutes()).slice(-2);
                        
                        var lastCheckHtml = '<strong>Just now</strong><br>' +
                                          '<small>' + formatted + '</small>';
                        $('.sapm-last-check-value').html(lastCheckHtml);
                        
                        var pluginCount = parseInt(response.data.plugin_count || 0, 10);
                        var themeCount = parseInt(response.data.theme_count || 0, 10);
                        var translationCount = parseInt(response.data.translation_count || 0, 10);
                        var updatesText = pluginCount + ' <?php echo esc_js(__('plugins', 'sapm')); ?>'
                            + ' + ' + themeCount + ' <?php echo esc_js(__('themes', 'sapm')); ?>'
                            + ' + ' + translationCount + ' <?php echo esc_js(__('translations', 'sapm')); ?>';
                        $('.sapm-updates-count').text(updatesText);
                        
                        $btn.find('.dashicons').removeClass('dashicons-update spin').addClass('dashicons-yes-alt');
                        $status.text((response.data && response.data.message) ? response.data.message : 'Check completed').css('color', '#46b450');
                        setTimeout(function() {
                            $btn.find('.dashicons').removeClass('dashicons-yes-alt').addClass('dashicons-update');
                            $status.fadeOut(300, function() {
                                $(this).text('').show();
                            });
                        }, 3000);
                    } else {
                        var errMsg = (response.data && response.data.message) ? response.data.message : (typeof response.data === 'string' ? response.data : 'Unknown error');
                        $status.text(errMsg).css('color', '#dc3232');
                    }
                }).fail(function() {
                    $status.text('Error during check').css('color', '#dc3232');
                }).always(function() {
                    $btn.prop('disabled', false).find('.dashicons').removeClass('spin');
                });
            });
        });
        </script>
        <?php
    }

    /**
     * Check if a dynamic screen has relevant content
     */
    private function has_relevant_content(string $def_id): bool {
        if (in_array($def_id, ['cpt_list', 'cpt_edit'], true)) {
            $cpts = get_post_types(['_builtin' => false, 'public' => true], 'names');
            $cpts = array_diff($cpts, ['product', 'shop_order', 'shop_coupon']);
            return !empty($cpts);
        }

        return true;
    }

    private function count_rules(array $rules): int {
        $count = 0;
        foreach ($rules as $screen_rules) {
            $count += count($screen_rules);
        }
        return $count;
    }

    /**
     * AJAX: Save rules
     */
    public function ajax_save_rules(): void {
        check_ajax_referer('sapm_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }

        $rules = json_decode(stripslashes($_POST['rules'] ?? '{}'), true);

        if (!is_array($rules) || json_last_error() !== JSON_ERROR_NONE) {
            wp_send_json_error('Invalid JSON data');
        }

        foreach ($rules as $key => $value) {
            if (!is_string($key) || (!is_array($value) && !is_null($value))) {
                wp_send_json_error('Invalid rules structure');
            }
        }

        $sanitized = [];
        foreach ($rules as $screen_id => $screen_rules) {
            $screen_id = sanitize_key($screen_id);
            $sanitized[$screen_id] = [];

            foreach ($screen_rules as $plugin => $state) {
                $plugin = wp_normalize_path(wp_unslash($plugin));
                $plugin = sanitize_text_field($plugin);
                if ($plugin === '' || strpos($plugin, '..') !== false) {
                    continue;
                }
                $state = in_array($state, ['enabled', 'disabled', 'defer'], true) ? $state : 'default';

                if ($state !== 'default') {
                    $sanitized[$screen_id][$plugin] = $state;
                }
            }
        }

        update_option(SAPM_OPTION_KEY, $sanitized);
        $this->core->set_rules($sanitized);

        wp_send_json_success(['rules_count' => $this->count_rules($sanitized)]);
    }

    /**
     * AJAX: Toggle rule from admin drawer
     */
    public function ajax_drawer_toggle_rule(): void {
        check_ajax_referer('sapm_drawer_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Insufficient permissions'], 403);
        }

        $screen_id = sanitize_key($_POST['screen_id'] ?? '');
        $plugin = wp_normalize_path(wp_unslash($_POST['plugin'] ?? ''));
        $plugin = sanitize_text_field($plugin);
        $state = sanitize_key($_POST['state'] ?? '');

        if ($screen_id === '' || $plugin === '' || strpos($plugin, '..') !== false) {
            wp_send_json_error(['message' => 'Invalid data'], 400);
        }

        $allowed_states = ['enabled', 'disabled', 'defer', 'default'];
        if (!in_array($state, $allowed_states, true)) {
            wp_send_json_error(['message' => 'Invalid state'], 400);
        }

        $matched_def = $this->core->match_screen_definition($screen_id);
        $rule_key = $matched_def ?? $screen_id;

        $rules = get_option(SAPM_OPTION_KEY, []);
        $rules = is_array($rules) ? $rules : [];

        if ($state === 'default') {
            if (isset($rules[$rule_key][$plugin])) {
                unset($rules[$rule_key][$plugin]);
            }
            if (isset($rules[$rule_key]) && empty($rules[$rule_key])) {
                unset($rules[$rule_key]);
            }
        } else {
            if (!isset($rules[$rule_key]) || !is_array($rules[$rule_key])) {
                $rules[$rule_key] = [];
            }
            $rules[$rule_key][$plugin] = $state;
        }

        update_option(SAPM_OPTION_KEY, $rules);
        $this->core->set_rules($rules);

        wp_send_json_success([
            'screen_id' => $screen_id,
            'rule_key' => $rule_key,
            'screen_rules' => $rules[$rule_key] ?? [],
        ]);
    }

    /**
     * AJAX: Get current screen
     */
    public function ajax_get_current_screen(): void {
        check_ajax_referer('sapm_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $screen_id = $this->core->get_current_screen_early();

        wp_send_json_success([
            'screen_id' => $screen_id,
            'matched_def' => $this->core->match_screen_definition($screen_id),
            'request_type' => $this->core->get_request_type(),
        ]);
    }

    /**
     * AJAX: Detect plugins
     */
    public function ajax_detect_plugins(): void {
        check_ajax_referer('sapm_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }

        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $all_plugins = get_plugins();
        $active_plugins = $this->core->get_active_plugins_raw();

        $plugins = [];
        foreach ($active_plugins as $plugin_file) {
            if (isset($all_plugins[$plugin_file])) {
                $plugins[$plugin_file] = $all_plugins[$plugin_file]['Name'] ?? $plugin_file;
            }
        }

        wp_send_json_success([
            'plugins' => $plugins,
        ]);
    }

    /**
     * AJAX: Save rules for request types (AJAX/REST/Cron/CLI)
     */
    public function ajax_save_request_type_rules(): void {
        check_ajax_referer('sapm_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Insufficient permissions'], 403);
        }

        $rules = json_decode(stripslashes($_POST['rules'] ?? '{}'), true);

        if (!is_array($rules)) {
            wp_send_json_error(['message' => 'Invalid data'], 400);
        }

        $allowed_types = ['ajax', 'rest', 'cron', 'cli', 'frontend'];
        $allowed_modes = ['passthrough', 'blacklist', 'whitelist'];

        $sanitized = [];
        foreach ($rules as $type => $type_rules) {
            if (!in_array($type, $allowed_types, true)) {
                continue;
            }

            $sanitized[$type] = [];

            // Mode
            if (isset($type_rules['_mode']) && in_array($type_rules['_mode'], $allowed_modes, true)) {
                $sanitized[$type]['_mode'] = $type_rules['_mode'];
            } else {
                $sanitized[$type]['_mode'] = 'passthrough';
            }

            // Detection flags
            if (!empty($type_rules['_detect_by_action'])) {
                $sanitized[$type]['_detect_by_action'] = true;
            }
            if (!empty($type_rules['_detect_by_namespace'])) {
                $sanitized[$type]['_detect_by_namespace'] = true;
            }

            // Plugin lists
            if (!empty($type_rules['disabled_plugins']) && is_array($type_rules['disabled_plugins'])) {
                $sanitized[$type]['disabled_plugins'] = array_values(array_filter(
                    array_map('sanitize_text_field', $type_rules['disabled_plugins'])
                ));
            }

            if (!empty($type_rules['default_plugins']) && is_array($type_rules['default_plugins'])) {
                $sanitized[$type]['default_plugins'] = array_values(array_filter(
                    array_map('sanitize_text_field', $type_rules['default_plugins'])
                ));
            }

            // Merge with default values for known_actions and known_namespaces
            $defaults = $this->core->get_default_request_type_rules();
            if (isset($defaults[$type]['known_actions'])) {
                $sanitized[$type]['known_actions'] = $defaults[$type]['known_actions'];
            }
            if (isset($defaults[$type]['known_namespaces'])) {
                $sanitized[$type]['known_namespaces'] = $defaults[$type]['known_namespaces'];
            }
        }

        // Merge with defaults for types that were not sent
        $defaults = $this->core->get_default_request_type_rules();
        foreach ($allowed_types as $type) {
            if (!isset($sanitized[$type])) {
                $sanitized[$type] = $defaults[$type] ?? ['_mode' => 'passthrough'];
            }
        }

        $this->core->save_request_type_rules($sanitized);

        wp_send_json_success([
            'rules' => $sanitized,
            'message' => __('Nastavení uloženo', 'sapm'),
        ]);
    }

    /**
     * AJAX: Get performance data for request types
     */
    public function ajax_get_request_type_performance(): void {
        check_ajax_referer('sapm_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Insufficient permissions'], 403);
        }

        $formatted = SAPM_Core::get_request_type_performance_formatted();

        // Get plugin names for display
        $plugins = get_plugins();
        $plugin_names = [];
        foreach ($plugins as $file => $data) {
            $plugin_names[$file] = $data['Name'] ?? $file;
        }

        // Transform data for JavaScript consumption
        $transformed = [];
        foreach ($formatted as $request_type => $triggers) {
            $transformed[$request_type] = [];
            foreach ($triggers as $trigger => $trigger_data) {
                $plugins_arr = [];
                foreach ($trigger_data['plugins'] ?? [] as $plugin_info) {
                    $plugin_file = $plugin_info['plugin'] ?? '';
                    $plugins_arr[] = [
                        'file' => $plugin_file,
                        'name' => $plugin_names[$plugin_file] ?? $plugin_file,
                        'avg_ms' => $plugin_info['avg_ms'] ?? 0,
                        'avg_queries' => $plugin_info['avg_queries'] ?? 0,
                        'samples' => $plugin_info['samples'] ?? 0,
                    ];
                }

                $transformed[$request_type][$trigger] = [
                    'trigger' => $trigger,
                    'samples' => $trigger_data['samples'] ?? 0,
                    'first_sample' => $trigger_data['first_sample'] ?? null,
                    'last_sample' => $trigger_data['last_sample'] ?? null,
                    'avg_ms' => $trigger_data['avg_total_ms'] ?? 0,
                    'avg_queries' => $trigger_data['avg_total_queries'] ?? 0,
                    'plugins' => $plugins_arr,
                ];
            }
        }

        wp_send_json_success($transformed);
    }

    /**
     * AJAX: Clear performance data for request types
     */
    public function ajax_clear_request_type_performance(): void {
        check_ajax_referer('sapm_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Insufficient permissions'], 403);
        }

        $request_type = sanitize_key($_POST['type'] ?? '');

        if ($request_type && $request_type !== 'all') {
            SAPM_Core::clear_request_type_performance($request_type);
            wp_send_json_success(['message' => sprintf(__('Data for %s cleared', 'sapm'), $request_type)]);
        }

        SAPM_Core::clear_request_type_performance();
        wp_send_json_success(['message' => __('All performance data cleared', 'sapm')]);
    }

    /**
     * AJAX: Get/Set mode (manual/auto)
     */
    public function ajax_set_mode(): void {
        check_ajax_referer('sapm_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Insufficient permissions'], 403);
        }

        $mode = sanitize_key($_POST['mode'] ?? '');
        
        if (!in_array($mode, ['manual', 'auto'], true)) {
            wp_send_json_error(['message' => 'Invalid mode'], 400);
        }

        $result = $this->core->set_mode($mode);
        
        if ($result) {
            wp_send_json_success([
                'message' => sprintf(__('Mode changed to: %s', 'sapm'), $mode),
                'mode' => $mode,
            ]);
        } else {
            wp_send_json_error(['message' => 'Failed to save mode'], 500);
        }
    }

    /**
     * AJAX: Get auto-generated rule suggestions
     */
    public function ajax_get_auto_suggestions(): void {
        check_ajax_referer('sapm_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Insufficient permissions'], 403);
        }

        $confidence = floatval($_POST['confidence'] ?? 0.5);
        $confidence = max(0.1, min(1.0, $confidence));

        $suggestions = $this->core->get_auto_suggestions($confidence);
        $stats = SAPM_Database::get_stats();
        $summary = SAPM_Core::get_plugin_usage_summary();

        wp_send_json_success([
            'suggestions' => $suggestions,
            'stats' => $stats,
            'plugin_summary' => $summary,
            'mode' => $this->core->get_mode(),
        ]);
    }

    /**
     * AJAX: Apply auto rules (user-selected suggestions)
     */
    public function ajax_apply_auto_rules(): void {
        check_ajax_referer('sapm_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Insufficient permissions'], 403);
        }

        // Get user-selected suggestions from JSON
        $suggestions_json = isset($_POST['suggestions']) ? wp_unslash($_POST['suggestions']) : '[]';
        $selected = json_decode($suggestions_json, true);
        
        if (!is_array($selected)) {
            $selected = [];
        }

        $applied = ['blocks' => [], 'whitelist' => [], 'screens' => []];
        $current_rules = $this->core->get_request_type_rules();

        
        // Load main plugin rules for screen-based blocking (sapm_plugin_rules)
        $plugin_rules = get_option(SAPM_OPTION_KEY, []);
        if (!is_array($plugin_rules)) {
            $plugin_rules = [];
        }

        foreach ($selected as $item) {
            $type = sanitize_key($item['type'] ?? '');
            $action = sanitize_key($item['action'] ?? '');
            $plugin = sanitize_text_field($item['plugin'] ?? '');
            $screen = sanitize_key($item['screen'] ?? '');

            if (empty($plugin)) {
                continue;
            }


            // Handle screen-based suggestions (per-screen blocking/defer)
            if ($type === 'screen' && !empty($screen)) {
                // Map raw screen_id from Auto mode to $def_id from screen_definitions
                // Auto mode uses raw screen IDs: 'dashboard', 'options-general', 'plugins', 'edit-post', etc.
                // UI reads by $def_id from screen_definitions: 'dashboard', 'settings_general', 'plugin_pages', 'posts_edit', etc.
                $rule_screen_id = $this->core->match_screen_definition($screen);
                
                // If no matching definition found, use raw screen ID as fallback
                if ($rule_screen_id === null) {
                    $rule_screen_id = $screen;
                }
                
                // Initialize screen rules if not exists
                if (!isset($plugin_rules[$rule_screen_id])) {
                    $plugin_rules[$rule_screen_id] = [];
                }
                
                // Map action to rule state
                $state = ($action === 'block') ? 'disabled' : 'defer';
                
                // Only add if not already set
                if (!isset($plugin_rules[$rule_screen_id][$plugin])) {
                    $plugin_rules[$rule_screen_id][$plugin] = $state;
                    $applied['screens'][] = ['screen' => $rule_screen_id, 'action' => $action, 'plugin' => $plugin];
                }
                continue;
            }

            // Handle request type suggestions (ajax/rest/cron/cli)
            if (!in_array($type, ['ajax', 'rest', 'cron', 'cli'], true)) {
                continue;
            }
            
            if (!in_array($action, ['block', 'whitelist'], true)) {
                continue;
            }

            // Ensure type rules exist with correct structure expected by UI
            if (!isset($current_rules[$type])) {
                $current_rules[$type] = [
                    '_mode' => 'passthrough',
                    'disabled_plugins' => [],
                    'default_plugins' => [],
                ];
            }
            
            // Ensure arrays exist
            if (!isset($current_rules[$type]['disabled_plugins'])) {
                $current_rules[$type]['disabled_plugins'] = [];
            }
            if (!isset($current_rules[$type]['default_plugins'])) {
                $current_rules[$type]['default_plugins'] = [];
            }

            if ($action === 'block') {
                // Add to disabled_plugins (blacklist) and set mode to blacklist
                if (!in_array($plugin, $current_rules[$type]['disabled_plugins'], true)) {
                    $current_rules[$type]['disabled_plugins'][] = $plugin;
                    // Set mode to blacklist when blocking plugins
                    $current_rules[$type]['_mode'] = 'blacklist';
                    $applied['blocks'][] = ['type' => $type, 'plugin' => $plugin];
                }
            } else {
                // Add to default_plugins (whitelist) and set mode to whitelist
                if (!in_array($plugin, $current_rules[$type]['default_plugins'], true)) {
                    $current_rules[$type]['default_plugins'][] = $plugin;
                    // Set mode to whitelist
                    $current_rules[$type]['_mode'] = 'whitelist';
                    $applied['whitelist'][] = ['type' => $type, 'plugin' => $plugin];
                }
            }
        }

        $total_applied = count($applied['blocks']) + count($applied['whitelist']) + count($applied['screens']);

        // Save updated rules if any changes
        if ($total_applied > 0) {
            $this->core->save_request_type_rules($current_rules);
            
            // Save screen-based rules to main plugin rules option
            if (!empty($applied['screens'])) {
                update_option(SAPM_OPTION_KEY, $plugin_rules);
                // Refresh core rules cache
                $this->core->set_rules($plugin_rules);
            }
        }
        
        wp_send_json_success([
            'message' => sprintf(__('Aplikováno %d pravidel', 'sapm'), $total_applied),
            'applied' => $applied,
            'rules' => $this->core->get_request_type_rules(),
        ]);
    }

    /**
     * AJAX: Reset auto mode data (sampling + rules)
     */
    public function ajax_reset_auto_data(): void {
        check_ajax_referer('sapm_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Insufficient permissions'], 403);
        }

        // Clear sampling data (admin + request types)
        $cleared = SAPM_Database::clear_sampling_data();

        // Reset rules to defaults
        update_option(SAPM_OPTION_KEY, []);
        $this->core->set_rules([]);

        $defaults = $this->core->get_default_request_type_rules();
        $this->core->save_request_type_rules($defaults);

        // Clear performance snapshots/transients
        delete_transient('sapm_perf_last');
        delete_transient('sapm_perf_log');
        delete_transient('rbeo_perf_last');
        delete_transient('rbeo_perf_log');
        delete_transient('apf_perf_last');
        delete_transient('apf_perf_log');

        wp_send_json_success([
            'message' => __('Auto data a pravidla byla resetována', 'sapm'),
            'sampling_cleared' => $cleared,
        ]);
    }

    /**
     * AJAX: Get sampling statistics
     */
    public function ajax_get_sampling_stats(): void {
        check_ajax_referer('sapm_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Insufficient permissions'], 403);
        }

        wp_send_json_success([
            'stats' => SAPM_Database::get_stats(),
            'summary' => SAPM_Core::get_plugin_usage_summary(),
            'mode' => $this->core->get_mode(),
        ]);
    }

    /**
     * AJAX: Save Update Optimizer settings
     */
    public function ajax_save_update_optimizer(): void {
        check_ajax_referer('sapm_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Insufficient permissions'], 403);
        }

        $optimizer = SAPM_Update_Optimizer::get_instance();
        
        // Validate enum values
        $strategy = sanitize_text_field($_POST['strategy'] ?? 'cron_only');
        if (!in_array($strategy, ['ttl_extension', 'page_specific', 'cron_only'], true)) {
            $strategy = 'cron_only';
        }

        $cron_interval = sanitize_text_field($_POST['cron_interval'] ?? 'twicedaily');
        if (!in_array($cron_interval, ['hourly', 'twicedaily', 'daily'], true)) {
            $cron_interval = 'twicedaily';
        }

        $ttl_hours = intval($_POST['ttl_hours'] ?? 24);
        if (!in_array($ttl_hours, [12, 24, 48, 72], true)) {
            $ttl_hours = 24;
        }

        $config = [
            'enabled' => !empty($_POST['enabled']),
            'strategy' => $strategy,
            'ttl_hours' => $ttl_hours,
            'cron_interval' => $cron_interval,
            'show_stale_indicator' => !empty($_POST['show_stale_indicator']),
        ];
        
        // Sanitize blacklist - always set (empty array if not provided)
        // jQuery doesn't send empty arrays, so we must handle missing key as empty
        if (isset($_POST['blacklist']) && is_array($_POST['blacklist'])) {
            $config['plugin_updater_blacklist'] = array_map('sanitize_text_field', $_POST['blacklist']);
        } else {
            $config['plugin_updater_blacklist'] = [];
        }
        
        // Sanitize whitelist - always set (empty array if not provided)
        // jQuery doesn't send empty arrays, so we must handle missing key as empty
        if (isset($_POST['whitelist']) && is_array($_POST['whitelist'])) {
            $config['plugin_updater_whitelist'] = array_map('sanitize_text_field', $_POST['whitelist']);
        } else {
            $config['plugin_updater_whitelist'] = [];
        }
        
        $result = $optimizer->save_config($config);
        
        if ($result) {
            wp_send_json_success([
                'message' => __('Settings saved', 'sapm'),
                'config' => $optimizer->get_config(),
            ]);
        } else {
            wp_send_json_error(['message' => __('Error saving settings', 'sapm')]);
        }
    }

    /**
     * AJAX: Force update check
     */
    public function ajax_force_update_check(): void {
        check_ajax_referer('sapm_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Insufficient permissions'], 403);
        }

        $optimizer = SAPM_Update_Optimizer::get_instance();
        $result = $optimizer->force_update_check();
        $breakdown = SAPM_Update_Optimizer::get_update_breakdown();
        
        wp_send_json_success([
            'message' => __('Check completed', 'sapm'),
            'plugin_count' => $breakdown['plugins'],
            'theme_count' => $breakdown['themes'],
            'translation_count' => $breakdown['translations'],
            'core_count' => $breakdown['core'],
            'total_count' => $breakdown['total'],
            'result' => $result,
        ]);
    }

    /**
     * AJAX: Save Administration tab theme
     */
    public function ajax_save_admin_theme(): void {
        check_ajax_referer('sapm_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Insufficient permissions'], 403);
        }

        $theme = sanitize_key($_POST['theme'] ?? '');
        if (!in_array($theme, ['dark', 'light'], true)) {
            wp_send_json_error(['message' => __('Invalid theme value', 'sapm')], 400);
        }

        update_option(self::ADMIN_THEME_OPTION, $theme, false);

        wp_send_json_success([
            'message' => __('Theme saved', 'sapm'),
            'theme' => $theme,
        ]);
    }

    /**
     * Admin bar info
     */
    public function admin_bar_info($wp_admin_bar): void {
        if (!is_admin() || !current_user_can('manage_options')) {
            return;
        }

        $snapshots = $this->get_menu_snapshots();

        $disabled = $this->core->get_disabled_this_request();
        $disabled_count = count($disabled);

        $label = __('⚡SAPM', 'sapm');
        $label = esc_html($label);
        $wp_admin_bar->add_node([
            'id' => 'sapm-info',
            'title' => sprintf(
                '<span class="sapm-drawer-toggle-label">%s</span>',
                $label
            ),
            'href' => '#sapm-admin-drawer',
            'meta' => [
                'class' => 'sapm-adminbar-toggle',
            ],
        ]);

        $wp_admin_bar->add_node([
            'id' => 'sapm-settings',
            'parent' => 'sapm-info',
            'title' => esc_html__('Settings', 'sapm'),
            'href' => admin_url('options-general.php?page=smart-admin-plugin-manager'),
        ]);

        if ($disabled_count > 0 || SAPM_DEBUG) {
            if (!function_exists('get_plugins')) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }
            $all_plugins = get_plugins();

            foreach ($disabled as $plugin) {
                $name = $all_plugins[$plugin]['Name'] ?? $plugin;
                $menu_url = $this->get_snapshot_menu_url($plugin, $snapshots);

                $wp_admin_bar->add_node([
                    'id' => 'sapm-disabled-' . sanitize_key($plugin),
                    'parent' => 'sapm-info',
                    'title' => '✕ ' . esc_html($name),
                    'href' => $menu_url !== '' ? esc_url($menu_url) : null,
                ]);
            }
        }
    }

    /**
     * Admin notice
     */
    public function show_disabled_notice(): void {
        // Debug: Show dependencies info if requested
        if (current_user_can('manage_options') && isset($_GET['sapm_deps_debug'])) {
            $this->show_dependencies_debug();
        }
        
        if (!SAPM_DEBUG || !current_user_can('manage_options')) {
            return;
        }

        $screen = get_current_screen();
        if (!$screen || $screen->id === 'settings_page_smart-admin-plugin-manager') {
            return;
        }

        $disabled = $this->core->get_disabled_this_request();
        if (empty($disabled)) {
            return;
        }

        $count = count($disabled);
        ?>
        <div class="notice notice-info is-dismissible">
            <p>
                <strong>Smart Plugin Manager:</strong>
                <?php printf(
                    __('%d plugins disabled for screen <code>%s</code>.', 'sapm'),
                    $count,
                    esc_html($this->core->get_current_screen_id())
                ); ?>
                <a href="<?php echo admin_url('options-general.php?page=smart-admin-plugin-manager'); ?>">
                    <?php _e('Settings', 'sapm'); ?>
                </a>
            </p>
        </div>
        <?php
    }
    
    /**
     * Show dependencies debug info
     */
    private function show_dependencies_debug(): void {
        echo '<div class="notice notice-info"><pre style="font-size:11px; max-height:600px; overflow:auto;">';
        echo "<strong>=== SAPM Dependencies Debug ===</strong>\n\n";
        
        echo "SAPM_DEPS_LOADED constant: " . (defined('SAPM_DEPS_LOADED') ? 'YES' : 'NO') . "\n";
        echo "SAPM_Dependencies class exists: " . (class_exists('SAPM_Dependencies') ? 'YES' : 'NO') . "\n\n";
        
        if (class_exists('SAPM_Dependencies')) {
            $deps = SAPM_Dependencies::init();
            echo "Cascade enabled: " . ($deps->is_cascade_enabled() ? 'YES' : 'NO') . "\n";
            
            $map = $deps->get_dependency_map();
            echo "Dependency map count: " . count($map) . "\n\n";
            
            echo "<strong>Dependency Map:</strong>\n";
            foreach ($map as $plugin => $parents) {
                echo "  - $plugin => [" . implode(', ', $parents) . "]\n";
            }
            
            echo "\n<strong>Test cascade with WooCommerce blocked:</strong>\n";
            $cascade = $deps->get_cascade_blocked(['woocommerce/woocommerce.php']);
            echo "  Cascade count: " . count($cascade) . "\n";
            foreach ($cascade as $p) {
                echo "    - $p\n";
            }
        }
        
        echo "\n<strong>Disabled this request:</strong>\n";
        $disabled = $this->core->get_disabled_this_request();
        foreach ($disabled as $plugin => $reason) {
            echo "  - $plugin => $reason\n";
        }
        
        echo '</pre></div>';
    }

    /**
     * Debug output
     */
    public function debug_output(): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        $all_plugins = get_option('active_plugins', []);
        $loaded = count($all_plugins) - count($this->core->get_disabled_this_request());

        echo '<!-- SAPM Debug:';
        echo "\nRequest Type: " . $this->core->get_request_type();
        echo "\nScreen ID: " . $this->core->get_current_screen_id();
        echo "\nMatched Definition: " . ($this->core->match_screen_definition($this->core->get_current_screen_id()) ?: 'none');
        echo "\nPlugins Loaded: $loaded / " . count($all_plugins);
        echo "\nDisabled: " . implode(', ', $this->core->get_disabled_this_request());
        echo "\nDeferred: " . implode(', ', $this->core->get_deferred_this_request());
        echo "\nMemory: " . size_format(memory_get_peak_usage(true));
        echo "\n-->";
    }

    /**
     * Render admin drawer
     */
    public function render_admin_drawer(): void {
        if (!$this->should_render_admin_drawer()) {
            return;
        }

        $title = esc_html__('Quick Plugin Management', 'sapm');
        $toggle = esc_html__('Panel', 'sapm');
        $context_label = esc_html__('Screen', 'sapm');
        $close_label = esc_html__('Close', 'sapm');
        $open_settings = esc_html__('Open settings', 'sapm');

        echo <<<HTML
        <div id="sapm-admin-drawer" class="sapm-admin-drawer" aria-hidden="true">
            <div id="sapm-admin-drawer-panel" class="sapm-drawer-panel" role="complementary">
                <div class="sapm-drawer-header">
                    <h2 class="sapm-drawer-title">{$title}</h2>
                    <button type="button" class="sapm-drawer-close" aria-label="{$close_label}">×</button>
                </div>
                <div class="sapm-drawer-context">
                    <strong>{$context_label}:</strong>
                    <span data-sapm-context-label></span>
                </div>
                <div class="sapm-drawer-notice" data-sapm-notice aria-live="polite"></div>
                <div class="sapm-drawer-section sapm-drawer-perf">
                    <div class="sapm-drawer-section-title" data-sapm-perf-title></div>
                    <div class="sapm-drawer-meta" data-sapm-perf-meta></div>
                    <div class="sapm-drawer-list" data-sapm-perf-list></div>
                    <p class="sapm-drawer-empty" data-sapm-perf-empty></p>
                </div>
                <div class="sapm-drawer-section sapm-drawer-disabled">
                    <div class="sapm-drawer-section-title" data-sapm-disabled-title></div>
                    <div class="sapm-drawer-list" data-sapm-disabled-list></div>
                    <p class="sapm-drawer-empty" data-sapm-disabled-empty></p>
                </div>
                <div class="sapm-drawer-section sapm-drawer-deferred">
                    <div class="sapm-drawer-section-title" data-sapm-deferred-title></div>
                    <div class="sapm-drawer-list" data-sapm-deferred-list></div>
                    <p class="sapm-drawer-empty" data-sapm-deferred-empty></p>
                </div>
                <div class="sapm-drawer-section sapm-drawer-rules">
                    <div class="sapm-drawer-section-title" data-sapm-rules-title></div>
                    <div class="sapm-drawer-list" data-sapm-rules-list></div>
                    <p class="sapm-drawer-empty" data-sapm-rules-empty></p>
                    <div class="sapm-drawer-add" data-sapm-add>
                        <div class="sapm-drawer-add-title" data-sapm-add-title></div>
                        <div class="sapm-drawer-add-row">
                            <select class="sapm-drawer-select" data-sapm-add-plugin></select>
                            <select class="sapm-drawer-select" data-sapm-add-action>
                                <option value="disabled"></option>
                                <option value="defer"></option>
                                <option value="enabled"></option>
                            </select>
                            <button type="button" class="button button-primary" data-sapm-add-apply></button>
                        </div>
                    </div>
                </div>
                <div class="sapm-drawer-footer">
                    <a href="" class="sapm-drawer-settings" data-sapm-settings-link>{$open_settings}</a>
                </div>
            </div>
        </div>
        HTML;
    }

    /**
     * Data for admin drawer
     */
    private function get_drawer_data(): array {
        $screen_id = $this->core->get_current_screen_early();
        $matched_def = $this->core->match_screen_definition($screen_id);
        $screen_definitions = $this->core->get_screen_definitions();

        $screen_label = $screen_id;
        if ($matched_def && !empty($screen_definitions[$matched_def]['label'])) {
            $screen_label = $screen_definitions[$matched_def]['label'];
        }

        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $all_plugins = get_plugins();
        $active_plugins = $this->core->get_active_plugins_raw();
        $plugin_names = [];

        foreach ($active_plugins as $plugin_file) {
            if (isset($all_plugins[$plugin_file])) {
                $plugin_names[$plugin_file] = $all_plugins[$plugin_file]['Name'] ?? $plugin_file;
            }
        }

        $effective_states = [];
        $inherited_states = [];

        foreach (array_keys($plugin_names) as $plugin_file) {
            $effective_states[$plugin_file] = $this->core->get_effective_rule($plugin_file, $screen_id, $matched_def);
            $inherited_states[$plugin_file] = $this->core->get_inherited_rule($plugin_file, $matched_def);
        }

        $screen_rules_filtered = [];
        foreach ($effective_states as $plugin_file => $info) {
            $state = $info['state'] ?? null;
            $source = $info['source'] ?? null;
            if ($source !== 'screen') {
                continue;
            }
            if (!in_array($state, ['enabled', 'disabled', 'defer'], true)) {
                continue;
            }
            $screen_rules_filtered[$plugin_file] = $state;
        }

        $context = $this->get_admin_context();
        $perf_payload = $this->get_perf_payload($plugin_names, $context);
        $disabled_this_request = array_values(array_unique($this->core->get_disabled_this_request()));
        $deferred_this_request = array_values(array_unique($this->core->get_deferred_this_request()));

        return [
            'nonce' => wp_create_nonce('sapm_drawer_nonce'),
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'settingsUrl' => admin_url('options-general.php?page=smart-admin-plugin-manager'),
            'screen' => [
                'id' => $screen_id,
                'label' => wp_strip_all_tags((string) $screen_label),
            ],
            'plugins' => $plugin_names,
            'screenRules' => $screen_rules_filtered,
            'effectiveStates' => $effective_states,
            'inheritedStates' => $inherited_states,
            'perf' => $perf_payload,
            'disabledThisRequest' => $disabled_this_request,
            'deferredThisRequest' => $deferred_this_request,
            'strings' => [
                'drawerTitle' => __('Quick Plugin Manager', 'sapm'),
                'toggle' => __('Panel', 'sapm'),
                'contextLabel' => __('Screen', 'sapm'),
                'perfTitle' => __('Plugin Performance', 'sapm'),
                'perfEmpty' => __('No data for this screen yet.', 'sapm'),
                'perfLatest' => __('Showing latest snapshot (not from this screen).', 'sapm'),
                'perfTotal' => __('Total', 'sapm'),
                'perfQueries' => __('DB queries', 'sapm'),
                'perfQueriesShort' => __('queries', 'sapm'),
                'perfCaptured' => __('Captured', 'sapm'),
                'disabledTitle' => __('Disabled plugins', 'sapm'),
                'disabledEmpty' => __('No disabled plugins.', 'sapm'),
                'deferredTitle' => __('Deferred plugins', 'sapm'),
                'deferredEmpty' => __('No deferred plugins.', 'sapm'),
                'rulesTitle' => __('Rules for this screen', 'sapm'),
                'rulesEmpty' => __('No rules.', 'sapm'),
                'addTitle' => __('Add rule', 'sapm'),
                'addPlaceholder' => __('Select plugin', 'sapm'),
                'actionEnable' => __('Enable', 'sapm'),
                'actionDisable' => __('Block', 'sapm'),
                'actionDefer' => __('Defer', 'sapm'),
                'actionDefault' => __('Default', 'sapm'),
                'actionApply' => __('Apply', 'sapm'),
                'saving' => __('Saving...', 'sapm'),
                'saved' => __('Saved.', 'sapm'),
                'error' => __('Action failed. Please try again.', 'sapm'),
                'stateEnabled' => __('Always load', 'sapm'),
                'stateDisabled' => __('Never load', 'sapm'),
                'stateDefer' => __('Defer', 'sapm'),
                'stateDefault' => __('Default', 'sapm'),
                'selectPlugin' => __('Select plugin', 'sapm'),
            ],
        ];
    }

    /**
     * Context of the current admin page (for perf snapshot)
     */
    private function get_admin_context(): array {
        $pagenow = $GLOBALS['pagenow'] ?? '';
        if ($pagenow === '') {
            $uri_path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
            $pagenow = $uri_path ? basename($uri_path) : 'admin.php';
        }

        $query = [];
        if (!empty($_SERVER['QUERY_STRING'])) {
            parse_str($_SERVER['QUERY_STRING'], $query);
        }

        $match = $this->build_admin_match_from_request($pagenow, $query);
        $label = $this->build_admin_context_label($pagenow, $query, $match);

        return [
            'pagenow' => sanitize_text_field($pagenow),
            'query' => $query,
            'match' => $match,
            'label' => $label,
        ];
    }

    private function build_admin_match_from_request(string $pagenow, array $query): string {
        $pagenow = sanitize_text_field($pagenow);
        $match = $pagenow !== '' ? $pagenow : 'admin.php';

        switch ($pagenow) {
            case 'edit.php':
                if (!empty($query['post_type'])) {
                    $match .= '?post_type=' . sanitize_key($query['post_type']);
                }
                break;

            case 'post.php':
            case 'post-new.php':
                $post_type = '';
                if (!empty($query['post_type'])) {
                    $post_type = sanitize_key($query['post_type']);
                } elseif (!empty($query['post']) && is_numeric($query['post'])) {
                    $post_type = get_post_type((int) $query['post']);
                    $post_type = $post_type ? sanitize_key($post_type) : '';
                }
                if ($post_type !== '') {
                    $match .= '?post_type=' . $post_type;
                }
                break;

            case 'edit-tags.php':
            case 'term.php':
                if (!empty($query['taxonomy'])) {
                    $match .= '?taxonomy=' . sanitize_key($query['taxonomy']);
                    if (!empty($query['post_type'])) {
                        $match .= '&post_type=' . sanitize_key($query['post_type']);
                    }
                }
                break;

            case 'admin.php':
                if (!empty($query['page'])) {
                    $match .= '?page=' . sanitize_key($query['page']);
                }
                break;
        }

        return $match;
    }

    private function build_admin_context_label(string $pagenow, array $query, string $match): string {
        $pagenow = sanitize_text_field($pagenow);
        $parts = [];
        if ($pagenow !== '') {
            $parts[] = $pagenow;
        }
        if (!empty($query['page'])) {
            $parts[] = 'page=' . sanitize_key($query['page']);
        }
        if (!empty($query['post_type'])) {
            $parts[] = 'post_type=' . sanitize_key($query['post_type']);
        }
        if (!empty($query['taxonomy'])) {
            $parts[] = 'taxonomy=' . sanitize_key($query['taxonomy']);
        }
        if (empty($parts) && $match !== '') {
            return esc_html($match);
        }
        return esc_html(implode(' · ', $parts));
    }

    private function parse_admin_match(string $match, string $fallback_path = ''): array {
        $match = trim((string) $match);
        $path = '';
        $query = [];

        if ($match === '') {
            return ['path' => $path, 'query' => $query];
        }

        if (str_contains($match, '?')) {
            $parts = wp_parse_url($match);
            $path = basename($parts['path'] ?? '');
            parse_str($parts['query'] ?? '', $query);
        } elseif (str_contains($match, '=')) {
            $path = $fallback_path !== '' ? $fallback_path : '';
            parse_str($match, $query);
        } else {
            $path = $match;
        }

        return ['path' => $path, 'query' => $query];
    }

    private function perf_entry_matches_context($entry, array $context): bool {
        if (empty($entry) || !is_array($entry)) {
            return false;
        }
        if (!empty($entry['request_type']) && defined('REQUEST_ADMIN')) {
            if ((int) $entry['request_type'] !== REQUEST_ADMIN) {
                return false;
            }
        }
        $uri = (string) ($entry['uri'] ?? '');
        if ($uri === '') {
            return false;
        }
        $parts = wp_parse_url($uri);
        if (!is_array($parts)) {
            return false;
        }
        $path = basename($parts['path'] ?? '');
        if ($path === '' || $path !== ($context['pagenow'] ?? '')) {
            return false;
        }

        $context_match = $this->parse_admin_match($context['match'] ?? '', $context['pagenow'] ?? '');
        $required_query = is_array($context_match['query'] ?? null) ? $context_match['query'] : [];
        if (empty($required_query)) {
            return true;
        }
        $entry_query = [];
        parse_str($parts['query'] ?? '', $entry_query);
        foreach ($required_query as $key => $value) {
            if (!isset($entry_query[$key])) {
                return false;
            }
            if ((string) $value !== '' && (string) $entry_query[$key] !== (string) $value) {
                return false;
            }
        }
        return true;
    }

    private function get_perf_entry_for_context(array $context): ?array {
        $perf_last = get_transient('sapm_perf_last');
        if (empty($perf_last)) {
            $perf_last = get_transient('rbeo_perf_last');
            if (empty($perf_last)) {
                $legacy = get_transient('apf_perf_last');
                if (!empty($legacy)) {
                    $perf_last = $legacy;
                    set_transient('sapm_perf_last', $legacy, 10 * MINUTE_IN_SECONDS);
                    delete_transient('apf_perf_last');
                }
            } else {
                set_transient('sapm_perf_last', $perf_last, 10 * MINUTE_IN_SECONDS);
            }
        }
        if ($this->perf_entry_matches_context($perf_last, $context)) {
            return $perf_last;
        }

        $log = get_transient('sapm_perf_log');
        if (empty($log)) {
            $log = get_transient('rbeo_perf_log');
            if (empty($log)) {
                $legacy_log = get_transient('apf_perf_log');
                if (!empty($legacy_log)) {
                    $log = $legacy_log;
                    set_transient('sapm_perf_log', $legacy_log, 10 * MINUTE_IN_SECONDS);
                    delete_transient('apf_perf_log');
                }
            } else {
                set_transient('sapm_perf_log', $log, 10 * MINUTE_IN_SECONDS);
            }
        }
        if (is_array($log)) {
            foreach ($log as $entry) {
                if ($this->perf_entry_matches_context($entry, $context)) {
                    return $entry;
                }
            }
        }
        return is_array($perf_last) ? $perf_last : null;
    }

    private function get_perf_payload(array $plugin_names, array $context): ?array {
        $perf_entry = $this->get_perf_entry_for_context($context);
        if (!is_array($perf_entry) || empty($perf_entry['plugins']) || !is_array($perf_entry['plugins'])) {
            return null;
        }

        $plugins = (array) $perf_entry['plugins'];
        arsort($plugins);
        $query_counts = is_array($perf_entry['query_counts'] ?? null) ? (array) $perf_entry['query_counts'] : [];
        $total_queries = null;
        if (isset($perf_entry['query_total'])) {
            $total_queries = (int) $perf_entry['query_total'];
        } elseif (!empty($query_counts)) {
            $total_queries = array_sum($query_counts);
        }

        $items = [];
        $count = 0;
        foreach ($plugins as $plugin_file => $seconds) {
            $count++;
            if ($count > 10) {
                break;
            }
            $ms = round(((float) $seconds) * 1000, 3);
            $queries = null;
            if (isset($query_counts[$plugin_file])) {
                $queries = (int) $query_counts[$plugin_file];
            }
            $items[] = [
                'plugin' => $plugin_file,
                'name' => $plugin_names[$plugin_file] ?? $plugin_file,
                'ms' => $ms,
                'queries' => $queries,
            ];
        }

        return [
            'total_ms' => isset($perf_entry['total_ms']) ? (float) $perf_entry['total_ms'] : round(array_sum($plugins) * 1000, 3),
            'total_queries' => $total_queries,
            'captured_at' => !empty($perf_entry['captured_at']) ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), (int) $perf_entry['captured_at']) : '',
            'context' => $perf_entry['context'] ?? '',
            'uri' => $perf_entry['uri'] ?? '',
            'matches_current' => $this->perf_entry_matches_context($perf_entry, $context),
            'items' => $items,
        ];
    }

    private function should_capture_menu_snapshots(): bool {
        if (!is_admin() || wp_doing_ajax()) {
            return false;
        }
        if (!current_user_can('manage_options')) {
            return false;
        }
        return $this->is_sapm_settings_page();
    }

    private function is_sapm_settings_page(): bool {
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

    private function get_menu_snapshots(): array {
        $snapshots = get_option(SAPM_MENU_SNAPSHOT_OPTION, []);
        if (!is_array($snapshots) || empty($snapshots)) {
            $legacy = get_option('rbeo_quick_menu_snapshots', []);
            if (empty($legacy)) {
                $legacy = get_option('apf_quick_menu_snapshots', []);
            }
            if (is_array($legacy) && !empty($legacy)) {
                $snapshots = $legacy;
                update_option(SAPM_MENU_SNAPSHOT_OPTION, $snapshots, 'no');
            } elseif (!is_array($snapshots)) {
                $snapshots = [];
            }
        }
        return is_array($snapshots) ? $snapshots : [];
    }

    public function capture_menu_snapshots(): void {
        if (!$this->should_capture_menu_snapshots()) {
            return;
        }

        global $menu, $submenu;
        if (empty($menu) || !is_array($menu)) {
            return;
        }

        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $plugins_info = function_exists('get_plugins') ? get_plugins() : [];
        $active_plugins = $this->core->get_active_plugins_raw();

        if (is_multisite()) {
            $network_plugins = (array) get_site_option('active_sitewide_plugins', []);
            if (!empty($network_plugins)) {
                $active_plugins = array_merge($active_plugins, array_keys($network_plugins));
            }
        }

        $active_plugins = array_values(array_unique(array_filter(array_map('sanitize_text_field', (array) $active_plugins))));
        if (empty($active_plugins)) {
            return;
        }

        $snapshots = $this->get_menu_snapshots();
        $now = time();

        foreach ($active_plugins as $plugin_file) {
            if ($plugin_file === plugin_basename(SAPM_PLUGIN_FILE)) {
                continue;
            }

            $plugin_name = $plugins_info[$plugin_file]['Name'] ?? '';
            $plugin_dir = dirname($plugin_file);
            if ($plugin_dir === '.') {
                $plugin_dir = basename($plugin_file, '.php');
            }
            $plugin_slug = sanitize_key($plugin_dir);

            $primary_snapshot = $this->find_primary_menu_snapshot($plugin_dir, $plugin_name, $menu, $submenu);
            $primary_parent_slug = '';

            if (!empty($primary_snapshot)) {
                $primary_snapshot['captured_at'] = $now;
                $primary_parent_slug = (string) ($primary_snapshot['parent_slug'] ?? '');
                if (empty($primary_snapshot['menu_url']) && $primary_parent_slug !== '') {
                    $primary_snapshot['menu_url'] = $this->build_menu_url($primary_parent_slug, $primary_parent_slug);
                }
            }

            $extra_snapshots = [];
            $patterns = $this->get_plugin_menu_slug_patterns($plugin_slug, $plugin_name);
            $matched_slugs = $this->collect_menu_slugs_matching_patterns($menu, $submenu, $patterns);

            foreach ($matched_slugs as $slug) {
                if ($primary_parent_slug !== '' && $this->menu_slug_matches($primary_parent_slug, $slug)) {
                    continue;
                }

                $snapshot = $this->find_menu_snapshot($slug, $menu, $submenu);
                if (empty($snapshot)) {
                    continue;
                }

                $snapshot['captured_at'] = $now;
                $snapshot['menu_url'] = $this->build_menu_url((string) ($snapshot['parent_slug'] ?? ''), $slug);

                if (empty($primary_snapshot)) {
                    $primary_snapshot = $snapshot;
                    $primary_parent_slug = (string) ($snapshot['parent_slug'] ?? '');
                    continue;
                }

                $extra_snapshots[$slug] = $snapshot;
            }

            if (!empty($primary_snapshot) || !empty($extra_snapshots)) {
                $snapshots[$plugin_file] = array_filter([
                    '__primary' => !empty($primary_snapshot) ? $primary_snapshot : null,
                    '__extra' => !empty($extra_snapshots) ? $extra_snapshots : null,
                ]);
            }
        }

        $snapshots['__meta'] = [
            'captured_at' => $now,
            'active_hash' => md5(implode('|', $active_plugins)),
        ];

        update_option(SAPM_MENU_SNAPSHOT_OPTION, $snapshots, 'no');
    }

    public function restore_menu_snapshots(): void {
        if (!is_admin() || wp_doing_ajax()) {
            return;
        }

        $disabled = $this->core->get_disabled_this_request();
        $deferred = $this->core->get_deferred_this_request();
        $targets = $this->menu_targets;

        $plugins = array_values(array_unique(array_merge($disabled, $deferred)));
        if (empty($plugins)) {
            return;
        }

        $snapshots = $this->get_menu_snapshots();
        if (empty($snapshots) || !is_array($snapshots)) {
            return;
        }

        foreach ($plugins as $plugin_file) {
            if (!isset($snapshots[$plugin_file]) || !is_array($snapshots[$plugin_file])) {
                continue;
            }

            $snapshot_set = $snapshots[$plugin_file];

            if (!empty($snapshot_set['__primary']) && is_array($snapshot_set['__primary'])) {
                $targets = $this->register_menu_snapshot($snapshot_set['__primary'], $targets);
            }

            if (!empty($snapshot_set['__extra']) && is_array($snapshot_set['__extra'])) {
                foreach ($snapshot_set['__extra'] as $extra_snapshot) {
                    if (!is_array($extra_snapshot)) {
                        continue;
                    }
                    $targets = $this->register_menu_snapshot($extra_snapshot, $targets);
                }
            }
        }

        $this->menu_targets = $targets;
        $GLOBALS['sapm_menu_targets'] = $targets;
    }

    public function render_snapshot_menu(): void {
        $slug = sanitize_text_field($_GET['page'] ?? '');
        $targets = $GLOBALS['sapm_menu_targets'] ?? [];
        $target = is_array($targets) && $slug !== '' ? ($targets[$slug] ?? '') : '';

        if ($target !== '') {
            $url = $target;
            if (!preg_match('~^https?://~i', $url)) {
                $url = admin_url(ltrim($url, '/'));
            }

            if (!$this->is_current_admin_url($url)) {
                wp_safe_redirect($url);
                exit;
            }
        }

        $settings_url = admin_url('options-general.php?page=smart-admin-plugin-manager');

        echo '<div class="notice notice-warning"><p>' .
            esc_html__('This plugin is blocked on this page by Smart Admin Plugin Manager.', 'sapm') .
            ' <a href="' . esc_url($settings_url) . '">' .
            esc_html__('Open settings', 'sapm') .
            '</a></p></div>';
    }

    private function get_snapshot_menu_url(string $plugin_file, array $snapshots): string {
        if (empty($snapshots[$plugin_file]) || !is_array($snapshots[$plugin_file])) {
            return '';
        }

        $set = $snapshots[$plugin_file];
        if (!empty($set['__primary']['menu_url'])) {
            return (string) $set['__primary']['menu_url'];
        }
        if (!empty($set['__primary']['parent_slug'])) {
            $parent_slug = (string) $set['__primary']['parent_slug'];
            return $this->build_menu_url($parent_slug, $parent_slug);
        }
        if (!empty($set['__extra']) && is_array($set['__extra'])) {
            foreach ($set['__extra'] as $extra_snapshot) {
                if (!is_array($extra_snapshot)) {
                    continue;
                }
                if (!empty($extra_snapshot['menu_url'])) {
                    return (string) $extra_snapshot['menu_url'];
                }
                if (!empty($extra_snapshot['parent_slug'])) {
                    $parent_slug = (string) $extra_snapshot['parent_slug'];
                    return $this->build_menu_url($parent_slug, $parent_slug);
                }
            }
        }

        return '';
    }

    private function build_menu_url(string $parent_slug, string $slug): string {
        $parent_slug = trim((string) $parent_slug);
        $slug = trim((string) $slug);

        if ($slug === '') {
            return '';
        }

        if (preg_match('~^https?://~i', $slug)) {
            return $slug;
        }

        if (str_contains($slug, '.php') || str_contains($slug, '?')) {
            return admin_url(ltrim($slug, '/'));
        }

        if ($parent_slug !== '' && (str_contains($parent_slug, '.php') || str_contains($parent_slug, '?'))) {
            $separator = str_contains($parent_slug, '?') ? '&' : '?';
            return admin_url(ltrim($parent_slug, '/') . $separator . 'page=' . $slug);
        }

        return admin_url('admin.php?page=' . $slug);
    }

    private function register_menu_snapshot(array $snapshot, array $targets): array {
        $parent = is_array($snapshot['parent'] ?? null) ? $snapshot['parent'] : [];
        $parent_slug = sanitize_text_field($snapshot['parent_slug'] ?? ($parent['slug'] ?? ''));
        if ($parent_slug === '') {
            return $targets;
        }

        $menu_title = (string) ($parent['menu_title'] ?? $parent_slug);
        $page_title = (string) ($parent['page_title'] ?? $menu_title);
        $capability = (string) ($parent['capability'] ?? 'manage_options');
        if (!current_user_can($capability)) {
            $capability = 'manage_options';
        }
        $icon = (string) ($parent['icon'] ?? 'dashicons-admin-links');
        $position = $parent['position'] ?? 58;

        $menu_url = $snapshot['menu_url'] ?? $this->build_menu_url($parent_slug, $parent_slug);
        if ($menu_url !== '') {
            $targets[$parent_slug] = $menu_url;
        }

        if (!$this->menu_exists($parent_slug)) {
            add_menu_page(
                esc_html($page_title),
                $menu_title,
                $capability,
                $parent_slug,
                [$this, 'render_snapshot_menu'],
                $icon,
                $position
            );
        }

        $submenu_items = is_array($snapshot['submenu'] ?? null) ? $snapshot['submenu'] : [];
        foreach ($submenu_items as $sub_item) {
            if (!is_array($sub_item)) {
                continue;
            }
            $sub_slug = sanitize_text_field($sub_item['slug'] ?? '');
            if ($sub_slug === '') {
                continue;
            }

            $targets[$sub_slug] = $this->build_menu_url($parent_slug, $sub_slug);

            if ($this->submenu_exists($parent_slug, $sub_slug)) {
                continue;
            }

            $sub_menu_title = (string) ($sub_item['menu_title'] ?? $sub_slug);
            $sub_page_title = (string) ($sub_item['page_title'] ?? $sub_menu_title);
            $sub_cap = (string) ($sub_item['capability'] ?? $capability);
            if (!current_user_can($sub_cap)) {
                $sub_cap = $capability;
            }

            add_submenu_page(
                $parent_slug,
                esc_html($sub_page_title),
                $sub_menu_title,
                $sub_cap,
                $sub_slug,
                [$this, 'render_snapshot_menu']
            );
        }

        return $targets;
    }

    private function menu_exists(string $target_slug): bool {
        global $menu, $submenu;

        $target_slug = sanitize_text_field($target_slug);
        if ($target_slug === '') {
            return false;
        }

        if (is_array($menu)) {
            foreach ($menu as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $slug = (string) ($item[2] ?? '');
                if ($slug !== '' && $this->menu_slug_matches($slug, $target_slug)) {
                    return true;
                }
            }
        }

        if (is_array($submenu)) {
            foreach ($submenu as $items) {
                if (!is_array($items)) {
                    continue;
                }
                foreach ($items as $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    $slug = (string) ($item[2] ?? '');
                    if ($slug !== '' && $this->menu_slug_matches($slug, $target_slug)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    private function submenu_exists(string $parent_slug, string $target_slug): bool {
        global $submenu;

        $parent_slug = sanitize_text_field($parent_slug);
        $target_slug = sanitize_text_field($target_slug);
        if ($parent_slug === '' || $target_slug === '') {
            return false;
        }

        if (empty($submenu[$parent_slug]) || !is_array($submenu[$parent_slug])) {
            return false;
        }

        foreach ($submenu[$parent_slug] as $item) {
            if (!is_array($item)) {
                continue;
            }
            $slug = (string) ($item[2] ?? '');
            if ($slug !== '' && $this->menu_slug_matches($slug, $target_slug)) {
                return true;
            }
        }

        return false;
    }

    private function get_plugin_menu_slug_patterns(string $plugin_slug, string $plugin_name = ''): array {
        $plugin_slug = sanitize_key((string) $plugin_slug);
        $patterns = [];

        if ($plugin_slug !== '') {
            $escaped_slug = preg_quote($plugin_slug, '/');
            $patterns[] = '/' . $escaped_slug . '/i';
            $patterns[] = '/page=.*' . $escaped_slug . '/i';
        }

        $plugin_name = sanitize_text_field((string) $plugin_name);
        if ($plugin_name !== '') {
            $name_slug = sanitize_title($plugin_name);
            if ($name_slug !== '') {
                $escaped_name = preg_quote($name_slug, '/');
                $patterns[] = '/' . $escaped_name . '/i';
            }
        }

        if ($plugin_slug === 'woocommerce') {
            $patterns = [
                '/^woocommerce$/i',
                '/^wc-/i',
                '/^admin\.php\?page=wc-/i',
                '/post_type=product/i',
                '/post_type=shop_/i',
                '/^wc-admin&path=\//i',
                '/wc-settings/i',
            ];
        }

        return array_values(array_unique(array_filter($patterns)));
    }

    private function collect_menu_slugs_matching_patterns(array $menu, array $submenu, array $patterns): array {
        if (empty($patterns)) {
            return [];
        }

        $matched = [];

        foreach ($menu as $item) {
            if (!is_array($item)) {
                continue;
            }
            $slug = (string) ($item[2] ?? '');
            if ($slug === '') {
                continue;
            }
            foreach ($patterns as $pattern) {
                if ($pattern !== '' && @preg_match($pattern, $slug) === 1) {
                    $matched[] = $slug;
                    break;
                }
            }
        }

        foreach ($submenu as $items) {
            if (!is_array($items)) {
                continue;
            }
            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $slug = (string) ($item[2] ?? '');
                if ($slug === '') {
                    continue;
                }
                foreach ($patterns as $pattern) {
                    if ($pattern !== '' && @preg_match($pattern, $slug) === 1) {
                        $matched[] = $slug;
                        break;
                    }
                }
            }
        }

        return array_values(array_unique(array_filter($matched)));
    }

    private function find_primary_menu_snapshot(string $plugin_dir, string $plugin_name, array $menu, array $submenu): ?array {
        $plugin_dir = strtolower(trim((string) $plugin_dir));
        $plugin_name = strtolower(trim((string) $plugin_name));
        if ($plugin_dir === '' && $plugin_name === '') {
            return null;
        }

        $best_item = null;
        $best_position = null;
        $best_score = 0;

        foreach ($menu as $position => $item) {
            if (!is_array($item)) {
                continue;
            }
            $slug = strtolower((string) ($item[2] ?? ''));
            $menu_title = strtolower(wp_strip_all_tags((string) ($item[0] ?? '')));
            if ($slug === '' && $menu_title === '') {
                continue;
            }

            $score = 0;
            if ($plugin_dir !== '' && str_contains($slug, $plugin_dir)) {
                $score += 3;
            }
            if ($plugin_dir !== '' && str_contains($menu_title, $plugin_dir)) {
                $score += 2;
            }
            if ($plugin_name !== '' && str_contains($menu_title, $plugin_name)) {
                $score += 4;
            }
            if ($plugin_name !== '' && str_contains($slug, $plugin_name)) {
                $score += 1;
            }

            if ($score > $best_score) {
                $best_score = $score;
                $best_item = $item;
                $best_position = $position;
            }
        }

        if (empty($best_item) || $best_score === 0) {
            return null;
        }

        $parent = $this->build_menu_item($best_item, $best_position);
        $parent_slug = (string) ($parent['slug'] ?? '');
        if ($parent_slug === '') {
            return null;
        }

        $submenu_items = [];
        if (!empty($submenu[$parent_slug]) && is_array($submenu[$parent_slug])) {
            foreach ($submenu[$parent_slug] as $sub_item) {
                if (!is_array($sub_item)) {
                    continue;
                }
                $submenu_items[] = $this->build_submenu_item($sub_item);
            }
        }

        return [
            'parent_slug' => $parent_slug,
            'parent' => $parent,
            'submenu' => $submenu_items,
        ];
    }

    private function find_menu_snapshot(string $target_slug, array $menu, array $submenu): ?array {
        $target_slug = sanitize_text_field($target_slug);
        if ($target_slug === '') {
            return null;
        }

        $parent_slug = '';
        foreach ($submenu as $parent => $items) {
            if (!is_array($items)) {
                continue;
            }
            foreach ($items as $item) {
                $slug = is_array($item) ? (string) ($item[2] ?? '') : '';
                if ($slug === '') {
                    continue;
                }
                if ($this->menu_slug_matches($slug, $target_slug)) {
                    $parent_slug = (string) $parent;
                    break 2;
                }
            }
        }

        if ($parent_slug === '') {
            $parent_item = $this->find_menu_item_by_slug($target_slug, $menu);
            if (empty($parent_item)) {
                return null;
            }
            $parent_slug = (string) ($parent_item['slug'] ?? '');
        } else {
            $parent_item = $this->find_menu_item_by_slug($parent_slug, $menu);
            if (empty($parent_item)) {
                return null;
            }
        }

        $submenu_items = [];
        if (!empty($submenu[$parent_slug]) && is_array($submenu[$parent_slug])) {
            foreach ($submenu[$parent_slug] as $sub_item) {
                if (!is_array($sub_item)) {
                    continue;
                }
                $submenu_items[] = $this->build_submenu_item($sub_item);
            }
        }

        return [
            'parent_slug' => $parent_slug,
            'parent' => $parent_item,
            'submenu' => $submenu_items,
        ];
    }

    private function find_menu_item_by_slug(string $slug, array $menu): ?array {
        $slug = sanitize_text_field($slug);
        foreach ($menu as $position => $item) {
            if (!is_array($item)) {
                continue;
            }
            $item_slug = (string) ($item[2] ?? '');
            if ($item_slug === '') {
                continue;
            }
            if ($this->menu_slug_matches($item_slug, $slug) || $item_slug === $slug) {
                return $this->build_menu_item($item, $position);
            }
        }
        return null;
    }

    private function menu_slug_matches(string $slug, string $target_slug): bool {
        $slug = (string) $slug;
        $target_slug = (string) $target_slug;
        if ($slug === $target_slug) {
            return true;
        }

        $page = $this->extract_page_from_slug($slug);
        if ($page !== '' && $page === $target_slug) {
            return true;
        }

        return false;
    }

    private function extract_page_from_slug(string $slug): string {
        $slug = trim((string) $slug);
        if ($slug === '') {
            return '';
        }

        $parts = wp_parse_url($slug);
        if (!is_array($parts)) {
            return '';
        }
        $query = [];
        if (!empty($parts['query'])) {
            parse_str($parts['query'], $query);
        }
        if (!empty($query['page'])) {
            return sanitize_text_field($query['page']);
        }

        return '';
    }

    private function build_menu_item(array $item, $menu_position = null): array {
        $position = $item[7] ?? null;
        if ($position === null && $menu_position !== null) {
            $position = $menu_position;
        }
        return [
            'menu_title' => (string) ($item[0] ?? ''),
            'capability' => (string) ($item[1] ?? 'manage_options'),
            'slug' => (string) ($item[2] ?? ''),
            'page_title' => (string) ($item[3] ?? ($item[0] ?? '')),
            'icon' => (string) ($item[6] ?? ''),
            'position' => $position,
        ];
    }

    private function build_submenu_item(array $item): array {
        return [
            'menu_title' => (string) ($item[0] ?? ''),
            'capability' => (string) ($item[1] ?? 'manage_options'),
            'slug' => (string) ($item[2] ?? ''),
            'page_title' => (string) ($item[3] ?? ($item[0] ?? '')),
        ];
    }

    private function is_current_admin_url(string $url): bool {
        $url = trim((string) $url);
        if ($url === '') {
            return false;
        }

        $scheme = is_ssl() ? 'https://' : 'http://';
        $host = $_SERVER['HTTP_HOST'] ?? '';
        $request_uri = $_SERVER['REQUEST_URI'] ?? '';
        if ($host === '' || $request_uri === '') {
            return false;
        }

        $current = $scheme . $host . $request_uri;
        $current_parts = wp_parse_url($current);
        $target_parts = wp_parse_url($url);
        if (!is_array($current_parts) || !is_array($target_parts)) {
            return false;
        }

        $current_path = $current_parts['path'] ?? '';
        $target_path = $target_parts['path'] ?? '';
        if ($current_path === '' || $target_path === '') {
            return false;
        }

        if (untrailingslashit($current_path) !== untrailingslashit($target_path)) {
            return false;
        }

        $current_query = $current_parts['query'] ?? '';
        $target_query = $target_parts['query'] ?? '';

        return trim((string) $current_query) === trim((string) $target_query);
    }

    // ========================================
    // Frontend Optimization - AJAX Handlers
    // ========================================

    /**
     * AJAX: Save frontend settings
     */
    public function ajax_save_frontend_settings(): void {
        check_ajax_referer('sapm_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Insufficient permissions', 'sapm')]);
        }

        $settings = [
            'enabled' => !empty($_POST['enabled']),
            'admin_bypass' => !empty($_POST['admin_bypass']),
            'sampling_enabled' => !empty($_POST['sampling_enabled']),
            'asset_audit' => !empty($_POST['asset_audit']),
            'wc_protection' => !empty($_POST['wc_protection']),
            'url_patterns' => $this->sanitize_json_input($_POST['url_patterns'] ?? '[]'),
        ];

        $frontend = SAPM_Frontend::init($this->core);
        $result = $frontend->save_settings($settings);

        if ($result) {
            wp_send_json_success(['message' => __('Frontend settings saved', 'sapm')]);
        } else {
            wp_send_json_error(['message' => __('Error saving settings', 'sapm')]);
        }
    }

    /**
     * AJAX: Save frontend plugin rules per context
     */
    public function ajax_save_frontend_rules(): void {
        check_ajax_referer('sapm_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Insufficient permissions', 'sapm')]);
        }

        $rules_raw = $_POST['rules'] ?? '';
        $rules = json_decode(wp_unslash($rules_raw), true);

        if (!is_array($rules)) {
            wp_send_json_error(['message' => __('Invalid rules format', 'sapm')]);
        }

        // Sanitize rules
        $clean_rules = [];
        foreach ($rules as $context_id => $context_rules) {
            $context_id = sanitize_key($context_id);

            $mode = sanitize_key($context_rules['_mode'] ?? 'passthrough');
            if (!in_array($mode, ['passthrough', 'blacklist', 'whitelist'], true)) {
                $mode = 'passthrough';
            }

            $clean_rules[$context_id] = [
                '_mode' => $mode,
                'disabled_plugins' => $this->sanitize_plugin_basename_list($context_rules['disabled_plugins'] ?? []),
                'enabled_plugins' => $this->sanitize_plugin_basename_list($context_rules['enabled_plugins'] ?? []),
            ];
        }

        $frontend = SAPM_Frontend::init($this->core);
        $result = $frontend->save_rules($clean_rules);

        if ($result) {
            wp_send_json_success(['message' => __('Frontend rules saved', 'sapm')]);
        } else {
            wp_send_json_error(['message' => __('Error saving rules', 'sapm')]);
        }
    }

    /**
     * AJAX: Save frontend asset rules (CSS/JS dequeue)
     */
    public function ajax_save_frontend_asset_rules(): void {
        check_ajax_referer('sapm_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Insufficient permissions', 'sapm')]);
        }

        $rules_raw = $_POST['rules'] ?? '';
        $rules = json_decode(wp_unslash($rules_raw), true);

        if (!is_array($rules)) {
            wp_send_json_error(['message' => __('Invalid rules format', 'sapm')]);
        }

        // Sanitize asset rules
        $clean_rules = [];
        foreach ($rules as $context_id => $context_rules) {
            $context_id = sanitize_key($context_id);
            $clean_rules[$context_id] = [
                'dequeue_scripts' => array_map('sanitize_key', $context_rules['dequeue_scripts'] ?? []),
                'dequeue_styles' => array_map('sanitize_key', $context_rules['dequeue_styles'] ?? []),
                'defer_scripts' => array_map('sanitize_key', $context_rules['defer_scripts'] ?? []),
                'async_scripts' => array_map('sanitize_key', $context_rules['async_scripts'] ?? []),
            ];
        }

        $frontend = SAPM_Frontend::init($this->core);
        $result = $frontend->save_asset_rules($clean_rules);

        if ($result) {
            wp_send_json_success(['message' => __('Asset rules saved', 'sapm')]);
        } else {
            wp_send_json_error(['message' => __('Error saving asset rules', 'sapm')]);
        }
    }

    /**
     * AJAX: Get frontend asset audit data
     */
    public function ajax_get_frontend_asset_audit(): void {
        check_ajax_referer('sapm_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Insufficient permissions', 'sapm')]);
        }

        $context = sanitize_key($_POST['context'] ?? '');
        $frontend = SAPM_Frontend::init($this->core);

        if ($context) {
            $audit = $frontend->get_asset_audit($context);
        } else {
            $audit = $frontend->get_asset_audit();
        }

        wp_send_json_success([
            'audit' => $audit,
            'contexts' => array_keys($frontend->get_context_definitions()),
        ]);
    }

    /**
     * AJAX: Get frontend optimization suggestions
     */
    public function ajax_get_frontend_suggestions(): void {
        check_ajax_referer('sapm_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Insufficient permissions', 'sapm')]);
        }

        $frontend = SAPM_Frontend::init($this->core);
        $suggestions = $frontend->get_auto_suggestions();
        $performance = $frontend->get_frontend_performance_summary();

        wp_send_json_success([
            'suggestions' => $suggestions,
            'performance' => $performance,
            'safe_mode_url' => $frontend->get_safe_mode_url(),
        ]);
    }

    /**
     * Helper: Sanitize JSON input from POST
     */
    private function sanitize_json_input(string $input): array {
        $decoded = json_decode(wp_unslash($input), true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Sanitize seznam plugin basename hodnot.
     *
     * @param mixed $plugins
     * @return array
     */
    private function sanitize_plugin_basename_list($plugins): array {
        if (!is_array($plugins)) {
            return [];
        }

        $clean = [];
        foreach ($plugins as $plugin) {
            $plugin = wp_normalize_path(wp_unslash((string) $plugin));
            $plugin = sanitize_text_field($plugin);

            if ($plugin === '' || strpos($plugin, '..') !== false) {
                continue;
            }

            $clean[] = $plugin;
        }

        return array_values(array_unique($clean));
    }

    // ========================================
    // Frontend Optimization - Settings Page Render
    // ========================================

    /**
     * Render the frontend optimization tab content.
     * Called from the main settings page renderer.
     */
    public function render_frontend_tab(): void {
        $frontend = SAPM_Frontend::init($this->core);
        $settings = $frontend->get_settings();
        $rules = $frontend->get_rules();
        $asset_rules = $frontend->get_asset_rules();
        $contexts = $frontend->get_context_definitions();
        $safe_mode_url = $frontend->get_safe_mode_url();

        // Group contexts
        $grouped = [];
        foreach ($contexts as $id => $def) {
            $group = $def['group'] ?? 'other';
            $grouped[$group][$id] = $def;
        }

        // Get active plugins for the rule selectors
        $all_plugins = get_plugins();
        $active_plugins = get_option('active_plugins', []);

        $group_labels = [
            'woocommerce' => __('WooCommerce', 'sapm'),
            'core'        => __('WordPress Core', 'sapm'),
            'content'     => __('Content', 'sapm'),
            'archive'     => __('Archives', 'sapm'),
            'special'     => __('Special', 'sapm'),
            'other'       => __('Other', 'sapm'),
        ];

        $group_icons = [
            'woocommerce' => 'dashicons-cart',
            'core'        => 'dashicons-wordpress',
            'content'     => 'dashicons-admin-post',
            'archive'     => 'dashicons-archive',
            'special'     => 'dashicons-admin-tools',
            'other'       => 'dashicons-admin-generic',
        ];
        ?>

        <div class="sapm-notice info sapm-fe-intro-notice">
            <strong><?php _e('💡 Tip:', 'sapm'); ?></strong>
            <?php _e('Frontend Optimizer selectively loads plugins and CSS/JS assets per page type, significantly reducing load time for visitors. Start with "Passthrough" and gradually block unused plugins per context.', 'sapm'); ?>
        </div>

        <div class="sapm-screen-group sapm-fe-settings-group">
            <div class="sapm-group-header">
                <h3>
                    <span class="dashicons dashicons-admin-settings"></span>
                    <span class="sapm-group-title-text"><?php _e('Frontend Settings', 'sapm'); ?></span>
                    <span class="dashicons dashicons-arrow-down-alt2 sapm-collapse-icon"></span>
                </h3>
            </div>
            <div class="sapm-group-content">
                <div class="sapm-screen-row">
                    <div class="sapm-screen-name">
                        <?php _e('Enable Frontend Filtering', 'sapm'); ?>
                        <small><?php _e('When disabled, all plugins load normally on every frontend page.', 'sapm'); ?></small>
                    </div>
                    <div class="sapm-screen-plugins">
                        <label class="sapm-rt-plugin-check <?php echo !empty($settings['enabled']) ? 'checked' : ''; ?>">
                            <input type="checkbox" id="sapm-fe-enabled" <?php checked($settings['enabled']); ?>>
                            <span class="check-box"></span>
                            <span class="plugin-name"><?php _e('Enabled', 'sapm'); ?></span>
                        </label>
                    </div>
                </div>

                <div class="sapm-screen-row">
                    <div class="sapm-screen-name">
                        <?php _e('Safety', 'sapm'); ?>
                    </div>
                    <div class="sapm-screen-plugins">
                        <label class="sapm-rt-plugin-check <?php echo !empty($settings['admin_bypass']) ? 'checked' : ''; ?>">
                            <input type="checkbox" id="sapm-fe-admin-bypass" <?php checked($settings['admin_bypass']); ?>>
                            <span class="check-box"></span>
                            <span class="plugin-name"><?php _e('Admin Bypass', 'sapm'); ?></span>
                        </label>
                        <label class="sapm-rt-plugin-check <?php echo !empty($settings['wc_protection']) ? 'checked' : ''; ?>">
                            <input type="checkbox" id="sapm-fe-wc-protection" <?php checked($settings['wc_protection']); ?>>
                            <span class="check-box"></span>
                            <span class="plugin-name"><?php _e('WooCommerce Protection', 'sapm'); ?></span>
                        </label>
                    </div>
                </div>

                <div class="sapm-screen-row">
                    <div class="sapm-screen-name">
                        <?php _e('Data Collection', 'sapm'); ?>
                    </div>
                    <div class="sapm-screen-plugins">
                        <label class="sapm-rt-plugin-check <?php echo !empty($settings['sampling_enabled']) ? 'checked' : ''; ?>">
                            <input type="checkbox" id="sapm-fe-sampling" <?php checked($settings['sampling_enabled']); ?>>
                            <span class="check-box"></span>
                            <span class="plugin-name"><?php _e('Performance Sampling', 'sapm'); ?></span>
                        </label>
                        <label class="sapm-rt-plugin-check <?php echo !empty($settings['asset_audit']) ? 'checked' : ''; ?>">
                            <input type="checkbox" id="sapm-fe-asset-audit" <?php checked($settings['asset_audit']); ?>>
                            <span class="check-box"></span>
                            <span class="plugin-name"><?php _e('Asset Audit', 'sapm'); ?></span>
                        </label>
                    </div>
                </div>

                <div class="sapm-screen-row">
                    <div class="sapm-screen-name">
                        <?php _e('Emergency Recovery', 'sapm'); ?>
                        <small><?php _e('Use this URL to bypass all frontend filtering in case of problems. Bookmark it!', 'sapm'); ?></small>
                    </div>
                    <div class="sapm-screen-plugins">
                        <code id="sapm-fe-safe-url"><?php echo esc_html($safe_mode_url); ?></code>
                    </div>
                </div>

                <div class="sapm-rt-footer-actions">
                    <button type="button" class="button button-primary button-hero" id="sapm-fe-save-settings">
                        <span class="dashicons dashicons-saved"></span>
                        <?php _e('Save Frontend Settings', 'sapm'); ?>
                    </button>
                    <span id="sapm-fe-settings-status"></span>
                </div>
            </div>
        </div>

        <div class="sapm-screen-group sapm-fe-plugin-rules sapm-request-types" id="sapm-fe-request-types">
            <div class="sapm-group-header">
                <h3>
                    <span class="dashicons dashicons-admin-plugins"></span>
                    <span class="sapm-group-title-text"><?php _e('Plugin Rules per Page Type', 'sapm'); ?></span>
                    <span class="dashicons dashicons-arrow-down-alt2 sapm-collapse-icon"></span>
                </h3>
            </div>
            <div class="sapm-group-content">
            <div class="sapm-rt-warning-box">
                <div class="sapm-rt-warning-icon">
                    <span class="dashicons dashicons-warning"></span>
                </div>
                <div class="sapm-rt-warning-content">
                    <strong><?php _e('Frontend context rules', 'sapm'); ?></strong>
                    <p><?php _e('Configure which plugins to load (whitelist) or block (blacklist) per frontend context. Default is Passthrough. Start gradually to avoid frontend breakage.', 'sapm'); ?></p>
                </div>
            </div>

            <?php foreach ($grouped as $group => $group_contexts): ?>
            <?php
                $group_stats = [
                    'enabled' => 0,
                    'disabled' => 0,
                ];

                foreach ($group_contexts as $ctx_id => $ctx_def) {
                    $ctx_rules = $rules[$ctx_id] ?? [];
                    $ctx_mode = $ctx_rules['_mode'] ?? 'passthrough';
                    if ($ctx_mode === 'blacklist') {
                        $group_stats['disabled'] += count((array) ($ctx_rules['disabled_plugins'] ?? []));
                    } elseif ($ctx_mode === 'whitelist') {
                        $group_stats['enabled'] += count((array) ($ctx_rules['enabled_plugins'] ?? []));
                    }
                }
            ?>
            <div class="sapm-screen-group sapm-fe-context-group" data-group="<?php echo esc_attr($group); ?>">
                <div class="sapm-group-header">
                    <h3>
                        <span class="dashicons <?php echo esc_attr($group_icons[$group] ?? 'dashicons-admin-generic'); ?>"></span>
                        <span class="sapm-group-title-text"><?php echo esc_html($group_labels[$group] ?? ucfirst($group)); ?></span>
                        <span class="sapm-group-header-stats" aria-live="polite" data-show-delay="0">
                            <span class="sapm-group-stat is-block"><strong><?php echo (int) $group_stats['disabled']; ?></strong><em><?php esc_html_e('Block', 'sapm'); ?></em></span>
                            <span class="sapm-group-stat is-allow"><strong><?php echo (int) $group_stats['enabled']; ?></strong><em><?php esc_html_e('Allow', 'sapm'); ?></em></span>
                        </span>
                        <span class="dashicons dashicons-arrow-down-alt2 sapm-collapse-icon"></span>
                    </h3>
                </div>
                <div class="sapm-group-content">
                <div class="sapm-rt-grid">
                <?php foreach ($group_contexts as $ctx_id => $ctx_def):
                    $ctx_rules = $rules[$ctx_id] ?? [];
                    $ctx_mode = $ctx_rules['_mode'] ?? 'passthrough';
                    $badge_class = 'mode-' . $ctx_mode;
                    if ($ctx_mode === 'blacklist') {
                        $badge_text = __('Blacklist', 'sapm');
                    } elseif ($ctx_mode === 'whitelist') {
                        $badge_text = __('Whitelist', 'sapm');
                    } else {
                        $badge_text = __('Passthrough', 'sapm');
                    }
                ?>
                <details class="sapm-rt-card sapm-context-row" data-context="<?php echo esc_attr($ctx_id); ?>" data-mode="<?php echo esc_attr($ctx_mode); ?>"<?php echo $ctx_mode !== 'passthrough' ? ' open' : ''; ?>>
                    <summary class="sapm-rt-card-header">
                        <div class="sapm-rt-card-title">
                            <div class="sapm-rt-icon-wrapper">
                                <span class="dashicons dashicons-admin-site-alt3"></span>
                            </div>
                            <div class="sapm-rt-title-text">
                                <strong><?php echo esc_html($ctx_def['label']); ?></strong>
                                <?php if (!empty($ctx_def['desc'])): ?>
                                    <span class="sapm-rt-desc"><?php echo esc_html($ctx_def['desc']); ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="sapm-rt-header-meta">
                            <span class="sapm-mode-pill <?php echo esc_attr($badge_class); ?>"><?php echo esc_html($badge_text); ?></span>
                            <span class="dashicons dashicons-arrow-down-alt2 sapm-rt-toggle-icon"></span>
                        </div>
                    </summary>
                    <div class="sapm-rt-card-body">
                        <div class="sapm-rt-mode-selector sapm-fe-mode-selector">
                            <label class="sapm-rt-radio-option sapm-fe-mode-option <?php echo $ctx_mode === 'passthrough' ? 'selected' : ''; ?>">
                                <input type="radio" name="sapm_fe_mode_<?php echo esc_attr($ctx_id); ?>"
                                       value="passthrough" <?php checked($ctx_mode, 'passthrough'); ?>
                                       class="sapm-context-mode-radio" data-context="<?php echo esc_attr($ctx_id); ?>">
                                <div class="sapm-rt-radio-visual">
                                    <svg class="sapm-radio-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <circle cx="12" cy="12" r="10"></circle>
                                        <path class="check" d="M8 12l3 3 5-5"></path>
                                    </svg>
                                </div>
                                <div class="sapm-rt-radio-content">
                                    <strong><?php _e('Passthrough', 'sapm'); ?></strong>
                                    <span class="meta"><?php _e('Load all plugins', 'sapm'); ?></span>
                                </div>
                            </label>
                            <label class="sapm-rt-radio-option sapm-fe-mode-option sapm-fe-mode-option-blacklist <?php echo $ctx_mode === 'blacklist' ? 'selected' : ''; ?>">
                                <input type="radio" name="sapm_fe_mode_<?php echo esc_attr($ctx_id); ?>"
                                       value="blacklist" <?php checked($ctx_mode, 'blacklist'); ?>
                                       class="sapm-context-mode-radio" data-context="<?php echo esc_attr($ctx_id); ?>">
                                <div class="sapm-rt-radio-visual">
                                    <svg class="sapm-radio-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <circle cx="12" cy="12" r="10"></circle>
                                        <path class="check" d="M8 12l3 3 5-5"></path>
                                    </svg>
                                </div>
                                <div class="sapm-rt-radio-content">
                                    <strong><?php _e('Blacklist', 'sapm'); ?></strong>
                                    <span class="meta"><?php _e('Block selected plugins', 'sapm'); ?></span>
                                </div>
                            </label>
                            <label class="sapm-rt-radio-option sapm-fe-mode-option sapm-fe-mode-option-whitelist <?php echo $ctx_mode === 'whitelist' ? 'selected' : ''; ?>">
                                <input type="radio" name="sapm_fe_mode_<?php echo esc_attr($ctx_id); ?>"
                                       value="whitelist" <?php checked($ctx_mode, 'whitelist'); ?>
                                       class="sapm-context-mode-radio" data-context="<?php echo esc_attr($ctx_id); ?>">
                                <div class="sapm-rt-radio-visual">
                                    <svg class="sapm-radio-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <circle cx="12" cy="12" r="10"></circle>
                                        <path class="check" d="M8 12l3 3 5-5"></path>
                                    </svg>
                                </div>
                                <div class="sapm-rt-radio-content">
                                    <strong><?php _e('Whitelist', 'sapm'); ?></strong>
                                    <span class="meta"><?php _e('Allow selected plugins only', 'sapm'); ?></span>
                                </div>
                            </label>
                        </div>
                        <div class="sapm-rt-config-area">
                            <div class="sapm-rt-config-header danger sapm-context-config-header sapm-context-config-blacklist" style="display: <?php echo $ctx_mode === 'blacklist' ? 'flex' : 'none'; ?>;">
                                <span class="dashicons dashicons-no-alt"></span>
                                <?php _e('Select plugins to BLOCK:', 'sapm'); ?>
                            </div>
                            <div class="sapm-rt-config-header success sapm-context-config-header sapm-context-config-whitelist" style="display: <?php echo $ctx_mode === 'whitelist' ? 'flex' : 'none'; ?>;">
                                <span class="dashicons dashicons-yes-alt"></span>
                                <?php _e('Select plugins to ALLOW:', 'sapm'); ?>
                            </div>
                            <div class="sapm-context-plugins sapm-screen-plugins" style="display:<?php echo $ctx_mode !== 'passthrough' ? 'flex' : 'none'; ?>">
                                <?php foreach ($active_plugins as $plugin_file):
                                    $plugin_data = $all_plugins[$plugin_file] ?? [];
                                    $plugin_name = $plugin_data['Name'] ?? $plugin_file;
                                    $is_disabled = in_array($plugin_file, $ctx_rules['disabled_plugins'] ?? [], true);
                                    $is_enabled = in_array($plugin_file, $ctx_rules['enabled_plugins'] ?? [], true);
                                    $tag_state = 'default';
                                    if ($ctx_mode === 'blacklist' && $is_disabled) {
                                        $tag_state = 'disabled';
                                    } elseif ($ctx_mode === 'whitelist' && $is_enabled) {
                                        $tag_state = 'enabled';
                                    }
                                    $icon = $tag_state === 'enabled' ? 'yes' : ($tag_state === 'disabled' ? 'no' : 'minus');
                                ?>
                                <span class="sapm-plugin-tag <?php echo esc_attr($tag_state); ?>"
                                      data-plugin="<?php echo esc_attr($plugin_file); ?>"
                                      data-state="<?php echo esc_attr($tag_state); ?>"
                                      title="<?php echo esc_attr($plugin_file); ?>">
                                    <span class="dashicons dashicons-<?php echo esc_attr($icon); ?>"></span>
                                    <?php echo esc_html($plugin_name); ?>
                                </span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </details>
                <?php endforeach; ?>
                </div>
                </div>
            </div>
            <?php endforeach; ?>

            <div class="sapm-rt-footer-actions">
                <button type="button" class="button button-primary button-hero" id="sapm-fe-save-rules">
                    <span class="dashicons dashicons-saved"></span>
                    <?php _e('Save Plugin Rules', 'sapm'); ?>
                </button>
                <span id="sapm-fe-rules-status"></span>
            </div>
            </div>
        </div>

        <div class="sapm-screen-group sapm-fe-asset-manager">
            <div class="sapm-group-header">
                <h3>
                    <span class="dashicons dashicons-editor-code"></span>
                    <span class="sapm-group-title-text"><?php _e('Asset Manager (CSS/JS)', 'sapm'); ?></span>
                    <span class="dashicons dashicons-arrow-down-alt2 sapm-collapse-icon"></span>
                </h3>
            </div>
            <div class="sapm-group-content">
            <p>
                <?php _e('Dequeue unnecessary CSS/JS files per page type. Enable Asset Audit first to discover which assets are loaded.', 'sapm'); ?>
            </p>

            <div id="sapm-fe-asset-audit-results">
                <div class="sapm-rt-perf-actions">
                    <button type="button" class="button button-secondary" id="sapm-fe-load-audit">
                        <span class="dashicons dashicons-update"></span>
                        <?php _e('Load Asset Audit Data', 'sapm'); ?>
                    </button>
                </div>
                <div id="sapm-fe-audit-content"></div>
            </div>
            </div>
        </div>
        <?php
    }
}
