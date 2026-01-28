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
                                        <th><?php esc_html_e('Base SKU', 'mockup-generator'); ?></th>
                                        <th><?php esc_html_e('Kategória', 'mockup-generator'); ?></th>
                                    </tr>
                                </thead>
                                <tbody id="mg-temu-product-list">
                                    <tr><td colspan="5"><?php esc_html_e('Betöltés...', 'mockup-generator'); ?></td></tr>
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
            .mg-chip { display: inline-block; padding: 2px 6px; background: #eee; border-radius: 4px; font-size: 11px; margin-right: 4px; }
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
            let selectedProducts = {}; 
            // { productId: { checked: true } }
            // Note: For variants, we will just pass product IDs to backend and let backend generate based on virtual config,
            // OR we fetch structure and let user deselect.
            // Requirement says: "ki tudjam jelölni... mely variánsai".
            // So we need to fetch virtual variants structure.

            // --- Step 1: Product List ---

            function loadProducts(page) {
                currentPage = page;
                productsPerPage = $('#mg-temu-per-page').val();
                
                $('#mg-temu-product-list').html('<tr><td colspan="5">Betöltés...</td></tr>');
                
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
                            $('#mg-temu-product-list').html('<tr><td colspan="5">Hiba: ' + response.data + '</td></tr>');
                        }
                    },
                    error: function() {
                        $('#mg-temu-product-list').html('<tr><td colspan="5">Kommunikációs hiba.</td></tr>');
                    }
                });
            }

            function renderProductList(products, totalPages) {
                let html = '';
                if (products.length === 0) {
                     html = '<tr><td colspan="5">Nincs megjeleníthető termék.</td></tr>';
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
                                <td>${p.category}</td>
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
                    selectedProducts[pid] = true;
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
                 $('#mg-temu-variant-list').html('<p style="padding:20px;text-align:center;">Virtuális variációk letöltése és elemzése...</p>');
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
                if (!data || data.length === 0) {
                     $('#mg-temu-variant-list').html('<p>Nem található konfigurált terméktípus ezekhez a termékekhez.</p>');
                     return;
                }
                
                // Group by product
                data.forEach(item => { // item = { product_id, product_name, sku_base, variants: [...] }
                    let variantsHtml = '';
                    
                    if (item.variants.length === 0) {
                         variantsHtml = '<p style="padding:10px; color:#777;">Ehhez a termékhez nincs renderelő konfiguráció (Virtual Variant Manager).</p>';
                    } else {
                        // Variants here are effectively Type+Color combos since Size is sub-attribute but we list them all?
                        // Actually, let's list distinct Variant Rows.
                        // { key: 'TYPE|COLOR|SIZE', label: 'T-Shirt - Red - L', sku_suffix: '...' }
                        
                        item.variants.forEach(v => {
                             // v = { type, color, size, label, sku_generated }
                             const uniqueKey = `${v.type}|${v.color}|${v.size}`;
                             
                             variantsHtml += `
                                <div class="mg-temu-variant-row">
                                    <label>
                                        <input type="checkbox" class="mg-temu-var-cb" 
                                               data-pid="${item.product_id}" 
                                               data-type="${v.type}" 
                                               data-color="${v.color}" 
                                               data-size="${v.size}"
                                               checked>
                                        <span class="mg-chip">${v.type_label}</span>
                                        <span class="mg-chip" style="background:#eef;color:#338;">${v.color_label}</span>
                                        <span class="mg-chip" style="background:#efe;color:#050;">${v.size}</span>
                                        <span style="color:#666;font-size:12px;">SKU: ${v.sku}</span>
                                    </label>
                                </div>
                            `;
                        });
                    }

                    html += `
                        <div class="mg-temu-variant-group">
                            <div class="mg-temu-variant-header">
                                <span>${item.product_name} <small>(${item.sku_base})</small></span>
                                <div>
                                    <button type="button" class="button button-small mg-temu-toggle-vars" onclick="jQuery('#vars-${item.product_id}').toggle()">Mutat/Rejt</button>
                                </div>
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
                const selection = [];
                $('.mg-temu-var-cb:checked').each(function() {
                    selection.push({
                        pid: $(this).data('pid'),
                        type: $(this).data('type'),
                        color: $(this).data('color'),
                        size: $(this).data('size')
                    });
                });

                if (selection.length === 0) {
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

        // Query only Simple products, since our system treats simple products as base for virtual variants
        // But some users might attach it to variable products? The Virtual Manager supports 'simple' primarily.
        // Let's filter for supported products efficiently.
        // Actually, let's just list all products, the user knows which ones are configured.
        
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
            
            // Get category
            $cats = wc_get_product_term_ids($product->get_id(), 'product_cat');
            $cat_name = '';
            if (!empty($cats)) {
                $term = get_term($cats[0], 'product_cat');
                if ($term && !is_wp_error($term)) $cat_name = $term->name;
            }

            $products[] = [
                'id' => $product->get_id(),
                'name' => $product->get_name(),
                'sku' => $product->get_sku(),
                'category' => $cat_name,
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
        
        if (!class_exists('MG_Virtual_Variant_Manager')) {
            wp_send_json_error('MG_Virtual_Variant_Manager class not found');
        }

        $product_ids = isset($_POST['product_ids']) ? (array) $_POST['product_ids'] : [];
        $data = [];

        foreach ($product_ids as $pid) {
            $product = wc_get_product($pid);
            if (!$product) continue;

            $config = MG_Virtual_Variant_Manager::get_frontend_config($product);
            
            // Config structure:
            // 'types' => [ slug => [ 'label', 'colors' => [ slug => [ 'label', 'sizes' => [...] ] ] ] ]
            
            $variants = [];
            $base_sku = $config['product']['sku'] ?? $product->get_sku();

            if (!empty($config['types'])) {
                foreach ($config['types'] as $type_slug => $type_meta) {
                    if (empty($type_meta['colors'])) continue;
                    
                    foreach ($type_meta['colors'] as $color_slug => $color_meta) {
                        $sizes = $color_meta['sizes'] ?? [];
                        if (empty($sizes)) {
                            // Should we output a variant without size?
                            // Requirement: "ha egy terméknek ... több mérete van akkor méretenként külön sor"
                            // If no sizes, maybe one row? Let's skip for now as clothing usually has sizes if configured.
                            continue; 
                        }

                        // SKU Logic: {BaseSKU} (Simple, same for all rows)
                        $sku_generated = $base_sku;

                        foreach ($sizes as $size) {
                            $variants[] = [
                                'type' => $type_slug,
                                'type_label' => $type_meta['label'],
                                'color' => $color_slug,
                                'color_label' => $color_meta['label'],
                                'size' => $size,
                                'sku' => $sku_generated
                            ];
                        }
                    }
                }
            }

            $data[] = [
                'product_id' => $product->get_id(),
                'product_name' => $product->get_name(),
                'sku_base' => $base_sku,
                'variants' => $variants
            ];
        }

        wp_send_json_success($data);
    }

    public static function ajax_generate_export() {
        check_ajax_referer('mg_temu_nonce', 'nonce');
        
        $selection = isset($_POST['selection']) ? $_POST['selection'] : []; 
        // selection = [ { pid, type, color, size }, ... ]
        
        // CSV Header
        $header = ['Termék neve', 'SKU', 'Szín', 'Méret', 'Leírás', 'Kép URL'];
        $rows = [];

        // Cache products to avoid reloading
        $product_cache = [];
        $config_cache = [];

        foreach ($selection as $item) {
            $pid = $item['pid'];
            if (!isset($product_cache[$pid])) {
                $product_cache[$pid] = wc_get_product($pid);
                $config_cache[$pid] = MG_Virtual_Variant_Manager::get_frontend_config($product_cache[$pid]);
            }
            $product = $product_cache[$pid];
            $config = $config_cache[$pid];
            
            if (!$product) continue;

            $type_slug = $item['type'];
            $color_slug = $item['color'];
            $size = $item['size'];
            
            $base_sku = $config['product']['sku'] ?? $product->get_sku();
            $sku_generated = $base_sku;

            // Labels
            $type_label = $config['types'][$type_slug]['label'] ?? $type_slug;
            $color_label = $config['types'][$type_slug]['colors'][$color_slug]['label'] ?? $color_slug;
            
            // Image Logic
            // Pattern: /mg_mockups/{SKU}/{SKU}_{TYPE}_{COLOR}_front.webp
            // Note: Use Base SKU for directory structure as per convention observed
            $uploads = wp_upload_dir();
            $base_url = isset($uploads['baseurl']) ? trailingslashit($uploads['baseurl']) . 'mg_mockups' : '';
            $filename = $base_sku . '_' . $type_slug . '_' . $color_slug . '_front.webp';
            $img_url = $base_url . '/' . $base_sku . '/' . $filename;
            
            // Verify file exists? User didn't strictly ask to verify, just "img url". 
            // Better to provide the predicted URL so they can fix missing files later.
            // But let's check validation if possible? No, faster to just output predicted for bulk.

            $rows[] = [
                $product->get_name(), // Termék név
                $sku_generated,       // SKU
                $color_label,         // Szín
                $size,                // Méret
                $product->get_description(), // Leírás
                $img_url              // Img URL
            ];
        }

        // Export
        $upload_dir = wp_upload_dir();
        $filename = 'temu-export-' . date('Y-m-d-H-i-s') . '.csv';
        $filepath = $upload_dir['path'] . '/' . $filename;
        $fileurl = $upload_dir['url'] . '/' . $filename;
        
        $fp = fopen($filepath, 'w');
        // BOM for Excel compatibility
        fprintf($fp, chr(0xEF).chr(0xBB).chr(0xBF));
        
        fputcsv($fp, $header, ';');
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
