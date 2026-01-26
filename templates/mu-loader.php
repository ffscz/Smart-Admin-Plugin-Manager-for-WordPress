<?php
/**
 * Plugin Name: Smart Admin Plugin Manager (MU Loader)
 * Description: MU loader for Smart Admin Plugin Manager (core logic runs in the regular plugin).
 * Version: {{VERSION}}
 * Author: FFS.cz
 * Text Domain: sapm
 */

if (!defined('ABSPATH')) {
    exit;
}

$plugin_dir = WP_PLUGIN_DIR . '/smart-admin-plugin-manager/includes';
$dependencies_path = $plugin_dir . '/class-sapm-dependencies.php';
$core_path = $plugin_dir . '/class-sapm-core.php';

if (file_exists($dependencies_path)) {
    require_once $dependencies_path;
}

if (file_exists($core_path)) {
    require_once $core_path;
    if (class_exists('SAPM_Core')) {
        SAPM_Core::init();
    }
}
