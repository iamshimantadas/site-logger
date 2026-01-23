<?php
/**
 * Plugin Name: Site Logger
 * Plugin URI: https://github.com/yourusername/site-logger
 * Description: Minimal activity logger for WordPress
 * Version: 1.0.0
 * Author: Your Name
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

// Include background processing library
// if (!class_exists('WP_Async_Request')) {
//     require_once SITE_LOGGER_PLUGIN_DIR . 'vendor/wp-background-processing/wp-async-request.php';
// }

// if (!class_exists('WP_Background_Process')) {
//     require_once SITE_LOGGER_PLUGIN_DIR . 'vendor/wp-background-processing/wp-background-process.php';
// }

 require_once SITE_LOGGER_PLUGIN_DIR . 'vendor/wp-background-processing/wp-background-processing.php';

// Include plugin classes
require_once SITE_LOGGER_PLUGIN_DIR . 'includes/class-site-logger.php';
require_once SITE_LOGGER_PLUGIN_DIR . 'includes/class-site-logger-hooks.php';

// Initialize plugin
add_action('plugins_loaded', function() {
    Site_Logger::init();
});

// Activation hook
register_activation_hook(__FILE__, ['Site_Logger', 'activate']);

// Deactivation hook
register_deactivation_hook(__FILE__, ['Site_Logger', 'deactivate']);