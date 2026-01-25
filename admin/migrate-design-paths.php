<?php
/**
 * Migration script to fix missing _mg_last_design_path metadata
 * Run this once to populate design paths for existing products
 */

if (!defined('ABSPATH')) {
    exit;
}

class MG_Design_Path_Migration {
    
    public static function run_migration() {
        global $wpdb;
        
        $results = array(
            'total_products' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => array(),
        );
        
        // Get all simple products
        $product_ids = $wpdb->get_col("
            SELECT ID FROM {$wpdb->posts} 
            WHERE post_type = 'product' 
            AND post_status IN ('publish', 'draft', 'private')
        ");
        
        $results['total_products'] = count($product_ids);
        
        foreach ($product_ids as $product_id) {
            // Skip if already has design path
            $existing_path = get_post_meta($product_id, '_mg_last_design_path', true);
            if ($existing_path && file_exists($existing_path)) {
                $results['skipped']++;
                continue;
            }
            
            $design_info = self::find_design_for_product($product_id);
            
            if ($design_info) {
                if (!empty($design_info['path'])) {
                    update_post_meta($product_id, '_mg_last_design_path', $design_info['path']);
                }
                if (!empty($design_info['attachment_id'])) {
                    update_post_meta($product_id, '_mg_last_design_attachment', $design_info['attachment_id']);
                }
                $results['updated']++;
            } else {
                $results['skipped']++;
            }
        }
        
        return $results;
    }
    
    protected static function find_design_for_product($product_id) {
        $product = wc_get_product($product_id);
        if (!$product) {
            return null;
        }
        
        // Strategy 1: Check featured image attachment
        $featured_id = get_post_thumbnail_id($product_id);
        if ($featured_id) {
            $path = get_attached_file($featured_id);
            if ($path && file_exists($path)) {
                $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                if (in_array($ext, array('png', 'jpg', 'jpeg', 'webp'))) {
                    // Exclude mockup renders
                    if (strpos($path, 'mg_mockups') === false && strpos($path, 'mockup-renders') === false) {
                        return array(
                            'path' => wp_normalize_path($path),
                            'attachment_id' => $featured_id,
                        );
                    }
                }
            }
        }
        
        // Strategy 2: Search media library by product name
        $product_name = $product->get_name();
        $sku = $product->get_sku();
        
        if ($product_name) {
            $args = array(
                'post_type' => 'attachment',
                'post_status' => 'inherit',
                'posts_per_page' => 10,
                's' => $product_name,
                'post_mime_type' => array('image/png', 'image/jpeg', 'image/jpg', 'image/webp'),
            );
            
            $attachments = get_posts($args);
            
            foreach ($attachments as $attachment) {
                $path = get_attached_file($attachment->ID);
                if ($path && file_exists($path)) {
                    if (strpos($path, 'mg_mockups') === false && strpos($path, 'mockup-renders') === false) {
                        return array(
                            'path' => wp_normalize_path($path),
                            'attachment_id' => $attachment->ID,
                        );
                    }
                }
            }
        }
        
        // Strategy 3: SCAN ENTIRE UPLOADS DIRECTORY for physical files
        // This finds design files uploaded by the plugin but not registered as attachments
        $uploads_dir = wp_upload_dir();
        $base_dir = wp_normalize_path($uploads_dir['basedir']);
        
        if (!$base_dir || !is_dir($base_dir)) {
            return null;
        }
        
        // Build search patterns based on product data
        $search_terms = array();
        
        if ($product_name) {
            // Add full product name (sanitized)
            $search_terms[] = strtolower(sanitize_file_name($product_name));
            
            // Also add individual words from product name (first word is likely the design name)
            // Example: "alma gyümölcs piros" -> search for "alma"
            $words = preg_split('/[\s\-_]+/', $product_name, -1, PREG_SPLIT_NO_EMPTY);
            foreach ($words as $word) {
                $word = trim($word);
                if (strlen($word) >= 3) { // Skip very short words
                    $search_terms[] = strtolower(sanitize_file_name($word));
                }
            }
        }
        if ($sku) {
            $search_terms[] = strtolower($sku);
            $search_terms[] = strtolower(str_replace('-', '_', $sku));
            $search_terms[] = strtolower(str_replace('_', '-', $sku));
        }
        
        // Remove duplicates and very short terms
        $search_terms = array_filter(array_unique($search_terms), function($term) {
            return strlen($term) >= 3;
        });
        
        if (empty($search_terms)) {
            return null;
        }
        
        // Scan year directories (2020-2026)
        $current_year = date('Y');
        for ($year = 2020; $year <= $current_year; $year++) {
            $year_dir = $base_dir . '/' . $year;
            if (!is_dir($year_dir)) {
                continue;
            }
            
            // Scan month directories (01-12)
            for ($month = 1; $month <= 12; $month++) {
                $month_str = str_pad($month, 2, '0', STR_PAD_LEFT);
                $month_dir = $year_dir . '/' . $month_str;
                
                if (!is_dir($month_dir)) {
                    continue;
                }
                
                // Scan all image files in this month
                $extensions = array('png', 'jpg', 'jpeg', 'webp');
                foreach ($extensions as $ext) {
                    $files = glob($month_dir . '/*.' . $ext);
                    if (!$files) {
                        continue;
                    }
                    
                    foreach ($files as $file_path) {
                        $file_path = wp_normalize_path($file_path);
                        $filename = basename($file_path);
                        $filename_lower = strtolower($filename);
                        
                        // Skip mockup files
                        if (strpos($file_path, 'mg_mockups') !== false || 
                            strpos($file_path, 'mockup-renders') !== false ||
                            strpos($filename_lower, '_front.') !== false ||
                            strpos($filename_lower, '_back.') !== false ||
                            strpos($filename_lower, '_side.') !== false) {
                            continue;
                        }
                        
                        // Check if filename matches any search term
                        foreach ($search_terms as $term) {
                            if (strpos($filename_lower, $term) !== false) {
                                // Found a match!
                                $attachment_id = self::find_attachment_by_path($file_path);
                                return array(
                                    'path' => $file_path,
                                    'attachment_id' => $attachment_id > 0 ? $attachment_id : 0,
                                );
                            }
                        }
                    }
                }
            }
        }
        
        return null;
    }
    
    protected static function find_attachment_by_path($file_path) {
        $uploads = wp_upload_dir();
        $base_dir = wp_normalize_path($uploads['basedir']);
        $normalized_path = wp_normalize_path($file_path);
        
        if (strpos($normalized_path, $base_dir) !== 0) {
            return 0;
        }
        
        $relative = ltrim(substr($normalized_path, strlen($base_dir)), '/');
        
        global $wpdb;
        $attachment_id = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value = %s LIMIT 1",
            $relative
        ));
        
        return $attachment_id ? intval($attachment_id) : 0;
    }
}

// Admin page to run migration
add_action('admin_menu', function() {
    add_submenu_page(
        'mockup-generator',
        'Design Path Migration',
        'Design Path Migration',
        'manage_woocommerce',
        'mg-design-path-migration',
        function() {
            if (!current_user_can('manage_woocommerce')) {
                wp_die('Unauthorized');
            }
            
            echo '<div class="wrap">';
            echo '<h1>Design Path Migration</h1>';
            
            if (isset($_POST['run_migration']) && wp_verify_nonce($_POST['_wpnonce'], 'mg_migration')) {
                echo '<div class="notice notice-info"><p>Running migration... This may take a while.</p></div>';
                
                set_time_limit(300); // 5 minutes
                
                $results = MG_Design_Path_Migration::run_migration();
                
                echo '<div class="notice notice-success"><p><strong>Migration Complete!</strong></p>';
                echo '<ul>';
                echo '<li>Total Products: ' . $results['total_products'] . '</li>';
                echo '<li>Updated: ' . $results['updated'] . '</li>';
                echo '<li>Skipped (already had path or not found): ' . $results['skipped'] . '</li>';
                echo '</ul></div>';
            }
            
            echo '<p>This will scan all products and try to find their design files based on:</p>';
            echo '<ul>';
            echo '<li>Featured image attachment</li>';
            echo '<li>Media library search by product name</li>';
            echo '<li>File name matching (SKU or product name)</li>';
            echo '</ul>';
            echo '<p><strong>Note:</strong> Products that already have a design path will be skipped.</p>';
            
            echo '<form method="post">';
            wp_nonce_field('mg_migration');
            echo '<input type="hidden" name="run_migration" value="1" />';
            echo '<button type="submit" class="button button-primary button-large">Run Migration</button>';
            echo '</form>';
            
            echo '</div>';
        }
    );
}, 100);
