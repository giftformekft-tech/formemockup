<?php
if (!defined('ABSPATH')) exit;

class MG_Supplier_Export {

    public static function init() {
        // Register custom order status
        add_action('init', [self::class, 'register_order_status']);
        add_filter('wc_order_statuses', [self::class, 'add_to_status_list']);

        // Legacy (Post type) Orders
        add_filter('bulk_actions-edit-shop_order', [self::class, 'register_bulk_action'], 99);
        add_filter('handle_bulk_actions-edit-shop_order', [self::class, 'handle_bulk_action'], 10, 3);

        // HPOS Orders
        add_filter('bulk_actions-woocommerce_page_wc-orders', [self::class, 'register_bulk_action'], 99);
        add_filter('handle_bulk_actions-woocommerce_page_wc-orders', [self::class, 'handle_bulk_action'], 10, 3);

        // Error notices for export
        add_action('admin_notices', [self::class, 'show_export_notices']);

        // Fallback Javascript injection for aggressive themes/plugins
        add_action('admin_footer', [self::class, 'inject_bulk_action_js'], 999);
    }

    public static function register_order_status() {
        register_post_status('wc-manufacturing', array(
            'label'                     => 'Gyártás alatt',
            'public'                    => true,
            'exclude_from_search'       => false,
            'show_in_admin_all_list'    => true,
            'show_in_admin_status_list' => true,
            'label_count'               => _n_noop('Gyártás alatt (%s)', 'Gyártás alatt (%s)', 'mockup-generator'),
        ));
    }

    public static function add_to_status_list($statuses) {
        $statuses['wc-manufacturing'] = 'Gyártás alatt';
        return $statuses;
    }

    public static function register_bulk_action($bulk_actions) {
        $bulk_actions['mg_export_supplier_csv'] = __('Nagyker CSV Export (UTT)', 'mgdtp');
        return $bulk_actions;
    }

    public static function inject_bulk_action_js() {
        if (!is_admin()) return;
        $page = isset($_GET['page']) ? sanitize_key($_GET['page']) : '';
        $post_type = isset($_GET['post_type']) ? sanitize_key($_GET['post_type']) : '';
        
        if ($page !== 'wc-orders' && $post_type !== 'shop_order') {
            return;
        }
        ?>
        <script type="text/javascript">
            jQuery(document).ready(function($) {
                console.log('MG Export JS initialized on wc-orders page');
                var attempts = 0;
                var interval = setInterval(function() {
                    attempts++;
                    var option = '<option value="mg_export_supplier_csv">Nagyker CSV Export (UTT)</option>';
                    var added = false;
                    
                    ['select[name="action"]', 'select[name="action2"]', '#bulk-action-selector-top', '#bulk-action-selector-bottom'].forEach(function(selector) {
                        var $select = $(selector);
                        if ($select.length > 0 && $select.find('option[value="mg_export_supplier_csv"]').length === 0) {
                            $select.append(option);
                            added = true;
                        }
                    });
                    
                    if (added || attempts > 20) { // Stop after 10 seconds (20 * 500ms)
                        clearInterval(interval);
                        console.log('MG Export option added or timed out');
                    }
                }, 500); 
            });
        </script>
        <?php
    }

    public static function handle_bulk_action($redirect_to, $action, $post_ids) {
        if ($action !== 'mg_export_supplier_csv') {
            return $redirect_to;
        }

        if (empty($post_ids)) {
            set_transient('mg_export_notice', 'Nem voltak rendelések kiválasztva.', 60);
            return add_query_arg('mg_export_error', 'no_orders', $redirect_to);
        }

        // Debug log
        $debug = array();
        $debug[] = '=== MG Supplier Export Debug ===';
        $debug[] = 'Order IDs: ' . implode(', ', $post_ids);

        // Aggregate order items
        $aggregated = array();
        $products = get_option('mg_products', array());
        $product_lookup = array();
        foreach ($products as $p) {
            if (!empty($p['key'])) {
                $product_lookup[$p['key']] = $p;
            }
        }
        $debug[] = 'Product types with UTT: ' . implode(', ', array_keys($product_lookup));

        foreach ($post_ids as $order_id) {
            $order = wc_get_order($order_id);
            if (!$order) {
                $debug[] = "Order #{$order_id}: NOT FOUND";
                continue;
            }

            $debug[] = "Order #{$order_id}: " . count($order->get_items()) . " items";

            foreach ($order->get_items() as $item_id => $item) {
                if (!$item instanceof WC_Order_Item_Product) {
                    continue;
                }

                $qty = $item->get_quantity();
                if ($qty <= 0) {
                    continue;
                }

                $product_type = '';
                $color_slug = '';
                $size_val = '';

                // First try to extract from order item metadata
                $all_meta = $item->get_meta_data();
                $meta_keys = array();
                foreach ($all_meta as $meta) {
                    $meta_keys[] = $meta->key . '=' . $meta->value;
                }
                $debug[] = "  Item #{$item_id} ({$item->get_name()}) qty={$qty} meta: " . implode(', ', $meta_keys);

                $product_type = $item->get_meta('mg_product_type') ?: $item->get_meta('product_type') ?: $item->get_meta('termektipus') ?: $item->get_meta('pa_termektipus');
                $color_slug = $item->get_meta('mg_color') ?: $item->get_meta('color') ?: $item->get_meta('pa_szin') ?: $item->get_meta('szin');
                $size_val = $item->get_meta('mg_size') ?: $item->get_meta('size') ?: $item->get_meta('pa_meret') ?: $item->get_meta('meret');

                // If missing, look at variation / product
                if (empty($product_type) || empty($color_slug) || empty($size_val)) {
                    $variation_id = $item->get_variation_id();
                    $wc_product = $variation_id > 0 ? wc_get_product($variation_id) : wc_get_product($item->get_product_id());
                    if ($wc_product) {
                        $attributes = $wc_product->get_attributes();
                        $debug[] = "    Fallback attributes: " . wp_json_encode($attributes);
                        if (empty($product_type)) {
                            $product_type = $attributes['pa_termektipus'] ?? ($attributes['pa_product_type'] ?? '');
                        }
                        if (empty($color_slug)) {
                            $color_slug = $attributes['pa_szin'] ?? ($attributes['pa_color'] ?? '');
                        }
                        if (empty($size_val)) {
                            $size_val = $attributes['pa_meret'] ?? ($attributes['pa_size'] ?? ($attributes['meret'] ?? ''));
                        }
                    }
                }

                $product_type = sanitize_title($product_type);
                $color_slug = sanitize_title($color_slug);
                $size_val = sanitize_title($size_val);

                // Normalize legacy size names
                $size_map = array(
                    'xxl'   => '2xl',
                    'xxxl'  => '3xl',
                    'xxxxl' => '4xl',
                    '2'     => '2a',
                    '4'     => '4a',
                    '6'     => '6a',
                    '8'     => '8a',
                    '10'    => '10a',
                    '12'    => '12a',
                    '3-6-ho'   => '3/6m',
                    '6-12-ho'  => '6/12m',
                    '12-18-ho' => '12/18m',
                );
                if (isset($size_map[$size_val])) {
                    $size_val = $size_map[$size_val];
                }

                $debug[] = "    Extracted: type={$product_type} color={$color_slug} size={$size_val}";

                if (empty($product_type) || empty($color_slug) || empty($size_val)) {
                    $debug[] = "    SKIPPED: missing type/color/size";
                    continue;
                }

                $has_utt = isset($product_lookup[$product_type]['utt_skus'][$color_slug]);
                $debug[] = "    UTT lookup [{$product_type}][{$color_slug}]: " . ($has_utt ? $product_lookup[$product_type]['utt_skus'][$color_slug] : 'NOT FOUND');

                if ($has_utt) {
                    $base_sku = trim($product_lookup[$product_type]['utt_skus'][$color_slug]);
                    if ($base_sku !== '') {
                        $final_sku = $base_sku . '-' . $size_val;
                        if (!isset($aggregated[$final_sku])) {
                            $aggregated[$final_sku] = 0;
                        }
                        $aggregated[$final_sku] += $qty;
                        $debug[] = "    => ADDED: {$final_sku} x {$qty}";
                    }
                }
            }
        }

        // Save debug log
        $debug[] = 'Aggregated items: ' . count($aggregated);
        $upload_dir = wp_upload_dir();
        file_put_contents($upload_dir['basedir'] . '/mg-supplier-export-debug.log', implode("\n", $debug));

        if (empty($aggregated)) {
            set_transient('mg_export_notice', 'Nem találtam exportálható tételt. Ellenőrizd, hogy az UTT cikkszámok be vannak-e állítva a terméktípusoknál, és a rendelések tartalmazzák-e a szükséges metaadatokat (típus, szín, méret). Debug log: ' . $upload_dir['baseurl'] . '/mg-supplier-export-debug.log', 120);
            return add_query_arg('mg_export_error', 'no_skus', $redirect_to);
        }

        // Generate output
        $items = array();
        foreach ($aggregated as $sku => $qty) {
            $items[] = array($sku, $qty);
        }

        $chunks = array_chunk($items, 100);

        // Update order statuses to "Gyártás alatt"
        foreach ($post_ids as $order_id) {
            $order = wc_get_order($order_id);
            if ($order) {
                $order->update_status('manufacturing', 'Rendelés exportálva a Nagyker CSV-be.');
            }
        }

        // Clear any output buffers
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        if (count($chunks) === 1) {
            $filename = 'nagyker-rendeles-' . gmdate('Y-m-d-H-i-s') . '.csv';
            self::download_csv($filename, $chunks[0]);
        } else {
            $zip_filename = 'nagyker-rendelesek-' . gmdate('Y-m-d-H-i-s') . '.zip';
            self::download_zip($zip_filename, $chunks);
        }
        exit;
    }

    public static function show_export_notices() {
        $notice = get_transient('mg_export_notice');
        if ($notice) {
            delete_transient('mg_export_notice');
            echo '<div class="notice notice-error is-dismissible"><p><strong>Nagyker CSV Export:</strong> ' . esc_html($notice) . '</p></div>';
        }
    }

    private static function download_csv($filename, $data) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');

        $output = fopen('php://output', 'w');
        // No header required
        foreach ($data as $row) {
            fputcsv($output, $row, ',');
        }
        fclose($output);
        exit;
    }

    private static function download_zip($zip_filename, $chunks) {
        $temp_file = tempnam(sys_get_temp_dir(), 'mg_zip_');
        $zip = new ZipArchive();
        
        if ($zip->open($temp_file, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            wp_die('Nem sikerült létrehozni a ZIP fájlt.');
        }

        $count = 1;
        foreach ($chunks as $chunk) {
            // Generate valid CSV content in memory
            $fd = fopen('php://memory', 'r+');
            foreach ($chunk as $row) {
                fputcsv($fd, $row, ',');
            }
            rewind($fd);
            $csv_content = stream_get_contents($fd);
            fclose($fd);

            $zip->addFromString('export-' . $count . '.csv', $csv_content);
            $count++;
        }

        $zip->close();

        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $zip_filename . '"');
        header('Content-Length: ' . filesize($temp_file));
        header('Pragma: no-cache');
        header('Expires: 0');

        readfile($temp_file);
        unlink($temp_file);
        exit;
    }
}
