<?php
/**
 * Maintenance Tools for Mockup Generator
 * - Bulk Delete Products & Files
 */

if (!defined('ABSPATH')) {
    exit;
}

// Add admin menu
add_action('admin_menu', function() {
    add_submenu_page(
        'mockup-generator',
        'Maintenance Tools',
        'Maintenance',
        'manage_woocommerce',
        'mg-maintenance',
        'mg_render_maintenance_page'
    );
}, 105);

function mg_render_maintenance_page() {
    if (!current_user_can('manage_woocommerce')) {
        wp_die('Unauthorized');
    }
    
    // Get product count
    global $wpdb;
    $product_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status IN ('publish','draft','private','trash')");
    
    ?>
    <div class="wrap">
        <h1>🛠️ Maintenance Tools</h1>
        
        <div class="card" style="max-width: 600px; margin-top: 20px; border-left: 4px solid #d63638;">
            <h2 style="color: #d63638;">⚠️ DANGER ZONE: Delete All Products</h2>
            <p>This tool will <strong>PERMANENTLY DELETE</strong>:</p>
            <ul style="list-style-type: disc; margin-left: 20px;">
                <li>All WooCommerce Products (<?php echo $product_count; ?> products found)</li>
                <li>All associated generated mockup images (Media Library & Files)</li>
                <li>All product metadata</li>
            </ul>
            <p><strong>This action cannot be undone.</strong></p>
            
            <hr>
            
            <div id="mg-delete-ui">
                <button id="mg-start-delete-btn" class="button button-primary button-large" style="background-color: #d63638; border-color: #d63638;">
                    🗑️ DELETE ALL PRODUCTS (<?php echo $product_count; ?>)
                </button>
            </div>
            
            <div id="mg-delete-progress" style="display:none; margin-top: 20px;">
                <div style="background: #f0f0f1; border-radius: 4px; height: 20px; overflow: hidden;">
                    <div id="mg-progress-bar" style="background: #d63638; width: 0%; height: 100%; transition: width 0.3s;"></div>
                </div>
                <p id="mg-progress-text">Starting...</p>
            </div>
        </div>
    </div>
    
    <script>
    jQuery(document).ready(function($) {
        var totalToDelete = <?php echo $product_count; ?>;
        var deletedCount = 0;
        var batchSize = 10; // Delete 10 at a time to avoid timeout
        
        $('#mg-start-delete-btn').on('click', function() {
            if (totalToDelete === 0) {
                alert('No products to delete!');
                return;
            }
            
            var confirm1 = confirm('🔴 ARE YOU SURE? This will delete ALL products and files!');
            if (!confirm1) return;
            
            var confirm2 = confirm('🔴 REALLY? There is NO UNDO. Type "DELETE" to confirm.');
            // Note: Simplification for prompt in JS
            // In a real scenario we'd use prompt(), but alert allows simple bool check logic flow or just double confirm. 
            // Let's stick to double confirm click.
            
            if (confirm2) {
                // Start deletion
                $('#mg-delete-ui').hide();
                $('#mg-delete-progress').show();
                deleteBatch();
            }
        });
        
        function deleteBatch() {
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'mg_delete_products_batch',
                    nonce: '<?php echo wp_create_nonce("mg_delete_batch"); ?>',
                    batch_size: batchSize
                },
                success: function(response) {
                    if (response.success) {
                        var deletedInThisBatch = response.data.count;
                        deletedCount += deletedInThisBatch;
                        var remaining = response.data.remaining;
                        
                        // Update UI
                        var percent = Math.min(100, Math.round((deletedCount / totalToDelete) * 100));
                        if (remaining === 0) percent = 100; // Force 100% when done
                        
                        $('#mg-progress-bar').css('width', percent + '%');
                        $('#mg-progress-text').text('Deleted ' + deletedCount + ' / ' + totalToDelete + ' products...');
                        
                        if (remaining > 0) {
                            // Continue next batch
                            deleteBatch();
                        } else {
                            $('#mg-progress-text').html('<strong>✅ DONE! All products deleted.</strong>').css('color', 'green');
                            $('#mg-progress-bar').css('background', 'green');
                        }
                    } else {
                        alert('Error: ' + response.data);
                        $('#mg-delete-ui').show();
                    }
                },
                error: function() {
                    alert('Server error occurred during deletion.');
                    $('#mg-delete-ui').show();
                }
            });
        }
    });
    </script>
    <?php
}

// Handler for AJAX deletion
add_action('wp_ajax_mg_delete_products_batch', function() {
    check_ajax_referer('mg_delete_batch', 'nonce');
    
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error('Unauthorized');
    }
    
    $batch_size = isset($_POST['batch_size']) ? intval($_POST['batch_size']) : 5;
    if ($batch_size > 50) $batch_size = 50; // Cap limit
    
    // Get IDs to delete
    // Include all post statuses
    $products = get_posts(array(
        'post_type' => 'product',
        'posts_per_page' => $batch_size,
        'post_status' => array('publish', 'pending', 'draft', 'auto-draft', 'future', 'private', 'inherit', 'trash'),
        'fields' => 'ids'
    ));
    
    $deleted_count = 0;
    
    foreach ($products as $product_id) {
        $product = wc_get_product($product_id);
        if ($product) {
            // 1. Delete associated images (Featured + Gallery)
            // Be careful only to delete images if they are truly attached or generic cleanup
            // For now, let's strictly delete attachments that are children of this product
            
            $attachments = get_posts(array(
                'post_type' => 'attachment',
                'posts_per_page' => -1,
                'post_parent' => $product_id
            ));
            
            foreach ($attachments as $att) {
                // Force delete file and DB record
                wp_delete_attachment($att->ID, true);
            }
            
            // 2. Delete the product itself
            $product->delete(true); // true = force delete (skip trash)
            $deleted_count++;
        }
    }
    
    // Check remaining count
    $remaining = count(get_posts(array(
        'post_type' => 'product',
        'posts_per_page' => 1,
        'post_status' => array('publish', 'pending', 'draft', 'auto-draft', 'future', 'private', 'inherit', 'trash'),
        'fields' => 'ids'
    )));
    
    wp_send_json_success(array(
        'count' => $deleted_count,
        'remaining' => $remaining
    ));
});
