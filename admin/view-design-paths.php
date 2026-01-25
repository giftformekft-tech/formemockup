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
    
    // Get all products with design paths
    $results = $wpdb->get_results("
        SELECT p.ID, p.post_title, pm.meta_value as design_path
        FROM {$wpdb->posts} p
        INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
        WHERE p.post_type = 'product'
        AND p.post_status IN ('publish', 'draft', 'private')
        AND pm.meta_key = '_mg_last_design_path'
        ORDER BY p.post_title ASC
    ");
    
    echo '<div class="wrap">';
    echo '<h1>Design Path Assignments</h1>';
    
    if (empty($results)) {
        echo '<div class="notice notice-warning"><p>No products have design paths assigned yet. Run the migration first.</p></div>';
    } else {
        echo '<p>Found <strong>' . count($results) . '</strong> products with design paths.</p>';
        
        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead>';
        echo '<tr>';
        echo '<th style="width: 60px;">ID</th>';
        echo '<th style="width: 30%;">Product Name</th>';
        echo '<th style="width: 15%;">SKU</th>';
        echo '<th>Design File Path</th>';
        echo '<th style="width: 100px;">File Exists?</th>';
        echo '<th style="width: 80px;">Actions</th>';
        echo '</tr>';
        echo '</thead>';
        echo '<tbody>';
        
        foreach ($results as $row) {
            $product = wc_get_product($row->ID);
            $sku = $product ? $product->get_sku() : 'N/A';
            $file_exists = file_exists($row->design_path);
            $exists_class = $file_exists ? 'yes' : 'no';
            $exists_text = $file_exists ? '✓ Yes' : '✗ No';
            
            echo '<tr>';
            echo '<td>' . esc_html($row->ID) . '</td>';
            echo '<td><strong>' . esc_html($row->post_title) . '</strong></td>';
            echo '<td><code>' . esc_html($sku) . '</code></td>';
            echo '<td><code style="font-size: 11px;">' . esc_html($row->design_path) . '</code></td>';
            echo '<td><span class="dashicons dashicons-' . $exists_class . '"></span> ' . $exists_text . '</td>';
            echo '<td>';
            echo '<a href="' . get_edit_post_link($row->ID) . '" class="button button-small">Edit</a>';
            echo '</td>';
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
    
    echo '</div>';
}
