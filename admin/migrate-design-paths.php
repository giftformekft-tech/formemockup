<?php
/**
 * Migration script to fix missing _mg_last_design_path metadata
 * Run this once to populate design paths for existing products
 */

if (!defined('ABSPATH')) {
    exit;
}

class MG_Design_Path_Migration {
    
    public static function run_migration($force_overwrite = false, $batch_size = 100, $offset = 0) {
        global $wpdb;
        
        $results = array(
            'total_products' => 0,
            'processed' => 0,
            'updated' => 0,
            'skipped' => 0,
            'has_more' => false,
            'next_offset' => 0,
        );
        
        // Get total count
        $total = $wpdb->get_var("
            SELECT COUNT(ID) FROM {$wpdb->posts} 
            WHERE post_type = 'product' 
            AND post_status IN ('publish', 'draft', 'private')
        ");
        
        // Get batch of products
        $product_ids = $wpdb->get_col($wpdb->prepare("
            SELECT ID FROM {$wpdb->posts} 
            WHERE post_type = 'product' 
            AND post_status IN ('publish', 'draft', 'private')
            ORDER BY ID ASC
            LIMIT %d OFFSET %d
        ", $batch_size, $offset));
        
        $results['total_products'] = intval($total);
        $results['processed'] = count($product_ids);
        
        foreach ($product_ids as $product_id) {
            // Skip if already has design path (unless force overwrite)
            if (!$force_overwrite) {
                $existing_path = get_post_meta($product_id, '_mg_last_design_path', true);
                if ($existing_path && file_exists($existing_path)) {
                    $results['skipped']++;
                    continue;
                }
            }
            
            $design_info = self::find_design_for_product($product_id);
            
            if ($design_info) {
                // Found a design file - update meta
                if (!empty($design_info['path'])) {
                    update_post_meta($product_id, '_mg_last_design_path', $design_info['path']);
                }
                if (!empty($design_info['attachment_id'])) {
                    update_post_meta($product_id, '_mg_last_design_attachment', $design_info['attachment_id']);
                }
                $results['updated']++;
            } else {
                // No design file found
                if ($force_overwrite) {
                    // In force overwrite mode, clear invalid paths
                    $existing_path = get_post_meta($product_id, '_mg_last_design_path', true);
                    if ($existing_path) {
                        // Delete the invalid path
                        delete_post_meta($product_id, '_mg_last_design_path');
                        delete_post_meta($product_id, '_mg_last_design_attachment');
                        $results['updated']++; // Count as updated (cleared)
                    } else {
                        $results['skipped']++;
                    }
                } else {
                    $results['skipped']++;
                }
            }
        }
        
        // Check if there are more products
        $next_offset = $offset + $batch_size;
        $results['has_more'] = $next_offset < $total;
        $results['next_offset'] = $next_offset;
        
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
        
        // Strategy 3: Scan uploads/2026/01 folder (where all design PNGs are)
        $uploads_dir = wp_upload_dir();
        $base_dir = wp_normalize_path($uploads_dir['basedir']);
        
        // Hardcoded to 2026/01 as confirmed by user
        $design_folder = $base_dir . '/2026/01';
        
        if (!is_dir($design_folder)) {
            return null;
        }
        
        // Scan all PNG files in this folder
        $files = @glob($design_folder . '/*.png');
        if (!$files || !is_array($files)) {
            return null;
        }
        
        // IMPORTANT: Sort files by basename length (LONGEST first)
        // This ensures more SPECIFIC filenames match before generic ones
        usort($files, function($a, $b) {
            $len_a = strlen(basename($a));
            $len_b = strlen(basename($b));
            return $len_b - $len_a; // Descending order (longest first)
        });
        
        // Open log file
        $log_file = $uploads_dir['basedir'] . '/migration_debug.txt';
        $log = @fopen($log_file, 'a');
        
        $product_title = $product->post_title;
        $product_slug = $product->post_name; // Get slug
        
        $best_match = null;
        $best_score = 0;
        
        // --- NORMALIZE TITLE ---
        $title_lower = strtolower($product_title);
        $title_no_accents = remove_accents($title_lower);
        $title_normalized = preg_replace('/[\s\-_]+/', '-', $title_no_accents);
        $title_normalized = trim($title_normalized, '-');
        
        // Count words in original title (split by spaces/dashes)
        $word_count = count(array_filter(explode('-', $title_normalized), function($t) { 
            return strlen(trim($t)) >= 2; 
        }));
        
        // CRITICAL: Only remove category keywords if product has 2+ words
        // This prevents single-word products from being completely stripped
        $title_tokens_raw = explode('-', $title_normalized);
        $title_tokens = array();
        
        if ($word_count >= 2) {
            // Get all WooCommerce product category names dynamically
            $category_keywords = array();
            $categories = get_terms(array(
                'taxonomy' => 'product_cat',
                'hide_empty' => false,
            ));
            
            if (!is_wp_error($categories) && !empty($categories)) {
                foreach ($categories as $cat) {
                    $cat_slug = remove_accents(strtolower($cat->slug));
                    $cat_name = remove_accents(strtolower($cat->name));
                    $category_keywords[] = $cat_slug;
                    $category_keywords[] = $cat_name;
                }
            }
            
            // Add common suffixes that don't appear in filenames
            $category_keywords = array_merge($category_keywords, array(
                'akcio',
                'akcios',
                'uj',
                'ujdonsag'
            ));
            
            $category_keywords = array_unique($category_keywords);
            
        // Remove category keywords ONLY FROM THE END (Suffix Stripping)
        // This prevents removing "Gamer" from "Gamer T-Shirt" but removes it from "Cool T-Shirt Gamer"
        
        while (!empty($title_tokens) && count($title_tokens) > 1) { // Keep at least 1 word
            $last_token = end($title_tokens);
            if (in_array($last_token, $category_keywords)) {
                array_pop($title_tokens);
            } else {
                break; // Stop if the last word is not a keyword
            }
        }

        } else {
            // If only 1 word, keep everything (don't filter)
            $title_tokens = array_filter($title_tokens_raw, function($t) { 
                return strlen(trim($t)) >= 2; 
            });
        }
        
        // Rebuild normalized title WITHOUT category words (if filtered)
        $title_normalized_clean = implode('-', $title_tokens);

        // --- NORMALIZE SLUG ---
        $slug_lower = strtolower($product_slug);
        $slug_normalized = preg_replace('/[\s\-_]+/', '-', $slug_lower);
        $slug_normalized = trim($slug_normalized, '-');
        
        // Iterate ALL files - SIMPLE MATCHING (no scoring)
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
            
            // Get filename without extension
            $filename_no_ext = pathinfo($filename_lower, PATHINFO_FILENAME);
            
            // Normalize filename: remove accents, convert all separators to dashes
            $filename_no_accents = remove_accents($filename_no_ext);
            $filename_normalized = preg_replace('/[\s\-_]+/', '-', $filename_no_accents);
            $filename_normalized = trim($filename_normalized, '-');
            
            // Skip very short filenames (less than 4 chars)
            if (strlen($filename_normalized) < 4) {
                continue;
            }
            
            // --- SIMPLE MATCHING LOGIC (NO SCORING, NO SKU) ---
            
            // 1. EXACT Match (Slug or Cleaned Title)
            if ($filename_normalized === $slug_normalized || $filename_normalized === $title_normalized_clean) {
                if ($log) {
                    fwrite($log, "MATCH: Exact match '$filename' for product '$product_title'\n");
                    fclose($log);
                }
                $attachment_id = self::find_attachment_by_path($file_path);
                return array(
                    'path' => $file_path,
                    'attachment_id' => $attachment_id > 0 ? $attachment_id : 0,
                );
            }
            
            // 2. PREFIX Match (Product name STARTS WITH filename)
            // For shorter filenames like "FUTAR.png" matching "FUTAR SZIV LELEK"
            if (strpos($title_normalized_clean, $filename_normalized) === 0 ||
                strpos($slug_normalized, $filename_normalized) === 0) {
                if ($log) {
                    fwrite($log, "MATCH: Product '$product_title' starts with filename '$filename'\n");
                    fclose($log);
                }
                $attachment_id = self::find_attachment_by_path($file_path);
                return array(
                    'path' => $file_path,
                    'attachment_id' => $attachment_id > 0 ? $attachment_id : 0,
                );
            }
            
            // 3. CONTAINS Match (Filename STARTS WITH product name)
            // For longer filenames like "FUTAR-SZIV-LELEK-KOR.png" matching "FUTAR SZIV LELEK"
            if (strpos($filename_normalized, $title_normalized_clean) === 0 ||
                strpos($filename_normalized, $slug_normalized) === 0) {
                if ($log) {
                    fwrite($log, "MATCH: Filename '$filename' starts with product '$product_title'\n");
                    fclose($log);
                }
                $attachment_id = self::find_attachment_by_path($file_path);
                return array(
                    'path' => $file_path,
                    'attachment_id' => $attachment_id > 0 ? $attachment_id : 0,
                );
            }
        }
        
        if ($log) fclose($log);
        
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
            
            echo '<p>This will scan all products and try to find their design files based on:</p>';
            echo '<ul>';
            echo '<li>Featured image attachment (if not a mockup)</li>';
            echo '<li>Media library search by product name</li>';
            echo '<li>File name matching (SKU or product name tokens)</li>';
            echo '</ul>';
            
            echo '<div style="background: #fff3cd; border-left: 4px solid #ffc107; padding: 12px; margin: 20px 0;">';
            echo '<p><strong>⚠️ Important:</strong></p>';
            echo '<ul style="margin: 5px 0 0 20px;">';
            echo '<li>Processes products in batches of 100 to prevent timeout</li>';
            echo '<li>By default, products with existing design paths are skipped</li>';
            echo '<li>Use "Force Overwrite" to re-scan ALL products</li>';
            echo '<li>The script prioritizes non-mockup files</li>';
            echo '</ul>';
            echo '</div>';
            
            echo '<div id="migration-controls">';
            echo '<p><label>';
            echo '<input type="checkbox" id="force-overwrite" />';
            echo ' <strong>Force Overwrite</strong> - Update ALL products, even if they already have a design path';
            echo '</label></p>';
            echo '<button id="start-migration" class="button button-primary button-large">Start Migration</button>';
            echo '</div>';
            
            echo '<div id="migration-progress" style="display:none; margin-top: 20px;">';
            echo '<div style="background: #fff; border: 1px solid #ccc; padding: 15px;">';
            echo '<h3>Migration Progress</h3>';
            echo '<div class="progress-bar" style="background: #f0f0f0; height: 30px; border-radius: 3px; overflow: hidden; margin: 10px 0;">';
            echo '<div id="progress-fill" style="background: #2271b1; height: 100%; width: 0%; transition: width 0.3s;"></div>';
            echo '</div>';
            echo '<p id="progress-text">Initializing...</p>';
            echo '<div id="progress-stats" style="margin-top: 15px; font-family: monospace; background: #f9f9f9; padding: 10px; border-radius: 3px;"></div>';
            echo '</div>';
            echo '</div>';
            
            echo '<script>
            jQuery(document).ready(function($) {
                let totalUpdated = 0;
                let totalSkipped = 0;
                let totalProcessed = 0;
                
                $("#start-migration").click(function() {
                    const forceOverwrite = $("#force-overwrite").is(":checked");
                    totalUpdated = 0;
                    totalSkipped = 0;
                    totalProcessed = 0;
                    
                    $("#migration-controls").hide();
                    $("#migration-progress").show();
                    
                    runBatch(0, forceOverwrite);
                });
                
                function runBatch(offset, forceOverwrite) {
                    $.ajax({
                        url: ajaxurl,
                        method: "POST",
                        data: {
                            action: "mg_migrate_batch",
                            offset: offset,
                            force_overwrite: forceOverwrite ? 1 : 0,
                            nonce: "' . wp_create_nonce('mg_migrate_batch') . '"
                        },
                        success: function(response) {
                            if (response.success) {
                                const data = response.data;
                                totalUpdated += data.updated;
                                totalSkipped += data.skipped;
                                totalProcessed += data.processed;
                                
                                const progress = (totalProcessed / data.total_products) * 100;
                                $("#progress-fill").css("width", progress + "%");
                                $("#progress-text").text("Processing: " + totalProcessed + " / " + data.total_products + " products");
                                
                                $("#progress-stats").html(
                                    "<strong>Statistics:</strong><br>" +
                                    "Total Products: " + data.total_products + "<br>" +
                                    "Processed: " + totalProcessed + "<br>" +
                                    "Updated: " + totalUpdated + "<br>" +
                                    "Skipped: " + totalSkipped
                                );
                                
                                if (data.has_more) {
                                    runBatch(data.next_offset, forceOverwrite);
                                } else {
                                    $("#progress-text").html("<strong style=\"color: green;\">✓ Migration Complete!</strong>");
                                    setTimeout(function() {
                                        $("#migration-controls").show();
                                        $("#migration-progress").hide();
                                    }, 3000);
                                }
                            } else {
                                alert("Error: " + (response.data || "Unknown error"));
                                $("#migration-controls").show();
                                $("#migration-progress").hide();
                            }
                        },
                        error: function() {
                            alert("AJAX error occurred");
                            $("#migration-controls").show();
                            $("#migration-progress").hide();
                        }
                    });
                }
            });
            </script>';
            
            echo '</div>';
        }
    );
}, 100);

// AJAX handler for batch processing
add_action('wp_ajax_mg_migrate_batch', function() {
    if (!check_ajax_referer('mg_migrate_batch', 'nonce', false)) {
        wp_send_json_error('Invalid nonce');
        return;
    }
    
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error('Unauthorized');
        return;
    }
    
    $offset = isset($_POST['offset']) ? intval($_POST['offset']) : 0;
    $force_overwrite = isset($_POST['force_overwrite']) && $_POST['force_overwrite'] == '1';
    
    set_time_limit(60); // 1 minute per batch
    
    $results = MG_Design_Path_Migration::run_migration($force_overwrite, 100, $offset);
    
    wp_send_json_success($results);
});
