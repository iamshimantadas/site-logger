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
            INDEX idx_action (action)
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
        $formatted_details = $details;
        
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
        
        return $wpdb->insert($table_name, $data);
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
        
        if (!empty($filters['search'])) {
            $where[] = '(object_name LIKE %s OR details LIKE %s)';
            $params[] = '%' . $wpdb->esc_like($filters['search']) . '%';
            $params[] = '%' . $wpdb->esc_like($filters['search']) . '%';
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
    }
    
    /**
     * Get user IP address
     */
    private static function get_user_ip() {
        $ip = '';
        
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
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
            'users' => $wpdb->get_var("SELECT COUNT(DISTINCT user_id) FROM $table_name"),
            'errors' => $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE severity IN ('error', 'critical', 'alert', 'emergency')")
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
        
        ?>
        <div class="wrap">
            <h1><?php _e('Site Activity Logs', 'site-logger'); ?></h1>
            
            <!-- Summary -->
            <div class="site-logs-summary" style="background: #fff; padding: 20px; margin: 20px 0; border: 1px solid #ccd0d4; border-radius: 4px;">
                <h2 style="margin-top: 0;"><?php _e('Overview', 'site-logger'); ?></h2>
                <div style="display: flex; gap: 30px; flex-wrap: wrap;">
                    <div>
                        <h3 style="margin: 0 0 5px 0; color: #2271b1;"><?php echo esc_html($summary['total']); ?></h3>
                        <p style="margin: 0; color: #646970;"><?php _e('Total Logs', 'site-logger'); ?></p>
                    </div>
                    <div>
                        <h3 style="margin: 0 0 5px 0; color: #00a32a;"><?php echo esc_html($summary['today']); ?></h3>
                        <p style="margin: 0; color: #646970;"><?php _e('Today', 'site-logger'); ?></p>
                    </div>
                    <div>
                        <h3 style="margin: 0 0 5px 0; color: #d63638;"><?php echo esc_html($summary['errors']); ?></h3>
                        <p style="margin: 0; color: #646970;"><?php _e('Errors', 'site-logger'); ?></p>
                    </div>
                    <div>
                        <h3 style="margin: 0 0 5px 0; color: #f0c33c;"><?php echo esc_html($summary['users']); ?></h3>
                        <p style="margin: 0; color: #646970;"><?php _e('Active Users', 'site-logger'); ?></p>
                    </div>
                </div>
            </div>
            
            <!-- Filters -->
            <div class="site-logs-filters" style="background: #fff; padding: 20px; margin: 20px 0; border: 1px solid #ccd0d4; border-radius: 4px;">
                <h3 style="margin-top: 0;"><?php _e('Filter Logs', 'site-logger'); ?></h3>
                <form method="get" action="">
                    <input type="hidden" name="page" value="site-logs">
                    
                    <div style="display: flex; gap: 20px; margin-bottom: 15px; flex-wrap: wrap;">
                        <div>
                            <label for="severity" style="display: block; margin-bottom: 5px; font-weight: 600;">
                                <?php _e('Severity:', 'site-logger'); ?>
                            </label>
                            <select name="severity" id="severity" style="min-width: 150px;">
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
                                'style' => 'min-width: 200px;'
                            ]);
                            ?>
                        </div>
                        
                        <div>
                            <label for="action" style="display: block; margin-bottom: 5px; font-weight: 600;">
                                <?php _e('Action:', 'site-logger'); ?>
                            </label>
                            <select name="action" id="action" style="min-width: 200px;">
                                <option value=""><?php _e('All Actions', 'site-logger'); ?></option>
                                <?php foreach ($actions as $action): ?>
                                    <option value="<?php echo esc_attr($action); ?>" <?php selected(!empty($filters['action']) && $filters['action'] === $action); ?>>
                                        <?php echo esc_html(self::format_action($action)); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div style="display: flex; gap: 20px; align-items: flex-end;">
                        <div style="flex: 1;">
                            <label for="search" style="display: block; margin-bottom: 5px; font-weight: 600;">
                                <?php _e('Search:', 'site-logger'); ?>
                            </label>
                            <input type="text" name="search" id="search" value="<?php echo !empty($filters['search']) ? esc_attr($filters['search']) : ''; ?>" 
                                   placeholder="<?php esc_attr_e('Search logs...', 'site-logger'); ?>" style="width: 100%;">
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
                                $details = json_decode($log->details, true);
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
                                        <?php if ($log->object_id && $log->object_type === 'post'): ?>
                                            <a href="<?php echo get_edit_post_link($log->object_id); ?>">
                                                <?php echo esc_html($log->object_name ?: __('Post', 'site-logger') . ' #' . $log->object_id); ?>
                                            </a>
                                        <?php elseif ($log->object_id && $log->object_type === 'user'): ?>
                                            <a href="<?php echo get_edit_user_link($log->object_id); ?>">
                                                <?php echo esc_html($log->object_name ?: __('User', 'site-logger') . ' #' . $log->object_id); ?>
                                            </a>
                                        <?php else: ?>
                                            <?php echo esc_html($log->object_name ?: ($log->object_type ?: '—')); ?>
                                        <?php endif; ?>
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
            <div style="margin-top: 20px;">
                <form method="post" action="">
                    <?php wp_nonce_field('site_logger_export', 'site_logger_nonce'); ?>
                    <input type="hidden" name="site_logger_action" value="export_csv">
                    <button type="submit" class="button">
                        <span class="dashicons dashicons-download" style="vertical-align: middle; margin-right: 5px;"></span>
                        <?php _e('Export to CSV', 'site-logger'); ?>
                    </button>
                </form>
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
        }
        .detail-item {
            margin-bottom: 4px;
            padding-bottom: 4px;
            border-bottom: 1px solid #f0f0f1;
        }
        .detail-item:last-child {
            border-bottom: none;
            margin-bottom: 0;
        }
        .detail-key {
            font-weight: 600;
            color: #1d2327;
            display: inline-block;
            min-width: 100px;
        }
        .change-old {
            color: #d63638;
            text-decoration: line-through;
            margin-right: 5px;
        }
        .change-new {
            color: #00a32a;
            font-weight: 600;
        }
        .change-arrow {
            color: #8c8f94;
            margin: 0 5px;
        }
        </style>
        <?php
        
        // Handle CSV export
        if (isset($_POST['site_logger_action']) && $_POST['site_logger_action'] === 'export_csv' 
            && wp_verify_nonce($_POST['site_logger_nonce'], 'site_logger_export')) {
            self::export_csv($logs);
        }
    }
    
    /**
     * Format action for display
     */
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
        'revision_created' => '📚 Revision Created',
        'user_registered' => '👤 User Registered',
        'user_updated' => '✏️ User Updated',
        'user_login' => '🔐 User Login',
        'user_logout' => '🔓 User Logout',
        'option_updated' => '⚙️ Setting Updated',
        'plugin_activated' => '🔌 Plugin Activated',
        'plugin_deactivated' => '🔌 Plugin Deactivated',
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
        'widget_updated' => '🧩 Widget Updated',
        'widgets_rearranged' => '🧩 Widgets Rearranged',
    ];
    
    return $actions[$action] ?? ucwords(str_replace('_', ' ', $action));
}
    
    /**
     * Format details for display
     */
    /**
 * Format details for display
 */
private static function format_details_display($details) {
    if (empty($details) || !is_array($details)) {
        return '<em>' . __('No details', 'site-logger') . '</em>';
    }
    
    $output = '<div class="log-details">';
    
    foreach ($details as $key => $value) {
        $output .= '<div class="detail-item">';
        $output .= '<span class="detail-key">' . esc_html(ucfirst(str_replace('_', ' ', $key))) . ':</span> ';
        
        if ($key === 'view_revisions' && strpos($value, '<a ') !== false) {
            // Allow HTML for revision links
            $output .= $value;
        } elseif (is_array($value)) {
            if (isset($value['old']) && isset($value['new'])) {
                // Before/after change
                $output .= '<span class="change-old">"' . esc_html($value['old']) . '"</span>';
                $output .= '<span class="change-arrow"> → </span>';
                $output .= '<span class="change-new">"' . esc_html($value['new']) . '"</span>';
            } elseif (isset($value['added']) || isset($value['removed'])) {
                // Array changes
                if (!empty($value['added'])) {
                    $output .= '<span class="change-new">➕ Added: ' . esc_html(is_array($value['added']) ? implode(', ', $value['added']) : $value['added']) . '</span>';
                }
                if (!empty($value['removed'])) {
                    if (!empty($value['added'])) $output .= '<br>';
                    $output .= '<span class="change-old">➖ Removed: ' . esc_html(is_array($value['removed']) ? implode(', ', $value['removed']) : $value['removed']) . '</span>';
                }
            } elseif (!empty($value)) {
                $output .= '<span class="detail-value">' . esc_html(json_encode($value, JSON_UNESCAPED_UNICODE)) . '</span>';
            }
        } elseif (is_string($value)) {
            // Check for special strings
            if (strpos($value, 'Content updated') !== false) {
                $output .= '<span class="detail-value">📝 ' . esc_html($value) . '</span>';
            } elseif (strpos($value, 'featured image') !== false) {
                $output .= '<span class="detail-value">🖼️ ' . esc_html($value) . '</span>';
            } elseif (strpos($value, 'Updated:') !== false) {
                $output .= '<span class="detail-value">🔧 ' . esc_html($value) . '</span>';
            } elseif (strpos($value, 'Set to:') !== false) {
                $output .= '<span class="detail-value">🏷️ ' . esc_html($value) . '</span>';
            } else {
                $output .= '<span class="detail-value">' . esc_html($value) . '</span>';
            }
        }
        
        $output .= '</div>';
    }
    
    $output .= '</div>';
    return $output;
}
    
    /**
     * Export logs to CSV
     */
    private static function export_csv($logs) {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="site-logs-' . date('Y-m-d') . '.csv"');
        
        $output = fopen('php://output', 'w');
        
        // Headers
        fputcsv($output, [
            'ID',
            'Timestamp',
            'User',
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
            $details = json_decode($log->details, true);
            $details_text = '';
            
            if ($details) {
                foreach ($details as $key => $value) {
                    if (is_array($value)) {
                        if (isset($value['old']) && isset($value['new'])) {
                            $details_text .= $key . ': ' . $value['old'] . ' → ' . $value['new'] . '; ';
                        } else {
                            $details_text .= $key . ': ' . json_encode($value) . '; ';
                        }
                    } else {
                        $details_text .= $key . ': ' . $value . '; ';
                    }
                }
            }
            
            fputcsv($output, [
                $log->id,
                $log->timestamp,
                $username,
                ucfirst($log->severity),
                self::format_action($log->action),
                $log->object_type,
                $log->object_id,
                $log->object_name,
                trim($details_text)
            ]);
        }
        
        fclose($output);
        exit;
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
            
            echo '<div class="notice notice-success"><p>' . __('Settings saved successfully.', 'site-logger') . '</p></div>';
        }
        
        ?>
        <div class="wrap">
            <h1><?php _e('Site Logger Settings', 'site-logger'); ?></h1>
            
            <form method="post" action="">
                <?php wp_nonce_field('site_logger_save_settings', 'site_logger_settings_nonce'); ?>
                
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
                </table>
                
                <p class="submit">
                    <button type="submit" name="site_logger_save_settings" class="button button-primary">
                        <?php _e('Save Settings', 'site-logger'); ?>
                    </button>
                </p>
            </form>
        </div>
        <style>
            /* Add to your existing CSS in class-site-logger.php */
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
        </style>
        <?php
    }
}