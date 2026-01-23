<?php
// If uninstall not called from WordPress, exit
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Get plugin options
$options = [
    'site_logger_version',
    'site_logger_retention_days',
];

// Delete options
foreach ($options as $option) {
    delete_option($option);
}

// Delete database table
global $wpdb;
$table_name = $wpdb->prefix . 'site_logs';
$wpdb->query("DROP TABLE IF EXISTS $table_name");

// Clear scheduled hooks
wp_clear_scheduled_hook('site_logger_daily_cleanup');