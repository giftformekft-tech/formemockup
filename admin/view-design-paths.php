<?php
/**
 * Admin page to view which design files are assigned to which products
 */

if (!defined('ABSPATH')) {
    exit;
}

// Add admin menu
add_action('admin_menu', function() {
    add_submenu_page(
        'mockup-generator',
        'View Design Paths',
        'View Design Paths',
        'manage_woocommerce',
        'mg-view-design-paths',
        'mg_render_design_paths_page'
    );
}, 101);

function mg_render_design_paths_page() {
    if (!current_user_can('manage_woocommerce')) {
        wp_die('Unauthorized');
    }
    
    global $wpdb;
    
    // Handle form submission
    if (isset($_POST['mg_update_path']) && isset($_POST['product_id']) && check_admin_referer('mg_update_path_' . $_POST['product_id'])) {
        $product_id = intval($_POST['product_id']);
        $new_path = sanitize_text_field(wp_unslash($_POST['design_path']));
        
        if ($product_id > 0) {
            update_post_meta($product_id, '_mg_last_design_path', $new_path);
            
            // Try to find attachment ID for the new path
            $attachment_id = 0;
            if (!empty($new_path)) {
                $uploads = wp_upload_dir();
                $base_dir = wp_normalize_path($uploads['basedir']);
                $normalized_path = wp_normalize_path($new_path);
                
                if (strpos($normalized_path, $base_dir) === 0) {
                    $relative = ltrim(substr($normalized_path, strlen($base_dir)), '/');
                    $attachment_id = $wpdb->get_var($wpdb->prepare(
                        "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value = %s LIMIT 1",
                        $relative
                    ));
                }
            }
            
            if ($attachment_id) {
                update_post_meta($product_id, '_mg_last_design_attachment', $attachment_id);
            } else {
                delete_post_meta($product_id, '_mg_last_design_attachment');
            }
            
            echo '<div class="notice notice-success is-dismissible"><p>Design path updated.</p></div>';
        }
    }
    
    // Filter option
    $filter = isset($_GET['filter']) ? sanitize_text_field($_GET['filter']) : 'all';
    
    // Get all products with or without design paths
    $where = "WHERE p.post_type = 'product' AND p.post_status IN ('publish', 'draft', 'private')";
    
    if ($filter === 'with_path') {
        $where .= " AND pm.meta_value IS NOT NULL AND pm.meta_value != ''";
    } elseif ($filter === 'without_path') {
        $where .= " AND (pm.meta_value IS NULL OR pm.meta_value = '')";
    }
    
    $results = $wpdb->get_results("
        SELECT p.ID, p.post_title, pm.meta_value as design_path
        FROM {$wpdb->posts} p
        LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_mg_last_design_path'
        {$where}
        ORDER BY p.post_title ASC
    ");
    
    echo '<div class="wrap">';
    echo '<h1>Design Path Assignments</h1>';
    
    // Filter tabs
    $all_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status IN ('publish', 'draft', 'private')");
    $with_path_count = $wpdb->get_var("SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id WHERE p.post_type = 'product' AND p.post_status IN ('publish', 'draft', 'private') AND pm.meta_key = '_mg_last_design_path' AND pm.meta_value != ''");
    $without_path_count = $all_count - $with_path_count;
    
    echo '<ul class="subsubsub">';
    echo '<li><a href="?page=mg-view-design-paths&filter=all" class="' . ($filter === 'all' ? 'current' : '') . '">All (' . $all_count . ')</a> | </li>';
    echo '<li><a href="?page=mg-view-design-paths&filter=with_path" class="' . ($filter === 'with_path' ? 'current' : '') . '">With Path (' . $with_path_count . ')</a> | </li>';
    echo '<li><a href="?page=mg-view-design-paths&filter=without_path" class="' . ($filter === 'without_path' ? 'current' : '') . '">Missing Path (' . $without_path_count . ')</a></li>';
    echo '</ul>';
    
    echo '<div style="clear:both; margin-bottom: 10px;"></div>';
    
    if (empty($results)) {
        echo '<div class="notice notice-warning"><p>No products found with this filter.</p></div>';
    } else {
        echo '<p>Showing <strong>' . count($results) . '</strong> products.</p>';
        
        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead>';
        echo '<tr>';
        echo '<th style="width: 60px;">ID</th>';
        echo '<th style="width: 25%;">Product Name</th>';
        echo '<th style="width: 10%;">SKU</th>';
        echo '<th style="width: 45%;">Design File Path</th>';
        echo '<th style="width: 80px;">Status</th>';
        echo '<th style="width: 80px;">Actions</th>';
        echo '</tr>';
        echo '</thead>';
        echo '<tbody>';
        
        foreach ($results as $row) {
            $product = wc_get_product($row->ID);
            $sku = $product ? $product->get_sku() : 'N/A';
            
            // Check if design path is set
            $has_path = !empty($row->design_path);
            
            if ($has_path) {
                $file_exists = file_exists($row->design_path);
                $exists_class = $file_exists ? 'yes' : 'no';
                $exists_text = $file_exists ? 'Found' : 'Missing';
            } else {
                $exists_class = '';
                $exists_text = '-';
            }
            
            $row_class = !$has_path ? 'style="background-color: #fff3cd;"' : '';
            
            echo '<tr ' . $row_class . '>';
            // Form wrapper
            echo '<form method="post" action="">';
            wp_nonce_field('mg_update_path_' . $row->ID);
            echo '<input type="hidden" name="product_id" value="' . esc_attr($row->ID) . '">';
            echo '<input type="hidden" name="mg_update_path" value="1">';
            
            echo '<td>' . esc_html($row->ID) . '</td>';
            echo '<td><a href="' . get_edit_post_link($row->ID) . '" target="_blank"><strong>' . esc_html($row->post_title) . '</strong></a></td>';
            echo '<td><code>' . esc_html($sku) . '</code></td>';
            
            // Inline Edit Field
            echo '<td>';
            echo '<input type="text" name="design_path" value="' . esc_attr($row->design_path) . '" style="width: 100%; font-size: 11px; font-family: monospace;">';
            echo '</td>';
            
            echo '<td>' . ($exists_class ? '<span class="dashicons dashicons-' . $exists_class . '"></span> ' : '') . $exists_text . '</td>';
            
            echo '<td>';
            echo '<button type="submit" class="button button-primary button-small">Save</button>';
            echo ' <a href="' . add_query_arg('inspect_id', $row->ID) . '" class="button button-small" style="margin-left:5px;">🔍 Info</a>';
            echo '</td>';
            
            echo '</form>'; // Close form
            echo '</tr>';
        }
        
        echo '</tbody>';
        echo '</table>';
    }
    
    // Statistics
    $total_products = $wpdb->get_var("
        SELECT COUNT(*) FROM {$wpdb->posts}
        WHERE post_type = 'product'
        AND post_status IN ('publish', 'draft', 'private')
    ");
    
    $with_paths = count($results);
    $without_paths = $total_products - $with_paths;
    
    echo '<div style="margin-top: 20px; padding: 15px; background: #f9f9f9; border: 1px solid #ddd;">';
    echo '<h3>Statistics</h3>';
    echo '<ul>';
    echo '<li>Total Products: <strong>' . $total_products . '</strong></li>';
    echo '<li>With Design Path: <strong>' . $with_paths . '</strong> (' . round(($with_paths / $total_products) * 100, 1) . '%)</li>';
    echo '<li>Without Design Path: <strong>' . $without_paths . '</strong> (' . round(($without_paths / $total_products) * 100, 1) . '%)</li>';
    echo '</ul>';
    echo '</div>';
    
    // Debug Inspector Handler
    if (isset($_GET['inspect_id'])) {
        $inspect_id = intval($_GET['inspect_id']);
        $meta = get_post_meta($inspect_id);
        echo '<div style="background: #fff; padding: 20px; border: 2px solid #0073aa; margin: 20px 0;">';
        echo '<h3>🔍 Debug Meta: Product ID ' . $inspect_id . '</h3>';
        echo '<p>Searching for anything that looks like a file path or design reference...</p>';
        echo '<table class="widefat striped">';
        echo '<thead><tr><th>Meta Key</th><th>Value</th></tr></thead><tbody>';
        
        foreach ($meta as $key => $values) {
            foreach ($values as $val) {
                // Highlight potential design paths
                $style = '';
                if (strpos($val, '.png') !== false || strpos($val, 'uploads') !== false || strpos($key, 'file') !== false || strpos($key, 'path') !== false || strpos($key, 'design') !== false) {
                    $style = 'background-color: #dff0d8; font-weight: bold;';
                }
                
                // Truncate long arrays/objects
                if (is_serialized($val)) {
                    $val = '[Serialized Data]';
                }
                
                echo '<tr style="' . $style . '">';
                echo '<td>' . esc_html($key) . '</td>';
                echo '<td style="word-break: break-all;">' . esc_html(substr($val, 0, 300)) . '</td>';
                echo '</tr>';
            }
        }
        echo '</tbody></table>';
        echo '<p><a href="' . remove_query_arg('inspect_id') . '" class="button">Close Inspector</a></p>';
        echo '</div>';
    }

    echo '</div>';
}
