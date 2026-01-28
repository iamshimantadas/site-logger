<?php
class Site_Logger_Export {
    
    /**
     * Handle export request
     */
    public static function handle_export_request() {
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
     * Get all logs for export (no pagination limit) with raw details
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
        
        return $results;
    }
    
    /**
     * Export logs to CSV with raw details
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
            
            // Get formatted details for CSV
            $details_text = self::format_details_for_csv($log->details);
            
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
     * Format details for CSV
     */
    private static function format_details_for_csv($details) {
        if (empty($details)) {
            return '';
        }
        
        // Decode JSON if it's a string
        if (is_string($details)) {
            $decoded = json_decode($details, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $details = $decoded;
            }
        }
        
        if (!is_array($details) || empty($details)) {
            return '';
        }
        
        $lines = [];
        foreach ($details as $key => $value) {
            if (in_array($key, ['edit_post', 'view_post', 'view_revisions', 'edit_acf_group'])) {
                continue; // Skip links
            }
            
            if (is_array($value)) {
                if (isset($value['old']) && isset($value['new'])) {
                    $lines[] = ucfirst($key) . ': "' . $value['old'] . '" changed to "' . $value['new'] . '"';
                } elseif (isset($value['added'])) {
                    $added = is_array($value['added']) ? implode(', ', $value['added']) : $value['added'];
                    $lines[] = ucfirst($key) . ': Added "' . $added . '"';
                } elseif (isset($value['removed'])) {
                    $removed = is_array($value['removed']) ? implode(', ', $value['removed']) : $value['removed'];
                    $lines[] = ucfirst($key) . ': Removed "' . $removed . '"';
                }
            } else {
                $lines[] = ucfirst($key) . ': ' . $value;
            }
        }
        
        return implode('; ', $lines);
    }
    
    /**
     * Export logs to PDF - Simple and complete
     */
    public static function export_pdf($logs, $filters = []) {
        // Generate filename
        $filename = 'site-logs-export-' . date('Y-m-d-H-i-s') . '.pdf';
        
        // Create HTML content for PDF
        $html = self::generate_pdf_html($logs, $filters);
        
        // Try to load DOMPDF
        $dompdf_loaded = false;
        
        // Check for DOMPDF
        $possible_paths = [
            plugin_dir_path(__FILE__) . '../vendor/autoload.php',
            plugin_dir_path(__FILE__) . '../vendor/dompdf/dompdf/autoload.inc.php',
            plugin_dir_path(__FILE__) . '../../dompdf/autoload.inc.php',
        ];
        
        foreach ($possible_paths as $path) {
            if (file_exists($path)) {
                require_once $path;
                $dompdf_loaded = true;
                break;
            }
        }
        
        if (!$dompdf_loaded && class_exists('Dompdf\Dompdf')) {
            $dompdf_loaded = true;
        }
        
        if ($dompdf_loaded) {
            try {
                // Clear output
                if (ob_get_level()) {
                    ob_end_clean();
                }
                
                // Create DOMPDF with simple options
                $options = new Dompdf\Options();
                $options->set('isRemoteEnabled', false);
                $options->set('defaultFont', 'Helvetica');
                $options->set('isHtml5ParserEnabled', true);
                $options->set('isPhpEnabled', true);
                $options->set('defaultPaperSize', 'A4');
                $options->set('defaultPaperOrientation', 'landscape');
                
                $dompdf = new Dompdf\Dompdf($options);
                $dompdf->loadHtml($html);
                $dompdf->setPaper('A4', 'landscape');
                $dompdf->render();
                
                $dompdf->stream($filename, [
                    'Attachment' => true,
                    'compress' => false
                ]);
                
                exit;
            } catch (Exception $e) {
                error_log('PDF Export Error: ' . $e->getMessage());
                self::fallback_html_export($html, str_replace('.pdf', '.html', $filename));
            }
        } else {
            // DOMPDF not available, fall back to HTML
            self::fallback_html_export($html, str_replace('.pdf', '.html', $filename));
        }
    }
    
    /**
     * Fallback HTML export when DOMPDF is not available
     */
    private static function fallback_html_export($html, $filename) {
        if (ob_get_level()) {
            ob_end_clean();
        }
        
        header('Content-Type: text/html; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');
        
        echo $html;
        exit;
    }
    
    /**
     * Generate HTML for PDF export - Simple and complete
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
            <title>Activity Log Report</title>
            <style>
                /* Very simple CSS for page fitting */
                body {
                    font-family: Arial, sans-serif;
                    font-size: 9px;
                    margin: 0;
                    padding: 10px;
                    color: #000;
                }
                h1 {
                    font-size: 14px;
                    margin: 5px 0;
                    color: #333;
                }
                .info {
                    font-size: 8px;
                    color: #666;
                    margin: 3px 0;
                }
                table {
                    width: 100%;
                    border-collapse: collapse;
                    margin-top: 10px;
                    font-size: 8px;
                    table-layout: fixed;
                }
                th {
                    background-color: #f2f2f2;
                    border: 1px solid #ddd;
                    padding: 6px;
                    text-align: left;
                    font-weight: bold;
                }
                td {
                    border: 1px solid #ddd;
                    padding: 5px;
                    vertical-align: top;
                    word-wrap: break-word;
                }
                tr:nth-child(even) {
                    background-color: #f9f9f9;
                }
                .time {
                    white-space: nowrap;
                    width: 12%;
                }
                .severity {
                    width: 8%;
                    font-weight: bold;
                }
                .severity-info { color: #0066cc; }
                .severity-warning { color: #cc6600; }
                .severity-error { color: #cc0000; }
                .severity-critical { color: #990000; }
                .user {
                    width: 12%;
                }
                .action {
                    width: 15%;
                }
                .object {
                    width: 15%;
                }
                .details {
                    width: 38%;
                    font-size: 8px;
                    line-height: 1.2;
                }
                .detail-line {
                    margin: 2px 0;
                    padding: 1px 0;
                }
                .detail-label {
                    font-weight: bold;
                    color: #333;
                }
                .old-value {
                    color: #cc0000;
                    text-decoration: line-through;
                }
                .new-value {
                    color: #006600;
                    font-weight: bold;
                }
                .arrow {
                    color: #666;
                    margin: 0 5px;
                }
                .footer {
                    text-align: center;
                    margin-top: 15px;
                    font-size: 8px;
                    color: #999;
                }
                @media print {
                    body {
                        font-size: 8px;
                    }
                    table {
                        font-size: 7px;
                    }
                }
            </style>
        </head>
        <body>
            <h1>Activity Log Report</h1>
            <div class="info">Site: <?php echo esc_html($site_name); ?></div>
            <div class="info">Generated: <?php echo esc_html($current_date); ?></div>
            <div class="info">Total Records: <?php echo number_format($total_logs); ?></div>
            
            <?php if (!empty($filters)): ?>
                <div class="info" style="background: #f5f5f5; padding: 5px; margin: 5px 0;">
                    <strong>Filters:</strong> 
                    <?php 
                    $filter_items = [];
                    foreach ($filters as $key => $value) {
                        if (!empty($value)) {
                            $filter_items[] = ucfirst($key) . ': ' . $value;
                        }
                    }
                    echo implode(' | ', $filter_items);
                    ?>
                </div>
            <?php endif; ?>
            
            <table>
                <thead>
                    <tr>
                        <th class="time">Time</th>
                        <th class="severity">Level</th>
                        <th class="user">User</th>
                        <th class="action">Action</th>
                        <th class="object">Object</th>
                        <th class="details">Details</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($logs)): ?>
                        <tr>
                            <td colspan="6" style="text-align: center; padding: 15px; color: #999;">
                                No activity logs found
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($logs as $log): ?>
                            <?php
                            $user = $log->user_id ? get_user_by('id', $log->user_id) : null;
                            $username = $user ? $user->display_name : 'System';
                            $time = date_i18n('M j, H:i', strtotime($log->timestamp));
                            $time_full = date_i18n('Y-m-d H:i:s', strtotime($log->timestamp));
                            $object_text = self::get_object_display_text($log);
                            ?>
                            <tr>
                                <td class="time" title="<?php echo esc_attr($time_full); ?>">
                                    <?php echo esc_html($time); ?>
                                </td>
                                <td class="severity severity-<?php echo esc_attr($log->severity); ?>">
                                    <?php echo esc_html(ucfirst($log->severity)); ?>
                                </td>
                                <td class="user">
                                    <div><?php echo esc_html($username); ?></div>
                                    <?php if ($log->user_id): ?>
                                        <small style="color: #666;">ID: <?php echo $log->user_id; ?></small>
                                    <?php endif; ?>
                                </td>
                                <td class="action"><?php echo esc_html(self::format_action($log->action)); ?></td>
                                <td class="object"><?php echo esc_html($object_text); ?></td>
                                <td class="details">
                                    <?php echo self::format_detailed_info_for_pdf($log); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            
            <div class="footer">
                Page <?php echo '{PAGENO}'; ?> of <?php echo '{nbpg}'; ?> | Generated by Site Logger Plugin
            </div>
        </body>
        </html>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Format detailed information for PDF - Show more info
     */
    private static function format_detailed_info_for_pdf($log) {
        $details = $log->details;
        
        if (empty($details)) {
            return '<span style="color: #999; font-style: italic;">No details available</span>';
        }

        // Decode JSON if it's a string
        if (is_string($details)) {
            $decoded = json_decode($details, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $details = $decoded;
            }
        }

        if (!is_array($details) || empty($details)) {
            return '<span style="color: #999; font-style: italic;">No details available</span>';
        }
        
        $output = '';
        $count = 0;
        
        // Remove unimportant keys
        $skip_keys = ['edit_post', 'view_post', 'view_revisions', 'edit_acf_group', 'visit_user'];
        
        foreach ($details as $key => $value) {
            if (in_array($key, $skip_keys)) {
                continue;
            }
            
            $clean_key = ucwords(str_replace('_', ' ', $key));
            
            $output .= '<div class="detail-line">';
            $output .= '<span class="detail-label">' . esc_html($clean_key) . ':</span> ';
            
            if (is_array($value)) {
                if (isset($value['old']) && isset($value['new'])) {
                    // Show complete old and new values
                    $output .= '<span class="old-value">' . esc_html($value['old']) . '</span>';
                    $output .= '<span class="arrow"> → </span>';
                    $output .= '<span class="new-value">' . esc_html($value['new']) . '</span>';
                } elseif (isset($value['added']) && !empty($value['added'])) {
                    $added = is_array($value['added']) ? implode(', ', $value['added']) : $value['added'];
                    $output .= '<span style="color: #006600;">Added: ' . esc_html($added) . '</span>';
                } elseif (isset($value['removed']) && !empty($value['removed'])) {
                    $removed = is_array($value['removed']) ? implode(', ', $value['removed']) : $value['removed'];
                    $output .= '<span style="color: #cc0000;">Removed: ' . esc_html($removed) . '</span>';
                } elseif (isset($value['characters_changed'])) {
                    // Content changes
                    $ch = $value['characters_changed'];
                    $is_plus = strpos($ch, '+') === 0;
                    $num = abs((int) $ch);
                    $output .= '<span style="color: ' . ($is_plus ? '#006600' : '#cc0000') . '; font-weight: bold;">';
                    $output .= ($is_plus ? '+' : '-') . $num . ' characters';
                    if (isset($value['old_length']) && isset($value['new_length'])) {
                        $output .= ' (' . $value['old_length'] . ' → ' . $value['new_length'] . ')';
                    }
                    $output .= '</span>';
                    
                    // Show word changes if available
                    if (isset($value['word_changes']['added_words']['count'])) {
                        $aw = $value['word_changes']['added_words'];
                        $output .= '<br><span style="color: #006600; font-size: 7px;">';
                        $output .= 'New words: ' . $aw['count'];
                        if (!empty($aw['sample'])) {
                            $output .= ' (e.g., "' . esc_html(substr($aw['sample'], 0, 50)) . '")';
                        }
                        $output .= '</span>';
                    }
                } else {
                    // Show array as JSON
                    $output .= esc_html(json_encode($value, JSON_UNESCAPED_UNICODE));
                }
            } else {
                // Show simple value (full text, not truncated)
                $output .= esc_html($value);
            }
            
            $output .= '</div>';
            $count++;
            
            // Limit to 5 detail lines to avoid too much height
            if ($count >= 5) {
                if (count($details) > 5) {
                    $output .= '<div style="color: #666; font-size: 7px;">... and ' . (count($details) - 5) . ' more changes</div>';
                }
                break;
            }
        }
        
        // Add IP address if available
        if (!empty($log->user_ip)) {
            $output .= '<div class="detail-line" style="margin-top: 3px; padding-top: 3px; border-top: 1px dashed #ddd;">';
            $output .= '<span class="detail-label">IP Address:</span> ';
            $output .= esc_html($log->user_ip);
            $output .= '</div>';
        }
        
        return $output;
    }
    
} // End of class