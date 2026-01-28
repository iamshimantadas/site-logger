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

        add_action('admin_init', [__CLASS__, 'handle_post_requests'], 1);
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
     * Create database table with optimized indexes
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
            INDEX idx_object_type (object_type),
            INDEX idx_object_id (object_id),
            INDEX idx_timestamp_severity (timestamp, severity),
            INDEX idx_user_action (user_id, action),
            INDEX idx_object (object_type, object_id)
        ) $charset_collate;";
        
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }
    
    /**
     * Log an activity with HTML support
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
        
        // Format details for storage
        $formatted_details = is_array($details) ? $details : [];
        
        // Process details to ensure HTML is properly stored
        foreach ($formatted_details as $key => $value) {
            // If value contains HTML, make sure it's properly formatted
            if (is_string($value) && preg_match('/<[^>]+>/', $value)) {
                // Already HTML, keep as is
                continue;
            } elseif (is_array($value) || is_object($value)) {
                // Convert arrays/objects to JSON
                $formatted_details[$key] = $value;
            }
        }
        
        $data = [
            'user_id' => get_current_user_id() ?: 0,
            'user_ip' => self::get_user_ip(),
            'severity' => $severity,
            'action' => sanitize_text_field($action),
            'object_type' => sanitize_text_field($object_type),
            'object_id' => absint($object_id),
            'object_name' => sanitize_text_field($object_name),
            'details' => wp_json_encode($formatted_details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
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
     * Get logs with pagination and HTML support
     */
    public static function get_logs($page = 1, $per_page = 50, $filters = []) {
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
        
        // Calculate offset for pagination
        $offset = max(0, ($page - 1) * $per_page);
        
        // Optimized query with EXPLAIN hints
        $query = "SELECT SQL_CALC_FOUND_ROWS * FROM $table_name WHERE $where_sql ORDER BY timestamp DESC";
        $query .= " LIMIT %d OFFSET %d";
        
        $params[] = absint($per_page);
        $params[] = absint($offset);
        
        if (!empty($params)) {
            $query = $wpdb->prepare($query, $params);
        }
        
        $results = $wpdb->get_results($query);
        
        // Handle database errors
        if ($wpdb->last_error) {
            error_log('Site Logger Database Error: ' . $wpdb->last_error);
            return [];
        }
        
        // Get total count using FOUND_ROWS() for better performance
        $total_logs = $wpdb->get_var('SELECT FOUND_ROWS()');
        
        // Decode details with HTML preservation
        foreach ($results as $log) {
            if (!empty($log->details)) {
                $decoded = json_decode($log->details, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $log->details = $decoded;
                } else {
                    $unserialized = maybe_unserialize($log->details);
                    if (is_array($unserialized) || is_object($unserialized)) {
                        $log->details = (array)$unserialized;
                    }
                }
            }
        }
        
        return [
            'logs' => $results,
            'total' => $total_logs
        ];
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
    public static function get_logs_by_action($page = 1, $per_page = 10) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . self::TABLE_NAME;
        $offset = ($page - 1) * $per_page;
        
        $query = $wpdb->prepare(
            "SELECT action, COUNT(*) as count 
             FROM $table_name 
             GROUP BY action 
             ORDER BY count DESC 
             LIMIT %d OFFSET %d",
            $per_page,
            $offset
        );
        
        return $wpdb->get_results($query);
    }
    
    /**
     * Get logs grouped by user
     */
    public static function get_logs_by_user($page = 1, $per_page = 10) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . self::TABLE_NAME;
        $offset = ($page - 1) * $per_page;
        
        $query = $wpdb->prepare(
            "SELECT user_id, COUNT(*) as count 
             FROM $table_name 
             WHERE user_id > 0 
             GROUP BY user_id 
             ORDER BY count DESC 
             LIMIT %d OFFSET %d",
            $per_page,
            $offset
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
    }
    
    /**
     * Render admin page
     */
    public static function render_admin_page() {
        // Initialize filters array
        $filters = [];
        
        // Handle reset parameter FIRST
        if (isset($_GET['reset']) && $_GET['reset'] == '1') {
            // Clear all stored filters
            delete_transient('site_logger_current_filters_' . get_current_user_id());
            delete_transient('site_logger_current_per_page_' . get_current_user_id());
            
            // Remove reset parameter from URL
            wp_safe_redirect(admin_url('admin.php?page=site-logs'));
            exit;
        }
        
        // Check if we're viewing filtered results (only if NOT reset)
        if (isset($_GET['filtered']) && $_GET['filtered'] == '1') {
            // Retrieve stored filters
            $filters = get_transient('site_logger_current_filters_' . get_current_user_id());
            $per_page = get_transient('site_logger_current_per_page_' . get_current_user_id());
            
            // If no stored filters, use defaults
            if ($filters === false) {
                $filters = [];
            }
            if ($per_page === false) {
                $per_page = get_option('posts_per_page', 10);
            }
        } else {
            // For direct GET requests (not from POST redirect), use URL parameters
            $per_page = get_option('posts_per_page', 10);
            
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
            if (!empty($_GET['per_page'])) {
                $per_page = intval($_GET['per_page']);
            }
        }
        
        // Get current page
        $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;

        $per_page_options = [10, 20, 30, 50, 100, 150, 200, 250, 500];
        if (!in_array($per_page, $per_page_options)) {
            $per_page_options[] = $per_page;
            sort($per_page_options);
        }
        
        // Get logs with pagination
        $logs_data = self::get_logs($current_page, $per_page, $filters);
        $logs = $logs_data['logs'];
        $total_logs = $logs_data['total'];
        $total_pages = ceil($total_logs / $per_page);
        
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
                <form method="post" action="<?php echo admin_url('admin.php?page=site-logs'); ?>">
                    <?php wp_nonce_field('site_logger_filter', 'site_logger_filter_nonce'); ?>
                    <input type="hidden" name="site_logger_filter" value="1">
                    
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
                            <label for="per_page" style="display: block; margin-bottom: 5px; font-weight: 600;">
                                <?php _e('Logs per page:', 'site-logger'); ?>
                            </label>
                            <select name="per_page" id="per_page" style="width: 120px;">
                                <?php foreach ($per_page_options as $option): ?>
                                    <option value="<?php echo esc_attr($option); ?>" <?php selected($per_page, $option); ?>>
                                        <?php echo esc_html($option); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div>
                            <button type="submit" name="apply_filters" class="button button-primary" style="margin-bottom: 5px;">
                                <?php _e('Apply Filters', 'site-logger'); ?>
                            </button>
                            <button type="submit" name="reset_filters" class="button" onclick="resetForm(event)">
                                <?php _e('Reset', 'site-logger'); ?>
                            </button>
                        </div>

                        <div style="border-left: 1px solid #dcdcde; padding-left: 15px;">
                            <span style="display: block; margin-bottom: 5px; font-weight: 600; color: #646970;">
                                <?php _e('Export:', 'site-logger'); ?>
                            </span>
                            <div style="display: flex; gap: 10px;">
                                <!-- Export buttons (still use GET with current filters) -->
                                <a href="<?php 
                                    echo wp_nonce_url(
                                        add_query_arg(
                                            array_merge(
                                                ['export_type' => 'csv', 'page' => 'site-logs'],
                                                $filters
                                            ),
                                            admin_url('admin.php')
                                        ),
                                        'site_logger_export'
                                    );
                                ?>" class="button" style="background: #00a32a; border-color: #00a32a; color: white;">
                                    <span class="dashicons dashicons-media-spreadsheet" style="vertical-align: middle; margin-right: 5px;"></span>
                                    <?php _e('Export CSV', 'site-logger'); ?>
                                </a>
                                
                                <a href="<?php 
                                    echo wp_nonce_url(
                                        add_query_arg(
                                            array_merge(
                                                ['export_type' => 'pdf', 'page' => 'site-logs'],
                                                $filters
                                            ),
                                            admin_url('admin.php')
                                        ),
                                        'site_logger_export'
                                    );
                                ?>" class="button" style="background: #d63638; border-color: #d63638; color: white;">
                                    <span class="dashicons dashicons-media-document" style="vertical-align: middle; margin-right: 5px;"></span>
                                    <?php _e('Export PDF', 'site-logger'); ?>
                                </a>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Hidden current page field -->
                    <input type="hidden" name="paged" value="<?php echo esc_attr($current_page); ?>">
                </form>
            </div>
            
            <!-- Logs Table with bottom-left pagination -->
            <div style="position: relative;">
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
                
                <!-- Bottom Left Pagination -->
                <?php if ($total_pages > 1): ?>
                <div class="tablenav bottom" style="margin: 20px 0; display: flex; justify-content: space-between; align-items: center;">
                    <div class="tablenav-pages" style="float: none;">
                        <span class="displaying-num"><?php 
                            printf(
                                __('Displaying %1$s–%2$s of %3$s', 'site-logger'),
                                number_format(($current_page - 1) * $per_page + 1),
                                number_format(min($current_page * $per_page, $total_logs)),
                                number_format($total_logs)
                            );
                        ?></span>
                        <span class="pagination-links">
                            <?php
                            // Create pagination links with filters
                            $pagination_args = array_merge(['page' => 'site-logs'], $filters);
                            
                            // First page link
                            if ($current_page > 1) {
                                $first_args = array_merge($pagination_args, ['paged' => 1]);
                                $first_url = add_query_arg($first_args, admin_url('admin.php'));
                                echo '<a class="first-page button" href="' . esc_url($first_url) . '"><span class="screen-reader-text">' . __('First page', 'site-logger') . '</span><span aria-hidden="true">«</span></a>';
                            } else {
                                echo '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">«</span>';
                            }
                            
                            // Previous page link
                            if ($current_page > 1) {
                                $prev_args = array_merge($pagination_args, ['paged' => $current_page - 1]);
                                $prev_url = add_query_arg($prev_args, admin_url('admin.php'));
                                echo '<a class="prev-page button" href="' . esc_url($prev_url) . '"><span class="screen-reader-text">' . __('Previous page', 'site-logger') . '</span><span aria-hidden="true">‹</span></a>';
                            } else {
                                echo '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">‹</span>';
                            }
                            ?>
                            
                            <span class="paging-input" style="margin: 0 10px;">
                                <label for="current-page-selector" class="screen-reader-text"><?php _e('Current Page', 'site-logger'); ?></label>
                                <?php _e('Page', 'site-logger'); ?>
                                <span class="current-page"><?php echo esc_html($current_page); ?></span>
                                <?php _e('of', 'site-logger'); ?> <span class="total-pages"><?php echo esc_html($total_pages); ?></span>
                            </span>
                            
                            <?php
                            // Next page link
                            if ($current_page < $total_pages) {
                                $next_args = array_merge($pagination_args, ['paged' => $current_page + 1]);
                                $next_url = add_query_arg($next_args, admin_url('admin.php'));
                                echo '<a class="next-page button" href="' . esc_url($next_url) . '"><span class="screen-reader-text">' . __('Next page', 'site-logger') . '</span><span aria-hidden="true">›</span></a>';
                            } else {
                                echo '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">›</span>';
                            }
                            
                            // Last page link
                            if ($current_page < $total_pages) {
                                $last_args = array_merge($pagination_args, ['paged' => $total_pages]);
                                $last_url = add_query_arg($last_args, admin_url('admin.php'));
                                echo '<a class="last-page button" href="' . esc_url($last_url) . '"><span class="screen-reader-text">' . __('Last page', 'site-logger') . '</span><span aria-hidden="true">»</span></a>';
                            } else {
                                echo '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">»</span>';
                            }
                            ?>
                        </span>
                    </div>
                    
                    <!-- Quick page jump form (POST method) -->
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <form method="post" action="<?php echo admin_url('admin.php?page=site-logs'); ?>" style="display: flex; align-items: center; gap: 5px;">
                            <?php wp_nonce_field('site_logger_filter', 'site_logger_filter_nonce'); ?>
                            <input type="hidden" name="site_logger_filter" value="1">
                            <?php foreach ($filters as $key => $value): ?>
                                <?php if (!empty($value)): ?>
                                    <input type="hidden" name="<?php echo esc_attr($key); ?>" value="<?php echo esc_attr($value); ?>">
                                <?php endif; ?>
                            <?php endforeach; ?>
                            <input type="hidden" name="per_page" value="<?php echo esc_attr($per_page); ?>">
                            <label for="jump-page" style="font-size: 13px;"><?php _e('Jump to:', 'site-logger'); ?></label>
                            <input type="number" id="jump-page" name="paged" min="1" max="<?php echo esc_attr($total_pages); ?>" 
                                   value="<?php echo esc_attr($current_page); ?>" style="width: 60px;">
                            <button type="submit" class="button button-small"><?php _e('Go', 'site-logger'); ?></button>
                        </form>
                    </div>
                </div>
                <?php else: ?>
                    <!-- Show total count when no pagination needed -->
                    <div class="tablenav bottom" style="margin: 20px 0;">
                        <div class="displaying-num">
                            <?php 
                            printf(
                                _n('%s item', '%s items', $total_logs, 'site-logger'),
                                number_format($total_logs)
                            );
                            ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
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
        
        /* HTML content styling */
        .log-details .html-content {
            background: #fff;
            border: 1px solid #dcdcde;
            border-radius: 4px;
            padding: 15px;
            margin-top: 5px;
            max-width: 100%;
            overflow-x: auto;
        }
        
        /* ACF specific styling */
        .log-details .html-content .acf-changes-summary,
        .log-details .html-content .location-rules {
            font-size: 13px;
            line-height: 1.5;
        }
        
        .log-details .html-content .change-item {
            margin: 8px 0;
            padding: 8px;
            background: #f8f9fa;
            border-radius: 4px;
            border-left: 3px solid #2271b1;
        }
        
        .log-details .html-content .change-label {
            font-weight: 600;
            color: #1d2327;
            display: block;
            margin-bottom: 5px;
        }
        
        .log-details .html-content .change-old, 
        .log-details .html-content .change-new {
            padding: 2px 6px;
            border-radius: 3px;
            margin: 0 2px;
        }
        
        .log-details .html-content .change-old {
            background: #fcf0f1;
            color: #d63638;
            text-decoration: line-through;
        }
        
        .log-details .html-content .change-new {
            background: #f0f9f1;
            color: #00a32a;
            font-weight: 600;
        }
        
        .log-details .html-content .change-arrow {
            color: #8c8f94;
            margin: 0 8px;
            font-weight: bold;
        }
        
        .log-details .html-content .field-changes-section {
            margin-top: 15px;
            border-top: 2px solid #f0f0f1;
            padding-top: 15px;
        }
        
        .log-details .html-content .field-changes-header {
            font-weight: 600;
            color: #1d2327;
            margin-bottom: 10px;
            font-size: 14px;
        }
        
        .log-details .html-content .field-change-group {
            margin: 10px 0;
            padding: 10px;
            border-radius: 5px;
        }
        
        .log-details .html-content .modified-fields {
            background: #fff9e6;
            border: 1px solid #ffecb5;
        }
        
        .log-details .html-content .field-group-header {
            font-weight: 600;
            margin-bottom: 8px;
            padding-bottom: 5px;
            border-bottom: 1px dashed #ddd;
            color: #664d03;
        }
        
        .log-details .html-content .field-item {
            margin: 8px 0;
            padding: 8px 10px;
            background: #fff;
            border-radius: 4px;
            border-left: 4px solid #2271b1;
        }
        
        .log-details .html-content .modified-field {
            border-left-color: #dba617;
        }
        
        .log-details .html-content .field-meta {
            font-size: 11px;
            color: #646970;
            font-family: monospace;
            margin-left: 8px;
        }
        
        .log-details .html-content .field-modifications {
            margin-top: 8px;
            padding-top: 8px;
            border-top: 1px dashed #e0e0e0;
        }
        
        .log-details .html-content .field-modification {
            margin: 4px 0;
            padding: 4px 8px;
            background: #f8f9fa;
            border-radius: 3px;
            font-size: 12px;
        }
        
        .log-details .html-content .mod-prop {
            font-weight: 600;
            color: #50575e;
            display: inline-block;
            min-width: 120px;
        }
        
        .log-details .html-content .mod-old, 
        .log-details .html-content .mod-new {
            padding: 1px 4px;
            border-radius: 2px;
            margin: 0 4px;
        }
        
        .log-details .html-content .mod-old {
            background: #ffe2e3;
            color: #d63638;
        }
        
        .log-details .html-content .mod-new {
            background: #cce8d1;
            color: #00a32a;
        }
        
        .log-details .html-content .location-rules {
            background: #f6f7f7;
            padding: 10px;
            border-radius: 4px;
            border: 1px solid #dcdcde;
        }
        
        .log-details .html-content .location-group {
            margin: 5px 0;
            padding: 8px;
            background: #fff;
            border-radius: 3px;
            border-left: 3px solid #2271b1;
        }
        
        .log-details .html-content .location-group code {
            background: #f0f0f1;
            padding: 2px 6px;
            border-radius: 3px;
            font-family: monospace;
            color: #50575e;
        }
        
        /* Action links styling */
        .action-links {
            margin-top: 10px;
            padding-top: 10px;
            border-top: 2px solid #e0e0e0;
        }
        
        .action-links a {
            display: inline-block;
            margin-right: 10px;
            padding: 3px 8px;
            background: #2271b1;
            color: white;
            text-decoration: none;
            border-radius: 3px;
            font-size: 11px;
        }
        
        .action-links a:hover {
            background: #135e96;
        }
        
        /* Make table more readable */
        .wp-list-table th {
            font-weight: 600;
            background: #f6f7f7;
        }
        .wp-list-table tr:hover {
            background: #f6f7f7 !important;
        }
        
        /* Pagination styling */
        .tablenav.bottom {
            background: #fff;
            padding: 15px;
            border: 1px solid #ccd0d4;
            border-radius: 4px;
            border-top: none;
            border-top-left-radius: 0;
            border-top-right-radius: 0;
        }
        
        .tablenav-pages a.button {
            display: inline-block;
            padding: 3px 8px;
            min-width: 30px;
            height: 28px;
            line-height: 20px;
            text-align: center;
            text-decoration: none;
        }
        
        .tablenav-pages-navspan.button.disabled {
            display: inline-block;
            padding: 3px 8px;
            min-width: 30px;
            height: 28px;
            line-height: 20px;
            text-align: center;
            background: #f6f7f7;
            color: #a7aaad;
            border-color: #dcdcde;
            cursor: default;
        }
        
        .paging-input input.current-page {
            padding: 3px 5px;
            border: 1px solid #8c8f94;
            border-radius: 3px;
        }
        
        /* Responsive table */
        @media screen and (max-width: 1200px) {
            .wp-list-table {
                display: block;
                overflow-x: auto;
                white-space: nowrap;
            }
        }
        
        /* Mobile responsive */
        @media screen and (max-width: 782px) {
            .tablenav.bottom {
                flex-direction: column;
                gap: 15px;
            }
            
            .tablenav-pages {
                width: 100%;
                justify-content: center;
            }
            
            .tablenav-pages .pagination-links {
                display: flex;
                align-items: center;
                justify-content: center;
                flex-wrap: wrap;
                gap: 5px;
            }
        }
        </style>

<script>
     jQuery(document).ready(function($) {
        // Add this inside your jQuery(document).ready() function
$('button[name="reset_filters"]').on('click', function(e) {
    e.preventDefault();
    var form = $(this).closest('form');
    form.find('select').val('');
    form.find('input[type="text"], input[type="date"], input[type="number"]').val('');
    form.find('input[name="user_id"]').val(0);
    form.find('input[name="paged"]').val(1);
    
    // Submit the reset
    form.submit();
});

// Handle export button clicks - simple version
$(document).on('click', 'a.button[href*="export_type"]', function(e) {
    var $button = $(this);
    
    // Store original state
    var originalHtml = $button.html();
    
    // Show loading state
    $button.html('<span class="spinner is-active" style="float: none; margin: 0 5px 0 0;"></span> ' + $button.text());
    $button.addClass('disabled').css('pointer-events', 'none');
    
});
        });
</script>

    <?php
}

    /**
     * Format action for display
     */
    public static function format_action($action) {
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
     * Get object display text
     */
    public static function get_object_display_text($log) {
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
     * Format details for display with HTML support
     */
    private static function format_details_display($details) {
        if (empty($details) || !is_array($details)) {
            return '<em>' . __('No details', 'site-logger') . '</em>';
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
            // Special handling for changes_display (ACF HTML)
            if ($key === 'changes_display' || $key === 'location') {
                $output .= '<div class="detail-item">';
                $output .= '<span class="detail-key">' . esc_html(ucfirst(str_replace('_', ' ', $key))) . ':</span> ';
                $output .= '<div class="detail-value html-content">' . $value . '</div>';
                $output .= '</div>';
                continue;
            }

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
                    $output .= '<pre style="font-size: 12px; max-height: 200px; overflow: auto; background: #f6f7f7; padding: 10px; border-radius: 4px;">' . esc_html(json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) . '</pre>';
                }
            } else {
                // Check if value contains HTML tags
                if (is_string($value) && preg_match('/<[^>]+>/', $value)) {
                    $output .= '<div class="detail-value html-content">' . $value . '</div>';
                } else {
                    $output .= '<span class="detail-value">' . esc_html($value) . '</span>';
                }
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


    /**
     * Handle POST filter requests early
     */
    public static function handle_post_requests() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['site_logger_filter'])) {
            return;
        }
        
        // Verify nonce
        if (!isset($_POST['site_logger_filter_nonce']) || 
            !wp_verify_nonce($_POST['site_logger_filter_nonce'], 'site_logger_filter')) {
            wp_die('Security check failed');
        }
        
        // Check if reset button was clicked
        if (isset($_POST['reset_filters'])) {
            // Clear all stored filters
            delete_transient('site_logger_current_filters_' . get_current_user_id());
            delete_transient('site_logger_current_per_page_' . get_current_user_id());
            
            // Redirect to clean URL WITHOUT filtered=1
            wp_safe_redirect(admin_url('admin.php?page=site-logs&reset=1'));
            exit;
        }
        
        // Collect filters from POST
        $filters = [];
        $filter_keys = ['severity', 'user_id', 'action', 'object_type', 'date_from', 'date_to', 'search'];
        
        foreach ($filter_keys as $key) {
            if (!empty($_POST[$key])) {
                $filters[$key] = sanitize_text_field($_POST[$key]);
            }
        }
        
        $per_page = get_option('posts_per_page', 10);
        if (!empty($_POST['per_page'])) {
            $per_page = intval($_POST['per_page']);
        }
        
        // Store filters in session/transient for pagination
        set_transient('site_logger_current_filters_' . get_current_user_id(), $filters, HOUR_IN_SECONDS);
        set_transient('site_logger_current_per_page_' . get_current_user_id(), $per_page, HOUR_IN_SECONDS);
        
        // Preserve current page if set
        $paged = 1;
        if (!empty($_POST['paged'])) {
            $paged = intval($_POST['paged']);
        }
        
        // Redirect with filtered=1 ONLY for apply_filters, NOT for reset
        $redirect_args = ['page' => 'site-logs', 'filtered' => '1'];
        if ($paged > 1) {
            $redirect_args['paged'] = $paged;
        }
        
        wp_safe_redirect(add_query_arg($redirect_args, admin_url('admin.php')));
        exit;
    }

}