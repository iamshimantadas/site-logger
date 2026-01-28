<?php
class Site_Logger_ACF_Tracker {
    
    private static $old_acf_field_groups = [];
    private static $is_acf_field_group_screen = false;
    
    public static function init() {
        if (!function_exists('acf')) {
            return;
        }
        
        $instance = new self();
        $instance->setup_hooks();
    }
    
    private function setup_hooks() {
        // Detect when we're on ACF field group edit screen
        add_action('current_screen', [$this, 'detect_acf_screen']);
        
        // Store complete field group data BEFORE saving
        add_action('load-post.php', [$this, 'store_field_group_data_before_edit']);
        add_action('load-post-new.php', [$this, 'store_field_group_data_before_edit']);
        
        // Also store on admin_init (backup method)
        add_action('admin_init', [$this, 'store_acf_field_group_backup'], 5);
        
        // Log field group changes AFTER saving
        add_action('acf/update_field_group', [$this, 'log_field_group_changes'], 20, 1);
        
        // Log field group duplication
        add_action('acf/duplicate_field_group', [$this, 'log_field_group_duplicate'], 10, 2);
        
        // Log field group deletion
        add_action('delete_post', [$this, 'log_field_group_deletion'], 10, 1);
        
        // Log individual field value changes
        add_action('acf/save_post', [$this, 'store_old_acf_values'], 5);
        add_action('acf/save_post', [$this, 'log_acf_value_changes'], 20);
    }
    
    /**
     * Detect if we're on ACF field group screen
     */
    public function detect_acf_screen($screen) {
        if ($screen->post_type === 'acf-field-group') {
            self::$is_acf_field_group_screen = true;
        }
    }
    
    /**
     * Store COMPLETE field group data before editing
     */
    public function store_field_group_data_before_edit() {
        global $post;
        
        if (!self::$is_acf_field_group_screen || !$post || $post->post_type !== 'acf-field-group') {
            return;
        }
        
        $field_group_id = $post->ID;
        
        // Only store once
        if (isset(self::$old_acf_field_groups[$field_group_id])) {
            return;
        }
        
        // Get complete field group data
        $field_group = $this->get_complete_field_group_data($field_group_id);
        
        if ($field_group) {
            self::$old_acf_field_groups[$field_group_id] = $field_group;
            
            // Debug: Store what we captured
            error_log('ACF Field Group Data Stored for ID: ' . $field_group_id);
            error_log('Field Group Title: ' . ($field_group['basic']['title'] ?? 'N/A'));
            error_log('Total Fields: ' . count($field_group['fields']));
        }
    }
    
    /**
     * Backup method to store ACF field group data
     */
    public function store_acf_field_group_backup() {
        global $post;
        
        if (!is_admin() || !$post || $post->post_type !== 'acf-field-group') {
            return;
        }
        
        $field_group_id = $post->ID;
        
        // Only store if not already stored
        if (isset(self::$old_acf_field_groups[$field_group_id])) {
            return;
        }
        
        // Get complete field group data
        $field_group = $this->get_complete_field_group_data($field_group_id);
        
        if ($field_group) {
            self::$old_acf_field_groups[$field_group_id] = $field_group;
        }
    }
    
    /**
     * Get COMPLETE field group data including ALL properties
     */
    private function get_complete_field_group_data($field_group_id) {
        $field_group = acf_get_field_group($field_group_id);
        
        if (!$field_group) {
            return null;
        }
        
        $data = [
            'basic' => $this->get_field_group_basic_properties($field_group),
            'location' => $this->get_field_group_location_rules($field_group),
            'presentation' => $this->get_field_group_presentation_properties($field_group),
            'fields' => $this->get_all_field_properties($field_group_id)
        ];
        
        return $data;
    }
    
    /**
     * Get field group BASIC properties
     */
    private function get_field_group_basic_properties($field_group) {
        return [
            'title' => $field_group['title'] ?? '',
            'key' => $field_group['key'] ?? '',
            'description' => $field_group['description'] ?? '',
            'active' => $field_group['active'] ?? 1,
            'menu_order' => $field_group['menu_order'] ?? 0
        ];
    }
    
    /**
     * Get field group LOCATION rules
     */
    private function get_field_group_location_rules($field_group) {
        $location_rules = [];
        
        if (!empty($field_group['location'])) {
            foreach ($field_group['location'] as $group_index => $group) {
                foreach ($group as $rule_index => $rule) {
                    $location_rules[$group_index][$rule_index] = [
                        'param' => $rule['param'] ?? '',
                        'operator' => $rule['operator'] ?? '==',
                        'value' => $rule['value'] ?? ''
                    ];
                }
            }
        }
        
        return $location_rules;
    }
    
    /**
     * Get field group PRESENTATION properties
     */
    private function get_field_group_presentation_properties($field_group) {
        return [
            'position' => $field_group['position'] ?? 'normal',
            'style' => $field_group['style'] ?? 'default',
            'label_placement' => $field_group['label_placement'] ?? 'top',
            'instruction_placement' => $field_group['instruction_placement'] ?? 'label',
            'hide_on_screen' => $field_group['hide_on_screen'] ?? [],
            'menu_order' => $field_group['menu_order'] ?? 0
        ];
    }
    
    /**
     * Get ALL field properties (including sub-fields)
     */
    private function get_all_field_properties($field_group_id) {
        $fields = acf_get_fields($field_group_id);
        $all_fields = [];
        
        if ($fields) {
            foreach ($fields as $field) {
                $field_key = $field['key'] ?? uniqid();
                $all_fields[$field_key] = $this->extract_complete_field_properties($field);
                
                // Handle nested fields
                if (!empty($field['sub_fields'])) {
                    foreach ($field['sub_fields'] as $sub_field) {
                        $sub_field_key = $sub_field['key'] ?? uniqid();
                        $all_fields[$sub_field_key] = array_merge(
                            $this->extract_complete_field_properties($sub_field),
                            ['parent_field' => $field_key]
                        );
                    }
                }
                
                // Handle layout fields (Flexible Content)
                if ($field['type'] === 'flexible_content' && !empty($field['layouts'])) {
                    foreach ($field['layouts'] as $layout) {
                        if (!empty($layout['sub_fields'])) {
                            foreach ($layout['sub_fields'] as $layout_field) {
                                $layout_field_key = $layout_field['key'] ?? uniqid();
                                $all_fields[$layout_field_key] = array_merge(
                                    $this->extract_complete_field_properties($layout_field),
                                    [
                                        'parent_field' => $field_key,
                                        'layout' => $layout['name'] ?? ''
                                    ]
                                );
                            }
                        }
                    }
                }
            }
        }
        
        return $all_fields;
    }
    
    /**
     * Extract COMPLETE field properties
     */
    private function extract_complete_field_properties($field) {
        $properties = [
            // Basic Properties
            'label' => $field['label'] ?? '',
            'name' => $field['name'] ?? '',
            'type' => $field['type'] ?? 'text',
            'key' => $field['key'] ?? '',
            
            // Validation Properties
            'required' => $field['required'] ?? 0,
            'conditional_logic' => $field['conditional_logic'] ?? [],
            
            // Value Properties
            'default_value' => $field['default_value'] ?? '',
            'placeholder' => $field['placeholder'] ?? '',
            'instructions' => $field['instructions'] ?? '',
            'prepend' => $field['prepend'] ?? '',
            'append' => $field['append'] ?? '',
            
            // Presentation Properties
            'wrapper' => $field['wrapper'] ?? [],
            'class' => $field['class'] ?? '',
            'id' => $field['id'] ?? '',
            
            // Type-Specific Properties
            'choices' => $field['choices'] ?? [],
            'allow_null' => $field['allow_null'] ?? 0,
            'multiple' => $field['multiple'] ?? 0,
            'ui' => $field['ui'] ?? 0,
            'ajax' => $field['ajax'] ?? 0,
            'return_format' => $field['return_format'] ?? 'value',
            'library' => $field['library'] ?? 'all',
            'min' => $field['min'] ?? '',
            'max' => $field['max'] ?? '',
            'step' => $field['step'] ?? '',
            'maxlength' => $field['maxlength'] ?? '',
            'rows' => $field['rows'] ?? '',
            'new_lines' => $field['new_lines'] ?? 'wpautop',
            'layout' => $field['layout'] ?? 'table',
            'button_label' => $field['button_label'] ?? '',
            'collapsed' => $field['collapsed'] ?? 0,
            'max' => $field['max'] ?? '',
            'min' => $field['min'] ?? '',
            
            // Special Properties
            'message' => $field['message'] ?? '', // for true_false
            'save_custom' => $field['save_custom'] ?? 0, // for taxonomy
            'load_terms' => $field['load_terms'] ?? 0, // for taxonomy
            'save_terms' => $field['save_terms'] ?? 0, // for taxonomy
            'allow_custom' => $field['allow_custom'] ?? 0, // for taxonomy
            'return_format' => $field['return_format'] ?? 'value',
            'post_type' => $field['post_type'] ?? [], // for relationship
            'taxonomy' => $field['taxonomy'] ?? [], // for taxonomy
            'filters' => $field['filters'] ?? [], // for relationship
            'elements' => $field['elements'] ?? [], // for link
            'mime_types' => $field['mime_types'] ?? '', // for file
            'min_size' => $field['min_size'] ?? '',
            'max_size' => $field['max_size'] ?? '',
            'min_width' => $field['min_width'] ?? '',
            'max_width' => $field['max_width'] ?? '',
            'min_height' => $field['min_height'] ?? '',
            'max_height' => $field['max_height'] ?? '',
            'file_type' => $field['file_type'] ?? '',
            'image_size' => $field['image_size'] ?? '',
            'center_lat' => $field['center_lat'] ?? '',
            'center_lng' => $field['center_lng'] ?? '',
            'zoom' => $field['zoom'] ?? '',
            'height' => $field['height'] ?? '',
            'tabs' => $field['tabs'] ?? 'all',
            'toolbar' => $field['toolbar'] ?? 'full',
            'media_upload' => $field['media_upload'] ?? 1,
            'delay' => $field['delay'] ?? 0,
            'sub_fields' => [], // Will be populated separately
            'layouts' => $field['layouts'] ?? [] // For flexible content
        ];
        
        return $properties;
    }
    
    /**
     * Log field group changes
     */
    public function log_field_group_changes($field_group) {
        $field_group_id = $field_group['ID'] ?? 0;
        
        if (!$field_group_id) {
            return;
        }
        
        // Get old data
        $old_data = isset(self::$old_acf_field_groups[$field_group_id]) 
            ? self::$old_acf_field_groups[$field_group_id] 
            : null;
        
        // Get new data
        $new_data = $this->get_complete_field_group_data($field_group_id);
        
        if (!$new_data) {
            return;
        }
        
        // Compare and get detailed changes
        $changes = $this->compare_field_group_changes($old_data, $new_data, $field_group_id);
        
        // Log if there are changes
        if (!empty($changes)) {
            $this->log_changes_to_database($field_group, $changes);
        }
        
        // Clean up
        unset(self::$old_acf_field_groups[$field_group_id]);
    }
    
    /**
     * Compare field group changes in DETAIL
     */
    private function compare_field_group_changes($old_data, $new_data, $field_group_id) {
        $changes = [];
        
        // If no old data, it's a new field group
        if (!$old_data) {
            $changes['action'] = 'New field group created';
            $changes['basic'] = $new_data['basic'];
            $changes['location'] = $new_data['location'];
            $changes['presentation'] = $new_data['presentation'];
            $changes['field_count'] = count($new_data['fields']);
            return $changes;
        }
        
        // 1. Compare Basic Properties
        $basic_changes = $this->compare_basic_properties($old_data['basic'], $new_data['basic']);
        if (!empty($basic_changes)) {
            $changes['basic'] = $basic_changes;
        }
        
        // 2. Compare Location Rules
        $location_changes = $this->compare_location_rules($old_data['location'], $new_data['location']);
        if (!empty($location_changes)) {
            $changes['location'] = $location_changes;
        }
        
        // 3. Compare Presentation Properties
        $presentation_changes = $this->compare_presentation_properties($old_data['presentation'], $new_data['presentation']);
        if (!empty($presentation_changes)) {
            $changes['presentation'] = $presentation_changes;
        }
        
        // 4. Compare ALL Fields
        $field_changes = $this->compare_all_fields($old_data['fields'], $new_data['fields']);
        if (!empty($field_changes)) {
            $changes['fields'] = $field_changes;
        }
        
        return $changes;
    }
    
    /**
     * Compare basic properties
     */
    private function compare_basic_properties($old_basic, $new_basic) {
        $changes = [];
        
        $properties = ['title', 'description', 'active', 'menu_order'];
        
        foreach ($properties as $prop) {
            $old_val = $old_basic[$prop] ?? '';
            $new_val = $new_basic[$prop] ?? '';
            
            if ($this->values_differ($old_val, $new_val)) {
                $changes[$prop] = [
                    'old' => $this->format_value($old_val, $prop),
                    'new' => $this->format_value($new_val, $prop)
                ];
            }
        }
        
        return $changes;
    }
    
    /**
     * Compare location rules
     */
    private function compare_location_rules($old_location, $new_location) {
        $changes = [];
        
        $old_serialized = serialize($old_location);
        $new_serialized = serialize($new_location);
        
        if ($old_serialized !== $new_serialized) {
            // Find specific changes
            $added = [];
            $removed = [];
            $modified = [];
            
            // Compare each rule group
            foreach ($new_location as $group_index => $new_group) {
                if (!isset($old_location[$group_index])) {
                    $added["Group " . ($group_index + 1)] = $this->format_location_group($new_group);
                } else {
                    $old_group = $old_location[$group_index];
                    if (serialize($old_group) !== serialize($new_group)) {
                        $modified["Group " . ($group_index + 1)] = [
                            'old' => $this->format_location_group($old_group),
                            'new' => $this->format_location_group($new_group)
                        ];
                    }
                }
            }
            
            // Find removed groups
            foreach ($old_location as $group_index => $old_group) {
                if (!isset($new_location[$group_index])) {
                    $removed["Group " . ($group_index + 1)] = $this->format_location_group($old_group);
                }
            }
            
            if (!empty($added)) $changes['added'] = $added;
            if (!empty($removed)) $changes['removed'] = $removed;
            if (!empty($modified)) $changes['modified'] = $modified;
        }
        
        return $changes;
    }
    
    /**
     * Format location group for display
     */
    private function format_location_group($group) {
        $rules = [];
        foreach ($group as $rule) {
            $param = $this->get_location_param_label($rule['param'] ?? '');
            $operator = $rule['operator'] ?? '==';
            $value = $this->get_location_value_label($rule['param'] ?? '', $rule['value'] ?? '');
            
            $rules[] = "{$param} {$operator} {$value}";
        }
        
        return implode(' AND ', $rules);
    }
    
    /**
     * Get location parameter label
     */
    private function get_location_param_label($param) {
        $labels = [
            'post_type' => 'Post Type',
            'post_template' => 'Post Template',
            'post_status' => 'Post Status',
            'post' => 'Post',
            'post_category' => 'Category',
            'post_format' => 'Post Format',
            'post_taxonomy' => 'Taxonomy',
            'page_type' => 'Page Type',
            'page_parent' => 'Page Parent',
            'page_template' => 'Page Template',
            'user_form' => 'User Form',
            'user_role' => 'User Role',
            'taxonomy' => 'Taxonomy',
            'attachment' => 'Media',
            'comment' => 'Comment',
            'widget' => 'Widget',
            'options_page' => 'Options Page',
            'nav_menu' => 'Navigation Menu',
            'nav_menu_item' => 'Menu Item'
        ];
        
        return $labels[$param] ?? ucfirst(str_replace('_', ' ', $param));
    }
    
    /**
     * Get location value label
     */
    private function get_location_value_label($param, $value) {
        switch ($param) {
            case 'post_type':
                $post_type_obj = get_post_type_object($value);
                return $post_type_obj ? $post_type_obj->labels->singular_name : $value;
                
            case 'post_template':
                return $value === 'default' ? 'Default Template' : $value;
                
            case 'post_status':
                $statuses = [
                    'publish' => 'Published',
                    'draft' => 'Draft',
                    'pending' => 'Pending',
                    'private' => 'Private',
                    'trash' => 'Trashed'
                ];
                return $statuses[$value] ?? ucfirst($value);
                
            case 'post':
            case 'page_parent':
                $post = get_post($value);
                return $post ? $post->post_title : "Post #{$value}";
                
            case 'post_category':
            case 'post_taxonomy':
                $term = get_term($value);
                return $term ? $term->name : "Term #{$value}";
                
            case 'user_form':
                $forms = [
                    'all' => 'All Forms',
                    'add' => 'Add User',
                    'edit' => 'Edit User',
                    'register' => 'Registration'
                ];
                return $forms[$value] ?? $value;
                
            case 'user_role':
                $wp_roles = wp_roles();
                return $wp_roles->roles[$value]['name'] ?? ucfirst($value);
                
            default:
                return $value;
        }
    }
    
    /**
     * Compare presentation properties
     */
    private function compare_presentation_properties($old_presentation, $new_presentation) {
        $changes = [];
        
        $properties = [
            'position' => [
                'normal' => 'Normal (after content)',
                'side' => 'Side',
                'acf_after_title' => 'After Title'
            ],
            'style' => [
                'default' => 'Standard (WP metabox)',
                'seamless' => 'Seamless (no metabox)'
            ],
            'label_placement' => [
                'top' => 'Top',
                'left' => 'Left'
            ],
            'instruction_placement' => [
                'label' => 'Below labels',
                'field' => 'Below fields'
            ],
            'hide_on_screen' => []
        ];
        
        foreach ($properties as $prop => $labels) {
            $old_val = $old_presentation[$prop] ?? '';
            $new_val = $new_presentation[$prop] ?? '';
            
            // Special handling for hide_on_screen
            if ($prop === 'hide_on_screen') {
                $hide_changes = $this->compare_hide_on_screen($old_val, $new_val);
                if (!empty($hide_changes)) {
                    $changes[$prop] = $hide_changes;
                }
                continue;
            }
            
            if ($this->values_differ($old_val, $new_val)) {
                $old_label = is_array($labels) && isset($labels[$old_val]) ? $labels[$old_val] : $old_val;
                $new_label = is_array($labels) && isset($labels[$new_val]) ? $labels[$new_val] : $new_val;
                
                $changes[$prop] = [
                    'old' => $old_label ?: '(empty)',
                    'new' => $new_label ?: '(empty)'
                ];
            }
        }
        
        return $changes;
    }
    
    /**
     * Compare hide_on_screen settings
     */
    private function compare_hide_on_screen($old_hide, $new_hide) {
        if (!is_array($old_hide)) $old_hide = [];
        if (!is_array($new_hide)) $new_hide = [];
        
        $added = array_diff($new_hide, $old_hide);
        $removed = array_diff($old_hide, $new_hide);
        
        if (empty($added) && empty($removed)) {
            return [];
        }
        
        $hide_labels = [
            'permalink' => 'Permalink',
            'the_content' => 'Content Editor',
            'excerpt' => 'Excerpt',
            'custom_fields' => 'Custom Fields',
            'discussion' => 'Discussion',
            'comments' => 'Comments',
            'revisions' => 'Revisions',
            'slug' => 'Slug',
            'author' => 'Author',
            'format' => 'Format',
            'page_attributes' => 'Page Attributes',
            'featured_image' => 'Featured Image',
            'categories' => 'Categories',
            'tags' => 'Tags',
            'send-trackbacks' => 'Send Trackbacks'
        ];
        
        $changes = [];
        
        if (!empty($added)) {
            $changes['added'] = [];
            foreach ($added as $item) {
                $changes['added'][] = $hide_labels[$item] ?? $item;
            }
        }
        
        if (!empty($removed)) {
            $changes['removed'] = [];
            foreach ($removed as $item) {
                $changes['removed'][] = $hide_labels[$item] ?? $item;
            }
        }
        
        return $changes;
    }
    
    /**
     * Compare ALL fields in detail
     */
    private function compare_all_fields($old_fields, $new_fields) {
        $changes = [];
        
        // Find added fields
        $added_fields = array_diff_key($new_fields, $old_fields);
        if (!empty($added_fields)) {
            $changes['added'] = [];
            foreach ($added_fields as $field_key => $field) {
                $changes['added'][$field['name']] = [
                    'label' => $field['label'],
                    'type' => $field['type'],
                    'is_subfield' => isset($field['parent_field']) ? 'Yes' : 'No'
                ];
                if (isset($field['parent_field'])) {
                    $parent = $old_fields[$field['parent_field']] ?? $new_fields[$field['parent_field']] ?? null;
                    if ($parent) {
                        $changes['added'][$field['name']]['parent'] = $parent['label'] ?? $parent['name'];
                    }
                }
            }
        }
        
        // Find removed fields
        $removed_fields = array_diff_key($old_fields, $new_fields);
        if (!empty($removed_fields)) {
            $changes['removed'] = [];
            foreach ($removed_fields as $field_key => $field) {
                $changes['removed'][$field['name']] = [
                    'label' => $field['label'],
                    'type' => $field['type'],
                    'is_subfield' => isset($field['parent_field']) ? 'Yes' : 'No'
                ];
                if (isset($field['parent_field'])) {
                    $parent = $old_fields[$field['parent_field']] ?? null;
                    if ($parent) {
                        $changes['removed'][$field['name']]['parent'] = $parent['label'] ?? $parent['name'];
                    }
                }
            }
        }
        
        // Compare modified fields
        $common_fields = array_intersect_key($old_fields, $new_fields);
        $modified_fields = [];
        
        foreach ($common_fields as $field_key => $old_field) {
            $new_field = $new_fields[$field_key];
            $field_changes = $this->compare_single_field_changes($old_field, $new_field);
            
            if (!empty($field_changes)) {
                $modified_fields[$old_field['label']] = $field_changes;
            }
        }
        
        if (!empty($modified_fields)) {
            $changes['modified'] = $modified_fields;
        }
        
        // Count summary
        $changes['summary'] = sprintf(
            'Added: %d, Removed: %d, Modified: %d',
            count($added_fields),
            count($removed_fields),
            count($modified_fields)
        );
        
        return $changes;
    }
    
    /**
     * Compare single field changes in DETAIL
     */
    private function compare_single_field_changes($old_field, $new_field) {
        $changes = [];
        
        // Compare ALL field properties
        $all_properties = array_keys(array_merge($old_field, $new_field));
        
        foreach ($all_properties as $property) {
            $old_value = $old_field[$property] ?? null;
            $new_value = $new_field[$property] ?? null;
            
            // Skip empty arrays that are the same
            if (is_array($old_value) && is_array($new_value) && empty($old_value) && empty($new_value)) {
                continue;
            }
            
            // Skip if values are the same
            if ($this->values_differ($old_value, $new_value)) {
                $property_label = $this->get_field_property_label($property);
                
                if (is_array($old_value) && is_array($new_value)) {
                    // For arrays, show detailed comparison
                    $array_changes = $this->compare_array_changes($old_value, $new_value, $property);
                    if (!empty($array_changes)) {
                        $changes[$property_label] = $array_changes;
                    }
                } else {
                    $changes[$property_label] = [
                        'old' => $this->format_value($old_value, $property),
                        'new' => $this->format_value($new_value, $property)
                    ];
                }
            }
        }
        
        return $changes;
    }
    
    /**
     * Compare array changes
     */
    private function compare_array_changes($old_array, $new_array, $property_name) {
        $changes = [];
        
        // Special handling for conditional_logic
        if ($property_name === 'conditional_logic') {
            if (!empty($old_array) || !empty($new_array)) {
                $old_formatted = $this->format_conditional_logic($old_array);
                $new_formatted = $this->format_conditional_logic($new_array);
                
                if ($old_formatted !== $new_formatted) {
                    return [
                        'old' => $old_formatted,
                        'new' => $new_formatted
                    ];
                }
            }
            return [];
        }
        
        // Special handling for wrapper
        if ($property_name === 'wrapper') {
            $wrapper_changes = [];
            $wrapper_props = ['width', 'class', 'id'];
            
            foreach ($wrapper_props as $prop) {
                $old_val = $old_array[$prop] ?? '';
                $new_val = $new_array[$prop] ?? '';
                
                if ($old_val !== $new_val) {
                    $wrapper_changes[$prop] = [
                        'old' => $old_val ?: '(empty)',
                        'new' => $new_val ?: '(empty)'
                    ];
                }
            }
            
            if (!empty($wrapper_changes)) {
                return ['wrapper_changes' => $wrapper_changes];
            }
            return [];
        }
        
        // Special handling for choices
        if ($property_name === 'choices') {
            $added = array_diff_assoc($new_array, $old_array);
            $removed = array_diff_assoc($old_array, $new_array);
            
            if (!empty($added) || !empty($removed)) {
                $choice_changes = [];
                
                if (!empty($added)) {
                    $choice_changes['added'] = [];
                    foreach ($added as $key => $value) {
                        $choice_changes['added'][] = "{$key}: {$value}";
                    }
                }
                
                if (!empty($removed)) {
                    $choice_changes['removed'] = [];
                    foreach ($removed as $key => $value) {
                        $choice_changes['removed'][] = "{$key}: {$value}";
                    }
                }
                
                return $choice_changes;
            }
            return [];
        }
        
        // Default array comparison
        $old_serialized = serialize($old_array);
        $new_serialized = serialize($new_array);
        
        if ($old_serialized !== $new_serialized) {
            return [
                'old' => json_encode($old_array, JSON_PRETTY_PRINT),
                'new' => json_encode($new_array, JSON_PRETTY_PRINT)
            ];
        }
        
        return [];
    }
    
    /**
     * Format conditional logic for display
     */
    private function format_conditional_logic($logic) {
        if (empty($logic) || !is_array($logic)) {
            return 'No conditional logic';
        }
        
        $groups = [];
        foreach ($logic as $group) {
            $rules = [];
            foreach ($group as $rule) {
                $field = $rule['field'] ?? '';
                $operator = $rule['operator'] ?? '';
                $value = $rule['value'] ?? '';
                
                $rules[] = "{$field} {$operator} '{$value}'";
            }
            $groups[] = '(' . implode(' AND ', $rules) . ')';
        }
        
        return implode(' OR ', $groups);
    }
    
    /**
     * Get field property label
     */
    private function get_field_property_label($property) {
        $labels = [
            'label' => 'Field Label',
            'name' => 'Field Name',
            'type' => 'Field Type',
            'key' => 'Field Key',
            'required' => 'Required',
            'conditional_logic' => 'Conditional Logic',
            'default_value' => 'Default Value',
            'placeholder' => 'Placeholder',
            'instructions' => 'Instructions',
            'prepend' => 'Prepend Text',
            'append' => 'Append Text',
            'wrapper' => 'Wrapper Settings',
            'class' => 'CSS Class',
            'id' => 'CSS ID',
            'choices' => 'Choices',
            'allow_null' => 'Allow Null',
            'multiple' => 'Multiple',
            'ui' => 'Stylized UI',
            'ajax' => 'AJAX',
            'return_format' => 'Return Format',
            'library' => 'Library',
            'min' => 'Minimum',
            'max' => 'Maximum',
            'step' => 'Step',
            'maxlength' => 'Max Length',
            'rows' => 'Rows',
            'new_lines' => 'New Lines',
            'layout' => 'Layout',
            'button_label' => 'Button Label',
            'collapsed' => 'Collapsed',
            'message' => 'Message',
            'save_custom' => 'Save Custom Terms',
            'load_terms' => 'Load Terms',
            'save_terms' => 'Save Terms',
            'allow_custom' => 'Allow Custom',
            'post_type' => 'Post Types',
            'taxonomy' => 'Taxonomies',
            'filters' => 'Filters',
            'elements' => 'Link Elements',
            'mime_types' => 'MIME Types',
            'min_size' => 'Min File Size',
            'max_size' => 'Max File Size',
            'min_width' => 'Min Width',
            'max_width' => 'Max Width',
            'min_height' => 'Min Height',
            'max_height' => 'Max Height',
            'file_type' => 'File Type',
            'image_size' => 'Image Size',
            'center_lat' => 'Center Latitude',
            'center_lng' => 'Center Longitude',
            'zoom' => 'Zoom Level',
            'height' => 'Height',
            'tabs' => 'Tabs',
            'toolbar' => 'Toolbar',
            'media_upload' => 'Media Upload',
            'delay' => 'Delay Initialization'
        ];
        
        return $labels[$property] ?? ucfirst(str_replace('_', ' ', $property));
    }
    
    /**
     * Format value for display
     */
    private function format_value($value, $property = '') {
        if (is_null($value)) {
            return '(empty)';
        }
        
        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }
        
        if (is_array($value)) {
            if (empty($value)) {
                return '(empty array)';
            }
            return json_encode($value);
        }
        
        if ($value === '' || $value === 0 || $value === '0') {
            return '(empty)';
        }
        
        return (string) $value;
    }
    
    /**
     * Check if values differ
     */
    private function values_differ($old_value, $new_value) {
        if ($old_value === $new_value) {
            return false;
        }
        
        // Handle arrays
        if (is_array($old_value) && is_array($new_value)) {
            return serialize($old_value) !== serialize($new_value);
        }
        
        // Handle empty values
        if (empty($old_value) && empty($new_value)) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Log changes to database
     */
    private function log_changes_to_database($field_group, $changes) {
        $edit_url = admin_url('post.php?post=' . $field_group['ID'] . '&action=edit');
        
        // Prepare details
        $details = [
            'field_group' => $field_group['title'],
            'key' => $field_group['key'] ?? '',
            'edit_acf_group' => "<a href='" . esc_url($edit_url) . "' target='_blank'>🔧 Edit ACF Field Group</a>",
            'changes' => $changes
        ];
        
        // Add basic changes if present
        if (isset($changes['basic'])) {
            foreach ($changes['basic'] as $prop => $change) {
                $details['basic_' . $prop] = $change;
            }
        }
        
        // Add location changes if present
        if (isset($changes['location'])) {
            $details['location_changes'] = $changes['location'];
        }
        
        // Add presentation changes if present
        if (isset($changes['presentation'])) {
            foreach ($changes['presentation'] as $prop => $change) {
                $details['presentation_' . $prop] = $change;
            }
        }
        
        // Add field changes if present (summarized)
        if (isset($changes['fields'])) {
            $details['field_changes'] = $changes['fields']['summary'] ?? 'Fields updated';
            
            // Add detailed field changes (but limit to avoid huge logs)
            if (isset($changes['fields']['added']) && count($changes['fields']['added']) <= 5) {
                $details['fields_added'] = $changes['fields']['added'];
            }
            if (isset($changes['fields']['removed']) && count($changes['fields']['removed']) <= 5) {
                $details['fields_removed'] = $changes['fields']['removed'];
            }
            
            // Log major field modifications separately
            if (isset($changes['fields']['modified'])) {
                foreach ($changes['fields']['modified'] as $field_name => $field_changes) {
                    if (count($field_changes) <= 3) { // Only log simple changes
                        $details['field_modified_' . $field_name] = $field_changes;
                    }
                }
            }
        }
        
        // Log to database
        Site_Logger::log(
            'acf_field_group_updated',
            'acf',
            $field_group['ID'],
            $field_group['title'],
            $details,
            'info'
        );
    }
    
    /**
     * Log field group duplication
     */
    public function log_field_group_duplicate($new_field_group, $old_field_group) {
        $edit_url = admin_url('post.php?post=' . $new_field_group['ID'] . '&action=edit');
        
        $details = [
            'original' => $old_field_group['title'],
            'duplicate' => $new_field_group['title'],
            'key' => $new_field_group['key'],
            'edit_acf_group' => "<a href='" . esc_url($edit_url) . "' target='_blank'>🔧 Edit ACF Field Group</a>"
        ];
        
        Site_Logger::log(
            'acf_field_group_duplicated',
            'acf',
            $new_field_group['ID'],
            $new_field_group['title'],
            $details,
            'info'
        );
    }
    
    /**
     * Log field group deletion
     */
    public function log_field_group_deletion($post_id) {
        $post = get_post($post_id);
        
        if (!$post || $post->post_type !== 'acf-field-group') {
            return;
        }
        
        $field_group = acf_get_field_group($post_id);
        
        if ($field_group) {
            $details = [
                'field_group' => $field_group['title'],
                'key' => $field_group['key']
            ];
            
            Site_Logger::log(
                'acf_field_group_deleted',
                'acf',
                $post_id,
                $field_group['title'],
                $details,
                'warning'
            );
        }
    }
    
    /**
     * Store old ACF field values before saving
     */
    /**
 * Store old ACF field values before saving
 */
public function store_old_acf_values($post_id) {
    // Skip field groups themselves
    if (get_post_type($post_id) === 'acf-field-group') {
        return;
    }
    
    // Skip if it's a field group or options page
    if ($post_id === 'acf-field-group' || $post_id === 'options') {
        return;
    }
    
    // Check if it's a term
    $is_term = false;
    $term_id = 0;
    
    if (strpos($post_id, 'term_') === 0) {
        $is_term = true;
        $term_id = intval(str_replace('term_', '', $post_id));
    }
    
    if (function_exists('get_field_objects')) {
        $acf_fields = get_field_objects($post_id);
        if ($acf_fields) {
            // Store in a global variable accessible to the main hooks class
            global $site_logger_old_acf_data;
            $site_logger_old_acf_data[$post_id] = [];
            
            foreach ($acf_fields as $field_name => $field) {
                $site_logger_old_acf_data[$post_id][$field_name] = [
                    'value' => $field['value'],
                    'label' => $field['label'] ?? $field_name,
                    'type' => $field['type'] ?? 'text'
                ];
            }
        }
    }
    
    // If it's a term, also prepare to log term-specific changes
    if ($is_term && $term_id > 0) {
        // We'll handle term logging in the main save hook
        add_action('edited_term', function($term_id) {
            $this->log_term_acf_value_changes($term_id);
        }, 20, 1);
    }
}

    
    /**
     * Log ACF field value changes
     */
    /**
 * Log ACF field value changes on posts/pages/terms
 */
public function log_acf_value_changes($post_id) {
    // // Skip if we shouldn't process
    // if ($this->should_skip_acf_logging($post_id)) {
    //     return;
    // }
    
    // // Get old values from global storage
    // global $site_logger_old_acf_data;
    // $old_data = isset($site_logger_old_acf_data[$post_id]) ? $site_logger_old_acf_data[$post_id] : [];
    
    // if (empty($old_data)) {
    //     // No old data to compare against (might be new post/term)
    //     return;
    // }
    
    // // Get current field values
    // $current_fields = get_field_objects($post_id);
    // if (!$current_fields || !is_array($current_fields)) {
    //     return;
    // }

     // Skip if we shouldn't process
    if ($this->should_skip_acf_logging($post_id)) {
        return;
    }
    
    // Check if it's a term
    if (strpos($post_id, 'term_') === 0) {
        // Terms are handled separately in log_term_acf_value_changes
        return;
    }
    
    // Get old values from global storage
    global $site_logger_old_acf_data;
    $old_data = isset($site_logger_old_acf_data[$post_id]) ? $site_logger_old_acf_data[$post_id] : [];
    
    if (empty($old_data)) {
        // No old data to compare against (might be new post)
        return;
    }
    
    // Get current field values
    $current_fields = get_field_objects($post_id);
    if (!$current_fields || !is_array($current_fields)) {
        return;
    }
    
    // Track changes
    $changes = [];
    $field_type_changes = [];
    $has_changes = false;
    
    foreach ($current_fields as $field_name => $current_field) {
        $old_field = isset($old_data[$field_name]) ? $old_data[$field_name] : null;
        
        if ($old_field) {
            // Check if field type changed
            if ($old_field['type'] !== $current_field['type']) {
                $field_type_changes[$field_name] = [
                    'field' => $current_field['label'] ?? $field_name,
                    'old_type' => $old_field['type'],
                    'new_type' => $current_field['type']
                ];
                $has_changes = true;
            }
            
            // Check if value changed
            if ($this->values_differ($old_field['value'], $current_field['value'])) {
                $field_label = $current_field['label'] ?? $field_name;
                $changes[$field_label] = [
                    'old' => $this->format_acf_field_value($old_field['value'], $old_field['type']),
                    'new' => $this->format_acf_field_value($current_field['value'], $current_field['type'])
                ];
                $has_changes = true;
            }
        } else {
            // New field with value (field existed before but now has value)
            if (!empty($current_field['value'])) {
                $field_label = $current_field['label'] ?? $field_name;
                $changes[$field_label] = [
                    'action' => 'field_value_added',
                    'value' => $this->format_acf_field_value($current_field['value'], $current_field['type'])
                ];
                $has_changes = true;
            }
        }
    }
    
    // Check for removed values (fields that had value but now are empty)
    foreach ($old_data as $field_name => $old_field) {
        if (!isset($current_fields[$field_name]) || empty($current_fields[$field_name]['value'])) {
            if (!empty($old_field['value'])) {
                $field_label = $old_field['label'] ?? $field_name;
                $changes[$field_label . '_removed'] = [
                    'action' => 'field_value_cleared',
                    'old_value' => $this->format_acf_field_value($old_field['value'], $old_field['type'])
                ];
                $has_changes = true;
            }
        }
    }
    
    // Log changes if any
    if ($has_changes) {
        $this->log_acf_field_value_changes_to_db($post_id, $changes, $field_type_changes);
    }
    
    // Clean up old data
    unset($site_logger_old_acf_data[$post_id]);
}

/**
 * Check if we should skip ACF logging for this post/term
 */
private function should_skip_acf_logging($post_id) {
    // Skip field groups themselves (handled separately)
    if (get_post_type($post_id) === 'acf-field-group') {
        return true;
    }
    
    // Skip options pages for now
    if ($post_id === 'options' || $post_id === 'acf-field-group') {
        return true;
    }
    
    // Skip auto-saves and revisions
    if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
        return true;
    }
    
    // Skip if it's a term (we'll handle differently)
    if (strpos($post_id, 'term_') === 0) {
        // We'll handle terms separately
        return false;
    }
    
    // Get the post
    $post = get_post($post_id);
    if (!$post) {
        return true;
    }
    
    // Skip certain post types
    $skip_post_types = ['revision', 'nav_menu_item', 'custom_css', 'customize_changeset', 'acf-field', 'acf-field-group'];
    if (in_array($post->post_type, $skip_post_types)) {
        return true;
    }
    
    return false;
}

/**
 * Format ACF field value for display
 */
private function format_acf_field_value($value, $field_type) {
    if (is_null($value) || $value === '' || $value === false || $value === []) {
        return '(empty)';
    }
    
    // Handle different field types
    switch ($field_type) {
        case 'image':
        case 'file':
            if (is_numeric($value)) {
                $attachment = get_post($value);
                return $attachment ? '📷 ' . $attachment->post_title : '📎 Attachment #' . $value;
            } elseif (is_array($value) && isset($value['ID'])) {
                $attachment = get_post($value['ID']);
                return $attachment ? '📷 ' . $attachment->post_title : '📎 Attachment #' . $value['ID'];
            } elseif (is_string($value)) {
                return '📄 ' . basename($value);
            }
            return '📎 Media file';
            
        case 'gallery':
            if (is_array($value)) {
                $count = count($value);
                $names = [];
                foreach (array_slice($value, 0, 3) as $image_id) {
                    $attachment = get_post($image_id);
                    $names[] = $attachment ? $attachment->post_title : 'Image #' . $image_id;
                }
                return '🖼️ Gallery: ' . $count . ' images (' . implode(', ', $names) . ($count > 3 ? '...' : '') . ')';
            }
            return '🖼️ Gallery';
            
        case 'relationship':
        case 'post_object':
            if (is_array($value)) {
                $count = count($value);
                $titles = [];
                foreach (array_slice($value, 0, 3) as $item) {
                    $post_id = is_object($item) ? $item->ID : $item;
                    $post = get_post($post_id);
                    $titles[] = $post ? '📝 ' . $post->post_title : 'Post #' . $post_id;
                }
                return '🔗 ' . $count . ' items (' . implode(', ', $titles) . ($count > 3 ? '...' : '') . ')';
            } else {
                $post = get_post($value);
                return $post ? '📝 ' . $post->post_title : 'Post #' . $value;
            }
            
        case 'user':
            if (is_array($value)) {
                $count = count($value);
                $names = [];
                foreach (array_slice($value, 0, 3) as $user_id) {
                    $user = get_user_by('id', $user_id);
                    $names[] = $user ? '👤 ' . $user->display_name : 'User #' . $user_id;
                }
                return '👥 ' . $count . ' users (' . implode(', ', $names) . ($count > 3 ? '...' : '') . ')';
            } else {
                $user = get_user_by('id', $value);
                return $user ? '👤 ' . $user->display_name : 'User #' . $value;
            }
            
        case 'page_link':
        case 'link':
            if (is_array($value)) {
                return '🔗 ' . ($value['title'] ?? 'Link') . ' → ' . ($value['url'] ?? '');
            } elseif (is_string($value)) {
                return '🔗 ' . $value;
            }
            return '🔗 Link';
            
        case 'google_map':
            if (is_array($value) && isset($value['address'])) {
                return '📍 ' . $value['address'];
            }
            return '📍 Map location';
            
        case 'true_false':
            return $value ? '✅ Yes' : '❌ No';
            
        case 'select':
        case 'checkbox':
        case 'radio':
        case 'button_group':
            if (is_array($value)) {
                return '📋 ' . implode(', ', array_slice($value, 0, 3)) . (count($value) > 3 ? '...' : '');
            }
            return '📋 ' . (string) $value;
            
        case 'color_picker':
            return '🎨 ' . $value;
            
        case 'date_picker':
            return '📅 ' . $value;
            
        case 'date_time_picker':
            return '🕒 ' . $value;
            
        case 'time_picker':
            return '⏰ ' . $value;
            
        case 'wysiwyg':
        case 'textarea':
            $stripped = wp_strip_all_tags($value);
            return '📝 ' . (strlen($stripped) > 50 ? substr($stripped, 0, 50) . '...' : $stripped);
            
        case 'repeater':
        case 'flexible_content':
        case 'group':
            if (is_array($value)) {
                $count = count($value);
                return '📑 ' . $count . ' item' . ($count !== 1 ? 's' : '');
            }
            return '📑 Repeater/Group field';
            
        case 'taxonomy':
            if (is_array($value)) {
                $count = count($value);
                $terms = [];
                foreach (array_slice($value, 0, 3) as $term_id) {
                    $term = get_term($term_id);
                    $terms[] = $term ? '🏷️ ' . $term->name : 'Term #' . $term_id;
                }
                return '🏷️ ' . $count . ' terms (' . implode(', ', $terms) . ($count > 3 ? '...' : '') . ')';
            } else {
                $term = get_term($value);
                return $term ? '🏷️ ' . $term->name : 'Term #' . $value;
            }
            
        case 'email':
            return '📧 ' . $value;
            
        case 'url':
            return '🌐 ' . $value;
            
        case 'password':
            return '🔒 ' . str_repeat('•', min(strlen($value), 8));
            
        case 'number':
        case 'range':
            return '🔢 ' . $value;
            
        case 'oembed':
            return '🎬 Embedded media';
            
        default:
            if (is_array($value)) {
                $count = count($value);
                return '📊 Array: ' . $count . ' item' . ($count !== 1 ? 's' : '');
            }
            if (is_object($value)) {
                return '⚙️ ' . get_class($value);
            }
            $str_value = (string) $value;
            return (strlen($str_value) > 50 ? substr($str_value, 0, 50) . '...' : $str_value);
    }
}

/**
 * Log ACF field value changes to database
 */
private function log_acf_field_value_changes_to_db($post_id, $changes, $field_type_changes = []) {
    // Determine object type and name
    $object_type = '';
    $object_name = '';
    $edit_url = '';
    $view_url = '';
    
    // Check if it's a term
    if (strpos($post_id, 'term_') === 0) {
        $term_id = intval(str_replace('term_', '', $post_id));
        $term = get_term($term_id);
        
        if ($term && !is_wp_error($term)) {
            $object_type = 'term';
            $object_name = $term->name;
            $edit_url = get_edit_term_link($term_id, $term->taxonomy);
            $term_url = get_term_link($term_id, $term->taxonomy);
            if (!is_wp_error($term_url)) {
                $view_url = $term_url;
            }
        }
    } else {
        // It's a post
        $post = get_post($post_id);
        if ($post) {
            $object_type = $post->post_type;
            $object_name = $post->post_title;
            $edit_url = get_edit_post_link($post_id);
            $view_url = get_permalink($post_id);
        }
    }
    
    if (!$object_type) {
        return;
    }
    
    // Prepare details
    $details = [
        'action' => 'ACF field values updated',
        'total_fields_changed' => count($changes),
        'fields_changed' => $changes
    ];
    
    // Add field type changes if any
    if (!empty($field_type_changes)) {
        $details['field_type_changes'] = $field_type_changes;
    }
    
    // Add links
    if ($edit_url) {
        $details['edit_' . $object_type] = "<a href='" . esc_url($edit_url) . "' target='_blank'>✏️ Edit " . ($object_type === 'term' ? 'term' : 'post') . "</a>";
    }
    if ($view_url) {
        $details['view_' . $object_type] = "<a href='" . esc_url($view_url) . "' target='_blank'>👁️ View " . ($object_type === 'term' ? 'term' : 'post') . "</a>";
    }
    
    // Log to database
    Site_Logger::log(
        'acf_fields_updated',
        $object_type,
        $post_id,
        $object_name,
        $details,
        'info'
    );
}

/**
 * Handle term ACF value changes separately
 */
public function log_term_acf_value_changes($term_id) {
    $post_id = 'term_' . $term_id;
    
    // Get old values
    global $site_logger_old_acf_data;
    $old_data = isset($site_logger_old_acf_data[$post_id]) ? $site_logger_old_acf_data[$post_id] : [];
    
    if (empty($old_data)) {
        return;
    }
    
    $term = get_term($term_id);
    if (!$term || is_wp_error($term)) {
        return;
    }
    
    // Get current field values
    $current_fields = get_field_objects($post_id);
    if (!$current_fields || !is_array($current_fields)) {
        return;
    }
    
    // Track changes
    $changes = [];
    $has_changes = false;
    
    foreach ($current_fields as $field_name => $current_field) {
        $old_field = isset($old_data[$field_name]) ? $old_data[$field_name] : null;
        
        if ($old_field) {
            // Check if value changed
            if ($this->values_differ($old_field['value'], $current_field['value'])) {
                $field_label = $current_field['label'] ?? $field_name;
                $changes[$field_label] = [
                    'old' => $this->format_acf_field_value($old_field['value'], $old_field['type']),
                    'new' => $this->format_acf_field_value($current_field['value'], $current_field['type'])
                ];
                $has_changes = true;
            }
        } else {
            // New field with value
            if (!empty($current_field['value'])) {
                $field_label = $current_field['label'] ?? $field_name;
                $changes[$field_label] = [
                    'action' => 'field_value_added',
                    'value' => $this->format_acf_field_value($current_field['value'], $current_field['type'])
                ];
                $has_changes = true;
            }
        }
    }
    
    // Check for removed values
    foreach ($old_data as $field_name => $old_field) {
        if (!isset($current_fields[$field_name]) || empty($current_fields[$field_name]['value'])) {
            if (!empty($old_field['value'])) {
                $field_label = $old_field['label'] ?? $field_name;
                $changes[$field_label . '_removed'] = [
                    'action' => 'field_value_cleared',
                    'old_value' => $this->format_acf_field_value($old_field['value'], $old_field['type'])
                ];
                $has_changes = true;
            }
        }
    }
    
    // Log changes if any
    if ($has_changes) {
        $edit_url = get_edit_term_link($term_id, $term->taxonomy);
        $term_url = get_term_link($term_id, $term->taxonomy);
        
        $details = [
            'taxonomy' => get_taxonomy($term->taxonomy)->labels->singular_name ?? $term->taxonomy,
            'action' => 'ACF field values updated for term',
            'fields_changed' => count($changes),
            'changes' => $changes
        ];
        
        if ($edit_url) {
            $details['edit_term'] = "<a href='" . esc_url($edit_url) . "' target='_blank'>✏️ Edit term</a>";
        }
        
        if (!is_wp_error($term_url) && $term_url) {
            $details['view_term'] = "<a href='" . esc_url($term_url) . "' target='_blank'>👁️ View term</a>";
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
    
    // Clean up
    unset($site_logger_old_acf_data[$post_id]);
}


}