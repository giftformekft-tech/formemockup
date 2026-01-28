<?php
if (!defined('ABSPATH')) {
    exit;
}

class MG_Temu_Export_Page {

    public static function init() {
        add_action('wp_ajax_mg_temu_get_products', [self::class, 'ajax_get_products']);
        add_action('wp_ajax_mg_temu_get_variants', [self::class, 'ajax_get_variants']);
        add_action('wp_ajax_mg_temu_generate_export', [self::class, 'ajax_generate_export']);
    }

    public static function render_page() {
        ?>
        <div class="mg-panel-body mg-panel-body--temu-export">
            <section class="mg-panel-section">
                <div class="mg-panel-section__header">
                    <h2><?php esc_html_e('Temu Export (CSV)', 'mockup-generator'); ?></h2>
                    <p><?php esc_html_e('Generálj Temu-kompatibilis CSV fájlt a termékeidből két egyszerű lépésben.', 'mockup-generator'); ?></p>
                </div>

                <div id="mg-temu-app" class="mg-temu-app">
                    <!-- Step 1: Product Selection -->
                    <div id="mg-temu-step-1" class="mg-temu-step">
                        <div class="mg-temu-toolbar">
                            <div class="mg-temu-pagination">
                                <label><?php esc_html_e('Termékek oldalanként:', 'mockup-generator'); ?>
                                    <select id="mg-temu-per-page">
                                        <option value="25">25</option>
                                        <option value="50">50</option>
                                        <option value="100">100</option>
                                    </select>
                                </label>
                            </div>
                            <div class="mg-temu-actions">
                                <button type="button" class="button" id="mg-temu-select-all-page"><?php esc_html_e('Összes kijelölése az oldalon', 'mockup-generator'); ?></button>
                                <button type="button" class="button button-primary" id="mg-temu-next-step"><?php esc_html_e('Tovább a variációkhoz', 'mockup-generator'); ?></button>
                            </div>
                        </div>

                        <div class="mg-table-wrap">
                            <table class="widefat fixed striped">
                                <thead>
                                    <tr>
                                        <td id="cb" class="manage-column column-cb check-column">
                                            <input id="cb-select-all-1" type="checkbox">
                                        </td>
                                        <th><?php esc_html_e('Kép', 'mockup-generator'); ?></th>
                                        <th><?php esc_html_e('Terméknév', 'mockup-generator'); ?></th>
                                        <th><?php esc_html_e('SKU', 'mockup-generator'); ?></th>
                                        <th><?php esc_html_e('Ár', 'mockup-generator'); ?></th>
                                        <th><?php esc_html_e('Készlet', 'mockup-generator'); ?></th>
                                    </tr>
                                </thead>
                                <tbody id="mg-temu-product-list">
                                    <tr><td colspan="6"><?php esc_html_e('Betöltés...', 'mockup-generator'); ?></td></tr>
                                </tbody>
                            </table>
                        </div>
                        
                        <div class="mg-temu-pagination-controls" id="mg-temu-pagination-controls"></div>
                    </div>

                    <!-- Step 2: Variant Selection -->
                    <div id="mg-temu-step-2" class="mg-temu-step" style="display:none;">
                         <div class="mg-temu-toolbar">
                            <button type="button" class="button" id="mg-temu-back-step"><?php esc_html_e('« Vissza a termékekhez', 'mockup-generator'); ?></button>
                            <button type="button" class="button button-primary" id="mg-temu-generate"><?php esc_html_e('CSV Export Generálása', 'mockup-generator'); ?></button>
                        </div>
                        
                        <div id="mg-temu-variant-list"></div>
                    </div>
                </div>
            </section>
        </div>
        
        <?php self::render_scripts(); ?>
        <style>
            .mg-temu-toolbar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; background: #fff; padding: 10px; border-radius: 8px; border: 1px solid #ddd; }
            .mg-temu-step { animation: fadeIn 0.3s ease; }
            .mg-temu-variant-group { background: #fff; border: 1px solid #ddd; border-radius: 8px; margin-bottom: 15px; overflow: hidden; }
            .mg-temu-variant-header { background: #f0f0f1; padding: 10px 15px; font-weight: bold; display: flex; align-items: center; justify-content: space-between; }
            .mg-temu-variant-body { padding: 15px; }
            .mg-temu-variant-row { display: flex; align-items: center; gap: 10px; padding: 5px 0; border-bottom: 1px solid #eee; }
            .mg-temu-variant-row:last-child { border-bottom: none; }
            .mg-temu-pagination-controls { display: flex; justify-content: center; gap: 5px; margin-top: 15px; }
            .mg-temu-pagination-controls button { min-width: 30px; }
            @keyframes fadeIn { from { opacity: 0; transform: translateY(5px); } to { opacity: 1; transform: translateY(0); } }
        </style>
        <?php
    }

    public static function render_scripts() {
        ?>
        <script>
        jQuery(document).ready(function($) {
            let currentPage = 1;
            let productsPerPage = 25;
            let selectedProducts = {}; // { productId: { checked: true, variants: { ... } } }
            
            // --- Step 1: Product List ---

            function loadProducts(page) {
                currentPage = page;
                productsPerPage = $('#mg-temu-per-page').val();
                
                $('#mg-temu-product-list').html('<tr><td colspan="6">Betöltés...</td></tr>');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'mg_temu_get_products',
                        page: currentPage,
                        per_page: productsPerPage,
                        nonce: '<?php echo wp_create_nonce('mg_temu_nonce'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            renderProductList(response.data.products, response.data.total_pages);
                        } else {
                            $('#mg-temu-product-list').html('<tr><td colspan="6">Hiba: ' + response.data + '</td></tr>');
                        }
                    },
                    error: function() {
                        $('#mg-temu-product-list').html('<tr><td colspan="6">Kommunikációs hiba.</td></tr>');
                    }
                });
            }

            function renderProductList(products, totalPages) {
                let html = '';
                if (products.length === 0) {
                     html = '<tr><td colspan="6">Nincs megjeleníthető termék.</td></tr>';
                } else {
                    products.forEach(p => {
                        const isChecked = selectedProducts[p.id] ? 'checked' : '';
                        html += `
                            <tr>
                                <th scope="row" class="check-column">
                                    <input type="checkbox" class="mg-temu-prod-cb" value="${p.id}" ${isChecked}>
                                </th>
                                <td><img src="${p.image}" width="40" height="40" style="border-radius:4px;object-fit:cover;"></td>
                                <td><strong>${p.name}</strong></td>
                                <td>${p.sku}</td>
                                <td>${p.price}</td>
                                <td>${p.stock}</td>
                            </tr>
                        `;
                    });
                }
                $('#mg-temu-product-list').html(html);
                renderPagination(totalPages);
            }

            function renderPagination(totalPages) {
                let html = '';
                if (totalPages > 1) {
                    if (currentPage > 1) html += `<button type="button" class="button mg-temu-page-btn" data-page="${currentPage - 1}">«</button>`;
                    
                    for (let i = 1; i <= totalPages; i++) {
                        // Simple pagination logic (show all for now, optimize if needed)
                         let activeClass = (i === currentPage) ? 'button-primary' : 'button';
                         if (i <= 3 || i >= totalPages - 2 || (i >= currentPage - 1 && i <= currentPage + 1)) {
                             html += `<button type="button" class="button mg-temu-page-btn ${activeClass}" data-page="${i}">${i}</button>`;
                         } else if (html.slice(-3) !== '...') {
                             html += '...';
                         }
                    }

                    if (currentPage < totalPages) html += `<button type="button" class="button mg-temu-page-btn" data-page="${currentPage + 1}">»</button>`;
                }
                $('#mg-temu-pagination-controls').html(html);
            }

            // --- Events Step 1 ---

            $('#mg-temu-per-page').on('change', function() {
                loadProducts(1);
            });

            $(document).on('click', '.mg-temu-page-btn', function() {
                loadProducts($(this).data('page'));
            });

            $(document).on('change', '.mg-temu-prod-cb', function() {
                const pid = $(this).val();
                if ($(this).is(':checked')) {
                    selectedProducts[pid] = selectedProducts[pid] || { checked: true, variants: [] };
                } else {
                    delete selectedProducts[pid];
                }
            });
            
             $('#mg-temu-select-all-page').on('click', function() {
                $('.mg-temu-prod-cb').prop('checked', true).trigger('change');
            });
            
            $('#cb-select-all-1').on('click', function() {
                const checked = $(this).is(':checked');
                $('.mg-temu-prod-cb').prop('checked', checked).trigger('change');
            });


            // --- Step 2: Variant Selection ---

            $('#mg-temu-next-step').on('click', function() {
                const pids = Object.keys(selectedProducts);
                if (pids.length === 0) {
                    alert('Kérlek válassz legalább egy terméket!');
                    return;
                }
                
                $('#mg-temu-step-1').hide();
                $('#mg-temu-step-2').show();
                loadVariants(pids);
            });
            
            $('#mg-temu-back-step').on('click', function() {
                $('#mg-temu-step-2').hide();
                $('#mg-temu-step-1').show();
            });

            function loadVariants(pids) {
                 $('#mg-temu-variant-list').html('<p>Variációk betöltése...</p>');
                 $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'mg_temu_get_variants',
                        product_ids: pids,
                        nonce: '<?php echo wp_create_nonce('mg_temu_nonce'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            renderVariantList(response.data);
                        } else {
                            $('#mg-temu-variant-list').html('<p>Hiba: ' + response.data + '</p>');
                        }
                    }
                 });
            }

            function renderVariantList(data) {
                let html = '';
                data.forEach(item => {
                    let variantsHtml = '';
                    item.variants.forEach(v => {
                        variantsHtml += `
                            <div class="mg-temu-variant-row">
                                <label>
                                    <input type="checkbox" class="mg-temu-var-cb" data-pid="${item.product_id}" value="${v.id}" checked>
                                    ${v.name} (SKU: ${v.sku}) - ${v.stock_status}
                                </label>
                            </div>
                        `;
                    });

                    html += `
                        <div class="mg-temu-variant-group">
                            <div class="mg-temu-variant-header">
                                <span>${item.product_name}</span>
                                <button type="button" class="button button-small mg-temu-toggle-vars" data-target="vars-${item.product_id}">Mutat/Rejt</button>
                            </div>
                            <div class="mg-temu-variant-body" id="vars-${item.product_id}">
                                ${variantsHtml}
                            </div>
                        </div>
                    `;
                });
                $('#mg-temu-variant-list').html(html);
            }

            // --- Export ---

            $('#mg-temu-generate').on('click', function() {
                const selection = {};
                $('.mg-temu-var-cb:checked').each(function() {
                    const pid = $(this).data('pid');
                    const vid = $(this).val();
                    if (!selection[pid]) selection[pid] = [];
                    selection[pid].push(vid);
                });

                if (Object.keys(selection).length === 0) {
                    alert('Nincs kiválasztott variáció.');
                    return;
                }
                
                const $btn = $(this);
                $btn.prop('disabled', true).text('Generálás...');

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'mg_temu_generate_export',
                        selection: selection,
                        nonce: '<?php echo wp_create_nonce('mg_temu_nonce'); ?>'
                    },
                    success: function(response) {
                        $btn.prop('disabled', false).text('CSV Export Generálása');
                        if (response.success) {
                            // Download file
                            const link = document.createElement('a');
                            link.href = response.data.url;
                            link.download = response.data.filename;
                            document.body.appendChild(link);
                            link.click();
                            document.body.removeChild(link);
                        } else {
                            alert('Hiba: ' + response.data);
                        }
                    },
                    error: function() {
                        $btn.prop('disabled', false).text('CSV Export Generálása');
                        alert('Kommunikációs hiba.');
                    }
                });
            });

            // Initial load
            loadProducts(1);
        });
        </script>
        <?php
    }

    public static function ajax_get_products() {
        check_ajax_referer('mg_temu_nonce', 'nonce');
        
        $page = isset($_POST['page']) ? intval($_POST['page']) : 1;
        $per_page = isset($_POST['per_page']) ? intval($_POST['per_page']) : 25;

        // Query WooCommerce products
        $args = [
            'status' => 'publish',
            'limit' => $per_page,
            'page' => $page,
            'paginate' => true,
        ];
        
        $results = wc_get_products($args);
        $products = [];
        
        foreach ($results->products as $product) {
            $image_id = $product->get_image_id();
            $image_url = $image_id ? wp_get_attachment_image_url($image_id, 'thumbnail') : wc_placeholder_img_src();
            
            $products[] = [
                'id' => $product->get_id(),
                'name' => $product->get_name(),
                'sku' => $product->get_sku(),
                'price' => $product->get_price_html(),
                'stock' => $product->get_stock_status() === 'instock' ? 'Készleten' : 'Nincs készleten',
                'image' => $image_url
            ];
        }

        wp_send_json_success([
            'products' => $products,
            'total_pages' => $results->max_num_pages,
            'total' => $results->total
        ]);
    }

    public static function ajax_get_variants() {
        check_ajax_referer('mg_temu_nonce', 'nonce');
        
        $product_ids = isset($_POST['product_ids']) ? (array) $_POST['product_ids'] : [];
        $data = [];

        foreach ($product_ids as $pid) {
            $product = wc_get_product($pid);
            if (!$product) continue;

            $variants = [];
            
            if ($product->is_type('variable')) {
                $children = $product->get_children();
                foreach ($children as $cid) {
                     $child = wc_get_product($cid);
                     if (!$child) continue;
                     
                     // Format variant name
                     $attrs = $child->get_attributes();
                     $attr_string = [];
                     foreach ($attrs as $name => $slug) {
                         // $term = get_term_by('slug', $slug, $name); // Attribute lookups can be complex
                         $attr_string[] = $slug; 
                     }
                     
                     $variants[] = [
                         'id' => $child->get_id(),
                         'name' => implode(', ', $attr_string) ?: 'Variáció #' . $child->get_id(),
                         'sku' => $child->get_sku(),
                         'stock_status' => $child->get_stock_status()
                     ];
                }
            } else {
                // Simple product - treat as single variant
                 $variants[] = [
                     'id' => $product->get_id(),
                     'name' => 'Fő termék',
                     'sku' => $product->get_sku(),
                     'stock_status' => $product->get_stock_status()
                 ];
            }

            $data[] = [
                'product_id' => $product->get_id(),
                'product_name' => $product->get_name(),
                'variants' => $variants
            ];
        }

        wp_send_json_success($data);
    }

    public static function ajax_generate_export() {
        check_ajax_referer('mg_temu_nonce', 'nonce');
        
        $selection = isset($_POST['selection']) ? $_POST['selection'] : []; // { pid: [vid, vid] }
        
        // Generate CSV content
        // Columns: SKU, Product Name, Color, Size, Description, Image URL
        
        $header = ['SKU', 'Product Name', 'Color', 'Size', 'Description', 'Image URL'];
        $rows = [];

        foreach ($selection as $pid => $vids) {
            $parent = wc_get_product($pid);
            if (!$parent) continue;

            foreach ($vids as $vid) {
                $variant = wc_get_product($vid);
                if (!$variant) continue;

                // Extract attributes
                $color = '';
                $size = '';
                
                // Try to guess attributes from taxomomies (pa_color, pa_size)
                $attributes = $variant->get_attributes();
                
                foreach ($attributes as $key => $value) {
                    if (strpos($key, 'color') !== false || strpos($key, 'szin') !== false) {
                        $color = $value;
                    }
                    if (strpos($key, 'size') !== false || strpos($key, 'meret') !== false) {
                        $size = $value;
                    }
                }
                
                // Image
                $img_id = $variant->get_image_id();
                if (!$img_id) $img_id = $parent->get_image_id();
                $img_url = $img_id ? wp_get_attachment_url($img_id) : '';

                $rows[] = [
                    $variant->get_sku(),
                    $parent->get_name(),
                    $color,
                    $size,
                    $parent->get_description(), // Or short description
                    $img_url
                ];
            }
        }

        // Write to temp file
        $upload_dir = wp_upload_dir();
        $filename = 'temu-export-' . date('Y-m-d-H-i-s') . '.csv';
        $filepath = $upload_dir['path'] . '/' . $filename;
        $fileurl = $upload_dir['url'] . '/' . $filename;
        
        $fp = fopen($filepath, 'w');
        fputcsv($fp, $header, ';'); // Using Semicolon as requested (SSV-like)
        foreach ($rows as $row) {
            fputcsv($fp, $row, ';');
        }
        fclose($fp);

        wp_send_json_success([
            'url' => $fileurl,
            'filename' => $filename
        ]);
    }
}
