<?php
/**
 * Maintenance Tools for Mockup Generator
 * - Bulk Delete Products & Files
 * - Storage Analysis & Cleanup
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
    
    // Get storage stats (initial load)
    $upload_dir = wp_upload_dir();
    $base_dir = $upload_dir['basedir'];
    $mockup_dir = $base_dir . '/mg_mockups';
    $renders_dir = $base_dir . '/mockup-renders';
    
    ?>
    <div class="wrap">
        <h1>🛠️ Maintenance Tools</h1>
        
        <!-- STORAGE ANALYSIS -->
        <div class="card" style="max-width: 800px; margin-top: 20px;">
            <h2>📊 Storage Analysis</h2>
            <p>Check how much space your mockups are taking up.</p>
            
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th>Location</th>
                        <th>Path</th>
                        <th>Size</th>
                        <th>Files</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="mg-storage-stats">
                    <tr>
                        <td><strong>New Mockups</strong> (SKU-based)</td>
                        <td><code>/mg_mockups/</code></td>
                        <td id="size-mg-mockups"><em>Calculating...</em></td>
                        <td id="count-mg-mockups">-</td>
                        <td>
                            <button class="button button-small mg-analyze-btn" data-target="mg_mockups">Refresh</button>
                            <button class="button button-small mg-cleanup-orphans-btn" data-target="mg_mockups" style="color: #d63638;">Delete Orphans</button>
                        </td>
                    </tr>
                    <tr>
                        <td><strong>Old Renders</strong> (Legacy)</td>
                        <td><code>/mockup-renders/</code></td>
                        <td id="size-mockup-renders"><em>Calculating...</em></td>
                        <td id="count-mockup-renders">-</td>
                        <td>
                            <button class="button button-small mg-analyze-btn" data-target="mockup_renders">Refresh</button>
                            <button class="button button-small mg-delete-folder-btn" data-target="mockup_renders" style="color: #d63638;">Delete All</button>
                        </td>
                    </tr>
                </tbody>
            </table>
            
            <div id="mg-analysis-log" style="margin-top: 10px; max-height: 150px; overflow-y: auto; background: #f0f0f1; padding: 10px; display: none;"></div>
        </div>

        <!-- CLEANUP TOOLS -->
        <div class="card" style="max-width: 800px; margin-top: 20px;">
            <h2>🧹 Database & System Cleanup</h2>
            <p>Tools to fix database inconsistencies and recover system space.</p>
            
            <table class="widefat striped">
                <tbody>
                    <tr>
                        <td>
                            <strong>Fix Broken Media (Ghosts)</strong><br>
                            <small>Deletes Media Library entries where the file is missing from disk.</small>
                        </td>
                        <td>
                            <button id="mg-fix-media-btn" class="button button-secondary">Scan & Fix</button>
                            <span id="mg-fix-media-status" style="margin-left: 10px; color: #666;"></span>
                        </td>
                    </tr>
                    <tr>
                        <td>
                            <strong>Clean Temp Files</strong><br>
                            <small>Deletes old ImageMagick temporary files (magick-*) from system temp.</small>
                        </td>
                        <td>
                            <button id="mg-clean-temp-btn" class="button button-secondary">Clean Temp</button>
                            <span id="mg-clean-temp-status" style="margin-left: 10px; color: #666;"></span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- DANGER ZONE -->
        <div class="card" style="max-width: 800px; margin-top: 20px; border-left: 4px solid #d63638;">
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
        // --- STORAGE ANALYSIS ---
        
        function log(msg) {
            $('#mg-analysis-log').show().append('<div>' + msg + '</div>');
            var d = $('#mg-analysis-log')[0];
            d.scrollTop = d.scrollHeight;
        }

        $('.mg-analyze-btn').on('click', function() {
            var target = $(this).data('target');
            var btn = $(this);
            btn.prop('disabled', true).text('...');
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'mg_analyze_storage',
                    nonce: '<?php echo wp_create_nonce("mg_maintenance"); ?>',
                    target: target
                },
                success: function(res) {
                    btn.prop('disabled', false).text('Refresh');
                    if (res.success) {
                        var id = target.replace('_', '-');
                        $('#size-' + id).text(res.data.size_formatted);
                        $('#count-' + id).text(res.data.count + ' files');
                    } else {
                        alert('Error: ' + res.data);
                    }
                },
                error: function() {
                    btn.prop('disabled', false).text('Refresh');
                    alert('Server error.');
                }
            });
        });
        
        // Auto-analyze on load
        $('.mg-analyze-btn').click();
        
        // Cleanup Orphans
        $('.mg-cleanup-orphans-btn').on('click', function() {
            var target = $(this).data('target');
            if (!confirm('Are you sure you want to delete orphaned files in ' + target + '? This will delete folders that do not match any existing Product SKU.')) return;
            
            var btn = $(this);
            btn.prop('disabled', true).text('Cleaning...');
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'mg_cleanup_orphans',
                    nonce: '<?php echo wp_create_nonce("mg_maintenance"); ?>',
                    target: target
                },
                success: function(res) {
                    btn.prop('disabled', false).text('Delete Orphans');
                    if (res.success) {
                        alert('Cleanup complete!\nDeleted files: ' + res.data.deleted_files + '\nDeleted folders: ' + res.data.deleted_folders + '\nFreed space: ' + res.data.freed_formatted);
                        $('.mg-analyze-btn[data-target="' + target + '"]').click(); // Refresh
                    } else {
                        alert('Error: ' + res.data);
                    }
                },
                error: function() {
                    btn.prop('disabled', false).text('Delete Orphans');
                    alert('Server error.');
                }
            });
        });
        
        // Delete Folder
        $('.mg-delete-folder-btn').on('click', function() {
            var target = $(this).data('target');
            if (!confirm('🔴 DANGER: Are you sure you want to DELETE ALL files in ' + target + '? This cannot be undone.')) return;
            
            var btn = $(this);
            btn.prop('disabled', true).text('Deleting...');
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'mg_delete_folder_contents',
                    nonce: '<?php echo wp_create_nonce("mg_maintenance"); ?>',
                    target: target
                },
                success: function(res) {
                    btn.prop('disabled', false).text('Delete All');
                    if (res.success) {
                        alert('Deletion complete!');
                        $('.mg-analyze-btn[data-target="' + target + '"]').click(); // Refresh
                    } else {
                        alert('Error: ' + res.data);
                    }
                },
                error: function() {
                    btn.prop('disabled', false).text('Delete All');
                    alert('Server error.');
                }
            });
        });

        // --- CLEANUP TOOLS ---
        
        // Fix Broken Media
        $('#mg-fix-media-btn').on('click', function() {
            if (!confirm('This will scan your Media Library and delete entries where the file is missing. Continue?')) return;
            
            var btn = $(this);
            var status = $('#mg-fix-media-status');
            var offset = 0;
            var totalDeleted = 0;
            var totalChecked = 0;
            
            btn.prop('disabled', true);
            status.text('Scanning...');
            
            function processBatch() {
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'mg_fix_broken_media',
                        nonce: '<?php echo wp_create_nonce("mg_maintenance"); ?>',
                        offset: offset
                    },
                    success: function(res) {
                        if (res.success) {
                            totalDeleted += res.data.deleted;
                            totalChecked += res.data.checked;
                            offset = res.data.next_offset;
                            
                            status.text('Checked: ' + totalChecked + ' | Deleted: ' + totalDeleted);
                            
                            if (!res.data.done) {
                                processBatch();
                            } else {
                                btn.prop('disabled', false);
                                status.text('Done! Deleted ' + totalDeleted + ' broken entries.');
                                alert('Cleanup complete. Deleted ' + totalDeleted + ' broken media entries.');
                            }
                        } else {
                            btn.prop('disabled', false);
                            status.text('Error: ' + res.data);
                        }
                    },
                    error: function() {
                        btn.prop('disabled', false);
                        status.text('Server error.');
                    }
                });
            }
            
            processBatch();
        });
        
        // Clean Temp Files
        $('#mg-clean-temp-btn').on('click', function() {
            var btn = $(this);
            var status = $('#mg-clean-temp-status');
            
            btn.prop('disabled', true);
            status.text('Cleaning...');
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'mg_clean_temp_files',
                    nonce: '<?php echo wp_create_nonce("mg_maintenance"); ?>'
                },
                success: function(res) {
                    btn.prop('disabled', false);
                    if (res.success) {
                        status.text('Deleted ' + res.data.deleted + ' files (' + res.data.freed_formatted + ')');
                        alert('Cleanup complete!\nDeleted files: ' + res.data.deleted + '\nFreed space: ' + res.data.freed_formatted);
                    } else {
                        status.text('Error: ' + res.data);
                    }
                },
                error: function() {
                    btn.prop('disabled', false);
                    status.text('Server error.');
                }
            });
        });

        // --- BULK DELETE ---
        var totalToDelete = <?php echo $product_count; ?>;
        var deletedCount = 0;
        var batchSize = 10;
        
        $('#mg-start-delete-btn').on('click', function() {
            if (totalToDelete === 0) {
                alert('No products to delete!');
                return;
            }
            if (!confirm('🔴 ARE YOU SURE? This will delete ALL products and files!')) return;
            if (!confirm('🔴 REALLY? There is NO UNDO.')) return;
            
            $('#mg-delete-ui').hide();
            $('#mg-delete-progress').show();
            deleteBatch();
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
                        deletedCount += response.data.count;
                        var remaining = response.data.remaining;
                        var percent = Math.min(100, Math.round((deletedCount / totalToDelete) * 100));
                        if (remaining === 0) percent = 100;
                        
                        $('#mg-progress-bar').css('width', percent + '%');
                        $('#mg-progress-text').text('Deleted ' + deletedCount + ' / ' + totalToDelete + ' products...');
                        
                        if (remaining > 0) {
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

// AJAX: Analyze Storage
add_action('wp_ajax_mg_analyze_storage', function() {
    check_ajax_referer('mg_maintenance', 'nonce');
    if (!current_user_can('manage_woocommerce')) wp_send_json_error('Unauthorized');
    
    $target = isset($_POST['target']) ? sanitize_key($_POST['target']) : '';
    $upload_dir = wp_upload_dir();
    $base_dir = $upload_dir['basedir'];
    $dir = '';
    
    if ($target === 'mg_mockups') $dir = $base_dir . '/mg_mockups';
    elseif ($target === 'mockup_renders') $dir = $base_dir . '/mockup-renders';
    else wp_send_json_error('Invalid target');
    
    if (!is_dir($dir)) {
        wp_send_json_success(array('size' => 0, 'size_formatted' => '0 B', 'count' => 0));
    }
    
    $size = 0;
    $count = 0;
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS));
    foreach ($files as $file) {
        $size += $file->getSize();
        $count++;
    }
    
    wp_send_json_success(array(
        'size' => $size,
        'size_formatted' => size_format($size, 2),
        'count' => $count
    ));
});

// AJAX: Cleanup Orphans (mg_mockups only)
add_action('wp_ajax_mg_cleanup_orphans', function() {
    check_ajax_referer('mg_maintenance', 'nonce');
    if (!current_user_can('manage_woocommerce')) wp_send_json_error('Unauthorized');
    
    $target = isset($_POST['target']) ? sanitize_key($_POST['target']) : '';
    if ($target !== 'mg_mockups') wp_send_json_error('Only mg_mockups supports orphan cleanup');
    
    $upload_dir = wp_upload_dir();
    $dir = $upload_dir['basedir'] . '/mg_mockups';
    if (!is_dir($dir)) wp_send_json_error('Directory not found');
    
    // Get all valid SKUs
    global $wpdb;
    $skus = $wpdb->get_col("SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_sku' AND meta_value != ''");
    $valid_skus = array_map('strtoupper', $skus); // Normalize to uppercase
    
    $deleted_files = 0;
    $deleted_folders = 0;
    $freed_space = 0;
    
    $iterator = new DirectoryIterator($dir);
    foreach ($iterator as $fileinfo) {
        if ($fileinfo->isDot()) continue;
        if ($fileinfo->isDir()) {
            $folder_name = strtoupper($fileinfo->getFilename()); // SKU folder
            
            // Check if this folder name corresponds to a valid SKU
            // Note: SKU folders are usually exact SKU matches.
            // If folder is NOT in valid_skus, it's an orphan.
            
            if (!in_array($folder_name, $valid_skus)) {
                // Delete this folder and contents
                $files = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($fileinfo->getPathname(), RecursiveDirectoryIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::CHILD_FIRST
                );
                
                foreach ($files as $file) {
                    if ($file->isFile()) {
                        $freed_space += $file->getSize();
                        unlink($file->getRealPath());
                        $deleted_files++;
                    } else {
                        rmdir($file->getRealPath());
                    }
                }
                rmdir($fileinfo->getPathname());
                $deleted_folders++;
            }
        }
    }
    
    wp_send_json_success(array(
        'deleted_files' => $deleted_files,
        'deleted_folders' => $deleted_folders,
        'freed_formatted' => size_format($freed_space, 2)
    ));
});

// AJAX: Delete Folder Contents
add_action('wp_ajax_mg_delete_folder_contents', function() {
    check_ajax_referer('mg_maintenance', 'nonce');
    if (!current_user_can('manage_woocommerce')) wp_send_json_error('Unauthorized');
    
    $target = isset($_POST['target']) ? sanitize_key($_POST['target']) : '';
    $upload_dir = wp_upload_dir();
    $dir = '';
    
    if ($target === 'mg_mockups') $dir = $upload_dir['basedir'] . '/mg_mockups';
    elseif ($target === 'mockup_renders') $dir = $upload_dir['basedir'] . '/mockup-renders';
    else wp_send_json_error('Invalid target');
    
    if (!is_dir($dir)) wp_send_json_error('Directory not found');
    
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    
    foreach ($files as $file) {
        if ($file->isFile()) {
            unlink($file->getRealPath());
        } else {
            rmdir($file->getRealPath());
        }
    }
    
    wp_send_json_success();
});

// Handler for AJAX deletion (Products)
add_action('wp_ajax_mg_delete_products_batch', function() {
    check_ajax_referer('mg_delete_batch', 'nonce');
    
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error('Unauthorized');
    }
    
    $batch_size = isset($_POST['batch_size']) ? intval($_POST['batch_size']) : 5;
    if ($batch_size > 50) $batch_size = 50; // Cap limit
    
    // Get IDs to delete
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
            $attachments = get_posts(array(
                'post_type' => 'attachment',
                'posts_per_page' => -1,
                'post_parent' => $product_id
            ));
            
            foreach ($attachments as $att) {
                wp_delete_attachment($att->ID, true);
            }
            
            // 2. Delete the product itself
            $product->delete(true);
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

// AJAX: Fix Broken Media (Ghost Attachments)
add_action('wp_ajax_mg_fix_broken_media', function() {
    check_ajax_referer('mg_maintenance', 'nonce');
    if (!current_user_can('manage_woocommerce')) wp_send_json_error('Unauthorized');

    $batch_size = 50; // Process 50 attachments at a time
    $offset = isset($_POST['offset']) ? intval($_POST['offset']) : 0;
    
    $query_args = array(
        'post_type'      => 'attachment',
        'post_status'    => 'inherit',
        'posts_per_page' => $batch_size,
        'offset'         => $offset,
        'fields'         => 'ids',
        'orderby'        => 'ID',
        'order'          => 'ASC',
    );
    
    $attachments = get_posts($query_args);
    $total_attachments = wp_count_posts('attachment')->inherit;
    
    $deleted_count = 0;
    $checked_count = count($attachments);
    
    if ($checked_count === 0) {
        wp_send_json_success(array('done' => true, 'deleted' => 0, 'checked' => 0));
    }

    foreach ($attachments as $att_id) {
        $path = get_attached_file($att_id);
        // If path is empty or file does not exist
        if (!$path || !file_exists($path)) {
            wp_delete_attachment($att_id, true);
            $deleted_count++;
        }
    }

    $new_offset = $offset + $checked_count - $deleted_count; // Adjust offset because deletion shifts indices? 
    // Actually, if we delete, the next page logic gets tricky with offsets. 
    // Safer approach for batch deletion: Don't use offset, use 'post__not_in' or just re-query?
    // But 'offset' is standard. If we delete items 0-10, items 11-20 become 0-10.
    // So if we deleted X items, we should NOT increase offset by checked_count, but by (checked_count - deleted_count).
    // HOWEVER, WP_Query with offset is slow for large datasets.
    // Let's stick to simple offset logic but be aware of the shift.
    // If we process from ID ASC, we can just use 'post__not_in' with processed IDs? No, too big.
    // Simple fix: If we found broken ones, we deleted them. The next batch at 'offset' will be the next set of valid ones (mostly).
    // Actually, if we delete row 0, row 1 becomes row 0. So if we increment offset by batch_size, we skip items.
    // CORRECT LOGIC: If we delete N items, the next query at same offset will return new items.
    // But we iterate through all.
    // Let's just return the counts and let client handle "next batch".
    // Client sends 'offset'.
    // If we deleted everything in this batch, next call should use SAME offset?
    // No, that's infinite loop risk if we fail to delete.
    // Let's assume we just increment offset by ($checked_count - $deleted_count).
    
    // Better yet: Don't use offset. Use 'paged' and don't delete immediately? No, we want to delete.
    // Best approach for deletion loop: Always query with offset 0, but filter? No.
    // Let's use the standard "processed count" to update UI, but for the query, we need to be careful.
    // If we delete, the total count decreases.
    // Let's just return 'deleted' count. The client will just keep calling until 'done'.
    // But how to iterate?
    // We can pass 'last_id' instead of offset for performance and stability.
    
    // RE-IMPLEMENTATION WITH LAST_ID
    // Client sends 'last_id' (default 0). We query IDs > last_id.
    // This is stable even with deletions.
    
    $last_id = isset($_POST['last_id']) ? intval($_POST['last_id']) : 0;
    
    $query_args = array(
        'post_type'      => 'attachment',
        'post_status'    => 'inherit',
        'posts_per_page' => $batch_size,
        'fields'         => 'ids',
        'orderby'        => 'ID',
        'order'          => 'ASC',
        // 'post__not_in' => ... // No, use date or ID range
    );
    
    // We can't easily do "ID > last_id" with get_posts standard args without a filter or meta query (which is slow).
    // But we can use 'offset' if we accept we might skip some if concurrent edits happen?
    // Let's go back to OFFSET but handle the shift.
    // Actually, if we delete item at index 0, the item at index 1 moves to 0.
    // So if we processed 50 items and deleted 10, we effectively advanced by 40 items in the "original" list.
    // So next offset should be current_offset + (50 - 10) = current_offset + 40.
    
    $next_offset = $offset + ($checked_count - $deleted_count);
    
    wp_send_json_success(array(
        'done' => ($checked_count < $batch_size),
        'deleted' => $deleted_count,
        'checked' => $checked_count,
        'next_offset' => $next_offset,
        'total_estimate' => $total_attachments
    ));
});

// AJAX: Clean Temp Files
add_action('wp_ajax_mg_clean_temp_files', function() {
    check_ajax_referer('mg_maintenance', 'nonce');
    if (!current_user_can('manage_woocommerce')) wp_send_json_error('Unauthorized');

    $temp_dir = sys_get_temp_dir();
    if (!$temp_dir || !is_dir($temp_dir)) {
        wp_send_json_error('Temp directory not found: ' . $temp_dir);
    }

    // Look for ImageMagick temp files (usually magick-*)
    $patterns = array('magick-*', 'magick-*.*'); // Sometimes they have extensions
    $deleted_count = 0;
    $freed_bytes = 0;
    $errors = array();

    foreach ($patterns as $pattern) {
        $files = glob($temp_dir . DIRECTORY_SEPARATOR . $pattern);
        if ($files) {
            foreach ($files as $file) {
                if (is_file($file)) {
                    // Safety: Check if file is older than 1 hour (3600s) to avoid deleting in-use files
                    if (time() - filemtime($file) > 3600) {
                        $size = filesize($file);
                        if (@unlink($file)) {
                            $deleted_count++;
                            $freed_bytes += $size;
                        } else {
                            // $errors[] = "Failed to delete " . basename($file);
                        }
                    }
                }
            }
        }
    }

    wp_send_json_success(array(
        'deleted' => $deleted_count,
        'freed_formatted' => size_format($freed_bytes, 2),
        'temp_dir' => $temp_dir
    ));
});
