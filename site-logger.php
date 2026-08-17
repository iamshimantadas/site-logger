<?php
/**
 * Plugin Name: TE Site Logger
 * Plugin URI: 
 * Description: Activity logger for wordpress. Log post update, user update, login of user, media actions etc.  
 * Version: 1.0.0
 * Author: Shimanta Das(TE)
 * Author URI:  https://techievolve.com/
 * License: GPL v2 or later
 * Text Domain: site-logger
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('SITE_LOGGER_VERSION', '1.0.0');
define('SITE_LOGGER_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SITE_LOGGER_PLUGIN_URL', plugin_dir_url(__FILE__));
define('SITE_LOGGER_PLUGIN_BASENAME', plugin_basename(__FILE__));

// Include plugin classes
require_once SITE_LOGGER_PLUGIN_DIR . 'includes/class-site-logger.php';
require_once SITE_LOGGER_PLUGIN_DIR . 'includes/class-site-logger-hooks.php';
require_once SITE_LOGGER_PLUGIN_DIR . 'includes/class-site-logger-export.php';

// Initialize plugin
add_action('plugins_loaded', function () {
    Site_Logger::init();
});

// Activation hook
register_activation_hook(__FILE__, ['Site_Logger', 'activate']);

// Deactivation hook
register_deactivation_hook(__FILE__, ['Site_Logger', 'deactivate']);

// Handle export requests early
add_action('admin_init', function () {
    if (isset($_GET['page']) && $_GET['page'] === 'site-logs' && isset($_GET['export_type'])) {
        Site_Logger_Export::handle_export_request();
    }
});