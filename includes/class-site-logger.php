<?php
class Site_Logger {
    
    /**
     * Plugin instance
     */
    private static $instance = null;
    
    /**
     * Database table name
     */
    const TABLE_NAME = 'site_logs';
    
    /**
     * Severity levels
     */
    const SEVERITY_LEVELS = [
        'emergency',
        'alert',
        'critical',
        'error',
        'warning',
        'notice',
        'info',
        'debug'
    ];
    
    /**
     * Options to skip logging (too noisy)
     */
    const SKIP_OPTIONS = [
        'cron',
        'recently_activated',
        '_transient_',
        '_site_transient_',
        'rewrite_rules',
        'can_compress_scripts',
        'auto_updater.lock',
        'finished_splitting_shared_terms',
        'db_upgraded',
    ];
    
    /**
     * Initialize plugin
     */
    public static function init() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor
     */
    private function __construct() {
        $this->setup_hooks();
    }
    
    /**
     * Setup WordPress hooks
     */
    private function setup_hooks() {
        // Initialize on WordPress init
        add_action('init', [$this, 'init_plugin']);
        
        // Cleanup old logs daily
        add_action('site_logger_daily_cleanup', [$this, 'cleanup_old_logs']);
    }
    
    /**
     * Initialize plugin components
     */
    public function init_plugin() {
        // Initialize hooks handler
        Site_Logger_Hooks::init();
    }
    
    /**
     * Plugin activation
     */
    public static function activate() {
        // Create database table
        self::create_table();
        
        // Set default options
        update_option('site_logger_version', SITE_LOGGER_VERSION);
        update_option('site_logger_retention_days', 30);
        update_option('site_logger_severity_level', 'info');
        update_option('site_logger_skip_cron', 'yes');
        
        // Schedule daily cleanup
        if (!wp_next_scheduled('site_logger_daily_cleanup')) {
            wp_schedule_event(time(), 'daily', 'site_logger_daily_cleanup');
        }
    }
    
    /**
     * Plugin deactivation
     */
    public static function deactivate() {
        // Clear scheduled cleanup
        wp_clear_scheduled_hook('site_logger_daily_cleanup');
    }
    
    /**
     * Create database table
     */
    private static function create_table() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . self::TABLE_NAME;
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
            user_id BIGINT UNSIGNED,
            user_ip VARCHAR(45),
            severity ENUM('emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug') DEFAULT 'info',
            action VARCHAR(100) NOT NULL,
            object_type VARCHAR(50),
            object_id BIGINT UNSIGNED,
            object_name VARCHAR(255),
            details LONGTEXT,
            PRIMARY KEY (id),
            INDEX idx_timestamp (timestamp),
            INDEX idx_user_id (user_id),
            INDEX idx_severity (severity),
            INDEX idx_action (action),
            INDEX idx_object_type (object_type)
        ) $charset_collate;";
        
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }
    
    /**
     * Log an activity
     */
    public static function log($action, $object_type = '', $object_id = 0, $object_name = '', $details = [], $severity = 'info') {
        global $wpdb;
        
        // Check if we should skip this log based on severity setting
        $min_severity = get_option('site_logger_severity_level', 'info');
        $levels = array_flip(self::SEVERITY_LEVELS);
        
        if (!isset($levels[$severity]) || !isset($levels[$min_severity])) {
            return false;
        }
        
        // Skip if severity is below minimum
        if ($levels[$severity] > $levels[$min_severity]) {
            return false;
        }
        
        $table_name = $wpdb->prefix . self::TABLE_NAME;
        
        // Format details for readability
        $formatted_details = is_array($details) ? $details : [];
        
        $data = [
            'user_id' => get_current_user_id() ?: 0,
            'user_ip' => self::get_user_ip(),
            'severity' => $severity,
            'action' => sanitize_text_field($action),
            'object_type' => sanitize_text_field($object_type),
            'object_id' => absint($object_id),
            'object_name' => sanitize_text_field($object_name),
            'details' => wp_json_encode($formatted_details, JSON_UNESCAPED_UNICODE),
            'timestamp' => current_time('mysql')
        ];
        
        // Insert into database
        $result = $wpdb->insert($table_name, $data);
        
        // Error logging for debugging
        if (false === $result) {
            error_log('Site Logger Error: Failed to insert log. Error: ' . $wpdb->last_error);
        }
        
        return $result;
    }
    
    /**
     * Get recent logs
     */
    public static function get_logs($limit = 50, $filters = []) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . self::TABLE_NAME;
        
        $where = ['1=1'];
        $params = [];
        
        // Apply filters
        if (!empty($filters['severity'])) {
            $where[] = 'severity = %s';
            $params[] = $filters['severity'];
        }
        
        if (!empty($filters['user_id'])) {
            $where[] = 'user_id = %d';
            $params[] = $filters['user_id'];
        }
        
        if (!empty($filters['action'])) {
            $where[] = 'action = %s';
            $params[] = $filters['action'];
        }
        
        if (!empty($filters['object_type'])) {
            $where[] = 'object_type = %s';
            $params[] = $filters['object_type'];
        }
        
        if (!empty($filters['object_id'])) {
            $where[] = 'object_id = %d';
            $params[] = $filters['object_id'];
        }
        
        if (!empty($filters['date_from'])) {
            $where[] = 'timestamp >= %s';
            $params[] = $filters['date_from'];
        }
        
        if (!empty($filters['date_to'])) {
            $where[] = 'timestamp <= %s';
            $params[] = $filters['date_to'];
        }
        
        if (!empty($filters['search'])) {
            $where[] = '(object_name LIKE %s OR details LIKE %s OR action LIKE %s)';
            $search_term = '%' . $wpdb->esc_like($filters['search']) . '%';
            $params[] = $search_term;
            $params[] = $search_term;
            $params[] = $search_term;
        }
        
        $where_sql = implode(' AND ', $where);
        
        $query = "SELECT * FROM $table_name WHERE $where_sql ORDER BY timestamp DESC";
        
        if ($limit > 0) {
            $query .= " LIMIT %d";
            $params[] = absint($limit);
        }
        
        if (!empty($params)) {
            $query = $wpdb->prepare($query, $params);
        }
        
        $results = $wpdb->get_results($query);
        
        foreach ($results as $log) {
    if (!empty($log->details)) {
        // Try to decode as JSON first
        $decoded = json_decode($log->details, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $log->details = $decoded;
        } else {
            // If not JSON, try unserialize
            $unserialized = maybe_unserialize($log->details);
            if (is_array($unserialized) || is_object($unserialized)) {
                $log->details = (array)$unserialized;
            }
        }
    }
}
        
        return $results;
    }
    
    /**
     * Get logs count with filters
     */
    public static function get_logs_count($filters = []) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . self::TABLE_NAME;
        
        $where = ['1=1'];
        $params = [];
        
        // Apply filters
        if (!empty($filters['severity'])) {
            $where[] = 'severity = %s';
            $params[] = $filters['severity'];
        }
        
        if (!empty($filters['user_id'])) {
            $where[] = 'user_id = %d';
            $params[] = $filters['user_id'];
        }
        
        if (!empty($filters['action'])) {
            $where[] = 'action = %s';
            $params[] = $filters['action'];
        }
        
        if (!empty($filters['date_from'])) {
            $where[] = 'timestamp >= %s';
            $params[] = $filters['date_from'];
        }
        
        if (!empty($filters['date_to'])) {
            $where[] = 'timestamp <= %s';
            $params[] = $filters['date_to'];
        }
        
        $where_sql = implode(' AND ', $where);
        
        $query = "SELECT COUNT(*) FROM $table_name WHERE $where_sql";
        
        if (!empty($params)) {
            $query = $wpdb->prepare($query, $params);
        }
        
        return $wpdb->get_var($query);
    }
    
    /**
     * Get logs grouped by action
     */
    public static function get_logs_by_action($limit = 10) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . self::TABLE_NAME;
        
        $query = $wpdb->prepare(
            "SELECT action, COUNT(*) as count 
             FROM $table_name 
             GROUP BY action 
             ORDER BY count DESC 
             LIMIT %d",
            $limit
        );
        
        return $wpdb->get_results($query);
    }
    
    /**
     * Get logs grouped by user
     */
    public static function get_logs_by_user($limit = 10) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . self::TABLE_NAME;
        
        $query = $wpdb->prepare(
            "SELECT user_id, COUNT(*) as count 
             FROM $table_name 
             WHERE user_id > 0 
             GROUP BY user_id 
             ORDER BY count DESC 
             LIMIT %d",
            $limit
        );
        
        return $wpdb->get_results($query);
    }
    
    /**
     * Cleanup old logs
     */
    public static function cleanup_old_logs() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . self::TABLE_NAME;
        $retention_days = get_option('site_logger_retention_days', 30);
        
        $date = date('Y-m-d H:i:s', strtotime("-$retention_days days"));
        
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM $table_name WHERE timestamp < %s",
                $date
            )
        );
        
        // Optimize table after deletion
        $wpdb->query("OPTIMIZE TABLE $table_name");
    }
    
    /**
     * Get user IP address
     */
    private static function get_user_ip() {
        $ip = '';
        
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip_list = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            $ip = trim($ip_list[0]);
        } elseif (!empty($_SERVER['REMOTE_ADDR'])) {
            $ip = $_SERVER['REMOTE_ADDR'];
        }
        
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
    }
    
    /**
     * Get activity summary
     */
    public static function get_summary() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . self::TABLE_NAME;
        
        return [
            'total' => $wpdb->get_var("SELECT COUNT(*) FROM $table_name"),
            'today' => $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM $table_name WHERE DATE(timestamp) = %s",
                    current_time('Y-m-d')
                )
            ),
            'yesterday' => $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM $table_name WHERE DATE(timestamp) = %s",
                    date('Y-m-d', strtotime('-1 day'))
                )
            ),
            'users' => $wpdb->get_var("SELECT COUNT(DISTINCT user_id) FROM $table_name WHERE user_id > 0"),
            'errors' => $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE severity IN ('error', 'critical', 'alert', 'emergency')"),
            'warnings' => $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE severity = 'warning'"),
            'notices' => $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE severity = 'notice'"),
            'info' => $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE severity = 'info'"),
            'debug' => $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE severity = 'debug'")
        ];
    }
    
    /**
     * Should skip option logging?
     */
    public static function should_skip_option($option_name) {
        // Skip cron logs if setting enabled
        if ($option_name === 'cron' && get_option('site_logger_skip_cron', 'yes') === 'yes') {
            return true;
        }
        
        // Skip other noisy options
        foreach (self::SKIP_OPTIONS as $skip) {
            if (strpos($option_name, $skip) !== false) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Add admin menu
     */
    public static function add_admin_menu() {
        add_menu_page(
            __('Site Logs', 'site-logger'),
            __('Site Logs', 'site-logger'),
            'manage_options',
            'site-logs',
            [__CLASS__, 'render_admin_page'],
            'dashicons-list-view',
            30
        );
        
        // Add settings submenu
        add_submenu_page(
            'site-logs',
            __('Settings', 'site-logger'),
            __('Settings', 'site-logger'),
            'manage_options',
            'site-logs-settings',
            [__CLASS__, 'render_settings_page']
        );
        
        // Add dashboard submenu
        add_submenu_page(
            'site-logs',
            __('Dashboard', 'site-logger'),
            __('Dashboard', 'site-logger'),
            'manage_options',
            'site-logs-dashboard',
            [__CLASS__, 'render_dashboard_page']
        );
    }
    
    /**
     * Render admin page
     */
    public static function render_admin_page() {
        // Handle filters
        $filters = [];
        if (!empty($_GET['severity'])) {
            $filters['severity'] = sanitize_text_field($_GET['severity']);
        }
        if (!empty($_GET['user_id'])) {
            $filters['user_id'] = intval($_GET['user_id']);
        }
        if (!empty($_GET['action'])) {
            $filters['action'] = sanitize_text_field($_GET['action']);
        }
        if (!empty($_GET['object_type'])) {
            $filters['object_type'] = sanitize_text_field($_GET['object_type']);
        }
        if (!empty($_GET['date_from'])) {
            $filters['date_from'] = sanitize_text_field($_GET['date_from']);
        }
        if (!empty($_GET['date_to'])) {
            $filters['date_to'] = sanitize_text_field($_GET['date_to']);
        }
        if (!empty($_GET['search'])) {
            $filters['search'] = sanitize_text_field($_GET['search']);
        }
        
        // Get logs
        $logs = self::get_logs(100, $filters);
        $summary = self::get_summary();
        
        // Get unique actions for filter
        global $wpdb;
        $table_name = $wpdb->prefix . self::TABLE_NAME;
        $actions = $wpdb->get_col("SELECT DISTINCT action FROM $table_name ORDER BY action");
        
        // Get unique object types for filter
        $object_types = $wpdb->get_col("SELECT DISTINCT object_type FROM $table_name WHERE object_type != '' ORDER BY object_type");
        
        ?>
        <div class="wrap">
            <h1><?php _e('Site Activity Logs', 'site-logger'); ?></h1>
            
            <!-- Summary -->
            <div class="site-logs-summary" style="background: #fff; padding: 20px; margin: 20px 0; border: 1px solid #ccd0d4; border-radius: 4px;">
                <h2 style="margin-top: 0;"><?php _e('Overview', 'site-logger'); ?></h2>
                <div style="display: flex; gap: 30px; flex-wrap: wrap;">
                    <div>
                        <h3 style="margin: 0 0 5px 0; color: #2271b1;"><?php echo esc_html(number_format($summary['total'])); ?></h3>
                        <p style="margin: 0; color: #646970;"><?php _e('Total Logs', 'site-logger'); ?></p>
                    </div>
                    <div>
                        <h3 style="margin: 0 0 5px 0; color: #00a32a;"><?php echo esc_html(number_format($summary['today'])); ?></h3>
                        <p style="margin: 0; color: #646970;"><?php _e('Today', 'site-logger'); ?></p>
                    </div>
                    <div>
                        <h3 style="margin: 0 0 5px 0; color: #d63638;"><?php echo esc_html(number_format($summary['errors'])); ?></h3>
                        <p style="margin: 0; color: #646970;"><?php _e('Errors', 'site-logger'); ?></p>
                    </div>
                    <div>
                        <h3 style="margin: 0 0 5px 0; color: #ffb900;"><?php echo esc_html(number_format($summary['warnings'])); ?></h3>
                        <p style="margin: 0; color: #646970;"><?php _e('Warnings', 'site-logger'); ?></p>
                    </div>
                    <div>
                        <h3 style="margin: 0 0 5px 0; color: #f0c33c;"><?php echo esc_html(number_format($summary['users'])); ?></h3>
                        <p style="margin: 0; color: #646970;"><?php _e('Active Users', 'site-logger'); ?></p>
                    </div>
                </div>
            </div>
            
            <!-- Filters -->
            <div class="site-logs-filters" style="background: #fff; padding: 20px; margin: 20px 0; border: 1px solid #ccd0d4; border-radius: 4px;">
                <h3 style="margin-top: 0;"><?php _e('Filter Logs', 'site-logger'); ?></h3>
                <form method="get" action="">
                    <input type="hidden" name="page" value="site-logs">
                    
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 15px;">
                        <div>
                            <label for="severity" style="display: block; margin-bottom: 5px; font-weight: 600;">
                                <?php _e('Severity:', 'site-logger'); ?>
                            </label>
                            <select name="severity" id="severity" style="width: 100%;">
                                <option value=""><?php _e('All Severities', 'site-logger'); ?></option>
                                <?php foreach (self::SEVERITY_LEVELS as $level): ?>
                                    <option value="<?php echo esc_attr($level); ?>" <?php selected(!empty($filters['severity']) && $filters['severity'] === $level); ?>>
                                        <?php echo esc_html(ucfirst($level)); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div>
                            <label for="user_id" style="display: block; margin-bottom: 5px; font-weight: 600;">
                                <?php _e('User:', 'site-logger'); ?>
                            </label>
                            <?php
                            wp_dropdown_users([
                                'name' => 'user_id',
                                'show_option_all' => __('All Users', 'site-logger'),
                                'selected' => !empty($filters['user_id']) ? $filters['user_id'] : 0,
                                'include_selected' => true,
                                'style' => 'width: 100%;'
                            ]);
                            ?>
                        </div>
                        
                        <div>
                            <label for="action" style="display: block; margin-bottom: 5px; font-weight: 600;">
                                <?php _e('Action:', 'site-logger'); ?>
                            </label>
                            <select name="action" id="action" style="width: 100%;">
                                <option value=""><?php _e('All Actions', 'site-logger'); ?></option>
                                <?php foreach ($actions as $action): ?>
                                    <option value="<?php echo esc_attr($action); ?>" <?php selected(!empty($filters['action']) && $filters['action'] === $action); ?>>
                                        <?php echo esc_html(self::format_action($action)); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div>
                            <label for="object_type" style="display: block; margin-bottom: 5px; font-weight: 600;">
                                <?php _e('Object Type:', 'site-logger'); ?>
                            </label>
                            <select name="object_type" id="object_type" style="width: 100%;">
                                <option value=""><?php _e('All Types', 'site-logger'); ?></option>
                                <?php foreach ($object_types as $type): ?>
                                    <option value="<?php echo esc_attr($type); ?>" <?php selected(!empty($filters['object_type']) && $filters['object_type'] === $type); ?>>
                                        <?php echo esc_html(ucfirst($type)); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div>
                            <label for="date_from" style="display: block; margin-bottom: 5px; font-weight: 600;">
                                <?php _e('Date From:', 'site-logger'); ?>
                            </label>
                            <input type="date" name="date_from" id="date_from" 
                                   value="<?php echo !empty($filters['date_from']) ? esc_attr($filters['date_from']) : ''; ?>" 
                                   style="width: 100%;">
                        </div>
                        
                        <div>
                            <label for="date_to" style="display: block; margin-bottom: 5px; font-weight: 600;">
                                <?php _e('Date To:', 'site-logger'); ?>
                            </label>
                            <input type="date" name="date_to" id="date_to" 
                                   value="<?php echo !empty($filters['date_to']) ? esc_attr($filters['date_to']) : ''; ?>" 
                                   style="width: 100%;">
                        </div>
                    </div>
                    
                    <div style="display: flex; gap: 20px; align-items: flex-end;">
                        <div style="flex: 1;">
                            <label for="search" style="display: block; margin-bottom: 5px; font-weight: 600;">
                                <?php _e('Search:', 'site-logger'); ?>
                            </label>
                            <input type="text" name="search" id="search" 
                                   value="<?php echo !empty($filters['search']) ? esc_attr($filters['search']) : ''; ?>" 
                                   placeholder="<?php esc_attr_e('Search logs...', 'site-logger'); ?>" 
                                   style="width: 100%;">
                        </div>
                        
                        <div>
                            <button type="submit" class="button button-primary" style="margin-bottom: 5px;">
                                <?php _e('Apply Filters', 'site-logger'); ?>
                            </button>
                            <a href="<?php echo admin_url('admin.php?page=site-logs'); ?>" class="button">
                                <?php _e('Reset', 'site-logger'); ?>
                            </a>
                        </div>
                    </div>
                </form>
            </div>
            
            <!-- Logs Table -->
            <div style="background: #fff; border: 1px solid #ccd0d4; border-radius: 4px; overflow: hidden;">
                <table class="wp-list-table widefat fixed striped" style="border: none;">
                    <thead>
                        <tr>
                            <th width="150"><?php _e('Time', 'site-logger'); ?></th>
                            <th width="100"><?php _e('Severity', 'site-logger'); ?></th>
                            <th width="120"><?php _e('User', 'site-logger'); ?></th>
                            <th width="150"><?php _e('Action', 'site-logger'); ?></th>
                            <th width="150"><?php _e('Object', 'site-logger'); ?></th>
                            <th><?php _e('Details', 'site-logger'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($logs)): ?>
                            <tr>
                                <td colspan="6" style="text-align: center; padding: 20px;">
                                    <?php _e('No activity logs found.', 'site-logger'); ?>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($logs as $log): ?>
                                <?php
                                $user = $log->user_id ? get_user_by('id', $log->user_id) : null;
                                $username = $user ? $user->display_name : __('System', 'site-logger');
                                $time = date_i18n('M j, H:i:s', strtotime($log->timestamp));
                                $time_full = date_i18n('Y-m-d H:i:s', strtotime($log->timestamp));
                                $details = $log->details;
                                ?>
                                <tr>
                                    <td>
                                        <span title="<?php echo esc_attr($time_full); ?>">
                                            <?php echo esc_html($time); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="severity-badge severity-<?php echo esc_attr($log->severity); ?>" 
                                              style="display: inline-block; padding: 3px 8px; border-radius: 3px; font-size: 11px; font-weight: 600; text-transform: uppercase; background: #f0f0f1; color: #50575e;">
                                            <?php echo esc_html(ucfirst($log->severity)); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($user): ?>
                                            <a href="<?php echo get_edit_user_link($log->user_id); ?>" title="<?php echo esc_attr($user->user_email); ?>">
                                                <?php echo esc_html($username); ?>
                                            </a>
                                        <?php else: ?>
                                            <?php echo esc_html($username); ?>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <code><?php echo esc_html(self::format_action($log->action)); ?></code>
                                    </td>
                                    <td>
                                        <?php 
                                        $object_text = '';
                                        if ($log->object_id > 0) {
                                            if ($log->object_type === 'post') {
                                                $object_text = '📝 Post #' . $log->object_id;
                                                $edit_url = get_edit_post_link($log->object_id);
                                                if ($edit_url) {
                                                    $object_text = '<a href="' . esc_url($edit_url) . '" target="_blank">📝 Post #' . $log->object_id . '</a>';
                                                }
                                            } elseif ($log->object_type === 'user') {
                                                $object_text = '👤 User #' . $log->object_id;
                                                $edit_url = get_edit_user_link($log->object_id);
                                                if ($edit_url) {
                                                    $object_text = '<a href="' . esc_url($edit_url) . '" target="_blank">👤 User #' . $log->object_id . '</a>';
                                                }
                                            } elseif ($log->object_type === 'attachment') {
                                                $object_text = '🖼️ Media #' . $log->object_id;
                                            } elseif ($log->object_type === 'comment') {
                                                $object_text = '💬 Comment #' . $log->object_id;
                                            } elseif ($log->object_type === 'term') {
                                                $object_text = '🏷️ Term #' . $log->object_id;
                                            } elseif ($log->object_type === 'revision') {
                                                $object_text = '📚 Revision #' . $log->object_id;
                                            } else {
                                                $object_text = ucfirst($log->object_type) . ' #' . $log->object_id;
                                            }
                                        } else {
                                            if ($log->object_type === 'plugin') {
                                                $object_text = '🔌 ' . $log->object_name;
                                            } elseif ($log->object_type === 'theme') {
                                                $object_text = '🎨 ' . $log->object_name;
                                            } elseif ($log->object_type === 'option') {
                                                $object_text = '⚙️ ' . $log->object_name;
                                            } elseif ($log->object_type === 'widget') {
                                                $object_text = '🧩 ' . $log->object_name;
                                            } elseif ($log->object_type === 'acf') {
                                                $object_text = '🔧 ' . $log->object_name;
                                            } else {
                                                $object_text = $log->object_name ?: ucfirst($log->object_type ?: 'System');
                                            }
                                        }
                                        echo $object_text;
                                        ?>
                                    </td>
                                    <td>
                                        <?php if ($details): ?>
                                            <?php echo self::format_details_display($details); ?>
                                        <?php else: ?>
                                            <em><?php _e('No details', 'site-logger'); ?></em>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Export Button -->
            <div style="margin-top: 20px; display: flex; gap: 10px;">
                <form method="post" action="">
                    <?php wp_nonce_field('site_logger_export', 'site_logger_nonce'); ?>
                    <input type="hidden" name="site_logger_action" value="export_csv">
                    <button type="submit" class="button">
                        <span class="dashicons dashicons-download" style="vertical-align: middle; margin-right: 5px;"></span>
                        <?php _e('Export to CSV', 'site-logger'); ?>
                    </button>
                </form>
                
                <form method="post" action="">
                    <?php wp_nonce_field('site_logger_clear', 'site_logger_nonce'); ?>
                    <input type="hidden" name="site_logger_action" value="clear_logs">
                    <button type="submit" class="button button-secondary" onclick="return confirm('<?php _e('Are you sure you want to clear all logs? This cannot be undone.', 'site-logger'); ?>');">
                        <span class="dashicons dashicons-trash" style="vertical-align: middle; margin-right: 5px;"></span>
                        <?php _e('Clear All Logs', 'site-logger'); ?>
                    </button>
                </form>
            </div>
            
            <!-- Pagination -->
            <?php
            $total_logs = self::get_logs_count($filters);
            $total_pages = ceil($total_logs / 100);
            $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
            
            if ($total_pages > 1):
            ?>
            <div class="tablenav bottom" style="margin-top: 20px;">
                <div class="tablenav-pages">
                    <span class="displaying-num"><?php printf(__('%s items', 'site-logger'), number_format($total_logs)); ?></span>
                    <span class="pagination-links">
                        <?php
                        $base_url = admin_url('admin.php?page=site-logs');
                        foreach ($filters as $key => $value) {
                            $base_url .= '&' . $key . '=' . urlencode($value);
                        }
                        
                        if ($current_page > 1) {
                            echo '<a class="prev-page" href="' . $base_url . '&paged=' . ($current_page - 1) . '"><span class="screen-reader-text">' . __('Previous page', 'site-logger') . '</span><span aria-hidden="true">‹</span></a>';
                        }
                        
                        for ($i = 1; $i <= $total_pages; $i++) {
                            if ($i == $current_page) {
                                echo '<span class="current-page" aria-current="page">' . $i . '</span>';
                            } else {
                                echo '<a class="page-numbers" href="' . $base_url . '&paged=' . $i . '">' . $i . '</a>';
                            }
                        }
                        
                        if ($current_page < $total_pages) {
                            echo '<a class="next-page" href="' . $base_url . '&paged=' . ($current_page + 1) . '"><span class="screen-reader-text">' . __('Next page', 'site-logger') . '</span><span aria-hidden="true">›</span></a>';
                        }
                        ?>
                    </span>
                </div>
            </div>
            <?php endif; ?>
        </div>
        
        <style>
        .severity-emergency { background: #dc3232 !important; color: white !important; }
        .severity-alert { background: #f56e28 !important; color: white !important; }
        .severity-critical { background: #d63638 !important; color: white !important; }
        .severity-error { background: #ff0000 !important; color: white !important; }
        .severity-warning { background: #ffb900 !important; color: #000 !important; }
        .severity-notice { background: #00a0d2 !important; color: white !important; }
        .severity-info { background: #2271b1 !important; color: white !important; }
        .severity-debug { background: #a7aaad !important; color: #000 !important; }
        
        /* Log details styling */
        .log-details {
            font-size: 12px;
            line-height: 1.4;
            max-height: 150px;
            overflow-y: auto;
            padding: 8px;
            background: #f8f9fa;
            border-radius: 4px;
            border-left: 3px solid #2271b1;
        }
        .detail-item {
            margin-bottom: 6px;
            padding-bottom: 6px;
            border-bottom: 1px dashed #e0e0e0;
        }
        .detail-item:last-child {
            border-bottom: none;
            margin-bottom: 0;
        }
        .detail-key {
            font-weight: 600;
            color: #1d2327;
            display: inline-block;
            min-width: 120px;
            background: #fff;
            padding: 2px 6px;
            border-radius: 3px;
            border: 1px solid #dcdcde;
        }
        .change-old {
            color: #d63638;
            background: #fcf0f1;
            padding: 1px 4px;
            border-radius: 2px;
            text-decoration: line-through;
            margin-right: 4px;
        }
        .change-new {
            color: #00a32a;
            background: #f0f9f1;
            padding: 1px 4px;
            border-radius: 2px;
            font-weight: 600;
        }
        .change-arrow {
            color: #8c8f94;
            margin: 0 8px;
            font-weight: bold;
        }
        
        /* Make table more readable */
        .wp-list-table th {
            font-weight: 600;
            background: #f6f7f7;
        }
        .wp-list-table tr:hover {
            background: #f6f7f7 !important;
        }
        
        /* Responsive table */
        @media screen and (max-width: 1200px) {
            .wp-list-table {
                display: block;
                overflow-x: auto;
                white-space: nowrap;
            }
        }
        </style>
        
         <style>
          .content-change-box {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            padding: 12px 16px;
            margin: 8px 0 12px;
            font-size: 13px;
        }

        .content-header {
            font-size: 14px;
            font-weight: 600;
            color: #1e40af;
            margin-bottom: 10px;
            padding-bottom: 8px;
            border-bottom: 1px solid #e5e7eb;
        }

        .added-chars { color: #15803d; font-weight: bold; }
        .removed-chars { color: #b91c1c; font-weight: bold; }

        .diff-section {
            margin: 12px 0;
            border-radius: 4px;
            overflow: hidden;
            border: 1px solid #e5e7eb;
        }

        .diff-title {
            background: #f1f5f9;
            padding: 6px 12px;
            font-weight: 600;
            font-size: 12px;
        }

        .diff-section.added .diff-title {
            background: #ecfdf5;
            color: #065f46;
        }

        .diff-section.modified .diff-title {
            background: #fffbeb;
            color: #92400e;
        }

        .diff-pre {
            margin: 0;
            padding: 10px 12px;
            background: white;
            font-family: Consolas, Monaco, monospace;
            white-space: pre-wrap;
            word-wrap: break-word;
            font-size: 12.5px;
            line-height: 1.45;
            max-height: 160px;
            overflow-y: auto;
        }

        .mod-line {
            padding: 8px 12px;
            border-bottom: 1px solid #f0f0f0;
        }

        .mod-line:last-child {
            border-bottom: none;
        }

        .old-text {
            color: #991b1b;
            text-decoration: line-through;
            background: #fef2f2;
            padding: 2px 4px;
            border-radius: 3px;
        }

        .new-text {
            color: #065f46;
            background: #ecfdf5;
            padding: 2px 4px;
            border-radius: 3px;
        }

        .more-info {
            text-align: center;
            color: #6b7280;
            font-size: 12px;
            padding: 8px;
            font-style: italic;
        }

        .word-summary {
            margin-top: 10px;
            font-size: 12.5px;
            color: #4b5563;
            padding: 6px 10px;
            background: #f1f5f9;
            border-radius: 4px;
        }
        </style>

        <?php
        
        // Handle CSV export
        if (isset($_POST['site_logger_action']) && $_POST['site_logger_action'] === 'export_csv' 
            && wp_verify_nonce($_POST['site_logger_nonce'], 'site_logger_export')) {
            self::export_csv($logs);
        }
        
        // Handle clear logs
        if (isset($_POST['site_logger_action']) && $_POST['site_logger_action'] === 'clear_logs' 
            && wp_verify_nonce($_POST['site_logger_nonce'], 'site_logger_clear')) {
            self::clear_all_logs();
        }
    }
    
    /**
     * Format action for display
     */
    private static function format_action($action) {
        $actions = [
            'post_created' => '📝 Post Created',
            'post_updated' => '✏️ Post Updated',
            'post_deleted' => '🗑️ Post Deleted',
            'post_trashed' => '🗑️ Post Trashed',
            'post_untrashed' => '↩️ Post Restored',
            'featured_image_added' => '🖼️ Featured Image Added',
            'featured_image_changed' => '🖼️ Featured Image Changed',
            'featured_image_removed' => '🖼️ Featured Image Removed',
            'revision_created' => '📚 Revision Created',
            'user_registered' => '👤 User Registered',
            'user_updated' => '✏️ User Updated',
            'user_login' => '🔐 User Login',
            'user_logout' => '🔓 User Logout',
            'password_reset' => '🔑 Password Reset',
            'password_changed' => '🔑 Password Changed',
            'password_reset_requested' => '🔑 Password Reset Requested',
            'user_role_changed' => '👑 User Role Changed',
            'user_meta_updated' => '👤 User Meta Updated',
            'user_meta_added' => '👤 User Meta Added',
            'user_meta_deleted' => '👤 User Meta Deleted',
            'option_updated' => '⚙️ Setting Updated',
            'plugin_activated' => '🔌 Plugin Activated',
            'plugin_deactivated' => '🔌 Plugin Deactivated',
            'plugin_deleted' => '🔌 Plugin Deleted',
            'theme_switched' => '🎨 Theme Switched',
            'comment_posted' => '💬 Comment Posted',
            'comment_edited' => '✏️ Comment Edited',
            'comment_deleted' => '🗑️ Comment Deleted',
            'media_added' => '🖼️ Media Added',
            'media_edited' => '✏️ Media Edited',
            'media_deleted' => '🗑️ Media Deleted',
            'term_created' => '🏷️ Term Created',
            'term_updated' => '✏️ Term Updated',
            'term_deleted' => '🗑️ Term Deleted',
            'taxonomy_updated' => '🏷️ Taxonomy Updated',
            'widget_updated' => '🧩 Widget Updated',
            'widgets_rearranged' => '🧩 Widgets Rearranged',
            'import_started' => '📥 Import Started',
            'import_completed' => '📥 Import Completed',
            'export_started' => '📤 Export Started',
            'acf_fields_updated' => '🔧 ACF Fields Updated',
            'acf_field_group_updated' => '🔧 ACF Field Group Updated',
            'acf_field_group_duplicated' => '🔧 ACF Field Group Duplicated',
            'acf_field_group_deleted' => '🔧 ACF Field Group Deleted',
            'term_meta_updated' => '🏷️ Term Meta Updated',
            'term_meta_added' => '🏷️ Term Meta Added',
            'term_meta_deleted' => '🏷️ Term Meta Deleted',
            'post_meta_updated' => '📝 Post Meta Updated',
            'post_meta_added' => '📝 Post Meta Added',
            'post_meta_deleted' => '📝 Post Meta Deleted',
            'menu_updated' => '📋 Menu Updated',
            'menu_created' => '📋 Menu Created',
            'menu_deleted' => '📋 Menu Deleted',
            'sidebar_widgets_updated' => '🧩 Sidebar Widgets Updated',
            'customizer_saved' => '🎨 Customizer Saved',
            'login_failed' => '🔒 Login Failed',
        ];
        
        return $actions[$action] ?? ucwords(str_replace('_', ' ', $action));
    }
    
    /**
     * Get object display text for CSV
     */
    private static function get_object_display_text($log) {
        if ($log->object_id > 0) {
            if ($log->object_type === 'post') {
                return 'Post #' . $log->object_id . ' - ' . $log->object_name;
            } elseif ($log->object_type === 'user') {
                return 'User #' . $log->object_id . ' - ' . $log->object_name;
            } elseif ($log->object_type === 'attachment') {
                return 'Media #' . $log->object_id . ' - ' . $log->object_name;
            } else {
                return ucfirst($log->object_type) . ' #' . $log->object_id . ' - ' . $log->object_name;
            }
        } else {
            return $log->object_name ?: ucfirst($log->object_type ?: 'System');
        }
    }
    
    /**
     * Format details for display
     */
    private static function format_details_display($details) {
        if (empty($details) || !is_array($details)) {
            return '<em>No details available</em>';
        }

        $output = '<div class="log-details">';

        // 1. Collect action links to show at the bottom
        $action_links = [];
        $link_keys = ['edit_post', 'view_post', 'view_revisions', 'edit_term', 'edit_acf_group', 'visit_user', 'settings_page', 'view_media', 'plugin_details'];
        foreach ($link_keys as $key) {
            if (!empty($details[$key])) {
                $action_links[] = $details[$key];
                unset($details[$key]); // remove so we don't show twice
            }
        }

        // 2. Loop through remaining details
        foreach ($details as $key => $value) {
            // Special beautiful rendering for content changes
            if ($key === 'content' && is_array($value)) {
                $output .= self::render_beautiful_content_changes($value);
                continue;
            }

            // Normal key-value
            $output .= '<div class="detail-item">';
            $output .= '<span class="detail-key">' . esc_html(ucfirst(str_replace('_', ' ', $key))) . ':</span> ';

            if (is_array($value)) {
                if (isset($value['old']) && isset($value['new'])) {
                    $output .= '<span class="change-old">"' . esc_html($value['old']) . '"</span>';
                    $output .= '<span class="change-arrow"> → </span>';
                    $output .= '<span class="change-new">"' . esc_html($value['new']) . '"</span>';
                } else {
                    $output .= '<pre>' . esc_html(json_encode($value, JSON_PRETTY_PRINT)) . '</pre>';
                }
            } else {
                $output .= '<span class="detail-value">' . esc_html($value) . '</span>';
            }

            $output .= '</div>';
        }

        // 3. Action links at the bottom
        if (!empty($action_links)) {
            $output .= '<div class="action-links">';
            $output .= '<span class="detail-key">Quick Actions:</span> ';
            $output .= implode(' ', $action_links);
            $output .= '</div>';
        }

        $output .= '</div>';

        return $output;
    }
    
    /**
     * Export logs to CSV
     */
    private static function export_csv($logs) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="site-logs-' . date('Y-m-d-H-i-s') . '.csv"');
        
        $output = fopen('php://output', 'w');
        
        // Add BOM for UTF-8
        fwrite($output, "\xEF\xBB\xBF");
        
        // Headers
        fputcsv($output, [
            'ID',
            'Timestamp',
            'User ID',
            'User',
            'IP Address',
            'Severity',
            'Action',
            'Object Type',
            'Object ID',
            'Object Name',
            'Details'
        ]);
        
        // Data
        foreach ($logs as $log) {
            $user = $log->user_id ? get_user_by('id', $log->user_id) : null;
            $username = $user ? $user->display_name : 'System';
            $details = $log->details;
            $details_text = '';
            
            if ($details && is_array($details)) {
                foreach ($details as $key => $value) {
                    if (is_array($value)) {
                        if (isset($value['old']) && isset($value['new'])) {
                            $details_text .= $key . ': ' . $value['old'] . ' → ' . $value['new'] . '; ';
                        } elseif (isset($value['added']) || isset($value['removed'])) {
                            if (!empty($value['added'])) {
                                $added = is_array($value['added']) ? implode(', ', $value['added']) : $value['added'];
                                $details_text .= $key . ' added: ' . $added . '; ';
                            }
                            if (!empty($value['removed'])) {
                                $removed = is_array($value['removed']) ? implode(', ', $value['removed']) : $value['removed'];
                                $details_text .= $key . ' removed: ' . $removed . '; ';
                            }
                        } else {
                            $details_text .= $key . ': ' . json_encode($value) . '; ';
                        }
                    } else {
                        // Strip HTML tags from details
                        $clean_value = strip_tags($value);
                        $details_text .= $key . ': ' . $clean_value . '; ';
                    }
                }
            }
            
            fputcsv($output, [
                $log->id,
                $log->timestamp,
                $log->user_id,
                $username,
                $log->user_ip,
                ucfirst($log->severity),
                self::format_action($log->action),
                $log->object_type,
                $log->object_id,
                self::get_object_display_text($log),
                trim($details_text)
            ]);
        }
        
        fclose($output);
        exit;
    }
    
    /**
     * Clear all logs
     */
    private static function clear_all_logs() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . self::TABLE_NAME;
        $result = $wpdb->query("TRUNCATE TABLE $table_name");
        
        if (false !== $result) {
            echo '<div class="notice notice-success is-dismissible"><p>' . __('All logs have been cleared.', 'site-logger') . '</p></div>';
        } else {
            echo '<div class="notice notice-error is-dismissible"><p>' . __('Failed to clear logs. Please try again.', 'site-logger') . '</p></div>';
        }
    }
    
    /**
     * Render settings page
     */
    public static function render_settings_page() {
        // Save settings if form submitted
        if (isset($_POST['site_logger_save_settings']) 
            && wp_verify_nonce($_POST['site_logger_settings_nonce'], 'site_logger_save_settings')) {
            
            update_option('site_logger_severity_level', sanitize_text_field($_POST['severity_level']));
            update_option('site_logger_retention_days', intval($_POST['retention_days']));
            update_option('site_logger_skip_cron', sanitize_text_field($_POST['skip_cron']));
            update_option('site_logger_log_ip', isset($_POST['log_ip']) ? 'yes' : 'no');
            update_option('site_logger_log_user_id', isset($_POST['log_user_id']) ? 'yes' : 'no');
            
            echo '<div class="notice notice-success is-dismissible"><p>' . __('Settings saved successfully.', 'site-logger') . '</p></div>';
        }
        
        ?>
        <div class="wrap">
            <h1><?php _e('Site Logger Settings', 'site-logger'); ?></h1>
            
            <form method="post" action="">
                <?php wp_nonce_field('site_logger_save_settings', 'site_logger_settings_nonce'); ?>
                
                <h2><?php _e('Logging Settings', 'site-logger'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="severity_level"><?php _e('Minimum Severity Level', 'site-logger'); ?></label>
                        </th>
                        <td>
                            <select name="severity_level" id="severity_level" class="regular-text">
                                <?php foreach (self::SEVERITY_LEVELS as $level): ?>
                                    <option value="<?php echo esc_attr($level); ?>" 
                                            <?php selected(get_option('site_logger_severity_level', 'info'), $level); ?>>
                                        <?php echo esc_html(ucfirst($level)); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">
                                <?php _e('Only log events at or above this severity level. "Debug" logs everything.', 'site-logger'); ?>
                            </p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="retention_days"><?php _e('Log Retention', 'site-logger'); ?></label>
                        </th>
                        <td>
                            <input type="number" name="retention_days" id="retention_days" 
                                   value="<?php echo esc_attr(get_option('site_logger_retention_days', 30)); ?>"
                                   min="1" max="3650" step="1" class="small-text">
                            <?php _e('days', 'site-logger'); ?>
                            <p class="description">
                                <?php _e('How long to keep log entries before automatic deletion.', 'site-logger'); ?>
                            </p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="skip_cron"><?php _e('Skip Cron Logs', 'site-logger'); ?></label>
                        </th>
                        <td>
                            <select name="skip_cron" id="skip_cron" class="regular-text">
                                <option value="yes" <?php selected(get_option('site_logger_skip_cron', 'yes'), 'yes'); ?>>
                                    <?php _e('Yes - Skip cron job logs', 'site-logger'); ?>
                                </option>
                                <option value="no" <?php selected(get_option('site_logger_skip_cron', 'yes'), 'no'); ?>>
                                    <?php _e('No - Log cron jobs', 'site-logger'); ?>
                                </option>
                            </select>
                            <p class="description">
                                <?php _e('Cron job updates create very large logs. Recommended to skip them.', 'site-logger'); ?>
                            </p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="log_ip"><?php _e('Log IP Addresses', 'site-logger'); ?></label>
                        </th>
                        <td>
                            <label>
                                <input type="checkbox" name="log_ip" id="log_ip" value="yes" 
                                       <?php checked(get_option('site_logger_log_ip', 'yes'), 'yes'); ?>>
                                <?php _e('Log user IP addresses', 'site-logger'); ?>
                            </label>
                            <p class="description">
                                <?php _e('Disable this for GDPR compliance if needed.', 'site-logger'); ?>
                            </p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="log_user_id"><?php _e('Log User IDs', 'site-logger'); ?></label>
                        </th>
                        <td>
                            <label>
                                <input type="checkbox" name="log_user_id" id="log_user_id" value="yes" 
                                       <?php checked(get_option('site_logger_log_user_id', 'yes'), 'yes'); ?>>
                                <?php _e('Log user IDs', 'site-logger'); ?>
                            </label>
                            <p class="description">
                                <?php _e('Disable this for additional privacy.', 'site-logger'); ?>
                            </p>
                        </td>
                    </tr>
                </table>
                
                <p class="submit">
                    <button type="submit" name="site_logger_save_settings" class="button button-primary">
                        <?php _e('Save Settings', 'site-logger'); ?>
                    </button>
                </p>
            </form>
            
            <!-- Database Stats -->
            <div class="card" style="margin-top: 30px;">
                <h2 class="title"><?php _e('Database Information', 'site-logger'); ?></h2>
                <?php
                global $wpdb;
                $table_name = $wpdb->prefix . self::TABLE_NAME;
                $total = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
                $today = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM $table_name WHERE DATE(timestamp) = %s",
                    current_time('Y-m-d')
                ));
                $yesterday = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM $table_name WHERE DATE(timestamp) = %s",
                    date('Y-m-d', strtotime('-1 day'))
                ));
                $table_size = $wpdb->get_var("SELECT ROUND(((data_length + index_length) / 1024 / 1024), 2) FROM information_schema.TABLES WHERE table_schema = DATABASE() AND table_name = '$table_name'");
                ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php _e('Total Logs:', 'site-logger'); ?></th>
                        <td><?php echo number_format($total); ?></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Logs Today:', 'site-logger'); ?></th>
                        <td><?php echo number_format($today); ?></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Logs Yesterday:', 'site-logger'); ?></th>
                        <td><?php echo number_format($yesterday); ?></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Table Size:', 'site-logger'); ?></th>
                        <td><?php echo $table_size ? $table_size . ' MB' : __('Unknown', 'site-logger'); ?></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Database Table:', 'site-logger'); ?></th>
                        <td><code><?php echo $table_name; ?></code></td>
                    </tr>
                </table>
                
                <p class="submit">
                    <form method="post" action="" onsubmit="return confirm('<?php _e('Are you sure you want to clear all logs? This cannot be undone.', 'site-logger'); ?>');">
                        <?php wp_nonce_field('site_logger_clear_settings', 'site_logger_nonce'); ?>
                        <input type="hidden" name="site_logger_action" value="clear_all_logs">
                        <button type="submit" class="button button-secondary">
                            <?php _e('Clear All Logs', 'site-logger'); ?>
                        </button>
                    </form>
                </p>
            </div>
            
            <!-- System Information -->
            <div class="card" style="margin-top: 30px;">
                <h2 class="title"><?php _e('System Information', 'site-logger'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php _e('WordPress Version:', 'site-logger'); ?></th>
                        <td><?php echo esc_html(get_bloginfo('version')); ?></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('PHP Version:', 'site-logger'); ?></th>
                        <td><?php echo esc_html(phpversion()); ?></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('MySQL Version:', 'site-logger'); ?></th>
                        <td><?php echo esc_html($wpdb->db_version()); ?></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Plugin Version:', 'site-logger'); ?></th>
                        <td><?php echo esc_html(SITE_LOGGER_VERSION); ?></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Site URL:', 'site-logger'); ?></th>
                        <td><?php echo esc_html(get_site_url()); ?></td>
                    </tr>
                </table>
            </div>
        </div>
        <?php
        
        // Handle clear logs action
        if (isset($_POST['site_logger_action']) && $_POST['site_logger_action'] === 'clear_all_logs' 
            && wp_verify_nonce($_POST['site_logger_nonce'], 'site_logger_clear_settings')) {
            self::clear_all_logs();
            echo '<script>setTimeout(function(){ window.location.href = "' . admin_url('admin.php?page=site-logs-settings') . '"; }, 1500);</script>';
        }
    }
    
    /**
     * Render dashboard page
     */
    public static function render_dashboard_page() {
        $summary = self::get_summary();
        $logs_by_action = self::get_logs_by_action(10);
        $logs_by_user = self::get_logs_by_user(10);
        
        ?>
        <div class="wrap">
            <h1><?php _e('Site Logger Dashboard', 'site-logger'); ?></h1>
            
            <!-- Summary Cards -->
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin: 20px 0;">
                <div style="background: #fff; padding: 20px; border-radius: 4px; border-left: 4px solid #2271b1;">
                    <h3 style="margin: 0 0 10px 0; color: #2271b1;"><?php echo number_format($summary['total']); ?></h3>
                    <p style="margin: 0; color: #646970; font-size: 14px;"><?php _e('Total Logs', 'site-logger'); ?></p>
                </div>
                
                <div style="background: #fff; padding: 20px; border-radius: 4px; border-left: 4px solid #00a32a;">
                    <h3 style="margin: 0 0 10px 0; color: #00a32a;"><?php echo number_format($summary['today']); ?></h3>
                    <p style="margin: 0; color: #646970; font-size: 14px;"><?php _e('Today', 'site-logger'); ?></p>
                </div>
                
                <div style="background: #fff; padding: 20px; border-radius: 4px; border-left: 4px solid #d63638;">
                    <h3 style="margin: 0 0 10px 0; color: #d63638;"><?php echo number_format($summary['errors']); ?></h3>
                    <p style="margin: 0; color: #646970; font-size: 14px;"><?php _e('Errors', 'site-logger'); ?></p>
                </div>
                
                <div style="background: #fff; padding: 20px; border-radius: 4px; border-left: 4px solid #ffb900;">
                    <h3 style="margin: 0 0 10px 0; color: #ffb900;"><?php echo number_format($summary['warnings']); ?></h3>
                    <p style="margin: 0; color: #646970; font-size: 14px;"><?php _e('Warnings', 'site-logger'); ?></p>
                </div>
                
                <div style="background: #fff; padding: 20px; border-radius: 4px; border-left: 4px solid #f0c33c;">
                    <h3 style="margin: 0 0 10px 0; color: #f0c33c;"><?php echo number_format($summary['users']); ?></h3>
                    <p style="margin: 0; color: #646970; font-size: 14px;"><?php _e('Active Users', 'site-logger'); ?></p>
                </div>
            </div>
            
            <!-- Charts and Stats -->
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin: 20px 0;">
                <!-- Top Actions -->
                <div style="background: #fff; padding: 20px; border-radius: 4px;">
                    <h3 style="margin-top: 0;"><?php _e('Top Actions', 'site-logger'); ?></h3>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th><?php _e('Action', 'site-logger'); ?></th>
                                <th><?php _e('Count', 'site-logger'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($logs_by_action)): ?>
                                <tr>
                                    <td colspan="2" style="text-align: center; padding: 10px;">
                                        <?php _e('No data available', 'site-logger'); ?>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($logs_by_action as $action): ?>
                                    <tr>
                                        <td><?php echo esc_html(self::format_action($action->action)); ?></td>
                                        <td><?php echo number_format($action->count); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Top Users -->
                <div style="background: #fff; padding: 20px; border-radius: 4px;">
                    <h3 style="margin-top: 0;"><?php _e('Top Users', 'site-logger'); ?></h3>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th><?php _e('User', 'site-logger'); ?></th>
                                <th><?php _e('Activity Count', 'site-logger'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($logs_by_user)): ?>
                                <tr>
                                    <td colspan="2" style="text-align: center; padding: 10px;">
                                        <?php _e('No data available', 'site-logger'); ?>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($logs_by_user as $user): ?>
                                    <?php $user_info = get_user_by('id', $user->user_id); ?>
                                    <tr>
                                        <td>
                                            <?php if ($user_info): ?>
                                                <a href="<?php echo get_edit_user_link($user->user_id); ?>">
                                                    <?php echo esc_html($user_info->display_name); ?>
                                                </a>
                                            <?php else: ?>
                                                <?php echo sprintf(__('User #%d', 'site-logger'), $user->user_id); ?>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo number_format($user->count); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <!-- Severity Distribution -->
            <div style="background: #fff; padding: 20px; border-radius: 4px; margin: 20px 0;">
                <h3 style="margin-top: 0;"><?php _e('Severity Distribution', 'site-logger'); ?></h3>
                <div style="display: flex; flex-wrap: wrap; gap: 20px; align-items: center;">
                    <?php
                    $severities = [
                        'emergency' => ['label' => 'Emergency', 'color' => '#dc3232'],
                        'alert' => ['label' => 'Alert', 'color' => '#f56e28'],
                        'critical' => ['label' => 'Critical', 'color' => '#d63638'],
                        'error' => ['label' => 'Error', 'color' => '#ff0000'],
                        'warning' => ['label' => 'Warning', 'color' => '#ffb900'],
                        'notice' => ['label' => 'Notice', 'color' => '#00a0d2'],
                        'info' => ['label' => 'Info', 'color' => '#2271b1'],
                        'debug' => ['label' => 'Debug', 'color' => '#a7aaad'],
                    ];
                    
                    foreach ($severities as $key => $severity):
                        $count = $summary[$key] ?? 0;
                        if ($count > 0):
                    ?>
                    <div style="text-align: center;">
                        <div style="width: 80px; height: 80px; border-radius: 50%; background: <?php echo $severity['color']; ?>; display: flex; align-items: center; justify-content: center; color: white; font-weight: bold; margin: 0 auto 10px;">
                            <?php echo number_format($count); ?>
                        </div>
                        <div style="font-size: 12px; color: #646970;"><?php echo $severity['label']; ?></div>
                    </div>
                    <?php
                        endif;
                    endforeach;
                    ?>
                </div>
            </div>
            
            <!-- Recent Activity -->
            <div style="background: #fff; padding: 20px; border-radius: 4px; margin: 20px 0;">
                <h3 style="margin-top: 0;"><?php _e('Recent Activity', 'site-logger'); ?></h3>
                <?php
                $recent_logs = self::get_logs(10);
                if (empty($recent_logs)):
                ?>
                    <p><?php _e('No recent activity found.', 'site-logger'); ?></p>
                <?php else: ?>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th width="150"><?php _e('Time', 'site-logger'); ?></th>
                                <th width="100"><?php _e('Severity', 'site-logger'); ?></th>
                                <th width="120"><?php _e('User', 'site-logger'); ?></th>
                                <th width="150"><?php _e('Action', 'site-logger'); ?></th>
                                <th width="150"><?php _e('Object', 'site-logger'); ?></th>
                                <th><?php _e('Details', 'site-logger'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recent_logs as $log): ?>
                                <?php
                                $user = $log->user_id ? get_user_by('id', $log->user_id) : null;
                                $username = $user ? $user->display_name : __('System', 'site-logger');
                                $time = date_i18n('M j, H:i:s', strtotime($log->timestamp));
                                ?>
                                <tr>
                                    <td><?php echo esc_html($time); ?></td>
                                    <td>
                                        <span class="severity-badge severity-<?php echo esc_attr($log->severity); ?>" 
                                              style="display: inline-block; padding: 3px 8px; border-radius: 3px; font-size: 11px; font-weight: 600; text-transform: uppercase; background: #f0f0f1; color: #50575e;">
                                            <?php echo esc_html(ucfirst($log->severity)); ?>
                                        </span>
                                    </td>
                                    <td><?php echo esc_html($username); ?></td>
                                    <td><code><?php echo esc_html(self::format_action($log->action)); ?></code></td>
                                    <td><?php echo esc_html(self::get_object_display_text($log)); ?></td>
                                    <td>
                                        <?php if ($log->details): ?>
                                            <span title="<?php echo esc_attr(json_encode($log->details, JSON_UNESCAPED_UNICODE)); ?>">
                                                <?php _e('View Details', 'site-logger'); ?>
                                            </span>
                                        <?php else: ?>
                                            <em><?php _e('No details', 'site-logger'); ?></em>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
            
            <!-- Quick Links -->
            <div style="display: flex; gap: 10px; margin-top: 20px;">
                <a href="<?php echo admin_url('admin.php?page=site-logs'); ?>" class="button button-primary">
                    <?php _e('View All Logs', 'site-logger'); ?>
                </a>
                <a href="<?php echo admin_url('admin.php?page=site-logs-settings'); ?>" class="button">
                    <?php _e('Settings', 'site-logger'); ?>
                </a>
            </div>
        </div>
        
        <style>
        .severity-badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 3px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }
        .severity-emergency { background: #dc3232; color: white; }
        .severity-alert { background: #f56e28; color: white; }
        .severity-critical { background: #d63638; color: white; }
        .severity-error { background: #ff0000; color: white; }
        .severity-warning { background: #ffb900; color: #000; }
        .severity-notice { background: #00a0d2; color: white; }
        .severity-info { background: #2271b1; color: white; }
        .severity-debug { background: #a7aaad; color: #000; }
        </style>
        <?php
    }

    /**
 * Beautiful rendering of content change details
 */
private static function render_beautiful_content_changes($data) {
    $output = '<div class="content-change-box">';

    // Summary line
    $output .= '<div class="content-header">';
    $output .= '<strong>Content Updated</strong>';

    if (!empty($data['characters_changed'])) {
        $ch = $data['characters_changed'];
        $is_plus = strpos($ch, '+') === 0;
        $num = abs((int) $ch);

        $output .= ' <span class="' . ($is_plus ? 'added-chars' : 'removed-chars') . '">';
        $output .= $is_plus ? '↑ +' : '↓ -';
        $output .= $num . ' char' . ($num !== 1 ? 's' : '');
        $output .= '</span>';
    }

    if (!empty($data['old_length']) && !empty($data['new_length'])) {
        $output .= ' <small>(' . esc_html($data['old_length']) . ' → ' . esc_html($data['new_length']) . ')</small>';
    }
    $output .= '</div>';

    // Detailed changes
    if (!empty($data['detailed_changes'])) {
        $dc = $data['detailed_changes'];

        // Added content
        if (!empty($dc['added']['sample'])) {
            $sample = esc_html($dc['added']['sample']);
            $count = $dc['added']['count'] ?? 0;

            $output .= '<div class="diff-section added">';
            $output .= '<div class="diff-title">＋ Added (' . $count . ' lines)</div>';
            $output .= '<pre class="diff-pre">' . nl2br($sample) . '</pre>';
            $output .= '</div>';
        }

        // Modified lines
        if (!empty($dc['modified'])) {
            $output .= '<div class="diff-section modified">';
            $output .= '<div class="diff-title">✏️ Modified lines</div>';

            foreach (array_slice($dc['modified'], 0, 4) as $mod) {  // limit to 4 for space
                $output .= '<div class="mod-line">';
                $output .= '<div class="line-info">Line ' . ($mod['line'] ?? '?') . ':</div>';
                $output .= '<div><span class="old-text">' . esc_html($mod['old'] ?? '') . '</span></div>';
                $output .= '<div><span class="new-text">' . esc_html($mod['new'] ?? '') . '</span></div>';
                $output .= '</div>';
            }

            if (count($dc['modified']) > 4) {
                $output .= '<div class="more-info">... and ' . (count($dc['modified']) - 4) . ' more modified lines</div>';
            }
            $output .= '</div>';
        }
    }

    // Word changes summary (small)
    if (!empty($data['word_changes']['added_words']['count'])) {
        $aw = $data['word_changes']['added_words'];
        $output .= '<div class="word-summary">';
        $output .= '➕ ' . $aw['count'] . ' new words (e.g. "' . esc_html(substr($aw['sample'] ?? '', 0, 80)) . '…")';
        $output .= '</div>';
    }

    $output .= '</div>';

    return $output;
}

}