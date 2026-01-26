<?php
/**
 * Uninstall Smart Admin Plugin Manager
 * 
 * This file runs automatically when the plugin is deleted via WordPress admin.
 * It removes all plugin data including:
 * - Database tables (sapm_sampling_data)
 * - WordPress options (7 options)
 * - MU-plugin loader file
 * - Scheduled cron jobs (sapm_daily_cleanup, sapm_cron_update_check)
 * - Transients (all _transient_sapm_*)
 * 
 * IMPORTANT:
 * - This file only runs when user clicks "Delete" in Plugins screen
 * - Plugin must be deactivated first
 * - WordPress automatically deletes plugin files - this only cleans database
 * - Multisite compatible - cleans data from all subsites
 *
 * @package SmartAdminPluginManager
 * @since 1.2.0
 * @link https://developer.wordpress.org/plugins/plugin-basics/uninstall-methods/
 */

// Exit if not called by WordPress during uninstall
// This constant is ONLY defined when WordPress executes this file
if (!defined('WP_UNINSTALL_PLUGIN')) {
    die('Direct access not allowed');
}

/**
 * Delete plugin database table
 * 
 * Drops the custom database table created by the plugin.
 * Table: {prefix}_sapm_sampling_data (stores plugin performance sampling data)
 * 
 * In multisite, drops table from each subsite (each has own prefix).
 */
function sapm_uninstall_drop_tables() {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'sapm_sampling_data';
    
    // Drop the table if it exists (suppresses errors if doesn't exist)
    $wpdb->query("DROP TABLE IF EXISTS `{$table_name}`");
    
    // For multisite: drop tables from all subsites
    if (is_multisite()) {
        $blog_ids = $wpdb->get_col("SELECT blog_id FROM {$wpdb->blogs}");
        $original_blog_id = get_current_blog_id();
        
        foreach ($blog_ids as $blog_id) {
            switch_to_blog($blog_id);
            
            // Each blog has its own table with its prefix
            $table_name = $wpdb->prefix . 'sapm_sampling_data';
            $wpdb->query("DROP TABLE IF EXISTS `{$table_name}`");
        }
        
        // Restore original blog context
        switch_to_blog($original_blog_id);
    }
}

/**
 * Delete all plugin options
 * 
 * Removes all options created by the plugin from wp_options table.
 * In multisite, removes from all subsites individually.
 */
function sapm_uninstall_delete_options() {
    // List of all plugin options to delete
    $options = [
        'sapm_db_version',              // Database schema version
        'sapm_last_cron_update_check',  // Last update check timestamp
        'sapm_update_optimizer_config', // Update optimizer configuration
        'sapm_mode',                    // Plugin operation mode (manual/auto)
        'sapm_request_type_rules',      // AJAX/REST/Cron blocking rules
        'sapm_plugin_rules',            // Main plugin rules (SAPM_OPTION_KEY)
        'sapm_menu_snapshots',          // Admin menu snapshots (SAPM_MENU_SNAPSHOT_OPTION)
    ];
    
    // Delete from current site (or main site in multisite)
    foreach ($options as $option) {
        delete_option($option);
        
        // Also delete from site options (network-wide) if exists
        if (is_multisite()) {
            delete_site_option($option);
        }
    }
    
    // For multisite: delete from all subsites
    if (is_multisite()) {
        global $wpdb;
        
        // Get all blog IDs
        $blog_ids = $wpdb->get_col("SELECT blog_id FROM {$wpdb->blogs}");
        $original_blog_id = get_current_blog_id();
        
        foreach ($blog_ids as $blog_id) {
            switch_to_blog($blog_id);
            
            // Delete options from this subsite
            foreach ($options as $option) {
                delete_option($option);
            }
        }
        
        // Restore original blog context
        switch_to_blog($original_blog_id);
    }
}

/**
 * Delete all plugin transients
 * 
 * Removes all transients cached by the plugin.
 * Includes both _transient_sapm_* and _transient_timeout_sapm_* entries.
 */
function sapm_uninstall_delete_transients() {
    global $wpdb;
    
    // Delete transients with 'sapm_' prefix from current site
    // Note: This includes both _transient_sapm_* and _transient_timeout_sapm_*
    $wpdb->query(
        "DELETE FROM {$wpdb->options} 
         WHERE option_name LIKE '_transient_sapm_%' 
         OR option_name LIKE '_transient_timeout_sapm_%'"
    );
    
    // For multisite: delete from all subsites
    if (is_multisite()) {
        $blog_ids = $wpdb->get_col("SELECT blog_id FROM {$wpdb->blogs}");
        $original_blog_id = get_current_blog_id();
        
        foreach ($blog_ids as $blog_id) {
            switch_to_blog($blog_id);
            
            // Delete transients from this subsite
            $wpdb->query(
                "DELETE FROM {$wpdb->options} 
                 WHERE option_name LIKE '_transient_sapm_%' 
                 OR option_name LIKE '_transient_timeout_sapm_%'"
            );
        }
        
        // Restore original blog context
        switch_to_blog($original_blog_id);
    }
}

/**
 * Delete MU-plugin loader file
 * 
 * Deletes the must-use plugin loader file that was created during activation.
 * File: wp-content/mu-plugins/smart-admin-plugin-manager.php
 * 
 * This file is outside the plugin directory, so WordPress won't delete it
 * automatically. We must remove it during uninstall.
 * 
 * Uses @ suppressor for unlink() to prevent errors if file doesn't exist
 * or lacks write permissions.
 */
function sapm_uninstall_remove_mu_loader() {
    $mu_file = WP_CONTENT_DIR . '/mu-plugins/smart-admin-plugin-manager.php';
    
    // Remove MU-plugin loader if it exists
    if (file_exists($mu_file)) {
        // Use @ to suppress errors (e.g., permission denied)
        @unlink($mu_file);
    }
    
    // For multisite: check if there's a network-wide MU-plugin location
    if (is_multisite() && defined('WPMU_PLUGIN_DIR')) {
        $network_mu_file = WPMU_PLUGIN_DIR . '/smart-admin-plugin-manager.php';
        
        // Only delete if it exists and is different from the main file
        if (file_exists($network_mu_file) && $network_mu_file !== $mu_file) {
            @unlink($network_mu_file);
        }
    }
}

/**
 * Clear scheduled cron jobs
 * 
 * Removes all scheduled cron jobs created by the plugin.
 * 
 * Jobs removed:
 * - sapm_daily_cleanup: Daily cleanup of old sampling data
 * - sapm_cron_update_check: Daily check for plugin updates
 * 
 * wp_clear_scheduled_hook() removes ALL scheduled instances of the hook,
 * which is safer than wp_unschedule_event() for cleanup.
 */
function sapm_uninstall_clear_cron() {
    // List of all cron hooks to clear
    $cron_hooks = [
        'sapm_daily_cleanup',      // Daily cleanup of sampling data
        'sapm_cron_update_check',  // Daily update check
    ];
    
    // Clear all cron jobs from current site
    foreach ($cron_hooks as $hook) {
        // wp_clear_scheduled_hook() removes ALL instances of this hook
        // This is more thorough than wp_unschedule_event()
        wp_clear_scheduled_hook($hook);
    }
    
    // For multisite: clear cron jobs from all subsites
    if (is_multisite()) {
        global $wpdb;
        $blog_ids = $wpdb->get_col("SELECT blog_id FROM {$wpdb->blogs}");
        $original_blog_id = get_current_blog_id();
        
        foreach ($blog_ids as $blog_id) {
            switch_to_blog($blog_id);
            
            // Clear cron hooks from this subsite
            foreach ($cron_hooks as $hook) {
                wp_clear_scheduled_hook($hook);
            }
        }
        
        // Restore original blog context
        switch_to_blog($original_blog_id);
    }
}

// ============================================================================
// Main Uninstall Function
// ============================================================================

/**
 * Main uninstall function
 * 
 * Executes all cleanup tasks in the correct order.
 * This is called automatically by WordPress when the plugin is deleted.
 * 
 * Cleanup order:
 * 1. Database tables - drops custom tables
 * 2. WordPress options - deletes all settings
 * 3. Transients - removes cached data
 * 4. MU-plugin loader - deletes external file
 * 5. Cron jobs - unschedules all tasks
 * 6. Object cache - flushes any remaining cache
 * 
 * Note: WordPress automatically deletes plugin files from wp-content/plugins/.
 * We only need to clean up database and external files (like MU-plugin).
 */
function sapm_uninstall() {
    // 1. Remove database tables
    sapm_uninstall_drop_tables();
    
    // 2. Remove all WordPress options
    sapm_uninstall_delete_options();
    
    // 3. Remove transients (cached data)
    sapm_uninstall_delete_transients();
    
    // 4. Remove MU-plugin loader file (outside plugin directory)
    sapm_uninstall_remove_mu_loader();
    
    // 5. Clear scheduled cron jobs
    sapm_uninstall_clear_cron();
    
    // 6. Clear any remaining object cache
    // This ensures no stale data remains in Redis/Memcached
    if (function_exists('wp_cache_flush')) {
        wp_cache_flush();
    }
}

// ============================================================================
// Execute Uninstall
// ============================================================================

// Run the complete uninstall process
sapm_uninstall();
