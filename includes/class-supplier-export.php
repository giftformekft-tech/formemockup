<?php
if (!defined('ABSPATH')) exit;

class MG_Supplier_Export {

    public static function init() {
        // Legacy (Post type) Orders
        add_filter('bulk_actions-edit-shop_order', [self::class, 'register_bulk_action'], 99);
        add_filter('handle_bulk_actions-edit-shop_order', [self::class, 'handle_bulk_action'], 10, 3);

        // HPOS Orders
        add_filter('bulk_actions-woocommerce_page_wc-orders', [self::class, 'register_bulk_action'], 99);
        add_filter('handle_bulk_actions-woocommerce_page_wc-orders', [self::class, 'handle_bulk_action'], 10, 3);

        // Fallback Javascript injection for aggressive themes/plugins
        add_action('admin_footer', [self::class, 'inject_bulk_action_js'], 999);
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
            return add_query_arg('mg_export_error', 'no_orders', $redirect_to);
        }

        // Aggregate order items
        $aggregated = array();
        $products = get_option('mg_products', array());
        $product_lookup = array();
        foreach ($products as $p) {
            if (!empty($p['key'])) {
                $product_lookup[$p['key']] = $p;
            }
        }

        foreach ($post_ids as $order_id) {
            $order = wc_get_order($order_id);
            if (!$order) {
                continue;
            }

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
                $product_type = $item->get_meta('termektipus') ?: $item->get_meta('pa_termektipus') ?: $item->get_meta('pa_product_type');
                $color_slug = $item->get_meta('pa_szin') ?: $item->get_meta('pa_color') ?: $item->get_meta('szin');
                $size_val = $item->get_meta('pa_meret') ?: $item->get_meta('pa_size') ?: $item->get_meta('meret');

                // If missing, look at variation / product
                if (empty($product_type) || empty($color_slug) || empty($size_val)) {
                    $variation_id = $item->get_variation_id();
                    $product = $variation_id > 0 ? wc_get_product($variation_id) : wc_get_product($item->get_product_id());
                    if ($product) {
                        $attributes = $product->get_attributes();
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

                if (empty($product_type) || empty($color_slug) || empty($size_val)) {
                    continue;
                }

                if (isset($product_lookup[$product_type]['utt_skus'][$color_slug])) {
                    $base_sku = trim($product_lookup[$product_type]['utt_skus'][$color_slug]);
                    if ($base_sku !== '') {
                        $final_sku = $base_sku . '-' . $size_val;
                        if (!isset($aggregated[$final_sku])) {
                            $aggregated[$final_sku] = 0;
                        }
                        $aggregated[$final_sku] += $qty;
                    }
                }
            }
        }

        if (empty($aggregated)) {
            return add_query_arg('mg_export_error', 'no_skus', $redirect_to);
        }

        // Generate output
        $items = array();
        foreach ($aggregated as $sku => $qty) {
            $items[] = array($sku, $qty);
        }

        $chunks = array_chunk($items, 100);

        if (count($chunks) === 1) {
            // Single file
            $filename = 'nagyker-rendeles-' . gmdate('Y-m-d-H-i-s') . '.csv';
            self::download_csv($filename, $chunks[0]);
        } else {
            // Multiple files in a ZIP
            $zip_filename = 'nagyker-rendelesek-' . gmdate('Y-m-d-H-i-s') . '.zip';
            self::download_zip($zip_filename, $chunks);
        }
        exit;
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
