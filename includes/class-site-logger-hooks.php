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
    add_action('load-edit-tags.php', [$this, 'check_bulk_operation_taxonomy']);
    
    // Store old post data
    add_action('wp_ajax_inline-save', [$this, 'store_old_post_data_ajax'], 1);
    add_filter('wp_insert_post_data', [$this, 'store_old_post_data'], 10, 2);
    
    // Store old meta data
    add_action('save_post', [$this, 'store_old_meta_data'], 5, 2);
    
    // Store old taxonomy data - ENHANCED with more hooks
    add_action('pre_post_update', [$this, 'store_old_taxonomy_data']);
    add_action('wp_ajax_add-tag', [$this, 'store_old_taxonomy_data_ajax'], 1);
    add_action('wp_ajax_inline-save-tax', [$this, 'store_old_taxonomy_data_ajax'], 1);
    
    // Store old user data
    add_action('load-profile.php', [$this, 'store_old_user_data']);
    add_action('load-user-edit.php', [$this, 'store_old_user_data']);
    add_action('personal_options_update', [$this, 'store_old_user_data_for_update']);
    add_action('edit_user_profile_update', [$this, 'store_old_user_data_for_update']);
    
    // Store ACF field data
    if (function_exists('acf')) {
        add_action('acf/save_post', [$this, 'store_old_acf_data'], 5);
        // Store ACF field group data
        add_action('acf/update_field_group', [$this, 'store_old_acf_field_group_data'], 5);
    }
    
    // Posts and Pages
    add_action('save_post', [$this, 'log_post_save'], 20, 3);
    add_action('wp_ajax_inline-save', [$this, 'log_quick_edit_save'], 20);
    add_action('delete_post', [$this, 'log_post_delete'], 10, 1);
    add_action('wp_trash_post', [$this, 'log_post_trash'], 10, 1);
    add_action('untrash_post', [$this, 'log_post_untrash'], 10, 2);
    
    // Post revisions
    add_action('_wp_put_post_revision', [$this, 'log_post_revision'], 10, 2);
    
    // Post meta changes
    add_action('updated_post_meta', [$this, 'log_post_meta_update'], 10, 4);
    add_action('added_post_meta', [$this, 'log_post_meta_add'], 10, 4);
    add_action('deleted_post_meta', [$this, 'log_post_meta_delete'], 10, 4);
    
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
    
    // Comment status changes
    add_action('transition_comment_status', [$this, 'log_comment_status_change'], 10, 3);
    
    // Media
    add_action('add_attachment', [$this, 'log_media_add']);
    add_action('edit_attachment', [$this, 'log_media_edit'], 10, 2);
    add_action('delete_attachment', [$this, 'log_media_delete'], 10, 1);
    
    // Featured image hooks
    add_action('added_post_meta', [$this, 'log_featured_image_add'], 10, 4);
    add_action('updated_post_meta', [$this, 'log_featured_image_update'], 10, 4);
    add_action('deleted_post_meta', [$this, 'log_featured_image_delete'], 10, 4);
    
    // Taxonomy terms - ENHANCED with better hooks
    add_action('created_term', [$this, 'log_term_created'], 10, 3);
    add_action('edited_term', [$this, 'log_term_updated'], 10, 3);
    add_action('delete_term', [$this, 'log_term_deleted'], 10, 4);
    add_action('set_object_terms', [$this, 'log_object_terms_change'], 10, 6);
    
    // Term meta changes - NEW hooks for term meta
    add_action('updated_term_meta', [$this, 'log_term_meta_update'], 10, 4);
    add_action('added_term_meta', [$this, 'log_term_meta_add'], 10, 4);
    add_action('deleted_term_meta', [$this, 'log_term_meta_delete'], 10, 4);
    
    // Widgets
    add_action('updated_option', [$this, 'log_widget_update'], 10, 3);
    
    // Import/Export Tools
    add_action('import_start', [$this, 'log_import_start']);
    add_action('import_end', [$this, 'log_import_end']);
    add_action('export_wp', [$this, 'log_export_start']);
    
    // Store old slug before update
    add_filter('wp_insert_post_data', [$this, 'store_old_slug_data'], 5, 2);
    
    // Store old template data
    add_filter('wp_insert_post_data', [$this, 'store_old_template_data'], 5, 2);
    
    // ACF field saves
    if (function_exists('acf')) {
        add_action('acf/save_post', [$this, 'log_acf_save'], 20);
        add_action('acf/update_field_group', [$this, 'log_acf_field_group_update'], 20, 1);
        add_action('acf/duplicate_field_group', [$this, 'log_acf_field_group_duplicate'], 10, 2);
        add_action('acf/delete_field_group', [$this, 'log_acf_field_group_delete'], 10, 1);
    }
    
    // Admin menu
    add_action('admin_menu', ['Site_Logger', 'add_admin_menu']);
    
    // Bulk actions
    add_action('handle_bulk_actions-edit-post', [$this, 'handle_bulk_action_post'], 10, 3);
    add_action('handle_bulk_actions-edit-page', [$this, 'handle_bulk_action_post'], 10, 3);
    add_action('handle_bulk_actions-edit-product', [$this, 'handle_bulk_action_post'], 10, 3);
    
    // Menu changes
    add_action('wp_update_nav_menu', [$this, 'log_menu_update'], 10, 2);
    add_action('wp_create_nav_menu', [$this, 'log_menu_created'], 10, 2);
    add_action('wp_delete_nav_menu', [$this, 'log_menu_deleted'], 10, 1);
    
    // Widget area updates
    add_action('update_option_sidebars_widgets', [$this, 'log_sidebar_widgets_update'], 10, 2);
    
    // Customizer changes
    add_action('customize_save_after', [$this, 'log_customizer_save']);
    
    // Security events
    add_action('wp_login_failed', [$this, 'log_login_failed'], 10, 1);
    
    // Performance optimization: Skip during WP-CLI
    if (defined('WP_CLI') && WP_CLI) {
        remove_action('save_post', [$this, 'log_post_save'], 20);
        remove_action('updated_post_meta', [$this, 'log_post_meta_update'], 10);
    }

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
                
                // Store all term meta
                $all_term_meta = get_term_meta($term_id);
                if ($all_term_meta) {
                    self::$old_taxonomy_data[$taxonomy][$term_id]['meta'] = [];
                    foreach ($all_term_meta as $meta_key => $meta_values) {
                        if (isset($meta_values[0])) {
                            self::$old_taxonomy_data[$taxonomy][$term_id]['meta'][$meta_key] = maybe_unserialize($meta_values[0]);
                        }
                    }
                }
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
            return;
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
    
    public function store_old_acf_field_group_data($field_group) {
        if (!isset(self::$old_acf_data['field_groups'])) {
            self::$old_acf_data['field_groups'] = [];
        }
        
        // Store the complete field group data
        self::$old_acf_data['field_groups'][$field_group['key']] = $field_group;
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
        
        if (in_array($post_id, self::$processed_posts)) {
            return;
        }
        
        self::$processed_posts[] = $post_id;
        
        $post = get_post($post_id);
        if (!$post) return;
        
        $details = [];
        $has_changes = false;
        
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
        
        if (isset($_POST['post_author'])) {
            $new_author = intval($_POST['post_author']);
            $old_author = isset(self::$old_post_data[$post_id]['author']) ? 
                         self::$old_post_data[$post_id]['author'] : $post->post_author;
            
            if ($old_author != $new_author) {
                $old_author_user = get_user_by('id', $old_author);
                $new_author_user = get_user_by('id', $new_author);
                $details['author'] = [
                    'old' => $old_author_user ? $old_author_user->display_name : 'User #' . $old_author,
                    'new' => $new_author_user ? $new_author_user->display_name : 'User #' . $new_author
                ];
                $has_changes = true;
            }
        }
        
        if (isset($_POST['comment_status'])) {
            $new_comment_status = sanitize_text_field($_POST['comment_status']);
            $old_comment_status = isset(self::$old_post_data[$post_id]['comment_status']) ? 
                                 self::$old_post_data[$post_id]['comment_status'] : $post->comment_status;
            
            if ($old_comment_status !== $new_comment_status) {
                $details['comment_status'] = [
                    'old' => $old_comment_status === 'open' ? 'Open' : 'Closed',
                    'new' => $new_comment_status === 'open' ? 'Open' : 'Closed'
                ];
                $has_changes = true;
            }
        }
        
        if (isset($_POST['ping_status'])) {
            $new_ping_status = sanitize_text_field($_POST['ping_status']);
            $old_ping_status = isset(self::$old_post_data[$post_id]['ping_status']) ? 
                              self::$old_post_data[$post_id]['ping_status'] : $post->ping_status;
            
            if ($old_ping_status !== $new_ping_status) {
                $details['ping_status'] = [
                    'old' => $old_ping_status === 'open' ? 'Open' : 'Closed',
                    'new' => $new_ping_status === 'open' ? 'Open' : 'Closed'
                ];
                $has_changes = true;
            }
        }
        
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
        
        if (isset(self::$old_taxonomy_data[$post_id])) {
            $post_type = $post->post_type;
            $taxonomies = get_object_taxonomies($post_type, 'names');
            
            foreach ($taxonomies as $taxonomy) {
                if (isset($_POST['tax_input'][$taxonomy])) {
                    $new_terms = $_POST['tax_input'][$taxonomy];
                    $old_terms = isset(self::$old_taxonomy_data[$post_id][$taxonomy]) ? 
                                self::$old_taxonomy_data[$post_id][$taxonomy] : [];
                    
                    if (is_array($new_terms)) {
                        $old_term_names = $this->get_term_names_from_ids($old_terms, $taxonomy);
                        $new_term_names = $this->get_term_names_from_input($new_terms, $taxonomy);
                        
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
        if (in_array($post_id, self::$processed_posts)) {
            return;
        }
        
        self::$processed_posts[] = $post_id;
        
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id) || $post->post_status === 'auto-draft') {
            return;
        }
        
        if ($post->post_status === 'trash' && $update) {
            return;
        }
        
        if ($post->post_type === 'acf-field-group' || $post->post_type === 'acf-field') {
            return;
        }
        
        remove_action('updated_post_meta', [$this, 'log_post_meta_update'], 10);
        remove_action('added_post_meta', [$this, 'log_post_meta_add'], 10);
        remove_action('deleted_post_meta', [$this, 'log_post_meta_delete'], 10);
        
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
            
            if (isset($old_data['title']) && $old_data['title'] !== $post->post_title) {
                $details['title'] = [
                    'old' => $old_data['title'],
                    'new' => $post->post_title
                ];
                $has_changes = true;
            }
            
            if (isset($old_data['content']) && $old_data['content'] !== $post->post_content) {
                $old_hash = md5(wp_strip_all_tags($old_data['content']));
                $new_hash = md5(wp_strip_all_tags($post->post_content));
                
                if ($old_hash !== $new_hash) {
                    $old_len = strlen(wp_strip_all_tags($old_data['content']));
                    $new_len = strlen(wp_strip_all_tags($post->post_content));
                    $diff = $new_len - $old_len;
                    
                    $details['content'] = [
                        'change' => 'Content updated',
                        'characters_changed' => ($diff >= 0 ? '+' : '') . $diff
                    ];
                    $has_changes = true;
                }
            }
            
            if (isset($old_data['slug']) && $old_data['slug'] !== $post->post_name) {
                $details['slug'] = [
                    'old' => $old_data['slug'],
                    'new' => $post->post_name
                ];
                $has_changes = true;
            }
            
            if (isset($old_data['status']) && $old_data['status'] !== $post->post_status) {
                $details['status'] = [
                    'old' => $this->get_post_status_label($old_data['status']),
                    'new' => $this->get_post_status_label($post->post_status)
                ];
                $has_changes = true;
            }
            
            if (isset($old_data['author']) && $old_data['author'] != $post->post_author) {
                $old_author = get_user_by('id', $old_data['author']);
                $new_author = get_user_by('id', $post->post_author);
                $details['author'] = [
                    'old' => $old_author ? $old_author->display_name : 'User #' . $old_data['author'],
                    'new' => $new_author ? $new_author->display_name : 'User #' . $post->post_author
                ];
                $has_changes = true;
            }
            
            if (!$has_changes) {
                $details['note'] = 'Post saved (no major changes detected)';
            }
            
            unset(self::$old_post_data[$post_id]);
            if (isset(self::$old_meta_data[$post_id])) {
                unset(self::$old_meta_data[$post_id]);
            }
            if (isset(self::$old_taxonomy_data[$post_id])) {
                unset(self::$old_taxonomy_data[$post_id]);
            }
            
        } else {
            $details['action'] = 'New post created';
            $details['status'] = $this->get_post_status_label($post->post_status);
            $details['post_type'] = $post->post_type;
            $details['author'] = get_user_by('id', $post->post_author)->display_name ?? 'Unknown';
        }
        
        $post_type_obj = get_post_type_object($post->post_type);
        if ($post_type_obj) {
            $details['post_type_label'] = $post_type_obj->labels->singular_name;
        }
        
        Site_Logger::log(
            $action,
            $post->post_type,
            $post_id,
            $post->post_title,
            $details,
            $severity
        );
        
        add_action('updated_post_meta', [$this, 'log_post_meta_update'], 10, 4);
        add_action('added_post_meta', [$this, 'log_post_meta_add'], 10, 4);
        add_action('deleted_post_meta', [$this, 'log_post_meta_delete'], 10, 4);
    }
    
    
    public function log_post_revision($revision_id, $original_id) {
        if (in_array($revision_id, self::$processed_posts)) {
            return;
        }
        
        self::$processed_posts[] = $revision_id;
        
        $revision = get_post($revision_id);
        $parent_post = get_post($original_id);
        
        if (!$parent_post || !$revision) return;
        
        $revisions_url = admin_url('revision.php?post=' . $original_id);
        
        $details = [
            'revision' => "Revision #{$revision_id} created",
            'parent_post' => $parent_post->post_title,
            'view_revisions' => "<a href='" . esc_url($revisions_url) . "' target='_blank'>📚 View post revision</a>",
        ];
        
        $edit_url = get_edit_post_link($parent_post->ID);
        if ($edit_url) {
            $details['edit_post'] = "<a href='" . esc_url($edit_url) . "' target='_blank'>✏️ Edit post</a>";
        }
        
        $view_url = get_permalink($parent_post->ID);
        if ($view_url) {
            $details['view_post'] = "<a href='" . esc_url($view_url) . "' target='_blank'>👁️ View post</a>";
        }
        
        Site_Logger::log(
            'revision_created',
            'revision',
            $revision_id,
            "Revision for: " . $parent_post->post_title,
            $details,
            'info'
        );
    }
    
    public function log_post_delete($post_id) {
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
            $details['view_post'] = "<a href='" . esc_url($view_url) . "' target='_blank'>👁️ View post</a>";
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
            'view_post' => get_permalink($post_id) ? "<a href='" . esc_url(get_permalink($post_id)) . "' target='_blank'>👁️ View post</a>" : ''
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
        
        if (isset($_REQUEST['_status']) && $_REQUEST['_status'] != -1) {
            $new_status = sanitize_text_field($_REQUEST['_status']);
            $changes['status'] = [
                'new' => $this->get_post_status_label($new_status)
            ];
        }
        
        $first_post_type = get_post_type($post_ids[0]);
        $taxonomies = get_object_taxonomies($first_post_type, 'names');
        
        foreach ($taxonomies as $taxonomy) {
            $taxonomy_key = 'tax_input[' . $taxonomy . ']';
            
            if (isset($_REQUEST[$taxonomy]) && $_REQUEST[$taxonomy] != -1) {
                $action = sanitize_text_field($_REQUEST[$taxonomy]);
                
                if ($action === '-1') continue;
                
                $taxonomy_obj = get_taxonomy($taxonomy);
                $tax_name = $taxonomy_obj->labels->name ?? $taxonomy;
                
                if ($action === 'add' && isset($_REQUEST['new' . $taxonomy])) {
                    $new_terms = explode(',', sanitize_text_field($_REQUEST['new' . $taxonomy]));
                    $term_names = [];
                    
                    foreach ($new_terms as $term_name) {
                        $term_name = trim($term_name);
                        if (!empty($term_name)) {
                            $term_names[] = $term_name;
                        }
                    }
                    
                    if (!empty($term_names)) {
                        $changes[$tax_name] = [
                            'action' => 'Add terms',
                            'terms' => implode(', ', $term_names)
                        ];
                    }
                    
                } elseif (is_numeric($action)) {
                    $term_id = intval($action);
                    $term = get_term($term_id, $taxonomy);
                    if ($term && !is_wp_error($term)) {
                        $changes[$tax_name] = [
                            'action' => 'Set term',
                            'term' => $term->name
                        ];
                    }
                }
            }
            
            if (isset($_REQUEST[$taxonomy . '_add']) && is_array($_REQUEST[$taxonomy . '_add'])) {
                $term_ids = array_map('intval', $_REQUEST[$taxonomy . '_add']);
                $term_names = [];
                
                foreach ($term_ids as $term_id) {
                    $term = get_term($term_id, $taxonomy);
                    if ($term && !is_wp_error($term)) {
                        $term_names[] = $term->name;
                    }
                }
                
                if (!empty($term_names)) {
                    $taxonomy_obj = get_taxonomy($taxonomy);
                    $tax_name = $taxonomy_obj->labels->name ?? $taxonomy;
                    $changes[$tax_name] = [
                        'action' => 'Add terms',
                        'terms' => implode(', ', $term_names)
                    ];
                }
            }
            
            if (isset($_REQUEST[$taxonomy . '_remove']) && is_array($_REQUEST[$taxonomy . '_remove'])) {
                $term_ids = array_map('intval', $_REQUEST[$taxonomy . '_remove']);
                $term_names = [];
                
                foreach ($term_ids as $term_id) {
                    $term = get_term($term_id, $taxonomy);
                    if ($term && !is_wp_error($term)) {
                        $term_names[] = $term->name;
                    }
                }
                
                if (!empty($term_names)) {
                    $taxonomy_obj = get_taxonomy($taxonomy);
                    $tax_name = $taxonomy_obj->labels->name ?? $taxonomy;
                    $changes[$tax_name] = [
                        'action' => 'Remove terms',
                        'terms' => implode(', ', $term_names)
                    ];
                }
            }
        }
        
        if (empty($changes)) return;
        
        foreach ($post_ids as $post_id) {
            $post = get_post($post_id);
            if (!$post) continue;
            
            $post_details = $changes;
            $post_details['action'] = 'Bulk edit applied';
            $post_details['total_posts'] = count($post_ids);
            $post_details['current_post'] = "Post #{$post_id}";
            
            $view_url = get_permalink($post_id);
            if ($view_url) {
                $post_details['view_post'] = "<a href='" . esc_url($view_url) . "' target='_blank'>👁️ View post</a>";
            }
            
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
    
    public function log_object_terms_change($object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids) {
        if (self::$is_ajax_request || defined('DOING_AJAX')) {
            return;
        }
        
        if (empty($old_tt_ids) && empty($tt_ids)) {
            return;
        }
        
        $old_tt_ids = (array) $old_tt_ids;
        $tt_ids = (array) $tt_ids;
        
        sort($old_tt_ids);
        sort($tt_ids);
        
        if ($old_tt_ids == $tt_ids) {
            return;
        }
        
        $post = get_post($object_id);
        if (!$post || wp_is_post_revision($object_id) || wp_is_post_autosave($object_id)) {
            return;
        }
        
        $taxonomy_obj = get_taxonomy($taxonomy);
        if (!$taxonomy_obj) {
            return;
        }
        
        $taxonomy_name = $taxonomy_obj->labels->name ?? $taxonomy;
        $taxonomy_singular = $taxonomy_obj->labels->singular_name ?? $taxonomy;
        
        $old_terms = [];
        $new_terms = [];
        
        foreach ($old_tt_ids as $term_taxonomy_id) {
            $term = get_term_by('term_taxonomy_id', $term_taxonomy_id, $taxonomy);
            if ($term && !is_wp_error($term)) {
                $old_terms[] = $term->name;
            }
        }
        
        foreach ($tt_ids as $term_taxonomy_id) {
            $term = get_term_by('term_taxonomy_id', $term_taxonomy_id, $taxonomy);
            if ($term && !is_wp_error($term)) {
                $new_terms[] = $term->name;
            }
        }
        
        sort($old_terms);
        sort($new_terms);
        
        $added = array_diff($new_terms, $old_terms);
        $removed = array_diff($old_terms, $new_terms);
        
        if (empty($added) && empty($removed)) {
            return;
        }
        
        $edit_url = get_edit_post_link($object_id);
        $view_url = get_permalink($object_id);
        
        $details = [
            'taxonomy' => $taxonomy_name,
            'action' => "{$taxonomy_singular} assignment updated",
            'append_mode' => $append ? 'Add to existing' : 'Replace existing'
        ];
        
        if (!empty($added)) {
            $details['added'] = implode(', ', array_values($added));
        }
        
        if (!empty($removed)) {
            $details['removed'] = implode(', ', array_values($removed));
        }
        
        if ($edit_url) {
            $details['edit_post'] = "<a href='" . esc_url($edit_url) . "' target='_blank'>✏️ Edit post</a>";
        }
        
        if ($view_url) {
            $details['view_post'] = "<a href='" . esc_url($view_url) . "' target='_blank'>👁️ View post</a>";
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

    /**
     * FIXED: Enhanced term update logging
     */
    public function log_term_updated($term_id, $tt_id, $taxonomy) {
        if (in_array($term_id, self::$processed_terms)) {
            return;
        }
        
        self::$processed_terms[] = $term_id;
        
        $term = get_term($term_id, $taxonomy);
        if (is_wp_error($term) || !$term) {
            return;
        }
        
        $taxonomy_obj = get_taxonomy($taxonomy);
        $taxonomy_name = $taxonomy_obj->labels->singular_name ?? $taxonomy;
        
        $details = [
            'taxonomy' => $taxonomy_name,
            'action' => 'Taxonomy term updated'
        ];
        
        $has_changes = false;
        
        // Get old data
        $old_data = isset(self::$old_taxonomy_data[$taxonomy][$term_id]) 
            ? self::$old_taxonomy_data[$taxonomy][$term_id] 
            : [];
        
        // Check for name changes
        if (!empty($_POST)) {
            // Check various possible name fields
            $new_name = '';
            if (isset($_POST['name'])) {
                $new_name = sanitize_text_field($_POST['name']);
            } elseif (isset($_POST['tag-name'])) {
                $new_name = sanitize_text_field($_POST['tag-name']);
            }
            
            if (!empty($new_name) && $new_name !== $term->name) {
                $old_name = isset($old_data['name']) ? $old_data['name'] : $term->name;
                $details['name'] = [
                    'old' => $old_name,
                    'new' => $new_name
                ];
                $has_changes = true;
            }
            
            // Check for slug changes
            $new_slug = '';
            if (isset($_POST['slug'])) {
                $new_slug = sanitize_title($_POST['slug']);
            } elseif (isset($_POST['tag-slug'])) {
                $new_slug = sanitize_title($_POST['tag-slug']);
            }
            
            if (!empty($new_slug) && $new_slug !== $term->slug) {
                $old_slug = isset($old_data['slug']) ? $old_data['slug'] : $term->slug;
                $details['slug'] = [
                    'old' => $old_slug,
                    'new' => $new_slug
                ];
                $has_changes = true;
            }
            
            // Check for description changes
            $new_description = '';
            if (isset($_POST['description'])) {
                $new_description = sanitize_textarea_field($_POST['description']);
            } elseif (isset($_POST['tag-description'])) {
                $new_description = sanitize_textarea_field($_POST['tag-description']);
            }
            
            if ($new_description !== '') {
                $current_description = $term->description ?? '';
                if (trim($current_description) !== trim($new_description)) {
                    $old_desc = isset($old_data['description']) ? $old_data['description'] : $current_description;
                    $old_desc = $old_desc ?: '(empty)';
                    $new_desc = $new_description ?: '(empty)';
                    
                    $details['description'] = [
                        'old' => substr($old_desc, 0, 100) . (strlen($old_desc) > 100 ? '...' : ''),
                        'new' => substr($new_desc, 0, 100) . (strlen($new_desc) > 100 ? '...' : '')
                    ];
                    $has_changes = true;
                }
            }
            
            // Check for parent changes
            $new_parent = 0;
            if (isset($_POST['parent'])) {
                $new_parent = intval($_POST['parent']);
            } elseif (isset($_POST['tag-parent'])) {
                $new_parent = intval($_POST['tag-parent']);
            }
            
            if ($new_parent !== 0) {
                $current_parent = $term->parent ?? 0;
                if ($current_parent != $new_parent) {
                    $old_parent_name = 'None (Top Level)';
                    if ($current_parent > 0) {
                        $parent_term = get_term($current_parent, $taxonomy);
                        $old_parent_name = $parent_term ? $parent_term->name : 'Term #' . $current_parent;
                    }
                    
                    $new_parent_name = 'None (Top Level)';
                    if ($new_parent > 0) {
                        $parent_term = get_term($new_parent, $taxonomy);
                        $new_parent_name = $parent_term ? $parent_term->name : 'Term #' . $new_parent;
                    }
                    
                    $details['parent_term'] = [
                        'old' => $old_parent_name,
                        'new' => $new_parent_name
                    ];
                    $has_changes = true;
                }
            }
            
            // Check for term meta changes
            if (!empty($_POST['term_meta'])) {
                if (is_array($_POST['term_meta'])) {
                    foreach ($_POST['term_meta'] as $meta_key => $meta_value) {
                        if (strpos($meta_key, '_') === 0) {
                            continue;
                        }
                        
                        $old_value = get_term_meta($term_id, $meta_key, true);
                        $new_value = is_array($meta_value) ? $meta_value : sanitize_text_field($meta_value);
                        
                        if ($this->values_differ($old_value, $new_value)) {
                            $meta_label = ucwords(str_replace(['_', '-'], ' ', $meta_key));
                            $details["term_meta: {$meta_label}"] = [
                                'old' => $this->format_field_value($old_value),
                                'new' => $this->format_field_value($new_value)
                            ];
                            $has_changes = true;
                        }
                    }
                }
            }
        }
        
        // Add term edit link
        $edit_url = get_edit_term_link($term_id, $taxonomy);
        if ($edit_url) {
            $details['edit_term'] = "<a href='" . esc_url($edit_url) . "' target='_blank'>✏️ Edit {$taxonomy_name}</a>";
        }
        
        // Add view link
        $term_url = get_term_link($term_id, $taxonomy);
        if (!is_wp_error($term_url) && $term_url) {
            $details['view_term'] = "<a href='" . esc_url($term_url) . "' target='_blank'>👁️ View {$taxonomy_name}</a>";
        }
        
        if ($has_changes) {
            Site_Logger::log(
                'term_updated',
                'term',
                $term_id,
                $term->name,
                $details,
                'info'
            );
        }
        
        // Clean up old data
        if (isset(self::$old_taxonomy_data[$taxonomy][$term_id])) {
            unset(self::$old_taxonomy_data[$taxonomy][$term_id]);
        }
    }
    
    
    public function log_term_created($term_id, $tt_id, $taxonomy) {
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
    
    /**
     * FIXED: Enhanced term meta update logging
     */
    public function log_term_meta_update($meta_id, $term_id, $meta_key, $meta_value) {
        if (strpos($meta_key, '_') === 0) return;
        
        $term = get_term($term_id);
        if (is_wp_error($term) || !$term) return;
        
        $old_value = get_term_meta($term_id, $meta_key, true);
        
        if ($this->values_differ($old_value, $meta_value)) {
            $taxonomy_obj = get_taxonomy($term->taxonomy);
            $tax_name = $taxonomy_obj->labels->singular_name ?? $term->taxonomy;
            
            $edit_url = get_edit_term_link($term_id, $term->taxonomy);
            
            $details = [
                'taxonomy' => $tax_name,
                'field' => $meta_key,
                'old_value' => $this->format_field_value($old_value),
                'new_value' => $this->format_field_value($meta_value)
            ];
            
            if ($edit_url) {
                $details['edit_term'] = "<a href='" . esc_url($edit_url) . "' target='_blank'>✏️ Edit {$tax_name}</a>";
            }
            
            Site_Logger::log(
                'term_meta_updated',
                'term',
                $term_id,
                $term->name,
                $details,
                'info'
            );
        }
    }
    
    /**
     * FIXED: Enhanced term meta addition logging
     */
    public function log_term_meta_add($meta_id, $term_id, $meta_key, $meta_value) {
        if (strpos($meta_key, '_') === 0) return;
        
        $term = get_term($term_id);
        if (is_wp_error($term) || !$term) return;
        
        $taxonomy_obj = get_taxonomy($term->taxonomy);
        $tax_name = $taxonomy_obj->labels->singular_name ?? $term->taxonomy;
        
        $edit_url = get_edit_term_link($term_id, $term->taxonomy);
        
        $details = [
            'taxonomy' => $tax_name,
            'field' => $meta_key,
            'value' => $this->format_field_value($meta_value)
        ];
        
        if ($edit_url) {
            $details['edit_term'] = "<a href='" . esc_url($edit_url) . "' target='_blank'>✏️ Edit {$tax_name}</a>";
        }
        
        Site_Logger::log(
            'term_meta_added',
            'term',
            $term_id,
            $term->name,
            $details,
            'info'
        );
    }
    
    /**
     * FIXED: Enhanced term meta deletion logging
     */
    public function log_term_meta_delete($meta_ids, $term_id, $meta_key, $meta_value) {
        if (strpos($meta_key, '_') === 0) return;
        
        $term = get_term($term_id);
        if (is_wp_error($term) || !$term) return;
        
        $taxonomy_obj = get_taxonomy($term->taxonomy);
        $tax_name = $taxonomy_obj->labels->singular_name ?? $term->taxonomy;
        
        $edit_url = get_edit_term_link($term_id, $term->taxonomy);
        
        $details = [
            'taxonomy' => $tax_name,
            'field' => $meta_key,
            'value' => $this->format_field_value($meta_value)
        ];
        
        if ($edit_url) {
            $details['edit_term'] = "<a href='" . esc_url($edit_url) . "' target='_blank'>✏️ Edit {$tax_name}</a>";
        }
        
        Site_Logger::log(
            'term_meta_deleted',
            'term',
            $term_id,
            $term->name,
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
            $details['plugin_details'] = "<a href='" . esc_url($plugin_url) . "' target='_blank'>🔗 Plugin details</a>";
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
            
            'default_category' => 'options-writing.php',
            'default_email_category' => 'options-writing.php',
            'default_post_format' => 'options-writing.php',
            'mailserver_url' => 'options-writing.php',
            'mailserver_port' => 'options-writing.php',
            'mailserver_login' => 'options-writing.php',
            'mailserver_pass' => 'options-writing.php',
            'ping_sites' => 'options-writing.php',
            
            'show_on_front' => 'options-reading.php',
            'page_on_front' => 'options-reading.php',
            'page_for_posts' => 'options-reading.php',
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
            
            'thumbnail_size_w' => 'options-media.php',
            'thumbnail_size_h' => 'options-media.php',
            'thumbnail_crop' => 'options-media.php',
            'medium_size_w' => 'options-media.php',
            'medium_size_h' => 'options-media.php',
            'medium_large_size_w' => 'options-media.php',
            'medium_large_size_h' => 'options-media.php',
            'large_size_w' => 'options-media.php',
            'large_size_h' => 'options-media.php',
            'uploads_use_yearmonth_folders' => 'options-media.php',
            
            'permalink_structure' => 'options-permalink.php',
            'category_base' => 'options-permalink.php',
            'tag_base' => 'options-permalink.php',
        ];
        
        $option_groups = [
            'writing' => 'options-writing.php',
            'reading' => 'options-reading.php',
            'discussion' => 'options-discussion.php',
            'media' => 'options-media.php',
            'permalink' => 'options-permalink.php',
        ];
        
        foreach ($option_groups as $group => $page) {
            if (strpos($option_name, $group) !== false) {
                $url = admin_url($page);
                return "<a href='" . esc_url($url) . "' target='_blank'>⚙️ Go to {$group} settings</a>";
            }
        }
        
        if (isset($pages[$option_name])) {
            $url = admin_url($pages[$option_name]);
            $page_name = str_replace(['options-', '.php'], '', $pages[$option_name]);
            return "<a href='" . esc_url($url) . "' target='_blank'>⚙️ Go to {$page_name} settings</a>";
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
        
        $comment_edit_url = get_edit_comment_link($comment_id);
        $comment_view_url = get_comment_link($comment_id);
        $post_view_url = get_permalink($commentdata['comment_post_ID']);
        $post_edit_url = get_edit_post_link($commentdata['comment_post_ID']);
        
        $details = [
            'post' => $post->post_title,
            'author' => $commentdata['comment_author'],
            'email' => $commentdata['comment_author_email'],
            'status' => $status_labels[$comment_approved] ?? 'Unknown'
        ];
        
        if ($comment_edit_url) {
            $details['edit_comment'] = "<a href='" . esc_url($comment_edit_url) . "' target='_blank'>✏️ Edit comment</a>";
        }
        
        if ($comment_view_url) {
            $details['view_comment'] = "<a href='" . esc_url($comment_view_url) . "' target='_blank'>👁️ View comment</a>";
        }
        
        if ($post_view_url) {
            $details['view_post'] = "<a href='" . esc_url($post_view_url) . "' target='_blank'>📝 View post</a>";
        }
        
        if ($post_edit_url) {
            $details['edit_post'] = "<a href='" . esc_url($post_edit_url) . "' target='_blank'>✏️ Edit post</a>";
        }
        
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
        if (!$comment) return;
        
        $post = get_post($comment->comment_post_ID);
        
        $comment_edit_url = get_edit_comment_link($comment_id);
        $comment_view_url = get_comment_link($comment_id);
        $post_view_url = get_permalink($comment->comment_post_ID);
        $post_edit_url = get_edit_post_link($comment->comment_post_ID);
        
        $details = [
            'author' => $comment->comment_author,
            'email' => $comment->comment_author_email,
            'status' => $comment->comment_approved == 1 ? 'Approved' : ($comment->comment_approved == 0 ? 'Pending' : 'Spam')
        ];
        
        if ($comment_edit_url) {
            $details['edit_comment'] = "<a href='" . esc_url($comment_edit_url) . "' target='_blank'>✏️ Edit comment</a>";
        }
        
        if ($comment_view_url) {
            $details['view_comment'] = "<a href='" . esc_url($comment_view_url) . "' target='_blank'>👁️ View comment</a>";
        }
        
        if ($post_view_url) {
            $details['view_post'] = "<a href='" . esc_url($post_view_url) . "' target='_blank'>📝 View post</a>";
        }
        
        if ($post_edit_url) {
            $details['edit_post'] = "<a href='" . esc_url($post_edit_url) . "' target='_blank'>✏️ Edit post</a>";
        }
        
        Site_Logger::log(
            'comment_edited',
            'comment',
            $comment_id,
            'Comment on "' . $post->post_title . '"',
            $details,
            'info'
        );
    }

    public function log_comment_delete($comment_id) {
        $comment = get_comment($comment_id);
        if ($comment) {
            $post = get_post($comment->comment_post_ID);
            
            $post_view_url = get_permalink($comment->comment_post_ID);
            $post_edit_url = get_edit_post_link($comment->comment_post_ID);
            
            $details = [
                'author' => $comment->comment_author,
                'email' => $comment->comment_author_email,
                'status' => $comment->comment_approved == 1 ? 'Approved' : ($comment->comment_approved == 0 ? 'Pending' : 'Spam'),
                'action' => 'Comment deleted'
            ];
            
            if ($post_view_url) {
                $details['view_post'] = "<a href='" . esc_url($post_view_url) . "' target='_blank'>📝 View post</a>";
            }
            
            if ($post_edit_url) {
                $details['edit_post'] = "<a href='" . esc_url($post_edit_url) . "' target='_blank'>✏️ Edit post</a>";
            }
            
            Site_Logger::log(
                'comment_deleted',
                'comment',
                $comment_id,
                'Comment on "' . $post->post_title . '"',
                $details,
                'warning'
            );
        }
    }

    public function log_comment_status_change($new_status, $old_status, $comment) {
        $comment_id = is_object($comment) ? $comment->comment_ID : $comment;
        $details = [
            'old_status' => $old_status,
            'new_status' => $new_status,
            'comment_id' => $comment_id
        ];
        
        Site_Logger::log(
            'comment_status_changed',
            'comment',
            $comment_id,
            "Comment status changed",
            $details,
            'info'
        );
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
        if (doing_action('before_delete_post') || doing_action('delete_post')) {
            return;
        }
        
        $attachment = get_post($attachment_id);
        if ($attachment) {
            if (wp_attachment_is_image($attachment_id) || 
                strpos($attachment->post_mime_type, 'audio/') === 0 ||
                strpos($attachment->post_mime_type, 'video/') === 0 ||
                strpos($attachment->post_mime_type, 'application/') === 0) {
                
                $file_url = wp_get_attachment_url($attachment_id);
                $file_size = '';
                $file = get_attached_file($attachment_id);
                if ($file && file_exists($file)) {
                    $file_size = size_format(filesize($file));
                }
                
                $details = [
                    'media_title' => $attachment->post_title,
                    'media_type' => $attachment->post_mime_type,
                    'file_size' => $file_size,
                    'action' => 'Media file deleted'
                ];
                
                if ($file_url) {
                    $details['file_url'] = $file_url;
                }
                
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
                    'post_deleted',
                    $attachment->post_type,
                    $attachment_id,
                    $attachment->post_title,
                    [
                        'post_type' => $attachment->post_type,
                        'status' => $this->get_post_status_label($attachment->post_status)
                    ],
                    'warning'
                );
            }
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
        ];
        
        if (isset($args['status']) && $args['status'] !== 'any') {
            $details['status'] = $args['status'];
        }
        
        if (isset($args['start_date']) && !empty($args['start_date'])) {
            $details['start_date'] = $args['start_date'];
        }
        
        if (isset($args['end_date']) && !empty($args['end_date'])) {
            $details['end_date'] = $args['end_date'];
        }
        
        if (isset($args['category']) && $args['category'] !== 0 && $args['category'] !== 'all') {
            $category = get_category($args['category']);
            $details['category'] = $category ? $category->name : 'Category ID: ' . $args['category'];
        }
        
        if (isset($args['taxonomy']) && $args['taxonomy'] !== 'all') {
            $taxonomy_obj = get_taxonomy($args['taxonomy']);
            $details['taxonomy'] = $taxonomy_obj ? $taxonomy_obj->labels->singular_name : $args['taxonomy'];
        }
        
        if (isset($args['post_type']) && !empty($args['post_type'])) {
            $post_type_obj = get_post_type_object($args['post_type']);
            $details['post_type'] = $post_type_obj ? $post_type_obj->labels->name : $args['post_type'];
        }
        
        Site_Logger::log(
            'export_started',
            'tool',
            0,
            'Content Export: ' . ($content_types[$content] ?? ucfirst($content)),
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
                    'action' => 'Featured image set',
                    'image' => $image_name,
                    'image_id' => $meta_value,
                    'view_image' => $image_url ? "<a href='" . esc_url($image_url) . "' target='_blank'>🖼️ View image</a>" : ''
                ];
                
                if (isset(self::$old_meta_data['removed_featured_image'][$post_id])) {
                    $old_image_id = self::$old_meta_data['removed_featured_image'][$post_id];
                    $old_image = $old_image_id ? get_post($old_image_id) : null;
                    $details['replaced_image'] = 'Replaced: ' . ($old_image ? $old_image->post_title : 'ID ' . $old_image_id);
                    unset(self::$old_meta_data['removed_featured_image'][$post_id]);
                }
                
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
                    'image' => $old_image ? $old_image->post_title : 'ID ' . $old_image_id,
                    'note' => 'Image was removed from post'
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
                
                self::$old_meta_data['removed_featured_image'][$post_id] = $old_image_id;
            }
        }
    }
    
    public function log_acf_save($post_id) {
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }
        
        if ($post_id === 'acf-field-group' || $post_id === 'options') {
            return;
        }
        
        if (strpos($post_id, 'term_') === 0) {
            $term_id = intval(str_replace('term_', '', $post_id));
            $term = get_term($term_id);
            
            if ($term && !is_wp_error($term)) {
                $taxonomy = $term->taxonomy;
                $edit_url = get_edit_term_link($term_id, $taxonomy);
                
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
                    $details = array_merge($changes, [
                        'action' => 'ACF fields updated for taxonomy term',
                        'fields_updated' => count($changes),
                        'taxonomy' => get_taxonomy($taxonomy)->labels->singular_name ?? $taxonomy
                    ]);
                    
                    if ($edit_url) {
                        $details['edit_term'] = "<a href='" . esc_url($edit_url) . "' target='_blank'>✏️ Edit term</a>";
                    }
                    
                    Site_Logger::log(
                        'acf_fields_updated',
                        'term',
                        $term_id,
                        $term->name,
                        $details,
                        'info'
                    );
                }
                
                unset(self::$old_acf_data[$post_id]);
            }
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
    
    /**
     * FIXED: Enhanced ACF Field Group Update Logging
     */
    public function log_acf_field_group_update($field_group) {
        $changes = [];
        $field_changes = [];
        $field_group_id = $field_group['ID'] ?? 0;
        
        // Get old field group data
        $old_field_group = isset(self::$old_acf_data['field_groups'][$field_group['key']]) ? 
                          self::$old_acf_data['field_groups'][$field_group['key']] : null;
        
        if ($old_field_group) {
            // 1. Basic field group settings
            $basic_settings = ['title', 'menu_order', 'position', 'style', 'label_placement', 
                              'instruction_placement', 'description', 'active'];
            
            foreach ($basic_settings as $setting) {
                if (isset($old_field_group[$setting]) && isset($field_group[$setting]) && 
                    $old_field_group[$setting] != $field_group[$setting]) {
                    
                    $old_val = $old_field_group[$setting];
                    $new_val = $field_group[$setting];
                    
                    if (is_bool($old_val)) $old_val = $old_val ? 'Yes' : 'No';
                    if (is_bool($new_val)) $new_val = $new_val ? 'Yes' : 'No';
                    
                    if (is_array($old_val)) $old_val = implode(', ', $old_val);
                    if (is_array($new_val)) $new_val = implode(', ', $new_val);
                    
                    $changes[$setting] = [
                        'old' => $old_val ?: '(empty)',
                        'new' => $new_val ?: '(empty)'
                    ];
                }
            }
            
            // 2. Location rules - detailed comparison
            if (isset($old_field_group['location']) && isset($field_group['location'])) {
                $old_locations = $this->format_acf_locations($old_field_group['location']);
                $new_locations = $this->format_acf_locations($field_group['location']);
                
                if ($old_locations !== $new_locations) {
                    $changes['location_rules'] = [
                        'old' => $old_locations,
                        'new' => $new_locations
                    ];
                }
            }
            
            // 3. Hide on screen settings
            if (isset($old_field_group['hide_on_screen']) && isset($field_group['hide_on_screen'])) {
                $old_hide = is_array($old_field_group['hide_on_screen']) ? $old_field_group['hide_on_screen'] : [];
                $new_hide = is_array($field_group['hide_on_screen']) ? $field_group['hide_on_screen'] : [];
                
                $added = array_diff($new_hide, $old_hide);
                $removed = array_diff($old_hide, $new_hide);
                
                if (!empty($added) || !empty($removed)) {
                    $hide_changes = [];
                    if (!empty($added)) {
                        $hide_changes['added'] = array_values($added);
                    }
                    if (!empty($removed)) {
                        $hide_changes['removed'] = array_values($removed);
                    }
                    $changes['hide_on_screen'] = $hide_changes;
                }
            }
            
            // 4. Post type and taxonomy assignments
            $this->check_acf_post_type_taxonomy_changes($old_field_group, $field_group, $changes);
            
            // 5. Field changes - EXTENSIVE DETECTION
            if (isset($old_field_group['fields']) && isset($field_group['fields'])) {
                $old_fields_by_key = [];
                $old_fields_by_name = [];
                foreach ($old_field_group['fields'] as $field) {
                    $old_fields_by_key[$field['key']] = $field;
                    if (isset($field['name'])) {
                        $old_fields_by_name[$field['name']] = $field;
                    }
                }
                
                $new_fields_by_key = [];
                foreach ($field_group['fields'] as $field) {
                    $new_fields_by_key[$field['key']] = $field;
                }
                
                // Track added fields
                $added_fields = array_diff_key($new_fields_by_key, $old_fields_by_key);
                foreach ($added_fields as $field_key => $field) {
                    $field_label = $field['label'] ?? $field['name'] ?? 'Unnamed field';
                    $field_changes[] = "➕ <strong>Added field:</strong> '{$field_label}' (Type: {$field['type']})";
                }
                
                // Track removed fields
                $removed_fields = array_diff_key($old_fields_by_key, $new_fields_by_key);
                foreach ($removed_fields as $field_key => $field) {
                    $field_label = $field['label'] ?? $field['name'] ?? 'Unnamed field';
                    $field_changes[] = "🗑️ <strong>Removed field:</strong> '{$field_label}' (Type: {$field['type']})";
                }
                
                // Track modified fields - CHECK EVERYTHING
                foreach ($old_fields_by_key as $field_key => $old_field) {
                    if (isset($new_fields_by_key[$field_key])) {
                        $new_field = $new_fields_by_key[$field_key];
                        
                        $field_identifier = $new_field['label'] ?? $old_field['label'] ?? 
                                           $new_field['name'] ?? $old_field['name'] ?? 'Unnamed field';
                        
                        $field_modifications = $this->get_acf_field_modifications($old_field, $new_field);
                        
                        if (!empty($field_modifications)) {
                            $field_changes[] = "✏️ <strong>Modified field '{$field_identifier}':</strong><br>" . 
                                             implode('<br>', array_map(function($item) {
                                                 return "&nbsp;&nbsp;&nbsp;&nbsp;• {$item}";
                                             }, $field_modifications));
                        }
                    }
                }
                
                if (!empty($field_changes)) {
                    $changes['field_changes'] = $field_changes;
                }
            }
        }
        
        $edit_url = $field_group_id ? admin_url('post.php?post=' . $field_group_id . '&action=edit') : '';
        $details = [
            'field_group' => $field_group['title'],
            'key' => $field_group['key'],
            'fields_count' => isset($field_group['fields']) ? count($field_group['fields']) : 0,
        ];
        
        if ($edit_url) {
            $details['edit_acf_group'] = "<a href='" . esc_url($edit_url) . "' target='_blank'>🔧 Edit ACF Field Group</a>";
        }
        
        if (!empty($changes)) {
            $details = array_merge($details, $changes);
            
            // Add comprehensive summary
            $field_changes_count = isset($changes['field_changes']) ? count($changes['field_changes']) : 0;
            $setting_changes_count = count($changes) - ($field_changes_count > 0 ? 1 : 0);
            
            $details['summary'] = "{$field_changes_count} field changes, {$setting_changes_count} setting changes";
        } else {
            $details['note'] = 'ACF field group saved (no changes detected)';
        }
        
        Site_Logger::log(
            'acf_field_group_updated',
            'acf',
            $field_group_id,
            $field_group['title'],
            $details,
            'info'
        );
        
        // Store current data for next comparison
        if (!isset(self::$old_acf_data['field_groups'])) {
            self::$old_acf_data['field_groups'] = [];
        }
        self::$old_acf_data['field_groups'][$field_group['key']] = $field_group;
    }
    
    /**
     * Check ACF post type and taxonomy changes
     */
    private function check_acf_post_type_taxonomy_changes($old_field_group, $new_field_group, &$changes) {
        // Check post type assignments
        if (isset($old_field_group['post_types']) && isset($new_field_group['post_types'])) {
            $old_post_types = $old_field_group['post_types'];
            $new_post_types = $new_field_group['post_types'];
            
            if (serialize($old_post_types) !== serialize($new_post_types)) {
                $old_pt_names = [];
                foreach ($old_post_types as $pt) {
                    $post_type_obj = get_post_type_object($pt);
                    $old_pt_names[] = $post_type_obj ? $post_type_obj->labels->singular_name : $pt;
                }
                
                $new_pt_names = [];
                foreach ($new_post_types as $pt) {
                    $post_type_obj = get_post_type_object($pt);
                    $new_pt_names[] = $post_type_obj ? $post_type_obj->labels->singular_name : $pt;
                }
                
                $changes['post_types'] = [
                    'old' => !empty($old_pt_names) ? implode(', ', $old_pt_names) : 'All post types',
                    'new' => !empty($new_pt_names) ? implode(', ', $new_pt_names) : 'All post types'
                ];
            }
        }
        
        // Check taxonomy assignments
        if (isset($old_field_group['taxonomies']) && isset($new_field_group['taxonomies'])) {
            $old_taxonomies = $old_field_group['taxonomies'];
            $new_taxonomies = $new_field_group['taxonomies'];
            
            if (serialize($old_taxonomies) !== serialize($new_taxonomies)) {
                $old_tax_names = [];
                foreach ($old_taxonomies as $tax) {
                    $taxonomy_obj = get_taxonomy($tax);
                    $old_tax_names[] = $taxonomy_obj ? $taxonomy_obj->labels->singular_name : $tax;
                }
                
                $new_tax_names = [];
                foreach ($new_taxonomies as $tax) {
                    $taxonomy_obj = get_taxonomy($tax);
                    $new_tax_names[] = $taxonomy_obj ? $taxonomy_obj->labels->singular_name : $tax;
                }
                
                $changes['taxonomies'] = [
                    'old' => !empty($old_tax_names) ? implode(', ', $old_tax_names) : 'All taxonomies',
                    'new' => !empty($new_tax_names) ? implode(', ', $new_tax_names) : 'All taxonomies'
                ];
            }
        }
    }
    
    /**
     * Get ACF field modifications
     */
    private function get_acf_field_modifications($old_field, $new_field) {
        $modifications = [];
        
        $field_properties = ['label', 'name', 'type', 'required', 'default_value', 
                            'instructions', 'placeholder', 'wrapper', 'choices', 
                            'allow_null', 'multiple', 'ui', 'ajax', 'return_format',
                            'library', 'min', 'max', 'step', 'prepend', 'append',
                            'maxlength', 'rows', 'new_lines', 'layout', 'button_label',
                            'collapsed', 'conditional_logic', 'parent'];
        
        foreach ($field_properties as $prop) {
            $old_val = $old_field[$prop] ?? '';
            $new_val = $new_field[$prop] ?? '';
            
            if ($prop === 'wrapper') {
                if (is_array($old_val) && is_array($new_val)) {
                    $wrapper_changes = [];
                    if (isset($old_val['width']) && isset($new_val['width']) && 
                        $old_val['width'] !== $new_val['width']) {
                        $wrapper_changes[] = "Wrapper width: {$old_val['width']}% → {$new_val['width']}%";
                    }
                    if (isset($old_val['class']) && isset($new_val['class']) && 
                        $old_val['class'] !== $new_val['class']) {
                        $wrapper_changes[] = "Wrapper class: '{$old_val['class']}' → '{$new_val['class']}'";
                    }
                    if (!empty($wrapper_changes)) {
                        $modifications = array_merge($modifications, $wrapper_changes);
                    }
                }
                continue;
            }
            
            if ($prop === 'choices' && is_array($old_val) && is_array($new_val)) {
                if (serialize($old_val) !== serialize($new_val)) {
                    $added = array_diff_assoc($new_val, $old_val);
                    $removed = array_diff_assoc($old_val, $new_val);
                    
                    if (!empty($added) || !empty($removed)) {
                        $choice_changes = [];
                        if (!empty($added)) {
                            foreach ($added as $key => $value) {
                                $choice_changes[] = "Added choice: '{$key}' => '{$value}'";
                            }
                        }
                        if (!empty($removed)) {
                            foreach ($removed as $key => $value) {
                                $choice_changes[] = "Removed choice: '{$key}' => '{$value}'";
                            }
                        }
                        $modifications[] = "Choices changed";
                        $modifications = array_merge($modifications, $choice_changes);
                    }
                }
                continue;
            }
            
            if ($prop === 'conditional_logic' && is_array($old_val) && is_array($new_val)) {
                if (serialize($old_val) !== serialize($new_val)) {
                    $modifications[] = "Conditional logic updated";
                }
                continue;
            }
            
            if (empty($old_val) && empty($new_val)) continue;
            
            if (is_array($old_val) && is_array($new_val)) {
                if (serialize($old_val) !== serialize($new_val)) {
                    $modifications[] = ucfirst($prop) . " changed";
                }
            } elseif ($old_val !== $new_val) {
                $old_display = is_bool($old_val) ? ($old_val ? 'Yes' : 'No') : $old_val;
                $new_display = is_bool($new_val) ? ($new_val ? 'Yes' : 'No') : $new_val;
                
                $old_display = $old_display ?: '(empty)';
                $new_display = $new_display ?: '(empty)';
                
                $modifications[] = ucfirst($prop) . ": '{$old_display}' → '{$new_display}'";
            }
        }
        
        return $modifications;
    }
    
    private function format_acf_locations($locations) {
        if (empty($locations) || !is_array($locations)) {
            return 'No locations';
        }
        
        $location_strings = [];
        foreach ($locations as $location_group) {
            $group_strings = [];
            foreach ($location_group as $rule) {
                $param = $rule['param'] ?? '';
                $operator = $rule['operator'] ?? '==';
                $value = $rule['value'] ?? '';
                
                $display_value = $value;
                
                switch ($param) {
                    case 'post_type':
                        $post_type_obj = get_post_type_object($value);
                        $display_value = $post_type_obj ? $post_type_obj->labels->singular_name : $value;
                        $location_strings[] = "Post Type: {$display_value}";
                        break;
                        
                    case 'post_template':
                        $location_strings[] = "Template: {$value}";
                        break;
                        
                    case 'post_status':
                        $location_strings[] = "Status: {$value}";
                        break;
                        
                    case 'post_category':
                        $term = get_term($value, 'category');
                        $term_name = $term ? $term->name : "Category ID: {$value}";
                        $location_strings[] = "Category: {$term_name}";
                        break;
                        
                    case 'post_format':
                        $location_strings[] = "Format: {$value}";
                        break;
                        
                    case 'page_type':
                        $location_strings[] = "Page Type: {$value}";
                        break;
                        
                    case 'page_parent':
                        $page = get_post($value);
                        $page_title = $page ? $page->post_title : "Page ID: {$value}";
                        $location_strings[] = "Parent Page: {$page_title}";
                        break;
                        
                    case 'user_form':
                        $user_forms = [
                            'all' => 'All User Forms',
                            'add' => 'Add New User',
                            'edit' => 'Edit User',
                            'register' => 'Registration'
                        ];
                        $display_value = $user_forms[$value] ?? $value;
                        $location_strings[] = "User Form: {$display_value}";
                        break;
                        
                    default:
                        $location_strings[] = "{$param}: {$value}";
                }
            }
            
            if (!empty($group_strings)) {
                $location_strings[] = implode(' AND ', $group_strings);
            }
        }
        
        return !empty($location_strings) ? implode(' OR ', $location_strings) : 'No location rules';
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
        if (($old_value === null || $old_value === '' || $old_value === false) && 
            ($new_value === null || $new_value === '' || $new_value === false)) {
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
            array_multisort($old_value);
            array_multisort($new_value);
            return serialize($old_value) !== serialize($new_value);
        }
        
        if (is_object($old_value) && is_object($new_value)) {
            return serialize($old_value) !== serialize($new_value);
        }
        
        if (is_string($old_value) && is_string($new_value)) {
            return trim($old_value) !== trim($new_value);
        }
        
        return $old_value !== $new_value;
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

    /**
     * List of post meta keys to SKIP logging
     */
    private static $skip_post_meta_keys = [
        '_edit_lock',
        '_edit_last',
        '_wp_old_slug',
        '_wp_attachment_metadata',
        '_wp_attachment_image_alt',
        '_thumbnail_id',
        '_encloseme',
        '_pingme',
        '_wp_page_template',
        '_yoast_wpseo_',
        '_yst_is_cornerstone',
        'rank_math_',
        '_aioseop_',
        '_regular_price',
        '_sale_price',
        '_price',
        '_sku',
        '_stock',
        '_manage_stock',
        '_backorders',
        '_sold_individually',
        '_weight',
        '_length',
        '_width',
        '_height',
        '_virtual',
        '_downloadable',
        '_download_limit',
        '_download_expiry',
        '_product_image_gallery',
        '_crosssell_ids',
        '_upsell_ids',
        '_purchase_note',
        '_default_attributes',
        '_product_attributes',
        '_variation_description',
        'field_',
        '_transient_',
        '_site_transient_',
        '_wp_old_date',
        '_wp_trash_meta',
        '_wp_desired_post_slug',
        '_wpcom_is_markdown',
        '_wp_attached_file',
        '_wp_attachment_backup_sizes',
        '_wpcom_featured_media',
        '_last_editor_used_jetpack',
        '_elementor_',
        '_elementor_css',
        '_elementor_data',
        '_elementor_controls_usage',
        '_elementor_page_assets',
        '_elementor_version',
        '_elementor_edit_mode',
        '_fl_builder_',
        '_fl_builder_enabled',
        '_fl_builder_data',
        '_fl_builder_draft',
        '_et_pb_',
        '_et_builder_version',
        'brizy-',
        'brizy_',
        '_wpb_vc_js_status',
        'vcv-',
        'panels_data',
        'siteorigin_panels_data',
        '_wpml_',
        'polylang_',
        'membership_level',
        '_rcp_',
        '_backup',
        '_snapshot',
        '_aiowps_',
        '_wordfence_',
        '_ithemes_',
        '_autoptimize_',
        '_w3tc_',
        '_wp_super_cache_',
        '_form_',
        '_gf_',
        '_Event',
        '_tribe_',
        '_tutor_',
        '_learndash_',
        '_sensei_',
        '_lock',
        '_temp',
        '_tmp',
        'types-field-',
        '_pods_',
        'cf_',
        '_ssharing_',
        '_social_',
        '_rating',
        '_comment',
        '_ga_',
        '_gtm_',
        '_fb_',
        '_wp_scheduled_',
        '_wp_revisions',
    ];

    /**
     * List of post meta keys to ALWAYS log
     */
    private static $important_post_meta_keys = [
        'price',
        'stock',
        'sku',
        'availability',
        'brand',
        'model',
        'year',
        'location',
        'address',
        'phone',
        'email',
        'website',
        'rating',
        'status',
        'priority',
        'deadline',
        'budget',
        'client',
        'project',
        'employee',
        'department',
        'category',
        'tag',
        'featured',
        'highlight',
        'promoted',
        'expiry_date',
        'start_date',
        'end_date',
        'event_date',
        'registration_deadline',
        'capacity',
        'seats',
        'ticket_price',
        'discount',
        'coupon',
        'promo_code',
    ];

    /**
     * Check if we should log this post meta change
     */
    private function should_log_post_meta($meta_key, $post_id) {
        if (strpos($meta_key, '_') === 0) {
            $important_underscored = [
                '_price',
                '_stock',
                '_sku',
                '_featured',
                '_status',
                '_priority'
            ];
            
            if (!in_array($meta_key, $important_underscored)) {
                return false;
            }
        }
        
        if (function_exists('acf_get_field') && acf_get_field($meta_key)) {
            return false;
        }
        
        foreach (self::$skip_post_meta_keys as $skip_key) {
            if (strpos($meta_key, $skip_key) === 0) {
                return false;
            }
        }
        
        if (self::$is_bulk_operation && is_array($meta_key)) {
            return false;
        }
        
        $post = get_post($post_id);
        if (!$post) return false;
        
        $skip_post_types = ['revision', 'nav_menu_item', 'custom_css', 'customize_changeset'];
        if (in_array($post->post_type, $skip_post_types)) {
            return false;
        }
        
        foreach (self::$important_post_meta_keys as $important_key) {
            if (strpos($meta_key, $important_key) !== false) {
                return true;
            }
        }
        
        return true;
    }

    /**
     * Log post meta update
     */
    public function log_post_meta_update($meta_id, $post_id, $meta_key, $meta_value) {
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }
        
        if (!$this->should_log_post_meta($meta_key, $post_id)) {
            return;
        }
        
        $post = get_post($post_id);
        if (!$post) return;
        
        $old_value = get_post_meta($post_id, $meta_key, true);
        
        $old_value_display = $this->format_post_meta_value($old_value, $meta_key);
        $new_value_display = $this->format_post_meta_value($meta_value, $meta_key);
        
        if (!$this->post_meta_values_differ($old_value, $meta_value, $meta_key)) {
            return;
        }
        
        $edit_url = get_edit_post_link($post_id);
        $view_url = get_permalink($post_id);
        
        $details = [
            'meta_key' => $meta_key,
            'action' => 'Custom field updated',
            'change' => [
                'old' => $old_value_display,
                'new' => $new_value_display
            ]
        ];
        
        $field_context = $this->get_post_meta_field_context($meta_key, $post_id);
        if ($field_context) {
            $details['field_info'] = $field_context;
        }
        
        $post_type_obj = get_post_type_object($post->post_type);
        if ($post_type_obj) {
            $details['post_type'] = $post_type_obj->labels->singular_name;
        }
        
        if ($edit_url) {
            $details['edit_post'] = "<a href='" . esc_url($edit_url) . "' target='_blank'>✏️ Edit post</a>";
        }
        if ($view_url) {
            $details['view_post'] = "<a href='" . esc_url($view_url) . "' target='_blank'>👁️ View post</a>";
        }
        
        $severity = 'info';
        if (in_array($meta_key, self::$important_post_meta_keys)) {
            $severity = 'notice';
        }
        
        Site_Logger::log(
            'post_meta_updated',
            $post->post_type,
            $post_id,
            $post->post_title,
            $details,
            $severity
        );
    }

    /**
     * Log post meta addition
     */
    public function log_post_meta_add($meta_id, $post_id, $meta_key, $meta_value) {
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }
        
        if (!$this->should_log_post_meta($meta_key, $post_id)) {
            return;
        }
        
        $post = get_post($post_id);
        if (!$post) return;
        
        $edit_url = get_edit_post_link($post_id);
        $view_url = get_permalink($post_id);
        
        $formatted_value = $this->format_post_meta_value($meta_value, $meta_key);
        
        $details = [
            'meta_key' => $meta_key,
            'action' => 'Custom field added',
            'value' => $formatted_value
        ];
        
        $field_context = $this->get_post_meta_field_context($meta_key, $post_id);
        if ($field_context) {
            $details['field_info'] = $field_context;
        }
        
        $post_type_obj = get_post_type_object($post->post_type);
        if ($post_type_obj) {
            $details['post_type'] = $post_type_obj->labels->singular_name;
        }
        
        if ($edit_url) {
            $details['edit_post'] = "<a href='" . esc_url($edit_url) . "' target='_blank'>✏️ Edit post</a>";
        }
        if ($view_url) {
            $details['view_post'] = "<a href='" . esc_url($view_url) . "' target='_blank'>👁️ View post</a>";
        }
        
        $severity = 'info';
        if (in_array($meta_key, self::$important_post_meta_keys)) {
            $severity = 'notice';
        }
        
        Site_Logger::log(
            'post_meta_added',
            $post->post_type,
            $post_id,
            $post->post_title,
            $details,
            $severity
        );
    }

    /**
     * Log post meta deletion
     */
    public function log_post_meta_delete($meta_ids, $post_id, $meta_key, $meta_value) {
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }
        
        if (!$this->should_log_post_meta($meta_key, $post_id)) {
            return;
        }
        
        $post = get_post($post_id);
        if (!$post) return;
        
        $edit_url = get_edit_post_link($post_id);
        $view_url = get_permalink($post_id);
        
        $formatted_value = $this->format_post_meta_value($meta_value, $meta_key);
        
        $details = [
            'meta_key' => $meta_key,
            'action' => 'Custom field deleted',
            'value' => $formatted_value
        ];
        
        $field_context = $this->get_post_meta_field_context($meta_key, $post_id);
        if ($field_context) {
            $details['field_info'] = $field_context;
        }
        
        $post_type_obj = get_post_type_object($post->post_type);
        if ($post_type_obj) {
            $details['post_type'] = $post_type_obj->labels->singular_name;
        }
        
        if ($edit_url) {
            $details['edit_post'] = "<a href='" . esc_url($edit_url) . "' target='_blank'>✏️ Edit post</a>";
        }
        if ($view_url) {
            $details['view_post'] = "<a href='" . esc_url($view_url) . "' target='_blank'>👁️ View post</a>";
        }
        
        $severity = 'warning';
        if (in_array($meta_key, self::$important_post_meta_keys)) {
            $severity = 'error';
        }
        
        Site_Logger::log(
            'post_meta_deleted',
            $post->post_type,
            $post_id,
            $post->post_title,
            $details,
            $severity
        );
    }

    /**
     * Improved value comparison for post meta
     */
    private function post_meta_values_differ($old_value, $new_value, $meta_key = '') {
        if (empty($old_value) && empty($new_value)) {
            return false;
        }
        
        if (is_serialized($old_value) || is_serialized($new_value)) {
            $old_unserialized = maybe_unserialize($old_value);
            $new_unserialized = maybe_unserialize($new_value);
            
            if (is_array($old_unserialized) && is_array($new_unserialized)) {
                $this->recursive_ksort($old_unserialized);
                $this->recursive_ksort($new_unserialized);
                return serialize($old_unserialized) !== serialize($new_unserialized);
            }
            
            return $old_unserialized !== $new_unserialized;
        }
        
        if (is_array($old_value) && is_array($new_value)) {
            $this->recursive_ksort($old_value);
            $this->recursive_ksort($new_value);
            return serialize($old_value) !== serialize($new_value);
        }
        
        if ($this->is_date_field($meta_key)) {
            $old_timestamp = strtotime($old_value);
            $new_timestamp = strtotime($new_value);
            return $old_timestamp !== $new_timestamp;
        }
        
        if (is_numeric($old_value) && is_numeric($new_value)) {
            return (float)$old_value !== (float)$new_value;
        }
        
        if (is_bool($old_value) || is_bool($new_value)) {
            return (bool)$old_value !== (bool)$new_value;
        }
        
        $old_str = is_string($old_value) ? trim($old_value) : $old_value;
        $new_str = is_string($new_value) ? trim($new_value) : $new_value;
        
        return $old_str !== $new_str;
    }

    /**
     * Format post meta value for display
     */
    private function format_post_meta_value($value, $meta_key = '') {
        if (is_null($value) || $value === '' || $value === false) {
            return '(empty)';
        }
        
        if (is_serialized($value)) {
            $unserialized = maybe_unserialize($value);
            if (is_array($unserialized)) {
                return $this->format_array_value($unserialized);
            }
            return $this->format_single_value($unserialized, $meta_key);
        }
        
        if (is_array($value)) {
            return $this->format_array_value($value);
        }
        
        return $this->format_single_value($value, $meta_key);
    }

    /**
     * Format single value
     */
    private function format_single_value($value, $meta_key = '') {
        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }
        
        if ($this->is_date_field($meta_key) && !empty($value)) {
            $timestamp = strtotime($value);
            if ($timestamp !== false) {
                return date('Y-m-d H:i:s', $timestamp);
            }
        }
        
        if (is_numeric($value)) {
            if (strpos($meta_key, 'price') !== false || 
                strpos($meta_key, 'cost') !== false || 
                strpos($meta_key, 'amount') !== false) {
                return number_format((float)$value, 2);
            }
            return (string)$value;
        }
        
        if (is_string($value) && strlen($value) > 100) {
            return substr($value, 0, 100) . '...';
        }
        
        return (string)$value;
    }

    /**
     * Format array value
     */
    private function format_array_value($array) {
        if (empty($array)) {
            return '(empty array)';
        }
        
        if ($this->is_associative_array($array)) {
            $items = [];
            foreach ($array as $key => $val) {
                if (is_scalar($val)) {
                    $items[] = $key . ': ' . $this->format_single_value($val);
                } else {
                    $items[] = $key . ': [complex value]';
                }
            }
            return implode(', ', $items);
        }
        
        $simple_values = [];
        foreach ($array as $val) {
            if (is_scalar($val)) {
                $simple_values[] = $this->format_single_value($val);
            }
        }
        
        if (!empty($simple_values)) {
            return '[' . implode(', ', $simple_values) . ']';
        }
        
        return '[array with ' . count($array) . ' items]';
    }

    /**
     * Get context about a post meta field
     */
    private function get_post_meta_field_context($meta_key, $post_id) {
        $context = [];
        
        if (strpos($meta_key, 'date') !== false || 
            strpos($meta_key, 'time') !== false ||
            strpos($meta_key, 'deadline') !== false ||
            strpos($meta_key, 'expiry') !== false) {
            $context['field_type'] = 'Date/Time field';
        } elseif (strpos($meta_key, 'price') !== false || 
                  strpos($meta_key, 'cost') !== false || 
                  strpos($meta_key, 'amount') !== false ||
                  strpos($meta_key, 'budget') !== false) {
            $context['field_type'] = 'Price/Amount field';
        } elseif (strpos($meta_key, 'email') !== false) {
            $context['field_type'] = 'Email field';
        } elseif (strpos($meta_key, 'url') !== false || 
                  strpos($meta_key, 'website') !== false || 
                  strpos($meta_key, 'link') !== false) {
            $context['field_type'] = 'URL/Link field';
        } elseif (strpos($meta_key, 'phone') !== false || 
                  strpos($meta_key, 'mobile') !== false || 
                  strpos($meta_key, 'telephone') !== false) {
            $context['field_type'] = 'Phone number field';
        } elseif (strpos($meta_key, 'image') !== false || 
                  strpos($meta_key, 'photo') !== false || 
                  strpos($meta_key, 'thumbnail') !== false) {
            $context['field_type'] = 'Image field';
        } elseif (strpos($meta_key, 'file') !== false || 
                  strpos($meta_key, 'document') !== false || 
                  strpos($meta_key, 'attachment') !== false) {
            $context['field_type'] = 'File field';
        }
        
        $post_type = get_post_type($post_id);
        $field_labels = $this->get_post_type_field_labels($post_type);
        
        if (isset($field_labels[$meta_key])) {
            $context['field_label'] = $field_labels[$meta_key];
        }
        
        return !empty($context) ? $context : false;
    }

    /**
     * Helper function to check if field is a date field
     */
    private function is_date_field($meta_key) {
        $date_keywords = ['date', 'time', 'datetime', 'deadline', 'expiry', 'start', 'end', 'published', 'created', 'modified'];
        
        foreach ($date_keywords as $keyword) {
            if (stripos($meta_key, $keyword) !== false) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Recursively sort array by keys
     */
    private function recursive_ksort(&$array) {
        if (!is_array($array)) return;
        
        ksort($array);
        foreach ($array as &$value) {
            if (is_array($value)) {
                $this->recursive_ksort($value);
            }
        }
    }

    /**
     * Check if array is associative
     */
    private function is_associative_array($array) {
        if (!is_array($array) || empty($array)) return false;
        
        return array_keys($array) !== range(0, count($array) - 1);
    }

    /**
     * Get field labels for post type
     */
    private function get_post_type_field_labels($post_type) {
        $field_labels = [
            'product' => [
                '_price' => 'Product Price',
                '_regular_price' => 'Regular Price',
                '_sale_price' => 'Sale Price',
                '_stock' => 'Stock Quantity',
                '_sku' => 'SKU',
                'brand' => 'Brand',
                'model' => 'Model',
            ],
            'event' => [
                'event_date' => 'Event Date',
                'event_time' => 'Event Time',
                'location' => 'Location',
                'ticket_price' => 'Ticket Price',
                'capacity' => 'Capacity',
            ],
        ];
        
        return $field_labels[$post_type] ?? [];
    }

    public function check_bulk_operation_taxonomy() {
        if (isset($_REQUEST['delete_tags']) || isset($_REQUEST['action']) || isset($_REQUEST['action2'])) {
            self::$is_bulk_operation = true;
        }
    }

    public function log_menu_update($menu_id, $menu_data = []) {
        $menu = wp_get_nav_menu_object($menu_id);
        $details = [
            'menu_name' => $menu->name ?? 'Unknown',
            'action' => 'Menu updated'
        ];
        
        Site_Logger::log(
            'menu_updated',
            'menu',
            $menu_id,
            $menu->name ?? 'Menu #' . $menu_id,
            $details,
            'info'
        );
    }

    public function log_menu_created($menu_id, $menu_data) {
        $details = [
            'menu_name' => $menu_data['menu-name'] ?? 'New Menu',
            'action' => 'Menu created'
        ];
        
        Site_Logger::log(
            'menu_created',
            'menu',
            $menu_id,
            $menu_data['menu-name'] ?? 'New Menu',
            $details,
            'info'
        );
    }

    public function log_menu_deleted($menu_id) {
        $details = [
            'action' => 'Menu deleted'
        ];
        
        Site_Logger::log(
            'menu_deleted',
            'menu',
            $menu_id,
            'Menu #' . $menu_id,
            $details,
            'warning'
        );
    }

    public function log_sidebar_widgets_update($old_value, $new_value) {
        $details = [
            'action' => 'Sidebar widgets arrangement updated'
        ];
        
        Site_Logger::log(
            'sidebar_widgets_updated',
            'widget',
            0,
            'Sidebar Widgets',
            $details,
            'info'
        );
    }

    public function log_customizer_save($wp_customize) {
        $details = [
            'action' => 'Customizer settings saved'
        ];
        
        Site_Logger::log(
            'customizer_saved',
            'theme',
            0,
            'Customizer',
            $details,
            'info'
        );
    }

    public function log_login_failed($username) {
        $details = [
            'username' => $username,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'Unknown',
            'action' => 'Failed login attempt'
        ];
        
        Site_Logger::log(
            'login_failed',
            'security',
            0,
            'Failed login: ' . $username,
            $details,
            'warning'
        );
    }
    
}    