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
        // Strategy 1: Check featured image attachment
        $featured_id = get_post_thumbnail_id($product_id);
        if ($featured_id) {
            $path = get_attached_file($featured_id);
            if ($path && file_exists($path)) {
                // Check if this is a design file (PNG, JPG, WebP in uploads root or subdirs)
                $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                if (in_array($ext, array('png', 'jpg', 'jpeg', 'webp'))) {
                    // Exclude mockup renders (they're in mg_mockups or mockup-renders)
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
        $product = wc_get_product($product_id);
        if (!$product) {
            return null;
        }
        
        $product_name = $product->get_name();
        if (!$product_name) {
            return null;
        }
        
        // Search for attachments with similar title
        $args = array(
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'posts_per_page' => 5,
            's' => $product_name,
            'post_mime_type' => array('image/png', 'image/jpeg', 'image/jpg', 'image/webp'),
        );
        
        $attachments = get_posts($args);
        
        foreach ($attachments as $attachment) {
            $path = get_attached_file($attachment->ID);
            if ($path && file_exists($path)) {
                // Exclude mockup renders
                if (strpos($path, 'mg_mockups') === false && strpos($path, 'mockup-renders') === false) {
                    return array(
                        'path' => wp_normalize_path($path),
                        'attachment_id' => $attachment->ID,
                    );
                }
            }
        }
        
        // Strategy 3: Look in uploads directory for files matching product name or SKU
        $sku = $product->get_sku();
        if ($sku) {
            $uploads_dir = wp_upload_dir();
            $base_dir = $uploads_dir['basedir'];
            
            // Common patterns
            $patterns = array(
                $sku . '.png',
                $sku . '.jpg',
                $sku . '.jpeg',
                $sku . '.webp',
                sanitize_file_name($product_name) . '.png',
                sanitize_file_name($product_name) . '.jpg',
                sanitize_file_name($product_name) . '.jpeg',
                sanitize_file_name($product_name) . '.webp',
            );
            
            foreach ($patterns as $pattern) {
                // Search in year/month subdirectories
                $years = glob($base_dir . '/20*', GLOB_ONLYDIR);
                foreach ($years as $year_dir) {
                    $months = glob($year_dir . '/*', GLOB_ONLYDIR);
                    foreach ($months as $month_dir) {
                        $file_path = $month_dir . '/' . $pattern;
                        if (file_exists($file_path)) {
                            // Try to find attachment ID
                            $attachment_id = self::find_attachment_by_path($file_path);
                            return array(
                                'path' => wp_normalize_path($file_path),
                                'attachment_id' => $attachment_id > 0 ? $attachment_id : 0,
                            );
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
