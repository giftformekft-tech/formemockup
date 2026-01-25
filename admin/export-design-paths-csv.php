<?php
/**
 * CSV Export/Import for Design Path Assignments
 * Simple manual matching workflow
 */

if (!defined('ABSPATH')) {
    exit;
}

// Add admin menu
add_action('admin_menu', function() {
    add_submenu_page(
        'mockup-generator',
        'CSV Export/Import',
        'CSV Export/Import',
        'manage_woocommerce',
        'mg-csv-design-paths',
        'mg_render_csv_page'
    );
}, 102);

function mg_render_csv_page() {
    if (!current_user_can('manage_woocommerce')) {
        wp_die('Unauthorized');
    }
    
    // Handle CSV Import
    if (isset($_POST['mg_import_csv']) && isset($_FILES['csv_file'])) {
        check_admin_referer('mg_csv_import');
        
        $file = $_FILES['csv_file'];
        if ($file['error'] === UPLOAD_ERR_OK) {
            $handle = fopen($file['tmp_name'], 'r');
            $imported = 0;
            $skipped = 0;
            
            // Skip header row
            fgetcsv($handle);
            
            while (($row = fgetcsv($handle)) !== false) {
                if (count($row) < 3) continue;
                
                $product_id = intval($row[0]);
                $design_path = trim($row[2]);
                
                if ($product_id > 0 && !empty($design_path)) {
                    update_post_meta($product_id, '_mg_last_design_path', $design_path);
                    $imported++;
                } else {
                    $skipped++;
                }
            }
            
            fclose($handle);
            echo '<div class="notice notice-success"><p>Imported ' . $imported . ' paths. Skipped ' . $skipped . '.</p></div>';
        }
    }
    
    // Handle CSV Export
    if (isset($_GET['export'])) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=design-paths-' . date('Y-m-d') . '.csv');
        
        $output = fopen('php://output', 'w');
        
        // Header
        fputcsv($output, array('Product ID', 'Product Name', 'Current Design Path'));
        
        // Get all products
        global $wpdb;
        $results = $wpdb->get_results("
            SELECT p.ID, p.post_title, pm.meta_value as design_path
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_mg_last_design_path'
            WHERE p.post_type = 'product' AND p.post_status IN ('publish', 'draft', 'private')
            ORDER BY p.post_title ASC
        ");
        
        foreach ($results as $row) {
            fputcsv($output, array(
                $row->ID,
                $row->post_title,
                $row->design_path ? $row->design_path : ''
            ));
        }
        
        fclose($output);
        exit;
    }
    
    echo '<div class="wrap">';
    echo '<h1>📊 CSV Export/Import - Design Paths</h1>';
    
    echo '<div style="background: #fff; padding: 20px; margin: 20px 0; border: 1px solid #ccc;">';
    echo '<h2>📥 Export to CSV</h2>';
    echo '<p>Download a CSV file with all products and their current design paths (if any).</p>';
    echo '<p><a href="?page=mg-csv-design-paths&export=1" class="button button-primary">Download CSV</a></p>';
    echo '</div>';
    
    echo '<div style="background: #fff; padding: 20px; margin: 20px 0; border: 1px solid #ccc;">';
    echo '<h2>📤 Import from CSV</h2>';
    echo '<p><strong>Instructions:</strong></p>';
    echo '<ol>';
    echo '<li>Export the CSV file above</li>';
    echo '<li>Open it in Excel or Google Sheets</li>';
    echo '<li>Fill in the "Current Design Path" column with the correct file paths</li>';
    echo '<li>Save the file and upload it here</li>';
    echo '</ol>';
    echo '<p><strong>Path format:</strong> <code>C:/path/to/uploads/2026/01/FILENAME.png</code></p>';
    
    echo '<form method="post" enctype="multipart/form-data">';
    wp_nonce_field('mg_csv_import');
    echo '<input type="file" name="csv_file" accept=".csv" required>';
    echo '<button type="submit" name="mg_import_csv" class="button button-primary" style="margin-left: 10px;">Import CSV</button>';
    echo '</form>';
    echo '</div>';
    
    // List available PNG files for reference
    echo '<div style="background: #fff; padding: 20px; margin: 20px 0; border: 1px solid #ccc;">';
    echo '<h2>📁 Available Design Files (2026/01)</h2>';
    $uploads_dir = wp_upload_dir();
    $design_folder = $uploads_dir['basedir'] . '/2026/01';
    
    if (is_dir($design_folder)) {
        $files = glob($design_folder . '/*.png');
        echo '<p>Showing ' . count($files) . ' PNG files:</p>';
        echo '<textarea readonly style="width: 100%; height: 300px; font-family: monospace; font-size: 11px;">';
        foreach ($files as $file) {
            echo wp_normalize_path($file) . "\n";
        }
        echo '</textarea>';
    } else {
        echo '<p>Folder not found.</p>';
    }
    echo '</div>';
    
    echo '</div>';
}
