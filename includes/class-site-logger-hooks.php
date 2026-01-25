<?php
class Site_Logger_Hooks {
    
    private static $old_post_data = [];
    private static $old_meta_data = [];
    private static $old_option_values = [];
    private static $old_taxonomy_data = [];
    private static $old_user_data = [];
    private static $old_acf_data = [];
    private static $is_ajax_request = false;
    private static $is_bulk_operation = false;
    private static $processed_posts = [];
    private static $processed_terms = [];
    private static $stored_bulk_data = false;
    
    public static function init() {
        $instance = new self();
        $instance->setup_hooks();
    }
    
    private function setup_hooks() {
        // Check if it's AJAX request
        add_action('admin_init', [$this, 'check_ajax_request']);
        
        // Check for bulk operations
        add_action('load-edit.php', [$this, 'check_bulk_operation']);
        
        // Store old post data
        add_action('wp_ajax_inline-save', [$this, 'store_old_post_data_ajax'], 1);
        add_filter('wp_insert_post_data', [$this, 'store_old_post_data'], 10, 2);
        
        // Store old meta data
        add_action('save_post', [$this, 'store_old_meta_data'], 5, 2);
        
        // Store old taxonomy data
        add_action('pre_post_update', [$this, 'store_old_taxonomy_data']);
        add_action('wp_ajax_add-tag', [$this, 'store_old_taxonomy_data_ajax'], 1);
        add_action('wp_ajax_inline-save-tax', [$this, 'store_old_taxonomy_data_ajax'], 1);
        
        // Store old user data
        add_action('load-profile.php', [$this, 'store_old_user_data']);
        add_action('load-user-edit.php', [$this, 'store_old_user_data']);
        add_action('personal_options_update', [$this, 'store_old_user_data_for_update']);
        add_action('edit_user_profile_update', [$this, 'store_old_user_data_for_update']);
        
        // Store ACF field data
        add_action('acf/save_post', [$this, 'store_old_acf_data'], 5);
        
        // Posts and Pages
        add_action('save_post', [$this, 'log_post_save'], 20, 3);
        add_action('wp_ajax_inline-save', [$this, 'log_quick_edit_save'], 20);
        add_action('delete_post', [$this, 'log_post_delete'], 10, 1);
        add_action('wp_trash_post', [$this, 'log_post_trash'], 10, 1);
        add_action('untrash_post', [$this, 'log_post_untrash'], 10, 2);
        
        // Post revisions
        add_action('_wp_put_post_revision', [$this, 'log_post_revision'], 10, 2);
        
        // Users
        add_action('user_register', [$this, 'log_user_register']);
        add_action('profile_update', [$this, 'log_profile_update'], 10, 2);
        add_action('wp_login', [$this, 'log_user_login'], 10, 2);
        add_action('wp_logout', [$this, 'log_user_logout']);
        add_action('password_reset', [$this, 'log_password_reset'], 10, 2);
        add_action('retrieve_password', [$this, 'log_password_reset_request'], 10, 1);
        add_action('after_password_reset', [$this, 'log_password_change'], 10, 2);
        add_action('set_user_role', [$this, 'log_user_role_change'], 10, 3);
        
        // User meta changes
        add_action('update_user_meta', [$this, 'log_user_meta_update'], 10, 4);
        add_action('added_user_meta', [$this, 'log_user_meta_add'], 10, 4);
        add_action('deleted_user_meta', [$this, 'log_user_meta_delete'], 10, 4);
        
        // Plugins and Themes
        add_action('activated_plugin', [$this, 'log_plugin_activation'], 10, 2);
        add_action('deactivated_plugin', [$this, 'log_plugin_deactivation'], 10, 2);
        add_action('delete_plugin', [$this, 'log_plugin_delete'], 10, 1);
        add_action('switch_theme', [$this, 'log_theme_switch'], 10, 3);
        
        // Store old option value
        add_filter('pre_update_option', [$this, 'store_old_option_value'], 10, 2);
        add_action('updated_option', [$this, 'log_option_update'], 10, 3);
        
        // Comments
        add_action('comment_post', [$this, 'log_comment_post'], 10, 3);
        add_action('edit_comment', [$this, 'log_comment_edit'], 10, 2);
        add_action('delete_comment', [$this, 'log_comment_delete'], 10, 2);
        
        // Media
        add_action('add_attachment', [$this, 'log_media_add']);
        add_action('edit_attachment', [$this, 'log_media_edit'], 10, 2);
        add_action('delete_attachment', [$this, 'log_media_delete'], 10, 1);
        
        // Featured image hooks
        add_action('added_post_meta', [$this, 'log_featured_image_add'], 10, 4);
        add_action('updated_post_meta', [$this, 'log_featured_image_update'], 10, 4);
        add_action('deleted_post_meta', [$this, 'log_featured_image_delete'], 10, 4);
        
        // Taxonomy terms
        add_action('created_term', [$this, 'log_term_created'], 10, 3);
        add_action('edited_term', [$this, 'log_term_updated'], 10, 3);
        add_action('delete_term', [$this, 'log_term_deleted'], 10, 4);
        add_action('set_object_terms', [$this, 'log_object_terms_change'], 10, 6);
        
        // Widgets
        add_action('updated_option', [$this, 'log_widget_update'], 10, 3);
        
        // Import/Export Tools
        add_action('import_start', [$this, 'log_import_start']);
        add_action('import_end', [$this, 'log_import_end']);
        add_action('export_wp', [$this, 'log_export_start']);
        
        // Store old slug before update
        add_filter('wp_insert_post_data', [$this, 'store_old_slug_data'], 5, 2);
        
        // ACF field saves
        add_action('acf/save_post', [$this, 'log_acf_save'], 20);
        
        // ACF field group saves
        add_action('acf/update_field_group', [$this, 'log_acf_field_group_update'], 10, 1);
        add_action('acf/duplicate_field_group', [$this, 'log_acf_field_group_duplicate'], 10, 2);
        add_action('acf/delete_field_group', [$this, 'log_acf_field_group_delete'], 10, 1);
        
        // Admin menu
        add_action('admin_menu', ['Site_Logger', 'add_admin_menu']);
        
        // Bulk actions
        add_action('handle_bulk_actions-edit-post', [$this, 'handle_bulk_action_post'], 10, 3);
        add_action('handle_bulk_actions-edit-page', [$this, 'handle_bulk_action_post'], 10, 3);
        
        // Store template data before update
        add_filter('wp_insert_post_data', [$this, 'store_old_template_data'], 5, 2);
    }
    
    public function check_ajax_request() {
        if (defined('DOING_AJAX') && DOING_AJAX) {
            self::$is_ajax_request = true;
        }
    }
    
    public function check_bulk_operation() {
        if (isset($_REQUEST['action']) || isset($_REQUEST['action2'])) {
            $action = !empty($_REQUEST['action']) && $_REQUEST['action'] != -1 ? $_REQUEST['action'] : $_REQUEST['action2'];
            if (in_array($action, ['edit', 'trash', 'untrash', 'delete'])) {
                self::$is_bulk_operation = true;
                
                // Store old data for bulk edit
                if ($action === 'edit' && isset($_REQUEST['post'])) {
                    foreach ($_REQUEST['post'] as $post_id) {
                        $this->store_post_data_for_bulk($post_id);
                    }
                }
            }
        }
    }
    
    private function store_post_data_for_bulk($post_id) {
        if (!self::$stored_bulk_data) {
            $post = get_post($post_id);
            if ($post) {
                self::$old_post_data[$post_id] = [
                    'title' => $post->post_title,
                    'status' => $post->post_status,
                    'author' => $post->post_author,
                    'template' => get_page_template_slug($post_id)
                ];
                
                // Store taxonomy data
                $post_type = $post->post_type;
                $taxonomies = get_object_taxonomies($post_type, 'names');
                foreach ($taxonomies as $taxonomy) {
                    $terms = wp_get_post_terms($post_id, $taxonomy, ['fields' => 'ids']);
                    self::$old_taxonomy_data[$post_id][$taxonomy] = $terms;
                }
            }
        }
    }
    
    public function store_old_template_data($data, $postarr) {
        if (!empty($postarr['ID'])) {
            $post_id = $postarr['ID'];
            $old_template = get_page_template_slug($post_id);
            if (!isset(self::$old_post_data[$post_id])) {
                self::$old_post_data[$post_id] = [];
            }
            self::$old_post_data[$post_id]['template'] = $old_template;
        }
        return $data;
    }
    
    public function store_old_post_data_ajax() {
        if (isset($_POST['post_ID'])) {
            $post_id = intval($_POST['post_ID']);
            $old_post = get_post($post_id);
            
            if ($old_post) {
                self::$old_post_data[$post_id] = [
                    'title' => $old_post->post_title,
                    'slug' => $old_post->post_name,
                    'status' => $old_post->post_status,
                    'author' => $old_post->post_author,
                    'parent' => $old_post->post_parent,
                    'menu_order' => $old_post->menu_order,
                    'comment_status' => $old_post->comment_status,
                    'ping_status' => $old_post->ping_status,
                    'template' => get_page_template_slug($post_id)
                ];
                
                // Store taxonomy data for quick edit
                $post_type = $old_post->post_type;
                $taxonomies = get_object_taxonomies($post_type, 'names');
                foreach ($taxonomies as $taxonomy) {
                    $terms = wp_get_post_terms($post_id, $taxonomy, ['fields' => 'ids']);
                    self::$old_taxonomy_data[$post_id][$taxonomy] = $terms;
                }
            }
        }
    }
    
    public function store_old_taxonomy_data_ajax() {
        if (isset($_POST['tag_ID'])) {
            $term_id = intval($_POST['tag_ID']);
            $taxonomy = isset($_POST['taxonomy']) ? sanitize_text_field($_POST['taxonomy']) : 'category';
            
            $term = get_term($term_id, $taxonomy);
            if ($term && !is_wp_error($term)) {
                if (!isset(self::$old_taxonomy_data[$taxonomy])) {
                    self::$old_taxonomy_data[$taxonomy] = [];
                }
                
                self::$old_taxonomy_data[$taxonomy][$term_id] = [
                    'name' => $term->name,
                    'slug' => $term->slug,
                    'description' => $term->description,
                    'parent' => $term->parent
                ];
                
                // Store term meta
                $term_meta = get_term_meta($term_id);
                self::$old_taxonomy_data[$taxonomy][$term_id]['meta'] = $term_meta;
            }
        }
    }
    
    public function store_old_user_data_for_update($user_id) {
        $user = get_user_by('id', $user_id);
        if ($user) {
            self::$old_user_data[$user_id] = [
                'display_name' => $user->display_name,
                'user_email' => $user->user_email,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'description' => $user->description,
                'user_url' => $user->user_url,
                'roles' => $user->roles
            ];
        }
    }
    
    public function store_old_acf_data($post_id) {
        if ($post_id === 'acf-field-group' || $post_id === 'options') {
            return; // Skip ACF field groups and options pages
        }
        
        if (function_exists('get_field_objects')) {
            $acf_fields = get_field_objects($post_id);
            if ($acf_fields) {
                self::$old_acf_data[$post_id] = [];
                foreach ($acf_fields as $field_name => $field) {
                    self::$old_acf_data[$post_id][$field_name] = [
                        'value' => $field['value'],
                        'label' => $field['label'] ?? $field_name,
                        'type' => $field['type'] ?? 'text'
                    ];
                }
            }
        }
    }
    
    public function log_acf_save($post_id) {
        // Skip revisions, autosaves, and auto-drafts
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }
        
        // Skip ACF field groups (handled separately)
        if ($post_id === 'acf-field-group' || $post_id === 'options') {
            return;
        }
        
        $post = get_post($post_id);
        if (!$post) return;
        
        $changes = [];
        
        if (isset(self::$old_acf_data[$post_id]) && function_exists('get_field_objects')) {
            $current_acf_fields = get_field_objects($post_id);
            
            if ($current_acf_fields) {
                foreach ($current_acf_fields as $field_name => $field) {
                    $old_field = isset(self::$old_acf_data[$post_id][$field_name]) ? 
                                self::$old_acf_data[$post_id][$field_name] : null;
                    
                    if ($old_field && $this->values_differ($old_field['value'], $field['value'])) {
                        $field_label = $field['label'] ?? $field_name;
                        $changes[$field_label] = [
                            'old' => $this->format_field_value($old_field['value'], $old_field['type']),
                            'new' => $this->format_field_value($field['value'], $field['type'])
                        ];
                    }
                }
            }
        }
        
        if (!empty($changes)) {
            $edit_url = get_edit_post_link($post_id);
            $view_url = get_permalink($post_id);
            
            $details = array_merge($changes, [
                'action' => 'ACF fields updated',
                'fields_updated' => count($changes)
            ]);
            
            if ($edit_url) {
                $details['edit_post'] = "<a href='" . esc_url($edit_url) . "' target='_blank'>✏️ Edit post</a>";
            }
            if ($view_url) {
                $details['view_post'] = "<a href='" . esc_url($view_url) . "' target='_blank'>👁️ View post</a>";
            }
            
            Site_Logger::log(
                'acf_fields_updated',
                'post',
                $post_id,
                $post->post_title,
                $details,
                'info'
            );
        }
        
        unset(self::$old_acf_data[$post_id]);
    }
    
    public function log_acf_field_group_update($field_group) {
        $details = [
            'field_group' => $field_group['title'],
            'key' => $field_group['key'],
            'fields_count' => isset($field_group['fields']) ? count($field_group['fields']) : 0,
            'action' => 'ACF Field Group updated'
        ];
        
        Site_Logger::log(
            'acf_field_group_updated',
            'acf',
            0,
            $field_group['title'],
            $details,
            'info'
        );
    }
    
    public function log_acf_field_group_duplicate($new_field_group, $old_field_group) {
        $details = [
            'original' => $old_field_group['title'],
            'duplicate' => $new_field_group['title'],
            'action' => 'ACF Field Group duplicated'
        ];
        
        Site_Logger::log(
            'acf_field_group_duplicated',
            'acf',
            0,
            $new_field_group['title'],
            $details,
            'info'
        );
    }
    
    public function log_acf_field_group_delete($field_group) {
        $details = [
            'field_group' => $field_group['title'],
            'key' => $field_group['key'],
            'action' => 'ACF Field Group deleted'
        ];
        
        Site_Logger::log(
            'acf_field_group_deleted',
            'acf',
            0,
            $field_group['title'],
            $details,
            'warning'
        );
    }
    
    public function store_old_slug_data($data, $postarr) {
        if (!empty($postarr['ID'])) {
            $post_id = $postarr['ID'];
            $old_post = get_post($post_id);
            if ($old_post) {
                if (!isset(self::$old_post_data[$post_id])) {
                    self::$old_post_data[$post_id] = [];
                }
                self::$old_post_data[$post_id]['slug'] = $old_post->post_name;
            }
        }
        return $data;
    }
    
    public function store_old_post_data($data, $postarr) {
        if (!empty($postarr['ID'])) {
            $post_id = $postarr['ID'];
            $old_post = get_post($post_id);
            if ($old_post) {
                if (!isset(self::$old_post_data[$post_id])) {
                    self::$old_post_data[$post_id] = [];
                }
                
                self::$old_post_data[$post_id] = array_merge(self::$old_post_data[$post_id], [
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
                    'slug' => $old_post->post_name,
                    'template' => get_page_template_slug($post_id)
                ]);
            }
        }
        return $data;
    }
    
    public function store_old_meta_data($post_id, $post) {
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }
        
        $all_meta = get_post_meta($post_id);
        self::$old_meta_data[$post_id] = [];
        
        foreach ($all_meta as $meta_key => $meta_values) {
            if (isset($meta_values[0])) {
                self::$old_meta_data[$post_id][$meta_key] = maybe_unserialize($meta_values[0]);
            }
        }
    }
    
    public function store_old_option_value($new_value, $option_name) {
        if (!isset(self::$old_option_values[$option_name])) {
            self::$old_option_values[$option_name] = get_option($option_name);
        }
        return $new_value;
    }
    
    public function store_old_taxonomy_data($post_id) {
        $post_type = get_post_type($post_id);
        $taxonomies = get_object_taxonomies($post_type, 'names');
        
        foreach ($taxonomies as $taxonomy) {
            $terms = wp_get_post_terms($post_id, $taxonomy, ['fields' => 'ids']);
            self::$old_taxonomy_data[$post_id][$taxonomy] = $terms;
        }
    }
    
    public function store_old_user_data() {
        if (isset($_GET['user_id'])) {
            $user_id = intval($_GET['user_id']);
            $user = get_user_by('id', $user_id);
            if ($user) {
                self::$old_user_data[$user_id] = [
                    'display_name' => $user->display_name,
                    'user_email' => $user->user_email,
                    'first_name' => $user->first_name,
                    'last_name' => $user->last_name,
                    'description' => $user->description,
                    'user_url' => $user->user_url,
                    'roles' => $user->roles
                ];
            }
        } elseif (isset($_POST['user_id'])) {
            $user_id = intval($_POST['user_id']);
            $user = get_user_by('id', $user_id);
            if ($user) {
                self::$old_user_data[$user_id] = [
                    'display_name' => $user->display_name,
                    'user_email' => $user->user_email,
                    'first_name' => $user->first_name,
                    'last_name' => $user->last_name,
                    'description' => $user->description,
                    'user_url' => $user->user_url,
                    'roles' => $user->roles
                ];
            }
        }
    }
    
    public function log_quick_edit_save() {
        if (!isset($_POST['post_ID'])) return;
        
        $post_id = intval($_POST['post_ID']);
        
        // Skip if already processed in this request
        if (in_array($post_id, self::$processed_posts)) {
            return;
        }
        
        self::$processed_posts[] = $post_id;
        
        $post = get_post($post_id);
        if (!$post) return;
        
        $details = [];
        $has_changes = false;
        
        // Check title change
        if (isset($_POST['post_title']) && isset(self::$old_post_data[$post_id]['title'])) {
            $new_title = sanitize_text_field($_POST['post_title']);
            if (self::$old_post_data[$post_id]['title'] !== $new_title) {
                $details['title'] = [
                    'old' => self::$old_post_data[$post_id]['title'],
                    'new' => $new_title
                ];
                $has_changes = true;
            }
        }
        
        // Check slug change
        if (isset($_POST['post_name']) && isset(self::$old_post_data[$post_id]['slug'])) {
            $new_slug = sanitize_title($_POST['post_name']);
            if (self::$old_post_data[$post_id]['slug'] !== $new_slug) {
                $details['slug'] = [
                    'old' => self::$old_post_data[$post_id]['slug'],
                    'new' => $new_slug
                ];
                $has_changes = true;
            }
        } elseif (isset($_POST['post_name']) && !empty($_POST['post_name'])) {
            $new_slug = sanitize_title($_POST['post_name']);
            $old_slug = $post->post_name;
            if ($old_slug !== $new_slug) {
                $details['slug'] = [
                    'old' => $old_slug,
                    'new' => $new_slug
                ];
                $has_changes = true;
            }
        }
        
        // Check author change
        if (isset($_POST['post_author']) && isset(self::$old_post_data[$post_id]['author'])) {
            $new_author = intval($_POST['post_author']);
            if (self::$old_post_data[$post_id]['author'] != $new_author) {
                $old_author = get_user_by('id', self::$old_post_data[$post_id]['author']);
                $new_author_user = get_user_by('id', $new_author);
                $details['author'] = [
                    'old' => $old_author ? $old_author->display_name : 'User #' . self::$old_post_data[$post_id]['author'],
                    'new' => $new_author_user ? $new_author_user->display_name : 'User #' . $new_author
                ];
                $has_changes = true;
            }
        }
        
        // Check page template change
        if (isset($_POST['page_template']) && isset(self::$old_post_data[$post_id]['template'])) {
            $new_template = sanitize_text_field($_POST['page_template']);
            if (self::$old_post_data[$post_id]['template'] !== $new_template) {
                $old_template_name = $this->get_template_name(self::$old_post_data[$post_id]['template']);
                $new_template_name = $this->get_template_name($new_template);
                $details['page_template'] = [
                    'old' => $old_template_name,
                    'new' => $new_template_name
                ];
                $has_changes = true;
            }
        }
        
        // Check status change
        if (isset($_POST['_status']) && isset(self::$old_post_data[$post_id]['status'])) {
            $new_status = sanitize_text_field($_POST['_status']);
            if (self::$old_post_data[$post_id]['status'] !== $new_status) {
                $details['status'] = [
                    'old' => $this->get_post_status_label(self::$old_post_data[$post_id]['status']),
                    'new' => $this->get_post_status_label($new_status)
                ];
                $has_changes = true;
            }
        }
        
        // Check taxonomy changes
        if (isset(self::$old_taxonomy_data[$post_id])) {
            $post_type = $post->post_type;
            $taxonomies = get_object_taxonomies($post_type, 'names');
            
            foreach ($taxonomies as $taxonomy) {
                if (isset($_POST['tax_input'][$taxonomy])) {
                    $new_terms = $_POST['tax_input'][$taxonomy];
                    $old_terms = isset(self::$old_taxonomy_data[$post_id][$taxonomy]) ? 
                                self::$old_taxonomy_data[$post_id][$taxonomy] : [];
                    
                    if (is_array($new_terms)) {
                        $old_term_names = [];
                        foreach ($old_terms as $term_id) {
                            $term = get_term($term_id, $taxonomy);
                            if ($term && !is_wp_error($term)) {
                                $old_term_names[] = $term->name;
                            }
                        }
                        
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
                            $taxonomy_obj = get_taxonomy($taxonomy);
                            $tax_name = $taxonomy_obj->labels->name ?? $taxonomy;
                            
                            $added = array_diff($new_term_names, $old_term_names);
                            $removed = array_diff($old_term_names, $new_term_names);
                            
                            if (!empty($added) || !empty($removed)) {
                                $tax_changes = [];
                                if (!empty($added)) {
                                    $tax_changes['added'] = array_values($added);
                                }
                                if (!empty($removed)) {
                                    $tax_changes['removed'] = array_values($removed);
                                }
                                $details[$tax_name] = $tax_changes;
                                $has_changes = true;
                            }
                        }
                    }
                }
            }
        }
        
        if ($has_changes) {
            $edit_url = get_edit_post_link($post_id);
            $view_url = get_permalink($post_id);
            
            if ($edit_url) {
                $details['edit_post'] = "<a href='" . esc_url($edit_url) . "' target='_blank'>✏️ Edit post</a>";
            }
            if ($view_url) {
                $details['view_post'] = "<a href='" . esc_url($view_url) . "' target='_blank'>👁️ View post</a>";
            }
            
            Site_Logger::log(
                'post_updated',
                $post->post_type,
                $post_id,
                $post->post_title,
                $details,
                'info'
            );
        }
        
        unset(self::$old_post_data[$post_id]);
        unset(self::$old_taxonomy_data[$post_id]);
    }
    
    public function log_post_save($post_id, $post, $update) {
        // Skip if already processed
        if (in_array($post_id, self::$processed_posts)) {
            return;
        }
        
        self::$processed_posts[] = $post_id;
        
        // Skip revisions, autosaves, and auto-drafts
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id) || $post->post_status === 'auto-draft') {
            return;
        }
        
        // Skip if post is being deleted (handled by delete_post hook)
        if ($post->post_status === 'trash' && $update) {
            return;
        }
        
        // Skip ACF field group posts
        if ($post->post_type === 'acf-field-group' || $post->post_type === 'acf-field') {
            return;
        }
        
        $action = $update ? 'post_updated' : 'post_created';
        $severity = 'info';
        $details = [];
        $has_changes = false;
        
        $edit_url = get_edit_post_link($post_id);
        $view_url = get_permalink($post_id);
        
        if ($edit_url) {
            $details['edit_post'] = "<a href='" . esc_url($edit_url) . "' target='_blank'>✏️ Edit post</a>";
        }
        if ($view_url) {
            $details['view_post'] = "<a href='" . esc_url($view_url) . "' target='_blank'>👁️ View post</a>";
        }
        
        if ($update && isset(self::$old_post_data[$post_id])) {
            $old_data = self::$old_post_data[$post_id];
            
            // Check title change
            if (isset($old_data['title']) && $old_data['title'] !== $post->post_title) {
                $details['title'] = [
                    'old' => $old_data['title'],
                    'new' => $post->post_title
                ];
                $has_changes = true;
            }
            
            // Check slug change
            if (isset($old_data['slug']) && $old_data['slug'] !== $post->post_name) {
                $details['slug'] = [
                    'old' => $old_data['slug'],
                    'new' => $post->post_name
                ];
                $has_changes = true;
            }
            
            // Check template change
            if (isset($old_data['template'])) {
                $current_template = get_page_template_slug($post_id);
                if ($old_data['template'] !== $current_template) {
                    $old_template_name = $this->get_template_name($old_data['template']);
                    $new_template_name = $this->get_template_name($current_template);
                    $details['page_template'] = [
                        'old' => $old_template_name,
                        'new' => $new_template_name
                    ];
                    $has_changes = true;
                }
            }
            
            // Check status change
            if (isset($old_data['status']) && $old_data['status'] !== $post->post_status) {
                $details['status'] = [
                    'old' => $this->get_post_status_label($old_data['status']),
                    'new' => $this->get_post_status_label($post->post_status)
                ];
                $has_changes = true;
                
                if ($post->post_status === 'private') {
                    $severity = 'warning';
                }
            }
            
            // Check author change
            if (isset($old_data['author']) && $old_data['author'] != $post->post_author) {
                $old_author = get_user_by('id', $old_data['author']);
                $new_author = get_user_by('id', $post->post_author);
                $details['author'] = [
                    'old' => $old_author ? $old_author->display_name : 'User #' . $old_data['author'],
                    'new' => $new_author ? $new_author->display_name : 'User #' . $post->post_author
                ];
                $has_changes = true;
            }
            
            // Check taxonomy changes
            $tax_changes = $this->check_taxonomy_changes($post_id);
            if ($tax_changes) {
                foreach ($tax_changes as $tax_name => $change) {
                    $details[$tax_name] = $change;
                }
                $has_changes = true;
            }
            
            if (!$has_changes) {
                $details['note'] = 'Post saved (no changes detected)';
            }
            
            unset(self::$old_post_data[$post_id]);
            unset(self::$old_meta_data[$post_id]);
            unset(self::$old_taxonomy_data[$post_id]);
            
        } else {
            // New post
            $details['action'] = 'New post created';
            $details['status'] = $this->get_post_status_label($post->post_status);
            $details['post_type'] = $post->post_type;
            
            // Log initial taxonomy assignment
            $initial_tax = $this->check_initial_taxonomy($post_id);
            if ($initial_tax) {
                foreach ($initial_tax as $tax_name => $terms) {
                    $details[$tax_name] = 'Set to: ' . implode(', ', $terms);
                }
            }
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
    
    public function log_post_revision($revision_id, $original_id) {
        // Skip if already processed
        if (in_array($revision_id, self::$processed_posts)) {
            return;
        }
        
        self::$processed_posts[] = $revision_id;
        
        $revision = get_post($revision_id);
        $parent_post = get_post($original_id);
        
        if ($parent_post) {
            $revisions_url = admin_url("revision.php?post={$original_id}");
            
            $details = [
                'revision' => "Revision #{$revision_id} created",
                'parent_post' => $parent_post->post_title,
                'view_revisions' => "<a href='" . esc_url($revisions_url) . "' target='_blank'>📚 View all revisions</a>",
                'edit_post' => get_edit_post_link($parent_post->ID) ? "<a href='" . esc_url(get_edit_post_link($parent_post->ID)) . "' target='_blank'>✏️ Edit post</a>" : '',
                'view_post' => get_permalink($parent_post->ID) ? "<a href='" . esc_url(get_permalink($parent_post->ID)) . "' target='_blank'>👁️ View post</a>" : ''
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
    
    public function log_post_delete($post_id) {
        // Skip if already processed
        if (in_array($post_id, self::$processed_posts)) {
            return;
        }
        
        self::$processed_posts[] = $post_id;
        
        $post = get_post($post_id);
        if (!$post) return;
        
        $view_url = get_permalink($post_id);
        $details = [
            'post_type' => $post->post_type,
            'status' => $this->get_post_status_label($post->post_status)
        ];
        
        if ($view_url) {
            $details['view_post'] = "<a href='" . esc_url($view_url) . "' target='_blank'>👁️ View post (if available)</a>";
        }
        
        Site_Logger::log(
            'post_deleted',
            $post->post_type,
            $post_id,
            $post->post_title,
            $details,
            'warning'
        );
    }
    
    public function log_post_trash($post_id) {
        // Skip if already processed
        if (in_array($post_id, self::$processed_posts)) {
            return;
        }
        
        self::$processed_posts[] = $post_id;
        
        $post = get_post($post_id);
        if (!$post) return;
        
        $edit_url = get_edit_post_link($post_id);
        $view_url = get_permalink($post_id);
        $details = [
            'status' => $this->get_post_status_label($post->post_status)
        ];
        
        if ($edit_url) {
            $details['edit_post'] = "<a href='" . esc_url($edit_url) . "' target='_blank'>✏️ Edit post</a>";
        }
        if ($view_url) {
            $details['view_post'] = "<a href='" . esc_url($view_url) . "' target='_blank'>👁️ View post</a>";
        }
        
        Site_Logger::log(
            'post_trashed',
            $post->post_type,
            $post_id,
            $post->post_title,
            $details,
            'warning'
        );
    }
    
    public function log_post_untrash($post_id, $previous_status) {
        // Skip if already processed
        if (in_array($post_id, self::$processed_posts)) {
            return;
        }
        
        self::$processed_posts[] = $post_id;
        
        $post = get_post($post_id);
        if (!$post) return;
        
        $edit_url = get_edit_post_link($post_id);
        $view_url = get_permalink($post_id);
        $details = [
            'status' => $this->get_post_status_label($post->post_status),
            'previous_status' => $this->get_post_status_label($previous_status)
        ];
        
        if ($edit_url) {
            $details['edit_post'] = "<a href='" . esc_url($edit_url) . "' target='_blank'>✏️ Edit post</a>";
        }
        if ($view_url) {
            $details['view_post'] = "<a href='" . esc_url($view_url) . "' target='_blank'>👁️ View post</a>";
        }
        
        Site_Logger::log(
            'post_untrashed',
            $post->post_type,
            $post_id,
            $post->post_title,
            $details,
            'notice'
        );
    }
    
    public function handle_bulk_action_post($redirect_to, $doaction, $post_ids) {
        if (empty($post_ids)) return $redirect_to;
        
        switch ($doaction) {
            case 'trash':
                foreach ($post_ids as $post_id) {
                    $this->log_bulk_trash($post_id);
                }
                break;
                
            case 'untrash':
                foreach ($post_ids as $post_id) {
                    $this->log_bulk_untrash($post_id);
                }
                break;
                
            case 'delete':
                foreach ($post_ids as $post_id) {
                    $this->log_bulk_delete($post_id);
                }
                break;
                
            case 'edit':
                $this->log_bulk_edit($post_ids);
                break;
        }
        
        return $redirect_to;
    }
    
    private function log_bulk_trash($post_id) {
        $post = get_post($post_id);
        if (!$post) return;
        
        $details = [
            'status' => $this->get_post_status_label($post->post_status),
            'action' => 'Bulk action: Trash',
            'view_post' => get_permalink($post_id) ? "<a href='" . esc_url(get_permalink($post_id)) . "' target='_blank'>👁️ View post</a>" : ''
        ];
        
        Site_Logger::log(
            'post_trashed',
            $post->post_type,
            $post_id,
            $post->post_title,
            $details,
            'warning'
        );
    }
    
    private function log_bulk_untrash($post_id) {
        $post = get_post($post_id);
        if (!$post) return;
        
        $details = [
            'status' => $this->get_post_status_label($post->post_status),
            'action' => 'Bulk action: Restore',
            'view_post' => get_permalink($post_id) ? "<a href='" . esc_url(get_permalink($post_id)) . "' target='_blank'>👁️ View post</a>" : ''
        ];
        
        Site_Logger::log(
            'post_untrashed',
            $post->post_type,
            $post_id,
            $post->post_title,
            $details,
            'notice'
        );
    }
    
    private function log_bulk_delete($post_id) {
        $post = get_post($post_id);
        if (!$post) return;
        
        $details = [
            'post_type' => $post->post_type,
            'status' => $this->get_post_status_label($post->post_status),
            'action' => 'Bulk action: Delete',
            'view_post' => get_permalink($post_id) ? "<a href='" . esc_url(get_permalink($post_id)) . "' target='_blank'>👁️ View post (if available)</a>" : ''
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
    
    private function log_bulk_edit($post_ids) {
        if (empty($post_ids)) return;
        
        $changes = [];
        
        // Check for status changes
        if (isset($_REQUEST['_status']) && $_REQUEST['_status'] != -1) {
            $new_status = sanitize_text_field($_REQUEST['_status']);
            $changes['status'] = [
                'new' => $this->get_post_status_label($new_status)
            ];
        }
        
        // Check for taxonomy changes
        $post_type = get_post_type($post_ids[0]);
        $taxonomies = get_object_taxonomies($post_type, 'names');
        
        foreach ($taxonomies as $taxonomy) {
            if (isset($_REQUEST[$taxonomy]) && $_REQUEST[$taxonomy] != -1) {
                $term_id = intval($_REQUEST[$taxonomy]);
                $term = get_term($term_id, $taxonomy);
                if ($term && !is_wp_error($term)) {
                    $taxonomy_obj = get_taxonomy($taxonomy);
                    $tax_name = $taxonomy_obj->labels->name ?? $taxonomy;
                    $changes[$tax_name] = [
                        'new' => $term->name
                    ];
                }
            }
        }
        
        if (empty($changes)) return;
        
        // Log for each post
        foreach ($post_ids as $post_id) {
            $post = get_post($post_id);
            if (!$post) continue;
            
            $post_details = $changes;
            $post_details['action'] = 'Bulk edit applied';
            $post_details['view_post'] = get_permalink($post_id) ? "<a href='" . esc_url(get_permalink($post_id)) . "' target='_blank'>👁️ View post</a>" : '';
            
            Site_Logger::log(
                'post_updated',
                $post->post_type,
                $post_id,
                $post->post_title,
                $post_details,
                'info'
            );
        }
    }
    
    private function get_template_name($template) {
        if (empty($template) || $template === 'default') {
            return 'Default Template';
        }
        
        $templates = wp_get_theme()->get_page_templates();
        return isset($templates[$template]) ? $templates[$template] : $template;
    }
    
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
    
    private function check_taxonomy_changes($post_id, $is_new = false) {
        $tax_changes = [];
        $post_type = get_post_type($post_id);
        
        $taxonomies = get_object_taxonomies($post_type, 'names');
        
        foreach ($taxonomies as $taxonomy) {
            $taxonomy_obj = get_taxonomy($taxonomy);
            $taxonomy_name = $taxonomy_obj->labels->name ?? $taxonomy;
            
            $current_terms = wp_get_post_terms($post_id, $taxonomy, ['fields' => 'ids']);
            
            if ($is_new) {
                if (isset($_POST['tax_input'][$taxonomy])) {
                    $selected_terms = $_POST['tax_input'][$taxonomy];
                    if (!empty($selected_terms)) {
                        $term_names = $this->get_term_names_from_input($selected_terms, $taxonomy);
                        if (!empty($term_names)) {
                            $tax_changes[$taxonomy_name] = 'Set to: ' . implode(', ', $term_names);
                        }
                    }
                }
            } else {
                $old_terms = isset(self::$old_taxonomy_data[$post_id][$taxonomy]) ? 
                            self::$old_taxonomy_data[$post_id][$taxonomy] : [];
                
                if (array_diff($old_terms, $current_terms) || array_diff($current_terms, $old_terms)) {
                    $old_names = $this->get_term_names_from_ids($old_terms, $taxonomy);
                    $new_names = $this->get_term_names_from_ids($current_terms, $taxonomy);
                    
                    $added = array_diff($new_names, $old_names);
                    $removed = array_diff($old_names, $new_names);
                    
                    if (!empty($added) || !empty($removed)) {
                        $changes = [];
                        if (!empty($added)) {
                            $changes['added'] = array_values($added);
                        }
                        if (!empty($removed)) {
                            $changes['removed'] = array_values($removed);
                        }
                        $tax_changes[$taxonomy_name] = $changes;
                    }
                }
            }
        }
        
        return $tax_changes;
    }
    
    private function check_initial_taxonomy($post_id) {
        $tax_changes = [];
        $post_type = get_post_type($post_id);
        $taxonomies = get_object_taxonomies($post_type, 'names');
        
        foreach ($taxonomies as $taxonomy) {
            $terms = wp_get_post_terms($post_id, $taxonomy, ['fields' => 'names']);
            if (!empty($terms)) {
                $taxonomy_obj = get_taxonomy($taxonomy);
                $taxonomy_name = $taxonomy_obj->labels->name ?? $taxonomy;
                $tax_changes[$taxonomy_name] = $terms;
            }
        }
        
        return $tax_changes;
    }
    
    public function log_object_terms_change($object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids) {
        // Skip if this is from save_post (already logged)
        if (did_action('save_post') || doing_action('save_post')) {
            return;
        }
        
        if (!get_post_type($object_id)) return;
        
        $post = get_post($object_id);
        if (!$post) return;
        
        sort($old_tt_ids);
        sort($tt_ids);
        if ($old_tt_ids == $tt_ids) return;
        
        $taxonomy_obj = get_taxonomy($taxonomy);
        $taxonomy_name = $taxonomy_obj->labels->name ?? $taxonomy;
        
        $old_terms = $this->get_term_names_from_ids($old_tt_ids, $taxonomy);
        $new_terms = $this->get_term_names_from_ids($tt_ids, $taxonomy);
        
        $added = array_diff($new_terms, $old_terms);
        $removed = array_diff($old_terms, $new_terms);
        
        if (empty($added) && empty($removed)) return;
        
        $view_url = get_permalink($object_id);
        $details = [
            'taxonomy' => $taxonomy_name,
            'post_title' => $post->post_title
        ];
        
        if ($view_url) {
            $details['view_post'] = "<a href='" . esc_url($view_url) . "' target='_blank'>👁️ View post</a>";
        }
        
        if (!empty($added)) {
            $details['added'] = array_values($added);
        }
        if (!empty($removed)) {
            $details['removed'] = array_values($removed);
        }
        
        Site_Logger::log(
            'taxonomy_updated',
            'post',
            $object_id,
            $post->post_title,
            $details,
            'info'
        );
    }
    
    public function log_term_updated($term_id, $tt_id, $taxonomy) {
        // Skip if already processed
        if (in_array($term_id, self::$processed_terms)) {
            return;
        }
        
        self::$processed_terms[] = $term_id;
        
        $term = get_term($term_id, $taxonomy);
        if (is_wp_error($term) || !$term) return;
        
        $taxonomy_obj = get_taxonomy($taxonomy);
        $details = [
            'taxonomy' => $taxonomy_obj->labels->singular_name ?? $taxonomy
        ];
        
        // Add term edit link
        $edit_url = get_edit_term_link($term_id, $taxonomy);
        if ($edit_url) {
            $details['edit_term'] = "<a href='" . esc_url($edit_url) . "' target='_blank'>✏️ Edit term</a>";
        }
        
        if (isset(self::$old_taxonomy_data[$taxonomy][$term_id])) {
            $old_data = self::$old_taxonomy_data[$taxonomy][$term_id];
            
            // Check name change
            if (isset($_POST['name']) && $old_data['name'] !== $_POST['name']) {
                $details['name'] = [
                    'old' => $old_data['name'],
                    'new' => sanitize_text_field($_POST['name'])
                ];
            }
            
            // Check slug change
            if (isset($_POST['slug']) && $old_data['slug'] !== $_POST['slug']) {
                $details['slug'] = [
                    'old' => $old_data['slug'],
                    'new' => sanitize_title($_POST['slug'])
                ];
            }
            
            // Check description change
            if (isset($_POST['description']) && $old_data['description'] !== $_POST['description']) {
                $old_desc = $old_data['description'] ?: '(empty)';
                $new_desc = sanitize_textarea_field($_POST['description']) ?: '(empty)';
                $details['description'] = [
                    'old' => substr($old_desc, 0, 100) . (strlen($old_desc) > 100 ? '...' : ''),
                    'new' => substr($new_desc, 0, 100) . (strlen($new_desc) > 100 ? '...' : '')
                ];
            }
            
            // Check parent change
            if (isset($_POST['parent']) && $old_data['parent'] != $_POST['parent']) {
                $old_parent = $old_data['parent'] ? get_term($old_data['parent'], $taxonomy)->name : '(no parent)';
                $new_parent = $_POST['parent'] ? get_term($_POST['parent'], $taxonomy)->name : '(no parent)';
                $details['parent'] = [
                    'old' => $old_parent,
                    'new' => $new_parent
                ];
            }
            
            // Check term meta changes
            if (isset($old_data['meta'])) {
                $current_meta = get_term_meta($term_id);
                foreach ($current_meta as $meta_key => $meta_values) {
                    if (strpos($meta_key, '_') !== 0) { // Skip internal meta
                        $old_meta_value = isset($old_data['meta'][$meta_key]) ? 
                                         $old_data['meta'][$meta_key][0] ?? '' : '';
                        $new_meta_value = $meta_values[0] ?? '';
                        
                        if ($old_meta_value !== $new_meta_value) {
                            $details["meta_{$meta_key}"] = [
                                'old' => substr($old_meta_value, 0, 50) . (strlen($old_meta_value) > 50 ? '...' : ''),
                                'new' => substr($new_meta_value, 0, 50) . (strlen($new_meta_value) > 50 ? '...' : '')
                            ];
                        }
                    }
                }
            }
        } else {
            if (isset($_POST['name']) && $_POST['name'] !== $term->name) {
                $details['name'] = [
                    'old' => $term->name,
                    'new' => sanitize_text_field($_POST['name'])
                ];
            }
            
            if (isset($_POST['slug']) && $_POST['slug'] !== $term->slug) {
                $details['slug'] = [
                    'old' => $term->slug,
                    'new' => sanitize_title($_POST['slug'])
                ];
            }
        }
        
        if (count($details) > 1) {
            Site_Logger::log(
                'term_updated',
                'term',
                $term_id,
                $term->name,
                $details,
                'info'
            );
        }
        
        unset(self::$old_taxonomy_data[$taxonomy][$term_id]);
    }
    
    public function log_term_created($term_id, $tt_id, $taxonomy) {
        // Skip if already processed
        if (in_array($term_id, self::$processed_terms)) {
            return;
        }
        
        self::$processed_terms[] = $term_id;
        
        $term = get_term($term_id, $taxonomy);
        if (!is_wp_error($term) && $term) {
            $taxonomy_obj = get_taxonomy($taxonomy);
            
            $parent_info = '';
            if ($term->parent) {
                $parent_term = get_term($term->parent, $taxonomy);
                $parent_info = $parent_term ? " (child of: {$parent_term->name})" : '';
            }
            
            // Add term edit link
            $edit_url = get_edit_term_link($term_id, $taxonomy);
            
            $details = [
                'taxonomy' => $taxonomy_obj->labels->singular_name ?? $taxonomy,
                'slug' => $term->slug,
                'parent' => $term->parent ? "Parent term ID: {$term->parent}" : 'No parent'
            ];
            
            if ($edit_url) {
                $details['edit_term'] = "<a href='" . esc_url($edit_url) . "' target='_blank'>✏️ Edit term</a>";
            }
            
            Site_Logger::log(
                'term_created',
                'term',
                $term_id,
                $term->name . $parent_info,
                $details,
                'info'
            );
        }
    }
    
    public function log_term_deleted($term_id, $tt_id, $taxonomy, $deleted_term) {
        // Skip if already processed
        if (in_array($term_id, self::$processed_terms)) {
            return;
        }
        
        self::$processed_terms[] = $term_id;
        
        $taxonomy_obj = get_taxonomy($taxonomy);
        
        $parent_info = '';
        if ($deleted_term && $deleted_term->parent) {
            $parent_term = get_term($deleted_term->parent, $taxonomy);
            $parent_info = $parent_term ? " (was child of: {$parent_term->name})" : '';
        }
        
        $details = [
            'taxonomy' => $taxonomy_obj->labels->singular_name ?? $taxonomy,
            'slug' => $deleted_term->slug ?? '',
            'parent' => $parent_info ?: 'No parent'
        ];
        
        Site_Logger::log(
            'term_deleted',
            'term',
            $term_id,
            ($deleted_term->name ?? 'Term #' . $term_id) . $parent_info,
            $details,
            'warning'
        );
    }
    
    public function log_profile_update($user_id, $old_user_data) {
        $user = get_user_by('id', $user_id);
        $new_user_data = get_userdata($user_id);
        $changes = [];
        
        $profile_url = get_author_posts_url($user_id);
        $changes['visit_user'] = $profile_url ? "<a href='" . esc_url($profile_url) . "' target='_blank'>👤 Visit profile</a>" : '';
        
        if ($old_user_data->user_email !== $new_user_data->user_email) {
            $changes['email'] = [
                'old' => $old_user_data->user_email,
                'new' => $new_user_data->user_email
            ];
        }
        
        if (isset($_POST['url']) && $old_user_data->user_url !== $_POST['url']) {
            $changes['website'] = [
                'old' => $old_user_data->user_url ?: '(empty)',
                'new' => esc_url_raw($_POST['url']) ?: '(empty)'
            ];
        }
        
        if (isset($_POST['display_name']) && $old_user_data->display_name !== $_POST['display_name']) {
            $changes['display_name'] = [
                'old' => $old_user_data->display_name,
                'new' => sanitize_text_field($_POST['display_name'])
            ];
        }
        
        $old_first_name = get_user_meta($user_id, 'first_name', true);
        $new_first_name = isset($_POST['first_name']) ? sanitize_text_field($_POST['first_name']) : $old_first_name;
        if ($old_first_name !== $new_first_name) {
            $changes['first_name'] = [
                'old' => $old_first_name ?: '(empty)',
                'new' => $new_first_name ?: '(empty)'
            ];
        }
        
        $old_last_name = get_user_meta($user_id, 'last_name', true);
        $new_last_name = isset($_POST['last_name']) ? sanitize_text_field($_POST['last_name']) : $old_last_name;
        if ($old_last_name !== $new_last_name) {
            $changes['last_name'] = [
                'old' => $old_last_name ?: '(empty)',
                'new' => $new_last_name ?: '(empty)'
            ];
        }
        
        $old_bio = get_user_meta($user_id, 'description', true);
        $new_bio = isset($_POST['description']) ? sanitize_textarea_field($_POST['description']) : $old_bio;
        if ($old_bio !== $new_bio) {
            $old_bio_preview = $old_bio ? (strlen($old_bio) > 50 ? substr($old_bio, 0, 50) . '...' : $old_bio) : '(empty)';
            $new_bio_preview = $new_bio ? (strlen($new_bio) > 50 ? substr($new_bio, 0, 50) . '...' : $new_bio) : '(empty)';
            $changes['bio'] = [
                'old' => $old_bio_preview,
                'new' => $new_bio_preview
            ];
        }
        
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
        
        if (isset($_POST['pass1']) && !empty($_POST['pass1'])) {
            $changes['password'] = 'Password changed';
        }
        
        if (count($changes) > 1) {
            Site_Logger::log(
                'user_updated',
                'user',
                $user_id,
                $user->user_login,
                $changes,
                'info'
            );
        }
    }
    
    public function log_user_register($user_id) {
        $user = get_user_by('id', $user_id);
        
        $profile_url = get_author_posts_url($user_id);
        $details = [
            'email' => $user->user_email,
            'role' => $this->get_role_label($user->roles[0] ?? 'subscriber'),
            'visit_user' => $profile_url ? "<a href='" . esc_url($profile_url) . "' target='_blank'>👤 Visit profile</a>" : ''
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
    
    public function log_password_reset($user, $new_pass) {
        $profile_url = get_author_posts_url($user->ID);
        $details = [
            'action' => 'Password reset via email',
            'user' => $user->user_login,
            'note' => 'Password changed via reset link',
            'visit_user' => $profile_url ? "<a href='" . esc_url($profile_url) . "' target='_blank'>👤 Visit profile</a>" : ''
        ];
        
        Site_Logger::log(
            'password_reset',
            'user',
            $user->ID,
            $user->user_login,
            $details,
            'warning'
        );
    }
    
    public function log_password_change($user, $new_pass) {
        $profile_url = get_author_posts_url($user->ID);
        $details = [
            'action' => 'Password changed',
            'user' => $user->user_login,
            'note' => 'Password updated manually',
            'visit_user' => $profile_url ? "<a href='" . esc_url($profile_url) . "' target='_blank'>👤 Visit profile</a>" : ''
        ];
        
        Site_Logger::log(
            'password_changed',
            'user',
            $user->ID,
            $user->user_login,
            $details,
            'warning'
        );
    }
    
    public function log_user_role_change($user_id, $new_role, $old_roles) {
        $user = get_user_by('id', $user_id);
        if ($user) {
            $profile_url = get_author_posts_url($user_id);
            $details = [
                'old_roles' => implode(', ', array_map([$this, 'get_role_label'], $old_roles)),
                'new_role' => $this->get_role_label($new_role),
                'visit_user' => $profile_url ? "<a href='" . esc_url($profile_url) . "' target='_blank'>👤 Visit profile</a>" : ''
            ];
            
            Site_Logger::log(
                'user_role_changed',
                'user',
                $user_id,
                $user->user_login,
                $details,
                'warning'
            );
        }
    }
    
    public function log_password_reset_request($user_login) {
        $user = get_user_by('login', $user_login) ?: get_user_by('email', $user_login);
        if ($user) {
            $profile_url = get_author_posts_url($user->ID);
            $details = [
                'action' => 'Password reset requested',
                'user' => $user->user_login,
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'Unknown',
                'visit_user' => $profile_url ? "<a href='" . esc_url($profile_url) . "' target='_blank'>👤 Visit profile</a>" : ''
            ];
            
            Site_Logger::log(
                'password_reset_requested',
                'user',
                $user->ID,
                $user->user_login,
                $details,
                'notice'
            );
        }
    }
    
    public function log_user_login($user_login, $user) {
        $last_login = get_user_meta($user->ID, 'last_login', true);
        $current_time = current_time('mysql');
        update_user_meta($user->ID, 'last_login', $current_time);
        
        $profile_url = get_author_posts_url($user->ID);
        $details = [
            'role' => $this->get_role_label($user->roles[0] ?? 'none'),
            'last_login' => $last_login ? human_time_diff(strtotime($last_login)) . ' ago' : 'First login',
            'visit_user' => $profile_url ? "<a href='" . esc_url($profile_url) . "' target='_blank'>👤 Visit profile</a>" : ''
        ];
        
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
    
    public function log_user_logout() {
        $user = wp_get_current_user();
        if ($user->ID) {
            $profile_url = get_author_posts_url($user->ID);
            Site_Logger::log(
                'user_logout',
                'user',
                $user->ID,
                $user->user_login,
                [
                    'session_ended' => 'User logged out',
                    'visit_user' => $profile_url ? "<a href='" . esc_url($profile_url) . "' target='_blank'>👤 Visit profile</a>" : ''
                ],
                'info'
            );
        }
    }
    
    public function log_user_meta_update($meta_id, $user_id, $meta_key, $meta_value) {
        if (strpos($meta_key, '_') === 0) return;
        
        $user = get_user_by('id', $user_id);
        if (!$user) return;
        
        $old_value = get_user_meta($user_id, $meta_key, true);
        
        if ($this->values_differ($old_value, $meta_value)) {
            $profile_url = get_author_posts_url($user_id);
            $details = [
                'field' => $meta_key,
                'old' => $this->format_field_value($old_value),
                'new' => $this->format_field_value($meta_value),
                'visit_user' => $profile_url ? "<a href='" . esc_url($profile_url) . "' target='_blank'>👤 Visit profile</a>" : ''
            ];
            
            Site_Logger::log(
                'user_meta_updated',
                'user',
                $user_id,
                $user->display_name,
                $details,
                'info'
            );
        }
    }
    
    public function log_user_meta_add($meta_id, $user_id, $meta_key, $meta_value) {
        if (strpos($meta_key, '_') === 0) return;
        
        $user = get_user_by('id', $user_id);
        if (!$user) return;
        
        $profile_url = get_author_posts_url($user_id);
        $details = [
            'field' => $meta_key,
            'value' => $this->format_field_value($meta_value),
            'visit_user' => $profile_url ? "<a href='" . esc_url($profile_url) . "' target='_blank'>👤 Visit profile</a>" : ''
        ];
        
        Site_Logger::log(
            'user_meta_added',
            'user',
            $user_id,
            $user->display_name,
            $details,
            'info'
        );
    }
    
    public function log_user_meta_delete($meta_ids, $user_id, $meta_key, $meta_value) {
        if (strpos($meta_key, '_') === 0) return;
        
        $user = get_user_by('id', $user_id);
        if (!$user) return;
        
        $profile_url = get_author_posts_url($user_id);
        $details = [
            'field' => $meta_key,
            'value' => $this->format_field_value($meta_value),
            'visit_user' => $profile_url ? "<a href='" . esc_url($profile_url) . "' target='_blank'>👤 Visit profile</a>" : ''
        ];
        
        Site_Logger::log(
            'user_meta_deleted',
            'user',
            $user_id,
            $user->display_name,
            $details,
            'warning'
        );
    }
    
    public function log_plugin_activation($plugin, $network_wide) {
        $plugin_name = $this->get_plugin_name($plugin);
        $plugin_data = get_plugin_data(WP_PLUGIN_DIR . '/' . $plugin);
        
        $author = $plugin_data['Author'] ?? 'Unknown';
        $author = strip_tags($author);
        
        $plugin_url = $plugin_data['PluginURI'] ?? '';
        
        $details = [
            'plugin' => $plugin_name,
            'version' => $plugin_data['Version'] ?? 'N/A',
            'author' => $author,
            'description' => substr($plugin_data['Description'] ?? '', 0, 100) . (strlen($plugin_data['Description'] ?? '') > 100 ? '...' : ''),
            'network_wide' => $network_wide ? 'Yes (network)' : 'No (single site)'
        ];
        
        if ($plugin_url) {
            $details['plugin_details'] = "<a href='" . esc_url($plugin_url) . "' target='_blank'>🔗 Plugin details</a>";
        }
        
        Site_Logger::log(
            'plugin_activated',
            'plugin',
            0,
            $plugin_name,
            $details,
            'warning'
        );
        
        if (isset(self::$old_option_values['active_plugins'])) {
            unset(self::$old_option_values['active_plugins']);
        }
    }
    
    public function log_plugin_deactivation($plugin, $network_wide) {
        $plugin_name = $this->get_plugin_name($plugin);
        
        $plugin_data = get_plugin_data(WP_PLUGIN_DIR . '/' . $plugin);
        $plugin_url = $plugin_data['PluginURI'] ?? '';
        
        $details = [
            'plugin' => $plugin_name,
            'version' => $plugin_data['Version'] ?? 'N/A',
            'network_wide' => $network_wide ? 'Yes (network)' : 'No (single site)'
        ];
        
        if ($plugin_url) {
            $details['plugin_details'] = "<a href='" . esc_url($plugin_url) . "' target='_blank'>🔗 Plugin details</a>";
        }
        
        Site_Logger::log(
            'plugin_deactivated',
            'plugin',
            0,
            $plugin_name,
            $details,
            'warning'
        );
        
        if (isset(self::$old_option_values['active_plugins'])) {
            unset(self::$old_option_values['active_plugins']);
        }
    }
    
    
    public function log_plugin_delete($plugin_file) {
    // Get plugin header data (file still exists here)
    $plugin_data = get_plugin_data(WP_PLUGIN_DIR . '/' . $plugin_file);

    $plugin_name = $plugin_data['Name'] ?? $this->get_plugin_name($plugin_file);
    $plugin_url  = $plugin_data['PluginURI'] ?? '';

    $details = [
        'plugin'  => $plugin_name,
        'version' => $plugin_data['Version'] ?? 'N/A',
        'author'  => $plugin_data['Author'] ?? '',
        'action'  => 'Plugin deleted'
    ];

    if ($plugin_url) {
        $details['plugin_details'] =
            "<a href='" . esc_url($plugin_url) . "' target='_blank'>🔗 Plugin details</a>";
    }

    Site_Logger::log(
        'plugin_deleted',
        'plugin',
        0,
        $plugin_name,
        $details,
        'warning'
    );
}
    
    public function log_theme_switch($new_name, $new_theme, $old_theme) {
        $new_theme_url = $new_theme->get('ThemeURI') ?? '';
        $old_theme_url = $old_theme ? ($old_theme->get('ThemeURI') ?? '') : '';
        
        $details = [
            'action' => 'Theme switched',
            'old_theme' => $old_theme ? $old_theme->name : 'None',
            'new_theme' => $new_name,
            'new_version' => $new_theme->get('Version') ?? 'N/A',
            'old_version' => $old_theme ? ($old_theme->get('Version') ?? 'N/A') : 'N/A',
            'new_author' => $new_theme->get('Author') ?? 'Unknown',
            'old_author' => $old_theme ? ($old_theme->get('Author') ?? 'Unknown') : 'Unknown'
        ];
        
        if ($new_theme_url) {
            $details['new_theme_details'] = "<a href='" . esc_url($new_theme_url) . "' target='_blank'>🔗 New theme details</a>";
        }
        
        Site_Logger::log(
            'theme_switched',
            'theme',
            0,
            $new_name,
            $details,
            'warning'
        );
    }
    
    public function log_option_update($option_name, $old_value, $value) {
        if (Site_Logger::should_skip_option($option_name)) {
            return;
        }
        
        if ($option_name === 'active_plugins' && (isset(self::$old_option_values['active_plugins']))) {
            return;
        }
        
        $sensitive_options = ['admin_email', 'auth_key', 'logged_in_key', 'secret', 'user_pass'];
        foreach ($sensitive_options as $sensitive) {
            if (strpos($option_name, $sensitive) !== false) {
                return;
            }
        }
        
        if (isset(self::$old_option_values[$option_name])) {
            $old_value = self::$old_option_values[$option_name];
            unset(self::$old_option_values[$option_name]);
        }
        
        if ($old_value === $value) {
            return;
        }
        
        $severity = 'info';
        $important_options = [
            'siteurl', 'home', 'blogname', 'blogdescription', 
            'users_can_register', 'default_role', 'permalink_structure',
            'WPLANG', 'timezone_string', 'page_on_front', 'page_for_posts',
            'show_on_front', 'posts_per_page', 'posts_per_rss', 'rss_use_excerpt',
            'blog_public', 'default_ping_status', 'default_comment_status',
            'comment_moderation', 'comment_whitelist', 'comment_registration',
            'close_comments_for_old_posts', 'close_comments_days_old',
            'thread_comments', 'thread_comments_depth', 'page_comments',
            'comments_per_page', 'default_comments_page', 'comment_order',
            'comment_max_links', 'moderation_keys', 'blacklist_keys',
            'show_avatars', 'avatar_rating', 'avatar_default'
        ];
        
        if (in_array($option_name, $important_options)) {
            $severity = 'warning';
        }
        
        $changes = $this->get_option_changes($option_name, $old_value, $value);
        
        if (empty($changes)) {
            return;
        }
        
        $settings_page = $this->get_settings_page_link($option_name);
        if ($settings_page) {
            $changes['settings_page'] = $settings_page;
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
    
    private function get_settings_page_link($option_name) {
        $pages = [
            'blogname' => 'options-general.php',
            'blogdescription' => 'options-general.php',
            'siteurl' => 'options-general.php',
            'home' => 'options-general.php',
            'admin_email' => 'options-general.php',
            'users_can_register' => 'options-general.php',
            'default_role' => 'options-general.php',
            'timezone_string' => 'options-general.php',
            'date_format' => 'options-general.php',
            'time_format' => 'options-general.php',
            'start_of_week' => 'options-general.php',
            'WPLANG' => 'options-general.php',
            'permalink_structure' => 'options-permalink.php',
            'category_base' => 'options-permalink.php',
            'tag_base' => 'options-permalink.php',
            'page_on_front' => 'options-reading.php',
            'page_for_posts' => 'options-reading.php',
            'show_on_front' => 'options-reading.php',
            'posts_per_page' => 'options-reading.php',
            'posts_per_rss' => 'options-reading.php',
            'rss_use_excerpt' => 'options-reading.php',
            'blog_public' => 'options-reading.php',
            'default_ping_status' => 'options-discussion.php',
            'default_comment_status' => 'options-discussion.php',
            'comment_moderation' => 'options-discussion.php',
            'comment_whitelist' => 'options-discussion.php',
            'comment_registration' => 'options-discussion.php',
            'close_comments_for_old_posts' => 'options-discussion.php',
            'close_comments_days_old' => 'options-discussion.php',
            'thread_comments' => 'options-discussion.php',
            'thread_comments_depth' => 'options-discussion.php',
            'page_comments' => 'options-discussion.php',
            'comments_per_page' => 'options-discussion.php',
            'default_comments_page' => 'options-discussion.php',
            'comment_order' => 'options-discussion.php',
            'comment_max_links' => 'options-discussion.php',
            'moderation_keys' => 'options-discussion.php',
            'blacklist_keys' => 'options-discussion.php',
            'show_avatars' => 'options-discussion.php',
            'avatar_rating' => 'options-discussion.php',
            'avatar_default' => 'options-discussion.php',
        ];
        
        if (isset($pages[$option_name])) {
            $url = admin_url($pages[$option_name]);
            return "<a href='" . esc_url($url) . "' target='_blank'>⚙️ Go to settings</a>";
        }
        
        return false;
    }
    
    private function get_option_changes($option_name, $old_value, $new_value) {
        $changes = [];
        
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
                
            case 'page_on_front':
                $old_page = $old_value ? get_the_title($old_value) : '(none)';
                $new_page = $new_value ? get_the_title($new_value) : '(none)';
                $changes['front_page'] = [
                    'old' => $old_page,
                    'new' => $new_page
                ];
                break;
                
            case 'page_for_posts':
                $old_page = $old_value ? get_the_title($old_value) : '(none)';
                $new_page = $new_value ? get_the_title($new_value) : '(none)';
                $changes['posts_page'] = [
                    'old' => $old_page,
                    'new' => $new_page
                ];
                break;
                
            case 'show_on_front':
                $changes['front_page_display'] = [
                    'old' => $old_value === 'page' ? 'Static Page' : 'Latest Posts',
                    'new' => $new_value === 'page' ? 'Static Page' : 'Latest Posts'
                ];
                break;
                
            case 'posts_per_page':
                $changes['posts_per_page'] = [
                    'old' => $old_value,
                    'new' => $new_value
                ];
                break;
                
            case 'posts_per_rss':
                $changes['rss_items'] = [
                    'old' => $old_value,
                    'new' => $new_value
                ];
                break;
                
            case 'rss_use_excerpt':
                $changes['rss_display'] = [
                    'old' => $old_value ? 'Summary' : 'Full Text',
                    'new' => $new_value ? 'Summary' : 'Full Text'
                ];
                break;
                
            case 'blog_public':
                $changes['search_engine_visibility'] = [
                    'old' => $old_value ? 'Visible' : 'Hidden',
                    'new' => $new_value ? 'Visible' : 'Hidden'
                ];
                break;
                
            default:
                if (is_scalar($old_value) && is_scalar($new_value)) {
                    $old_str = (string)$old_value;
                    $new_str = (string)$new_value;
                    
                    if (strlen($old_str) > 50 || strlen($new_str) > 50) {
                        $changes['value'] = 'Value changed';
                    } else {
                        $changes['value'] = [
                            'old' => $old_str ?: '(empty)',
                            'new' => $new_str ?: '(empty)'
                        ];
                    }
                } elseif (is_array($old_value) && is_array($new_value)) {
                    $added = $this->array_diff_recursive($new_value, $old_value);
                    $removed = $this->array_diff_recursive($old_value, $new_value);
                    
                    if (!empty($added) || !empty($removed)) {
                        $changes['array_changes'] = [];
                        
                        if (!empty($added)) {
                            $added_strings = $this->array_to_strings($added);
                            if (!empty($added_strings)) {
                                $changes['array_changes']['added'] = $added_strings;
                            }
                        }
                        
                        if (!empty($removed)) {
                            $removed_strings = $this->array_to_strings($removed);
                            if (!empty($removed_strings)) {
                                $changes['array_changes']['removed'] = $removed_strings;
                            }
                        }
                    }
                } else {
                    $changes['value'] = 'Complex value changed';
                }
        }
        
        return $changes;
    }
    
    private function array_diff_recursive($array1, $array2) {
        $result = [];
        
        foreach ($array1 as $key => $value) {
            if (!array_key_exists($key, $array2)) {
                $result[$key] = $value;
            } elseif (is_array($value) && is_array($array2[$key])) {
                $diff = $this->array_diff_recursive($value, $array2[$key]);
                if (!empty($diff)) {
                    $result[$key] = $diff;
                }
            } elseif ($value != $array2[$key]) {
                $result[$key] = $value;
            }
        }
        
        return $result;
    }
    
    private function array_to_strings($array) {
        $strings = [];
        
        foreach ($array as $key => $value) {
            if (is_array($value)) {
                $strings[] = $key . ': [' . implode(', ', $this->array_to_strings($value)) . ']';
            } else {
                $strings[] = $key . ': ' . (string)$value;
            }
        }
        
        return $strings;
    }
    
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
            'page_on_front' => 'Front Page',
            'page_for_posts' => 'Posts Page',
            'show_on_front' => 'Front Page Display',
            'posts_per_page' => 'Blog Pages Show',
            'posts_per_rss' => 'RSS Feed Items',
            'rss_use_excerpt' => 'RSS Feed Excerpt',
            'blog_public' => 'Search Engine Visibility',
            'default_ping_status' => 'Default Ping Status',
            'default_comment_status' => 'Default Comment Status',
            'comment_moderation' => 'Comment Moderation',
            'comment_whitelist' => 'Comment Whitelist',
            'comment_registration' => 'User Registration Required',
            'close_comments_for_old_posts' => 'Close Comments',
            'close_comments_days_old' => 'Days to Close Comments',
            'thread_comments' => 'Threaded Comments',
            'thread_comments_depth' => 'Thread Comments Depth',
            'page_comments' => 'Break Comments into Pages',
            'comments_per_page' => 'Comments per Page',
            'default_comments_page' => 'Default Comments Page',
            'comment_order' => 'Comments Order',
            'comment_max_links' => 'Comment Max Links',
            'moderation_keys' => 'Comment Moderation Keys',
            'blacklist_keys' => 'Comment Blacklist Keys',
            'show_avatars' => 'Show Avatars',
            'avatar_rating' => 'Avatar Rating',
            'avatar_default' => 'Default Avatar',
        ];
        
        return $labels[$option_name] ?? ucwords(str_replace('_', ' ', $option_name));
    }
    
    public function log_widget_update($option_name, $old_value, $value) {
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
    
    public function log_media_add($attachment_id) {
        $attachment = get_post($attachment_id);
        $file = get_attached_file($attachment_id);
        $filesize = file_exists($file) ? size_format(filesize($file)) : 'Unknown';
        $file_url = wp_get_attachment_url($attachment_id);
        
        $details = [
            'type' => $attachment->post_mime_type,
            'filename' => basename($file),
            'size' => $filesize,
            'view_media' => "<a href='" . esc_url($file_url) . "' target='_blank'>🖼️ View media</a>",
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
    
    public function log_media_edit($attachment_id) {
        $attachment = get_post($attachment_id);
        $file_url = wp_get_attachment_url($attachment_id);
        
        $details = [
            'view_media' => $file_url ? "<a href='" . esc_url($file_url) . "' target='_blank'>🖼️ View media</a>" : ''
        ];
        
        Site_Logger::log(
            'media_edited',
            'attachment',
            $attachment_id,
            $attachment->post_title,
            $details,
            'info'
        );
    }
    
    public function log_media_delete($attachment_id) {
        $attachment = get_post($attachment_id);
        if ($attachment) {
            $details = [
                'media_title' => $attachment->post_title,
                'media_type' => $attachment->post_mime_type
            ];
            
            Site_Logger::log(
                'media_deleted',
                'attachment',
                $attachment_id,
                $attachment->post_title,
                $details,
                'warning'
            );
        } else {
            Site_Logger::log(
                'media_deleted',
                'attachment',
                $attachment_id,
                'Attachment #' . $attachment_id,
                [],
                'warning'
            );
        }
    }
    
    public function log_import_start() {
        $importer = isset($_REQUEST['import']) ? sanitize_text_field($_REQUEST['import']) : 'unknown';
        $file = isset($_FILES['import']['name']) ? sanitize_file_name($_FILES['import']['name']) : 'unknown';
        
        $details = [
            'type' => ucfirst(str_replace('_', ' ', $importer)),
            'file' => $file,
            'action' => 'Import started'
        ];
        
        Site_Logger::log(
            'import_started',
            'tool',
            0,
            'Content Import',
            $details,
            'info'
        );
    }
    
    public function log_import_end() {
        $importer = isset($_REQUEST['import']) ? sanitize_text_field($_REQUEST['import']) : 'unknown';
        
        $details = [
            'type' => ucfirst(str_replace('_', ' ', $importer)),
            'action' => 'Import completed'
        ];
        
        Site_Logger::log(
            'import_completed',
            'tool',
            0,
            'Content Import',
            $details,
            'info'
        );
    }
    
    public function log_export_start($args) {
        $content = isset($args['content']) ? $args['content'] : 'all';
        $author = isset($args['author']) ? $args['author'] : 'all';
        
        $content_types = [
            'all' => 'All content',
            'post' => 'Posts',
            'page' => 'Pages',
            'attachment' => 'Media',
            'custom_post_type' => 'Custom Post Type'
        ];
        
        $details = [
            'content_type' => $content_types[$content] ?? ucfirst($content),
            'author' => $author === 'all' ? 'All authors' : 'Author ID: ' . $author,
            'status' => isset($args['status']) ? $args['status'] : 'any',
            'start_date' => isset($args['start_date']) ? $args['start_date'] : 'any',
            'end_date' => isset($args['end_date']) ? $args['end_date'] : 'any',
            'category' => isset($args['category']) ? $args['category'] : 'all',
            'taxonomy' => isset($args['taxonomy']) ? $args['taxonomy'] : 'all'
        ];
        
        Site_Logger::log(
            'export_started',
            'tool',
            0,
            'Content Export',
            $details,
            'info'
        );
    }
    
    public function log_featured_image_add($meta_id, $post_id, $meta_key, $meta_value) {
        if ($meta_key === '_thumbnail_id' && $meta_value) {
            $post = get_post($post_id);
            if ($post && $post->post_type !== 'attachment') {
                $image = get_post($meta_value);
                $image_name = $image ? $image->post_title : 'ID ' . $meta_value;
                $image_url = wp_get_attachment_url($meta_value);
                
                $edit_url = get_edit_post_link($post_id);
                $view_url = get_permalink($post_id);
                
                $details = [
                    'action' => 'Featured image added',
                    'image' => $image_name,
                    'image_id' => $meta_value,
                    'view_image' => $image_url ? "<a href='" . esc_url($image_url) . "' target='_blank'>🖼️ View image</a>" : ''
                ];
                
                if ($edit_url) {
                    $details['edit_post'] = "<a href='" . esc_url($edit_url) . "' target='_blank'>✏️ Edit post</a>";
                }
                if ($view_url) {
                    $details['view_post'] = "<a href='" . esc_url($view_url) . "' target='_blank'>👁️ View post</a>";
                }
                
                Site_Logger::log(
                    'featured_image_added',
                    'post',
                    $post_id,
                    $post->post_title,
                    $details,
                    'info'
                );
            }
        }
    }
    
    public function log_featured_image_update($meta_id, $post_id, $meta_key, $meta_value) {
        if ($meta_key === '_thumbnail_id') {
            $post = get_post($post_id);
            if ($post && $post->post_type !== 'attachment') {
                $old_image_id = get_post_thumbnail_id($post_id);
                $new_image_id = $meta_value;
                
                if ($old_image_id != $new_image_id) {
                    $old_image = $old_image_id ? get_post($old_image_id) : null;
                    $new_image = $new_image_id ? get_post($new_image_id) : null;
                    
                    $edit_url = get_edit_post_link($post_id);
                    $view_url = get_permalink($post_id);
                    $new_image_url = $new_image_id ? wp_get_attachment_url($new_image_id) : '';
                    
                    if ($old_image_id && $new_image_id) {
                        $details = [
                            'action' => 'Featured image changed',
                            'old_image' => $old_image ? $old_image->post_title : 'ID ' . $old_image_id,
                            'new_image' => $new_image ? $new_image->post_title : 'ID ' . $new_image_id,
                            'view_image' => $new_image_url ? "<a href='" . esc_url($new_image_url) . "' target='_blank'>🖼️ View new image</a>" : ''
                        ];
                        
                        if ($edit_url) {
                            $details['edit_post'] = "<a href='" . esc_url($edit_url) . "' target='_blank'>✏️ Edit post</a>";
                        }
                        if ($view_url) {
                            $details['view_post'] = "<a href='" . esc_url($view_url) . "' target='_blank'>👁️ View post</a>";
                        }
                        
                        Site_Logger::log(
                            'featured_image_changed',
                            'post',
                            $post_id,
                            $post->post_title,
                            $details,
                            'info'
                        );
                    } elseif ($new_image_id) {
                        $details = [
                            'action' => 'Featured image added',
                            'image' => $new_image ? $new_image->post_title : 'ID ' . $new_image_id,
                            'view_image' => $new_image_url ? "<a href='" . esc_url($new_image_url) . "' target='_blank'>🖼️ View image</a>" : ''
                        ];
                        
                        if ($edit_url) {
                            $details['edit_post'] = "<a href='" . esc_url($edit_url) . "' target='_blank'>✏️ Edit post</a>";
                        }
                        if ($view_url) {
                            $details['view_post'] = "<a href='" . esc_url($view_url) . "' target='_blank'>👁️ View post</a>";
                        }
                        
                        Site_Logger::log(
                            'featured_image_added',
                            'post',
                            $post_id,
                            $post->post_title,
                            $details,
                            'info'
                        );
                    }
                }
            }
        }
    }
    
    public function log_featured_image_delete($meta_ids, $post_id, $meta_key, $meta_value) {
        if ($meta_key === '_thumbnail_id') {
            $post = get_post($post_id);
            if ($post && $post->post_type !== 'attachment') {
                $old_image_id = $meta_value;
                $old_image = $old_image_id ? get_post($old_image_id) : null;
                
                $edit_url = get_edit_post_link($post_id);
                $view_url = get_permalink($post_id);
                
                $details = [
                    'action' => 'Featured image removed',
                    'image' => $old_image ? $old_image->post_title : 'ID ' . $old_image_id
                ];
                
                if ($edit_url) {
                    $details['edit_post'] = "<a href='" . esc_url($edit_url) . "' target='_blank'>✏️ Edit post</a>";
                }
                if ($view_url) {
                    $details['view_post'] = "<a href='" . esc_url($view_url) . "' target='_blank'>👁️ View post</a>";
                }
                
                Site_Logger::log(
                    'featured_image_removed',
                    'post',
                    $post_id,
                    $post->post_title,
                    $details,
                    'warning'
                );
            }
        }
    }
    
    private function format_field_value($value, $field_type = 'text') {
        if (is_null($value) || $value === '') {
            return '(empty)';
        }
        
        switch ($field_type) {
            case 'image':
            case 'file':
                if (is_numeric($value)) {
                    $file = get_post($value);
                    return $file ? $file->post_title : 'File #' . $value;
                }
                return basename($value);
                
            case 'relationship':
            case 'post_object':
                if (is_array($value)) {
                    $titles = [];
                    foreach ($value as $post_id) {
                        $post = get_post($post_id);
                        $titles[] = $post ? $post->post_title : 'Post #' . $post_id;
                    }
                    return implode(', ', $titles);
                }
                $post = get_post($value);
                return $post ? $post->post_title : 'Post #' . $value;
                
            case 'user':
                if (is_array($value)) {
                    $names = [];
                    foreach ($value as $user_id) {
                        $user = get_user_by('id', $user_id);
                        $names[] = $user ? $user->display_name : 'User #' . $user_id;
                    }
                    return implode(', ', $names);
                }
                $user = get_user_by('id', $value);
                return $user ? $user->display_name : 'User #' . $value;
                
            case 'true_false':
                return $value ? 'Yes' : 'No';
                
            case 'select':
            case 'checkbox':
            case 'radio':
                if (is_array($value)) {
                    return implode(', ', $value);
                }
                return $value;
                
            case 'wysiwyg':
            case 'textarea':
                return substr(strip_tags($value), 0, 100) . (strlen(strip_tags($value)) > 100 ? '...' : '');
                
            default:
                if (is_array($value)) {
                    return json_encode($value);
                }
                if (is_object($value)) {
                    return get_class($value);
                }
                return substr((string)$value, 0, 100) . (strlen((string)$value) > 100 ? '...' : '');
        }
    }
    
    private function values_differ($old_value, $new_value) {
        if ($old_value === $new_value) {
            return false;
        }
        
        if (is_serialized($old_value) || is_serialized($new_value)) {
            $old_unserialized = maybe_unserialize($old_value);
            $new_unserialized = maybe_unserialize($new_value);
            
            if (is_array($old_unserialized) && is_array($new_unserialized)) {
                return serialize($old_unserialized) !== serialize($new_unserialized);
            }
            
            return $old_unserialized !== $new_unserialized;
        }
        
        if (is_array($old_value) && is_array($new_value)) {
            return serialize($old_value) !== serialize($new_value);
        }
        
        if (is_object($old_value) && is_object($new_value)) {
            return $old_value != $new_value;
        }
        
        if (($old_value === null || $old_value === '') && ($new_value === null || $new_value === '')) {
            return false;
        }
        
        return true;
    }
    
    private function get_role_label($role) {
        $wp_roles = wp_roles();
        return $wp_roles->roles[$role]['name'] ?? ucfirst($role);
    }
    
    private function get_plugin_name($plugin_file) {
        if (!function_exists('get_plugin_data')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        
        $plugin_data = get_plugin_data(WP_PLUGIN_DIR . '/' . $plugin_file);
        return $plugin_data['Name'] ?? basename($plugin_file);
    }
    
    private function get_term_names_from_input($terms_input, $taxonomy) {
        $term_names = [];
        
        if (is_array($terms_input)) {
            foreach ($terms_input as $term) {
                if (is_numeric($term)) {
                    $term_obj = get_term($term, $taxonomy);
                    if ($term_obj && !is_wp_error($term_obj)) {
                        $term_names[] = $term_obj->name;
                    }
                } elseif (!empty($term)) {
                    $term_names[] = trim($term);
                }
            }
        }
        
        return $term_names;
    }
    
    private function get_term_names_from_ids($term_ids, $taxonomy) {
        $term_names = [];
        
        foreach ($term_ids as $term_id) {
            $term = get_term($term_id, $taxonomy);
            if ($term && !is_wp_error($term)) {
                $term_names[] = $term->name;
            }
        }
        
        return $term_names;
    }
    
}