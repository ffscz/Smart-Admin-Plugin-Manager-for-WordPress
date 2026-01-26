<?php
/**
 * Plugin Name: Smart Admin Plugin Manager
 * Plugin URI: https://ffs.cz
 * Description: Plugin loading management in WordPress admin - per screen control.
 * Version: 1.2.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: FFS.cz
 * Author URI: https://ffs.cz
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: sapm
 */

if (!defined('ABSPATH')) {
    exit;
}

define('SAPM_PLUGIN_FILE', __FILE__);
define('SAPM_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SAPM_PLUGIN_URL', plugin_dir_url(__FILE__));

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

require_once SAPM_PLUGIN_DIR . 'includes/class-sapm-database.php';
require_once SAPM_PLUGIN_DIR . 'includes/class-sapm-dependencies.php';
require_once SAPM_PLUGIN_DIR . 'includes/class-sapm-core.php';
require_once SAPM_PLUGIN_DIR . 'includes/class-sapm-admin.php';
require_once SAPM_PLUGIN_DIR . 'includes/class-sapm-update-optimizer.php';

register_activation_hook(__FILE__, 'sapm_activate_plugin');
register_deactivation_hook(__FILE__, 'sapm_deactivate_plugin');

function sapm_activate_plugin(): void {
    sapm_create_mu_loader();
    SAPM_Database::create_tables();
    SAPM_Database::schedule_cleanup();
}

function sapm_deactivate_plugin(): void {
    sapm_remove_mu_loader();
    SAPM_Database::unschedule_cleanup();
}

add_action('admin_init', 'sapm_maybe_refresh_mu_loader');

add_action('init', function () {
    load_plugin_textdomain('sapm', false, dirname(plugin_basename(__FILE__)) . '/languages');
});

add_action('plugins_loaded', function () {
    $core = SAPM_Core::init();
    if (is_admin()) {
        SAPM_Admin::init($core);
    }
});

// Daily cleanup cron hook
add_action('sapm_daily_cleanup', ['SAPM_Database', 'run_scheduled_cleanup']);

function sapm_get_mu_loader_contents(): string {
    $template_path = SAPM_PLUGIN_DIR . 'templates/mu-loader.php';
    $contents = '';

    if (file_exists($template_path)) {
        $contents = file_get_contents($template_path);
    }

    if ($contents === '' || $contents === false) {
        $contents = "<?php\n" .
            "/**\n" .
            " * Plugin Name: Smart Admin Plugin Manager (MU Loader)\n" .
            " * Description: MU loader for Smart Admin Plugin Manager (core logic runs in the regular plugin).\n" .
            " * Version: " . SAPM_VERSION . "\n" .
            " * Author:\n" .
            " * Text Domain: sapm\n" .
            " */\n\n" .
            "if (!defined('ABSPATH')) {\n    exit;\n}\n\n" .
            "$core_path = WP_PLUGIN_DIR . '/smart-admin-plugin-manager/includes/class-sapm-core.php';\n" .
            "if (file_exists($core_path)) {\n" .
            "    require_once $core_path;\n" .
            "    if (class_exists('SAPM_Core')) {\n" .
            "        SAPM_Core::init();\n" .
            "    }\n" .
            "}\n";
    }

    return str_replace('{{VERSION}}', SAPM_VERSION, $contents);
}

function sapm_create_mu_loader(): void {
    $mu_dir = WP_CONTENT_DIR . '/mu-plugins';
    $mu_file = $mu_dir . '/smart-admin-plugin-manager.php';

    $real_mu_dir = defined('WPMU_PLUGIN_DIR') ? realpath(WPMU_PLUGIN_DIR) : realpath(WP_CONTENT_DIR . '/mu-plugins');
    if ($real_mu_dir === false) {
        $real_content_dir = realpath(WP_CONTENT_DIR);
        if ($real_content_dir === false || strpos($mu_dir, $real_content_dir) !== 0) {
            return;
        }
    } else {
        $expected_path = $real_mu_dir . DIRECTORY_SEPARATOR . 'smart-admin-plugin-manager.php';
        if (realpath(dirname($mu_file)) !== $real_mu_dir) {
            return;
        }
    }

    if (!is_dir($mu_dir)) {
        wp_mkdir_p($mu_dir);
    }

    $contents = sapm_get_mu_loader_contents();
    if ($contents !== '') {
        file_put_contents($mu_file, $contents);
    }
}

function sapm_remove_mu_loader(): void {
    $mu_file = WP_CONTENT_DIR . '/mu-plugins/smart-admin-plugin-manager.php';
    if (file_exists($mu_file)) {
        unlink($mu_file);
    }
}

function sapm_maybe_refresh_mu_loader(): void {
    $mu_file = WP_CONTENT_DIR . '/mu-plugins/smart-admin-plugin-manager.php';
    if (!file_exists($mu_file)) {
        return;
    }

    $expected = sapm_get_mu_loader_contents();
    $current = file_get_contents($mu_file);
    if ($current === false) {
        return;
    }

    if (trim($current) !== trim($expected)) {
        file_put_contents($mu_file, $expected);
    }
}
