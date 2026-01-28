<?php
class Site_Logger_Export {
    
    /**
     * Handle export request
     */
    public static function handle_export_request() {
        // // Check if export_type is set
        // if (!isset($_GET['export_type']) || $_GET['page'] !== 'site-logs') {
        //     return;
        // }
        
        // // Verify nonce for security
        // if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'site_logger_export')) {
        //     wp_die('Security check failed');
        // }
        
        // // Collect filters from request
        // $filters = [];
        // $filter_keys = ['severity', 'user_id', 'action', 'object_type', 'object_id', 'date_from', 'date_to', 'search'];
        
        // foreach ($filter_keys as $key) {
        //     if (!empty($_GET[$key])) {
        //         $filters[$key] = sanitize_text_field($_GET[$key]);
        //     }
        // }
        // Check if export_type is set
    if (!isset($_GET['export_type']) || $_GET['page'] !== 'site-logs') {
        return;
    }
    
    // Verify nonce for security
    if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'site_logger_export')) {
        wp_die('Security check failed');
    }
    
    // Collect filters from request (GET parameters)
    $filters = [];
    $filter_keys = ['severity', 'user_id', 'action', 'object_type', 'object_id', 'date_from', 'date_to', 'search'];
    
    foreach ($filter_keys as $key) {
        if (!empty($_GET[$key])) {
            $filters[$key] = sanitize_text_field($_GET[$key]);
        }
    }
        
        // Get all logs for export
        $logs = self::get_all_logs_for_export($filters);
        
        // Perform export based on type
        $export_type = sanitize_text_field($_GET['export_type']);
        
        if ($export_type === 'csv') {
            self::export_csv($logs, $filters);
        } elseif ($export_type === 'pdf') {
            self::export_pdf($logs, $filters);
        } else {
            wp_die('Invalid export type');
        }
        
        exit; // Stop execution after export
    }
    
    /**
     * Format action for display (proxy method)
     */
    public static function format_action($action) {
        return Site_Logger::format_action($action);
    }
    
    /**
     * Get object display text (proxy method)
     */
    public static function get_object_display_text($log) {
        return Site_Logger::get_object_display_text($log);
    }
    
    /**
     * Get all logs for export (no pagination limit)
     */
    public static function get_all_logs_for_export($filters = []) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . Site_Logger::TABLE_NAME;
        
        $where = ['1=1'];
        $params = [];
        
        // Apply filters (same logic as get_logs but without LIMIT)
        if (!empty($filters['severity'])) {
            $where[] = 'severity = %s';
            $params[] = $filters['severity'];
        }
        
        if (!empty($filters['user_id'])) {
            $where[] = 'user_id = %d';
            $params[] = intval($filters['user_id']);
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
            $params[] = intval($filters['object_id']);
        }
        
        if (!empty($filters['date_from'])) {
            $where[] = 'DATE(timestamp) >= %s';
            $params[] = $filters['date_from'];
        }
        
        if (!empty($filters['date_to'])) {
            $where[] = 'DATE(timestamp) <= %s';
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
        
        if (!empty($params)) {
            $query = $wpdb->prepare($query, $params);
        }
        
        $results = $wpdb->get_results($query);
        
        // Decode details
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
        
        return $results;
    }
    
    /**
     * Export logs to CSV
     */
    public static function export_csv($logs, $filters = []) {
        // Generate filename based on filters
        $filename = 'site-logs-export';
        
        if (!empty($filters)) {
            $filter_parts = [];
            if (!empty($filters['severity'])) {
                $filter_parts[] = 'severity-' . $filters['severity'];
            }
            if (!empty($filters['date_from'])) {
                $filter_parts[] = 'from-' . str_replace('-', '', $filters['date_from']);
            }
            if (!empty($filters['date_to'])) {
                $filter_parts[] = 'to-' . str_replace('-', '', $filters['date_to']);
            }
            if (!empty($filter_parts)) {
                $filename .= '-' . implode('-', $filter_parts);
            }
        }
        
        $filename .= '-' . date('Y-m-d-H-i-s') . '.csv';
        
        // Clear any previous output
        if (ob_get_level()) {
            ob_end_clean();
        }
        
        // Set headers for CSV download
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');
        header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
        header('Content-Transfer-Encoding: binary');
        
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
                $details_array = [];
                foreach ($details as $key => $value) {
                    if (is_array($value)) {
                        if (isset($value['old']) && isset($value['new'])) {
                            $details_array[] = $key . ': ' . $value['old'] . ' → ' . $value['new'];
                        } elseif (isset($value['added']) || isset($value['removed'])) {
                            if (!empty($value['added'])) {
                                $added = is_array($value['added']) ? implode(', ', $value['added']) : $value['added'];
                                $details_array[] = $key . ' added: ' . $added;
                            }
                            if (!empty($value['removed'])) {
                                $removed = is_array($value['removed']) ? implode(', ', $value['removed']) : $value['removed'];
                                $details_array[] = $key . ' removed: ' . $removed;
                            }
                        } else {
                            $details_array[] = $key . ': ' . json_encode($value, JSON_UNESCAPED_UNICODE);
                        }
                    } else {
                        // Strip HTML tags from details
                        $clean_value = wp_strip_all_tags($value);
                        $details_array[] = $key . ': ' . $clean_value;
                    }
                }
                $details_text = implode('; ', $details_array);
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
                $details_text
            ]);
        }
        
        fclose($output);
        exit;
    }
    
    /**
     * Export logs to PDF
     */
    public static function export_pdf($logs, $filters = []) {
        // Generate filename based on filters
        $filename = 'site-logs-export';
        
        if (!empty($filters)) {
            $filter_parts = [];
            if (!empty($filters['severity'])) {
                $filter_parts[] = 'severity-' . $filters['severity'];
            }
            if (!empty($filters['date_from'])) {
                $filter_parts[] = 'from-' . str_replace('-', '', $filters['date_from']);
            }
            if (!empty($filters['date_to'])) {
                $filter_parts[] = 'to-' . str_replace('-', '', $filters['date_to']);
            }
            if (!empty($filter_parts)) {
                $filename .= '-' . implode('-', $filter_parts);
            }
        }
        
        $filename .= '-' . date('Y-m-d-H-i-s') . '.pdf';
        
        // Create HTML content for PDF
        $html = self::generate_pdf_html($logs, $filters);
        
        // Try to load DOMPDF
        $dompdf_loaded = false;
        
        // Check multiple possible locations for DOMPDF
        $plugin_dir = plugin_dir_path(__FILE__) . '../';
        $possible_paths = [
            $plugin_dir . 'vendor/autoload.php',
            $plugin_dir . 'vendor/dompdf/dompdf/src/Dompdf.php',
            $plugin_dir . 'vendor/dompdf/dompdf/autoload.inc.php',
        ];
        
        foreach ($possible_paths as $path) {
            if (file_exists($path)) {
                require_once $path;
                $dompdf_loaded = true;
                break;
            }
        }
        
        if (!$dompdf_loaded) {
            // Try to include DOMPDF directly if it's available via WordPress autoload
            if (class_exists('Dompdf\Dompdf')) {
                $dompdf_loaded = true;
            }
        }
        
        if ($dompdf_loaded) {
            try {
                // Clear any previous output
                if (ob_get_level()) {
                    ob_end_clean();
                }
                
                // Create DOMPDF options
                $options = new Dompdf\Options();
                $options->set('isRemoteEnabled', true);
                $options->set('defaultFont', 'DejaVu Sans');
                $options->set('isHtml5ParserEnabled', true);
                $options->set('isPhpEnabled', true);
                $options->set('defaultPaperSize', 'A4');
                $options->set('defaultPaperOrientation', 'landscape');
                
                // Create DOMPDF instance
                $dompdf = new Dompdf\Dompdf($options);
                
                // Load HTML
                $dompdf->loadHtml($html);
                
                // Set paper size and orientation
                $dompdf->setPaper('A4', 'landscape');
                
                // Render PDF
                $dompdf->render();
                
                // Output PDF
                $dompdf->stream($filename, [
                    'Attachment' => true,
                    'compress' => true
                ]);
                
                exit;
            } catch (Exception $e) {
                error_log('Site Logger PDF Export Error: ' . $e->getMessage());
                // Fall back to HTML download
                self::fallback_html_export($html, $filename);
            }
        } else {
            // DOMPDF not available, show helpful message
            wp_die(
                '<h1>DOMPDF Library Not Found</h1>' .
                '<p>To enable PDF export, please install DOMPDF:</p>' .
                '<ol>' .
                '<li>Install Composer if not already installed</li>' .
                '<li>Run this command in your WordPress root directory:</li>' .
                '<pre>composer require dompdf/dompdf</pre>' .
                '<li>Or manually download DOMPDF from: <a href="https://github.com/dompdf/dompdf" target="_blank">github.com/dompdf/dompdf</a></li>' .
                '<li>Place the DOMPDF library in the <code>vendor</code> folder inside the plugin directory</li>' .
                '</ol>' .
                '<p><a href="' . admin_url('admin.php?page=site-logs') . '">Return to logs</a></p>'
            );
        }
    }
    
    /**
     * Fallback HTML export when DOMPDF is not available
     */
    private static function fallback_html_export($html, $filename) {
        $filename = str_replace('.pdf', '.html', $filename);
        
        // Clear any previous output
        if (ob_get_level()) {
            ob_end_clean();
        }
        
        header('Content-Type: text/html; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');
        header('Content-Length: ' . strlen($html));
        
        echo $html;
        exit;
    }
    
    
    /**
 * Generate HTML for PDF export (Simplified version)
 */
private static function generate_pdf_html($logs, $filters = []) {
    $site_name = get_bloginfo('name');
    $current_date = date_i18n('F j, Y H:i:s');
    $total_logs = count($logs);
    
    ob_start();
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Site Activity Logs Export</title>
        <style>
            @page {
                margin: 15mm;
                size: A4 landscape;
            }
            
            body {
                font-family: DejaVu Sans, Arial, Helvetica, sans-serif;
                font-size: 10px;
                line-height: 1.3;
                margin: 0;
                padding: 0;
                color: #333;
            }
            
            .header {
                text-align: center;
                margin-bottom: 15px;
                padding-bottom: 10px;
                border-bottom: 2px solid #2271b1;
            }
            
            .header h1 {
                color: #2271b1;
                margin: 0 0 5px 0;
                font-size: 20px;
            }
            
            .header .meta {
                color: #666;
                font-size: 11px;
                margin: 2px 0;
            }
            
            table {
                width: 100%;
                border-collapse: collapse;
                font-size: 9px;
                word-wrap: break-word;
            }
            
            table th {
                background: #2271b1;
                color: white;
                text-align: left;
                padding: 6px 8px;
                border: 1px solid #1d5f96;
                font-weight: bold;
            }
            
            table td {
                padding: 5px 7px;
                border: 1px solid #ddd;
                vertical-align: top;
            }
            
            table tr:nth-child(even) {
                background: #f9f9f9;
            }
            
            .severity-badge {
                display: inline-block;
                padding: 2px 5px;
                border-radius: 3px;
                font-size: 8px;
                font-weight: bold;
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
            
            .footer {
                text-align: center;
                margin-top: 20px;
                padding-top: 10px;
                border-top: 1px solid #ddd;
                color: #666;
                font-size: 9px;
            }
            
            /* Simplified columns */
            .col-time { width: 12%; }
            .col-severity { width: 10%; }
            .col-user { width: 12%; }
            .col-action { width: 18%; }
            .col-object { width: 18%; }
            .col-details { width: 30%; }
            
            /* Ensure table fits */
            table {
                table-layout: auto;
            }
            
            /* Print styles */
            @media print {
                body {
                    font-size: 9px;
                }
                
                table {
                    font-size: 8px;
                }
                
                table th, table td {
                    padding: 4px 5px;
                }
            }
        </style>
    </head>
    <body>
        <div class="header">
            <h1>Site Activity Logs Export</h1>
            <div class="meta"><?php echo esc_html($site_name); ?></div>
            <div class="meta">Generated: <?php echo esc_html($current_date); ?></div>
            <div class="meta">Total Logs: <?php echo number_format($total_logs); ?></div>
        </div>
        
        <table>
            <thead>
                <tr>
                    <th class="col-time">Time</th>
                    <th class="col-severity">Severity</th>
                    <th class="col-user">User</th>
                    <th class="col-action">Action</th>
                    <th class="col-object">Object</th>
                    <th class="col-details">Details</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($logs)): ?>
                    <tr>
                        <td colspan="6" style="text-align: center; padding: 15px;">
                            No activity logs found.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($logs as $log): ?>
                        <?php
                        $user = $log->user_id ? get_user_by('id', $log->user_id) : null;
                        $username = $user ? $user->display_name : 'System';
                        $time = date_i18n('Y-m-d H:i', strtotime($log->timestamp));
                        $object_text = self::get_object_display_text($log);
                        $details_summary = '';
                        
                        if ($log->details && is_array($log->details)) {
                            $details_array = [];
                            foreach ($log->details as $key => $value) {
                                if (is_array($value)) {
                                    if (isset($value['old']) && isset($value['new'])) {
                                        $details_array[] = $key . ': ' . substr($value['old'], 0, 8) . '→' . substr($value['new'], 0, 8);
                                    }
                                } else {
                                    $details_array[] = $key . ': ' . substr($value, 0, 12);
                                }
                            }
                            $details_summary = implode('; ', array_slice($details_array, 0, 3));
                        }
                        ?>
                        <tr>
                            <td class="col-time"><?php echo esc_html($time); ?></td>
                            <td class="col-severity">
                                <span class="severity-badge severity-<?php echo esc_attr($log->severity); ?>">
                                    <?php echo esc_html(ucfirst($log->severity)); ?>
                                </span>
                            </td>
                            <td class="col-user"><?php echo esc_html($username); ?></td>
                            <td class="col-action"><?php echo esc_html(self::format_action($log->action)); ?></td>
                            <td class="col-object"><?php echo esc_html(substr($object_text, 0, 25)); ?></td>
                            <td class="col-details"><?php echo esc_html(substr($details_summary, 0, 40)); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        
        <div class="footer">
            <p>Generated by Site Logger Plugin v<?php echo esc_html(SITE_LOGGER_VERSION); ?></p>
            <p><?php echo esc_url(get_site_url()); ?></p>
        </div>
    </body>
    </html>
    <?php
    return ob_get_clean();
}

    
}