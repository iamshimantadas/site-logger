<?php
class Site_Logger_Hooks {
    
    /**
     * Store old post data for comparison
     */
    private static $old_post_data = [];
    
    /**
     * Store old meta data for comparison
     */
    private static $old_meta_data = [];
    
    /**
     * Store old option values
     */
    private static $old_option_values = [];
    
    /**
     * Initialize hooks
     */
    public static function init() {
        $instance = new self();
        $instance->setup_hooks();
    }
    
    /**
     * Setup WordPress hooks
     */
    private function setup_hooks() {
        // Store old post data before update
        add_action('wp_insert_post_data', [$this, 'store_old_post_data'], 10, 2);
        
        // Store old meta data before update
        add_action('save_post', [$this, 'store_old_meta_data'], 5, 2);
        
        // Posts and Pages
        add_action('save_post', [$this, 'log_post_save'], 20, 3);
        add_action('delete_post', [$this, 'log_post_delete'], 10, 2);
        add_action('trash_post', [$this, 'log_post_trash'], 10, 2);
        add_action('untrash_post', [$this, 'log_post_untrash'], 10, 2);
        
        // Post revisions
        add_action('_wp_put_post_revision', [$this, 'log_post_revision'], 10, 1);
        
        // Users
        add_action('user_register', [$this, 'log_user_register']);
        add_action('profile_update', [$this, 'log_profile_update'], 10, 2);
        add_action('wp_login', [$this, 'log_user_login'], 10, 2);
        add_action('wp_logout', [$this, 'log_user_logout']);
        
        // Plugins and Themes
        add_action('activated_plugin', [$this, 'log_plugin_activation'], 10, 2);
        add_action('deactivated_plugin', [$this, 'log_plugin_deactivation'], 10, 2);
        add_action('switch_theme', [$this, 'log_theme_switch'], 10, 3);
        
        // Store old option value before update
        add_filter('pre_update_option', [$this, 'store_old_option_value'], 10, 2);
        // Log option update
        add_action('updated_option', [$this, 'log_option_update'], 10, 3);
        
        // Comments
        add_action('comment_post', [$this, 'log_comment_post'], 10, 3);
        add_action('edit_comment', [$this, 'log_comment_edit'], 10, 2);
        add_action('delete_comment', [$this, 'log_comment_delete'], 10, 2);
        
        // Media
        add_action('add_attachment', [$this, 'log_media_add']);
        add_action('edit_attachment', [$this, 'log_media_edit'], 10, 2);
        add_action('delete_attachment', [$this, 'log_media_delete'], 10, 2);
        
        // Taxonomy terms
        add_action('created_term', [$this, 'log_term_created'], 10, 3);
        add_action('edited_term', [$this, 'log_term_updated'], 10, 3);
        add_action('delete_term', [$this, 'log_term_deleted'], 10, 4);
        
        // Widgets
        add_action('updated_option', [$this, 'log_widget_update'], 10, 3);
        
        // ACF specific hooks
        add_action('acf/save_post', [$this, 'log_acf_save'], 20, 1);
        
        // Admin menu
        add_action('admin_menu', ['Site_Logger', 'add_admin_menu']);
    }
    
    /**
     * Store old post data before update
     */
    public function store_old_post_data($data, $postarr) {
        if (!empty($postarr['ID'])) {
            $post_id = $postarr['ID'];
            $old_post = get_post($post_id);
            if ($old_post) {
                self::$old_post_data[$post_id] = [
                    'title' => $old_post->post_title,
                    'content' => $old_post->post_content,
                    'excerpt' => $old_post->post_excerpt,
                    'status' => $old_post->post_status,
                    'author' => $old_post->post_author,
                    'comment_status' => $old_post->comment_status,
                    'ping_status' => $old_post->ping_status,
                    'parent' => $old_post->post_parent,
                    'menu_order' => $old_post->menu_order,
                    'post_date' => $old_post->post_date,
                ];
            }
        }
        return $data;
    }
    
    /**
     * Store old meta data before update
     */
    public function store_old_meta_data($post_id, $post) {
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }
        
        // Store all meta for comparison
        $all_meta = get_post_meta($post_id);
        self::$old_meta_data[$post_id] = $all_meta;
    }
    
    /**
     * Store old option value
     */
    public function store_old_option_value($new_value, $option_name) {
        if (!isset(self::$old_option_values[$option_name])) {
            self::$old_option_values[$option_name] = get_option($option_name);
        }
        return $new_value;
    }
    
    /**
     * Log post save with detailed changes
     */
    public function log_post_save($post_id, $post, $update) {
        // Skip revisions, autosaves, and auto-drafts
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id) || $post->post_status === 'auto-draft') {
            return;
        }
        
        // Skip if post is being deleted
        if ($post->post_status === 'trash') {
            return;
        }
        
        $action = $update ? 'post_updated' : 'post_created';
        $severity = 'info';
        $details = [];
        
        if ($update && isset(self::$old_post_data[$post_id])) {
            $old_data = self::$old_post_data[$post_id];
            $has_changes = false;
            
            // Check title change
            if ($old_data['title'] !== $post->post_title) {
                $details['title'] = [
                    'old' => $old_data['title'],
                    'new' => $post->post_title
                ];
                $has_changes = true;
            }
            
            // Check content change
            if ($old_data['content'] !== $post->post_content) {
                $old_length = strlen($old_data['content']);
                $new_length = strlen($post->post_content);
                $details['content'] = "Content updated ({$old_length} → {$new_length} characters)";
                $has_changes = true;
            }
            
            // Check excerpt change
            if ($old_data['excerpt'] !== $post->post_excerpt) {
                $old_excerpt = $old_data['excerpt'] ?: '(empty)';
                $new_excerpt = $post->post_excerpt ?: '(empty)';
                $details['excerpt'] = "Excerpt changed";
                $has_changes = true;
            }
            
            // Check status change
            if ($old_data['status'] !== $post->post_status) {
                $details['status'] = [
                    'old' => $this->get_post_status_label($old_data['status']),
                    'new' => $this->get_post_status_label($post->post_status)
                ];
                $has_changes = true;
                
                // Increase severity for important status changes
                if ($post->post_status === 'private') {
                    $severity = 'warning';
                }
            }
            
            // Check author change
            if ($old_data['author'] != $post->post_author) {
                $old_author = get_user_by('id', $old_data['author']);
                $new_author = get_user_by('id', $post->post_author);
                $details['author'] = [
                    'old' => $old_author ? $old_author->display_name : 'User #' . $old_data['author'],
                    'new' => $new_author ? $new_author->display_name : 'User #' . $post->post_author
                ];
                $has_changes = true;
            }
            
            // Check comment status
            if ($old_data['comment_status'] !== $post->comment_status) {
                $details['comments'] = [
                    'old' => $old_data['comment_status'] === 'open' ? 'Open' : 'Closed',
                    'new' => $post->comment_status === 'open' ? 'Open' : 'Closed'
                ];
                $has_changes = true;
            }
            
            // Check ping status
            if ($old_data['ping_status'] !== $post->ping_status) {
                $details['pings'] = [
                    'old' => $old_data['ping_status'] === 'open' ? 'Open' : 'Closed',
                    'new' => $post->ping_status === 'open' ? 'Open' : 'Closed'
                ];
                $has_changes = true;
            }
            
            // Check featured image
            $this->check_featured_image($post_id, $details, $has_changes);
            
            // Check taxonomy changes
            $this->check_taxonomy_changes($post_id, $details, $has_changes);
            
            // Check custom fields (including ACF)
            $this->check_custom_fields($post_id, $details, $has_changes);
            
            // Check if it's a minor update (just saving)
            if (!$has_changes) {
                $details['note'] = 'Post saved (no changes detected)';
            }
            
            // Clean up old data
            unset(self::$old_post_data[$post_id]);
            unset(self::$old_meta_data[$post_id]);
            
        } else {
            // New post
            $details['action'] = 'New post created';
            $details['status'] = $this->get_post_status_label($post->post_status);
            $details['post_type'] = $post->post_type;
            
            // Check if it has featured image
            if (isset($_POST['_thumbnail_id']) && $_POST['_thumbnail_id'] > 0) {
                $image_id = intval($_POST['_thumbnail_id']);
                $details['featured_image'] = 'Set featured image (ID: ' . $image_id . ')';
            }
            
            // Check categories/tags on creation
            $this->check_taxonomy_changes($post_id, $details, true);
        }
        
        Site_Logger::log(
            $action,
            $post->post_type,
            $post_id,
            $post->post_title,
            $details,
            $severity
        );
    }
    
    /**
     * Get human-readable post status label
     */
    private function get_post_status_label($status) {
        $labels = [
            'publish' => 'Published',
            'draft' => 'Draft',
            'pending' => 'Pending Review',
            'private' => 'Private',
            'trash' => 'Trashed',
            'auto-draft' => 'Auto Draft',
            'inherit' => 'Inherit',
            'future' => 'Scheduled'
        ];
        
        return $labels[$status] ?? ucfirst($status);
    }
    
    /**
     * Check featured image changes
     */
    private function check_featured_image($post_id, &$details, &$has_changes) {
        // Get current thumbnail
        $old_thumbnail = get_post_thumbnail_id($post_id);
        
        // Check if thumbnail was in POST data
        if (isset($_POST['_thumbnail_id'])) {
            $new_thumbnail = intval($_POST['_thumbnail_id']);
            
            if ($old_thumbnail != $new_thumbnail) {
                if ($new_thumbnail == -1 || $new_thumbnail === 0) {
                    // Removed
                    if ($old_thumbnail) {
                        $details['featured_image'] = 'Removed featured image';
                        $has_changes = true;
                    }
                } elseif (empty($old_thumbnail)) {
                    // Added
                    $new_image = get_post($new_thumbnail);
                    $image_name = $new_image ? $new_image->post_title : 'ID ' . $new_thumbnail;
                    $details['featured_image'] = 'Added featured image: ' . $image_name;
                    $has_changes = true;
                } else {
                    // Changed
                    $old_image = get_post($old_thumbnail);
                    $new_image = get_post($new_thumbnail);
                    $old_name = $old_image ? $old_image->post_title : 'ID ' . $old_thumbnail;
                    $new_name = $new_image ? $new_image->post_title : 'ID ' . $new_thumbnail;
                    $details['featured_image'] = [
                        'old' => $old_name,
                        'new' => $new_name
                    ];
                    $has_changes = true;
                }
            }
        }
    }
    
    /**
     * Check taxonomy changes
     */
    private function check_taxonomy_changes($post_id, &$details, &$has_changes, $is_new = false) {
        // Get all taxonomies for this post type
        $taxonomies = get_object_taxonomies(get_post_type($post_id), 'names');
        
        foreach ($taxonomies as $taxonomy) {
            $old_terms = wp_get_post_terms($post_id, $taxonomy, ['fields' => 'names']);
            
            // For updates, check if terms were in POST data
            if (!$is_new && !isset($_POST['tax_input'][$taxonomy])) {
                continue;
            }
            
            if ($is_new) {
                // For new posts
                if (isset($_POST['tax_input'][$taxonomy]) && !empty($_POST['tax_input'][$taxonomy])) {
                    $new_terms = is_array($_POST['tax_input'][$taxonomy]) 
                        ? $_POST['tax_input'][$taxonomy] 
                        : explode(',', $_POST['tax_input'][$taxonomy]);
                    
                    $term_names = [];
                    foreach ($new_terms as $term) {
                        if (is_numeric($term)) {
                            $term_obj = get_term($term, $taxonomy);
                            if ($term_obj) {
                                $term_names[] = $term_obj->name;
                            }
                        } else {
                            $term_names[] = $term;
                        }
                    }
                    
                    if (!empty($term_names)) {
                        $tax_name = get_taxonomy($taxonomy)->labels->name;
                        $details['taxonomy'][$tax_name] = 'Set to: ' . implode(', ', $term_names);
                        $has_changes = true;
                    }
                }
            } else {
                // For updates
                $new_terms = [];
                if (isset($_POST['tax_input'][$taxonomy])) {
                    $new_terms = is_array($_POST['tax_input'][$taxonomy]) 
                        ? $_POST['tax_input'][$taxonomy] 
                        : explode(',', $_POST['tax_input'][$taxonomy]);
                }
                
                // Clean term names
                $old_term_names = array_map('trim', $old_terms);
                $new_term_names = [];
                
                foreach ($new_terms as $term) {
                    if (is_numeric($term)) {
                        $term_obj = get_term($term, $taxonomy);
                        if ($term_obj) {
                            $new_term_names[] = $term_obj->name;
                        }
                    } elseif (!empty($term)) {
                        $new_term_names[] = trim($term);
                    }
                }
                
                sort($old_term_names);
                sort($new_term_names);
                
                if ($old_term_names != $new_term_names) {
                    $tax_name = get_taxonomy($taxonomy)->labels->name;
                    $details['taxonomy'][$tax_name] = [
                        'old' => empty($old_term_names) ? '(none)' : implode(', ', $old_term_names),
                        'new' => empty($new_term_names) ? '(none)' : implode(', ', $new_term_names)
                    ];
                    $has_changes = true;
                }
            }
        }
    }
    
    /**
     * Check custom fields (including ACF)
     */
    private function check_custom_fields($post_id, &$details, &$has_changes) {
        $field_changes = [];
        
        // Check ACF fields if available
        if (function_exists('get_field_objects') && isset(self::$old_meta_data[$post_id])) {
            $current_fields = get_field_objects($post_id);
            $old_meta = self::$old_meta_data[$post_id];
            
            if ($current_fields) {
                foreach ($current_fields as $field_name => $field) {
                    $field_key = $field['key'];
                    $field_label = $field['label'] ?: $field_name;
                    
                    // Get current value
                    $current_value = $field['value'];
                    
                    // Get old value from stored meta
                    $old_value = null;
                    if (isset($old_meta[$field_name])) {
                        $old_value = maybe_unserialize($old_meta[$field_name][0] ?? null);
                    } elseif (isset($old_meta['_' . $field_name])) {
                        // Check for ACF field key
                        $acf_key = $old_meta['_' . $field_name][0] ?? '';
                        if ($acf_key && isset($old_meta[$acf_key])) {
                            $old_value = maybe_unserialize($old_meta[$acf_key][0] ?? null);
                        }
                    }
                    
                    // Compare values
                    if ($this->values_differ($old_value, $current_value)) {
                        $field_changes[] = $field_label;
                    }
                }
            }
        }
        
        // Check standard custom fields
        if (isset(self::$old_meta_data[$post_id])) {
            $old_meta = self::$old_meta_data[$post_id];
            $current_meta = get_post_meta($post_id);
            
            // Skip ACF fields and internal fields
            $skip_fields = ['_edit_lock', '_edit_last', '_thumbnail_id', '_wp_old_slug', '_wp_page_template'];
            
            foreach ($old_meta as $meta_key => $old_values) {
                if (strpos($meta_key, '_') === 0) {
                    continue; // Skip hidden fields
                }
                
                if (in_array($meta_key, $skip_fields)) {
                    continue;
                }
                
                $current_values = $current_meta[$meta_key] ?? [];
                $old_value = maybe_unserialize($old_values[0] ?? null);
                $current_value = maybe_unserialize($current_values[0] ?? null);
                
                if ($this->values_differ($old_value, $current_value)) {
                    $field_changes[] = $meta_key;
                }
            }
        }
        
        // Add field changes to details
        if (!empty($field_changes)) {
            $details['custom_fields'] = 'Updated: ' . implode(', ', array_unique($field_changes));
            $has_changes = true;
        }
    }
    
    /**
     * Compare values for differences
     */
    private function values_differ($old_value, $new_value) {
        if (is_array($old_value) && is_array($new_value)) {
            return serialize($old_value) !== serialize($new_value);
        }
        
        if (is_object($old_value) && is_object($new_value)) {
            return $old_value != $new_value;
        }
        
        return $old_value !== $new_value;
    }
    
    /**
     * Log post revision
     */
    public function log_post_revision($revision_id) {
        $revision = get_post($revision_id);
        $parent_post = get_post($revision->post_parent);
        
        if ($parent_post) {
            $revision_url = admin_url("revision.php?revision={$revision_id}");
            
            $details = [
                'revision' => "Revision #{$revision_id} created",
                'parent_post' => $parent_post->post_title,
                'view_revisions' => "<a href='" . admin_url("revision.php?post={$parent_post->ID}") . "' target='_blank'>View all revisions</a>"
            ];
            
            Site_Logger::log(
                'revision_created',
                'revision',
                $revision_id,
                "Revision for: " . $parent_post->post_title,
                $details,
                'info'
            );
        }
    }
    
    /**
     * Log ACF save
     */
    public function log_acf_save($post_id) {
        // This will be handled by check_custom_fields method
        // This hook ensures ACF fields are processed
        return;
    }
    
    /**
     * Log post delete
     */
    public function log_post_delete($post_id, $post) {
        $details = [
            'post_type' => $post->post_type,
            'status' => $this->get_post_status_label($post->post_status)
        ];
        
        Site_Logger::log(
            'post_deleted',
            $post->post_type,
            $post_id,
            $post->post_title,
            $details,
            'warning'
        );
    }
    
    /**
     * Log post trash
     */
    public function log_post_trash($post_id, $post) {
        Site_Logger::log(
            'post_trashed',
            $post->post_type,
            $post_id,
            $post->post_title,
            ['status' => $this->get_post_status_label($post->post_status)],
            'warning'
        );
    }
    
    /**
     * Log post untrash
     */
    public function log_post_untrash($post_id, $post) {
        Site_Logger::log(
            'post_untrashed',
            $post->post_type,
            $post_id,
            $post->post_title,
            ['status' => $this->get_post_status_label($post->post_status)],
            'notice'
        );
    }
    
    /**
     * Log user registration
     */
    public function log_user_register($user_id) {
        $user = get_user_by('id', $user_id);
        
        $details = [
            'email' => $user->user_email,
            'role' => $this->get_role_label($user->roles[0] ?? 'subscriber')
        ];
        
        Site_Logger::log(
            'user_registered',
            'user',
            $user_id,
            $user->user_login,
            $details,
            'info'
        );
    }
    
    /**
     * Get human-readable role label
     */
    private function get_role_label($role) {
        $wp_roles = wp_roles();
        return $wp_roles->roles[$role]['name'] ?? ucfirst($role);
    }
    
    /**
     * Log profile update
     */
    public function log_profile_update($user_id, $old_user_data) {
        $user = get_user_by('id', $user_id);
        $changes = [];
        
        // Get new user data
        $new_user_data = get_userdata($user_id);
        
        // Check email change
        if ($old_user_data->user_email !== $new_user_data->user_email) {
            $changes['email'] = [
                'old' => $old_user_data->user_email,
                'new' => $new_user_data->user_email
            ];
        }
        
        // Check display name change
        if ($old_user_data->display_name !== $new_user_data->display_name) {
            $changes['display_name'] = [
                'old' => $old_user_data->display_name,
                'new' => $new_user_data->display_name
            ];
        }
        
        // Check role change
        $old_roles = $old_user_data->roles;
        $new_roles = $new_user_data->roles;
        
        if ($old_roles != $new_roles) {
            $old_role_labels = array_map([$this, 'get_role_label'], $old_roles);
            $new_role_labels = array_map([$this, 'get_role_label'], $new_roles);
            $changes['role'] = [
                'old' => implode(', ', $old_role_labels),
                'new' => implode(', ', $new_role_labels)
            ];
        }
        
        Site_Logger::log(
            'user_updated',
            'user',
            $user_id,
            $user->user_login,
            $changes,
            'info'
        );
    }
    
    /**
     * Log user login
     */
    public function log_user_login($user_login, $user) {
        // Get user's last login time
        $last_login = get_user_meta($user->ID, 'last_login', true);
        $current_time = current_time('mysql');
        update_user_meta($user->ID, 'last_login', $current_time);
        
        $details = [
            'role' => $this->get_role_label($user->roles[0] ?? 'none'),
            'last_login' => $last_login ? human_time_diff(strtotime($last_login)) . ' ago' : 'First login'
        ];
        
        // Check if it's an admin login
        if (in_array('administrator', $user->roles)) {
            $details['note'] = '👑 Administrator login';
        }
        
        Site_Logger::log(
            'user_login',
            'user',
            $user->ID,
            $user->user_login,
            $details,
            'info'
        );
    }
    
    /**
     * Log user logout
     */
    public function log_user_logout() {
        $user = wp_get_current_user();
        if ($user->ID) {
            Site_Logger::log(
                'user_logout',
                'user',
                $user->ID,
                $user->user_login,
                ['session_ended' => 'User logged out'],
                'info'
            );
        }
    }
    
    /**
     * Log plugin activation
     */
    public function log_plugin_activation($plugin, $network_wide) {
        $plugin_name = $this->get_plugin_name($plugin);
        $plugin_data = get_plugin_data(WP_PLUGIN_DIR . '/' . $plugin);
        
        $details = [
            'plugin' => $plugin_name,
            'version' => $plugin_data['Version'] ?? 'N/A',
            'author' => $plugin_data['Author'] ?? 'N/A',
            'network_wide' => $network_wide ? 'Yes (network)' : 'No (single site)'
        ];
        
        Site_Logger::log(
            'plugin_activated',
            'plugin',
            0,
            $plugin_name,
            $details,
            'warning'
        );
    }
    
    /**
     * Log plugin deactivation
     */
    public function log_plugin_deactivation($plugin, $network_wide) {
        $plugin_name = $this->get_plugin_name($plugin);
        
        $details = [
            'plugin' => $plugin_name,
            'network_wide' => $network_wide ? 'Yes (network)' : 'No (single site)'
        ];
        
        Site_Logger::log(
            'plugin_deactivated',
            'plugin',
            0,
            $plugin_name,
            $details,
            'warning'
        );
    }
    
    /**
     * Log theme switch
     */
    public function log_theme_switch($new_name, $new_theme, $old_theme) {
        $details = [
            'new_theme' => $new_name,
            'old_theme' => $old_theme ? $old_theme->name : 'None',
            'version' => $new_theme->get('Version') ?? 'N/A'
        ];
        
        Site_Logger::log(
            'theme_switched',
            'theme',
            0,
            $new_name,
            $details,
            'warning'
        );
    }
    
    /**
     * Log option update
     */
    public function log_option_update($option_name, $old_value, $value) {
        // Skip if we should skip this option
        if (Site_Logger::should_skip_option($option_name)) {
            return;
        }
        
        // Skip sensitive options
        $sensitive_options = ['admin_email', 'auth_key', 'logged_in_key', 'secret', 'user_pass'];
        foreach ($sensitive_options as $sensitive) {
            if (strpos($option_name, $sensitive) !== false) {
                return;
            }
        }
        
        // Get stored old value (more accurate)
        if (isset(self::$old_option_values[$option_name])) {
            $old_value = self::$old_option_values[$option_name];
            unset(self::$old_option_values[$option_name]);
        }
        
        // Skip if values are the same
        if ($old_value === $value) {
            return;
        }
        
        // Determine severity
        $severity = 'info';
        $important_options = [
            'siteurl', 'home', 'blogname', 'blogdescription', 
            'users_can_register', 'default_role', 'permalink_structure',
            'WPLANG', 'timezone_string'
        ];
        
        if (in_array($option_name, $important_options)) {
            $severity = 'warning';
        }
        
        // Get human-readable changes
        $changes = $this->get_option_changes($option_name, $old_value, $value);
        
        // Skip if no real change
        if (empty($changes)) {
            return;
        }
        
        Site_Logger::log(
            'option_updated',
            'option',
            0,
            $this->get_option_label($option_name),
            $changes,
            $severity
        );
    }
    
    /**
     * Get option changes in human-readable format
     */
    private function get_option_changes($option_name, $old_value, $new_value) {
        $changes = [];
        
        // Handle specific options with better formatting
        switch ($option_name) {
            case 'blogname':
                $changes['site_title'] = [
                    'old' => $old_value,
                    'new' => $new_value
                ];
                break;
                
            case 'blogdescription':
                $changes['tagline'] = [
                    'old' => $old_value,
                    'new' => $new_value
                ];
                break;
                
            case 'siteurl':
                $changes['wordpress_address'] = [
                    'old' => $old_value,
                    'new' => $new_value
                ];
                break;
                
            case 'home':
                $changes['site_address'] = [
                    'old' => $old_value,
                    'new' => $new_value
                ];
                break;
                
            case 'timezone_string':
                $changes['timezone'] = [
                    'old' => $old_value ?: 'UTC',
                    'new' => $new_value ?: 'UTC'
                ];
                break;
                
            case 'date_format':
                $changes['date_format'] = [
                    'old' => $old_value,
                    'new' => $new_value
                ];
                break;
                
            case 'time_format':
                $changes['time_format'] = [
                    'old' => $old_value,
                    'new' => $new_value
                ];
                break;
                
            case 'permalink_structure':
                $old_label = empty($old_value) ? 'Default (Plain)' : $old_value;
                $new_label = empty($new_value) ? 'Default (Plain)' : $new_value;
                $changes['permalink_structure'] = [
                    'old' => $old_label,
                    'new' => $new_label
                ];
                break;
                
            case 'users_can_register':
                $changes['user_registration'] = [
                    'old' => $old_value ? 'Enabled' : 'Disabled',
                    'new' => $new_value ? 'Enabled' : 'Disabled'
                ];
                break;
                
            case 'default_role':
                $changes['default_user_role'] = [
                    'old' => $this->get_role_label($old_value),
                    'new' => $this->get_role_label($new_value)
                ];
                break;
                
            case 'WPLANG':
                $changes['site_language'] = [
                    'old' => $old_value ?: 'English',
                    'new' => $new_value ?: 'English'
                ];
                break;
                
            default:
                // For other options
                if (is_scalar($old_value) && is_scalar($new_value)) {
                    // Simple scalar values
                    $old_str = (string)$old_value;
                    $new_str = (string)$new_value;
                    
                    if (strlen($old_str) > 50 || strlen($new_str) > 50) {
                        $changes['value'] = 'Value changed (long text)';
                    } else {
                        $changes['value'] = [
                            'old' => $old_str ?: '(empty)',
                            'new' => $new_str ?: '(empty)'
                        ];
                    }
                } else {
                    $changes['value'] = 'Complex value changed';
                }
        }
        
        return $changes;
    }
    
    /**
     * Get option label
     */
    private function get_option_label($option_name) {
        $labels = [
            'blogname' => 'Site Title',
            'blogdescription' => 'Tagline',
            'siteurl' => 'WordPress Address',
            'home' => 'Site Address',
            'admin_email' => 'Admin Email',
            'users_can_register' => 'Membership',
            'default_role' => 'New User Default Role',
            'timezone_string' => 'Timezone',
            'date_format' => 'Date Format',
            'time_format' => 'Time Format',
            'start_of_week' => 'Week Starts On',
            'WPLANG' => 'Site Language',
            'permalink_structure' => 'Permalink Structure',
            'category_base' => 'Category Base',
            'tag_base' => 'Tag Base',
        ];
        
        return $labels[$option_name] ?? ucwords(str_replace('_', ' ', $option_name));
    }
    
    /**
     * Log widget update
     */
    public function log_widget_update($option_name, $old_value, $value) {
        // Check if it's a widget option
        if (strpos($option_name, 'widget_') === 0) {
            $widget_name = str_replace('widget_', '', $option_name);
            
            $details = [
                'widget' => ucwords(str_replace('_', ' ', $widget_name))
            ];
            
            Site_Logger::log(
                'widget_updated',
                'widget',
                0,
                $widget_name,
                $details,
                'info'
            );
        }
        
        // Check if it's a sidebar widget
        if ($option_name === 'sidebars_widgets') {
            $details = ['action' => 'Widget arrangement changed'];
            
            Site_Logger::log(
                'widgets_rearranged',
                'widget',
                0,
                'Sidebar Widgets',
                $details,
                'info'
            );
        }
    }
    
    /**
     * Log comment post
     */
    public function log_comment_post($comment_id, $comment_approved, $commentdata) {
        $post = get_post($commentdata['comment_post_ID']);
        
        $status_labels = [
            1 => 'Approved',
            0 => 'Pending',
            'spam' => 'Spam',
            'trash' => 'Trashed'
        ];
        
        $details = [
            'post' => $post->post_title,
            'author' => $commentdata['comment_author'],
            'status' => $status_labels[$comment_approved] ?? 'Unknown'
        ];
        
        Site_Logger::log(
            'comment_posted',
            'comment',
            $comment_id,
            'Comment on "' . $post->post_title . '"',
            $details,
            'info'
        );
    }
    
    /**
     * Log comment edit
     */
    public function log_comment_edit($comment_id) {
        $comment = get_comment($comment_id);
        $post = get_post($comment->comment_post_ID);
        
        Site_Logger::log(
            'comment_edited',
            'comment',
            $comment_id,
            'Comment on "' . $post->post_title . '"',
            ['author' => $comment->comment_author],
            'info'
        );
    }
    
    /**
     * Log comment delete
     */
    public function log_comment_delete($comment_id) {
        $comment = get_comment($comment_id);
        if ($comment) {
            $post = get_post($comment->comment_post_ID);
            
            Site_Logger::log(
                'comment_deleted',
                'comment',
                $comment_id,
                'Comment on "' . $post->post_title . '"',
                ['author' => $comment->comment_author],
                'warning'
            );
        }
    }
    
    /**
     * Log media add
     */
    public function log_media_add($attachment_id) {
        $attachment = get_post($attachment_id);
        $file = get_attached_file($attachment_id);
        $filesize = file_exists($file) ? size_format(filesize($file)) : 'Unknown';
        
        $details = [
            'type' => $attachment->post_mime_type,
            'filename' => basename($file),
            'size' => $filesize,
            'uploaded_by' => get_user_by('id', $attachment->post_author)->display_name ?? 'Unknown'
        ];
        
        Site_Logger::log(
            'media_added',
            'attachment',
            $attachment_id,
            $attachment->post_title,
            $details,
            'info'
        );
    }
    
    /**
     * Log media edit
     */
    public function log_media_edit($attachment_id) {
        $attachment = get_post($attachment_id);
        
        Site_Logger::log(
            'media_edited',
            'attachment',
            $attachment_id,
            $attachment->post_title,
            [],
            'info'
        );
    }
    
    /**
     * Log media delete
     */
    public function log_media_delete($attachment_id) {
        Site_Logger::log(
            'media_deleted',
            'attachment',
            $attachment_id,
            'Attachment #' . $attachment_id,
            [],
            'warning'
        );
    }
    
    /**
     * Log term created
     */
    public function log_term_created($term_id, $tt_id, $taxonomy) {
        $term = get_term($term_id, $taxonomy);
        
        Site_Logger::log(
            'term_created',
            'term',
            $term_id,
            $term->name,
            ['taxonomy' => get_taxonomy($taxonomy)->labels->singular_name],
            'info'
        );
    }
    
    /**
     * Log term updated
     */
    public function log_term_updated($term_id, $tt_id, $taxonomy) {
        $term = get_term($term_id, $taxonomy);
        
        Site_Logger::log(
            'term_updated',
            'term',
            $term_id,
            $term->name,
            ['taxonomy' => get_taxonomy($taxonomy)->labels->singular_name],
            'info'
        );
    }
    
    /**
     * Log term deleted
     */
    public function log_term_deleted($term_id, $tt_id, $taxonomy, $deleted_term) {
        Site_Logger::log(
            'term_deleted',
            'term',
            $term_id,
            $deleted_term->name,
            ['taxonomy' => get_taxonomy($taxonomy)->labels->singular_name],
            'warning'
        );
    }
    
    /**
     * Get plugin name from file path
     */
    private function get_plugin_name($plugin_file) {
        if (!function_exists('get_plugin_data')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        
        $plugin_data = get_plugin_data(WP_PLUGIN_DIR . '/' . $plugin_file);
        return $plugin_data['Name'] ?? basename($plugin_file);
    }
}