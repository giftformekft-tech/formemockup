<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * MG_Order_Design_Download
 *
 * Adds a "Minták letöltése" bulk action to the WooCommerce orders list.
 * For each selected order it finds the base design PNG for every line item
 * (stored in _mg_last_design_path on the parent product) and packs them into
 * a ZIP file, repeating each file as many times as the ordered quantity.
 *
 * Supports both legacy orders (CPT) and HPOS.
 */
class MG_Order_Design_Download {

    public static function init() {
        // Register bulk action – HPOS list table
        add_filter('bulk_actions-woocommerce_page_wc-orders', array(__CLASS__, 'register_bulk_action'));
        // Register bulk action – legacy CPT list table
        add_filter('bulk_actions-edit-shop_order', array(__CLASS__, 'register_bulk_action'));

        // Handle the action – HPOS
        add_filter('handle_bulk_actions-woocommerce_page_wc-orders', array(__CLASS__, 'handle_bulk_action'), 10, 3);
        // Handle the action – legacy
        add_filter('handle_bulk_actions-edit-shop_order', array(__CLASS__, 'handle_bulk_action'), 10, 3);

        // Early intercept: the ZIP download must be streamed before any output
        add_action('admin_init', array(__CLASS__, 'maybe_stream_zip'));
    }

    /* ------------------------------------------------------------------ */

    public static function register_bulk_action($actions) {
        $actions['mg_download_designs'] = __('Minták letöltése (ZIP)', 'mg');
        return $actions;
    }

    /* ------------------------------------------------------------------ */

    /**
     * Called by WooCommerce after the bulk action.  We store the order IDs in a
     * transient and redirect back; the ZIP is then streamed on the next load via
     * maybe_stream_zip() so we can set headers cleanly before WordPress outputs anything.
     */
    public static function handle_bulk_action($redirect_to, $action, $order_ids) {
        if ($action !== 'mg_download_designs') {
            return $redirect_to;
        }

        if (empty($order_ids) || !is_array($order_ids)) {
            return $redirect_to;
        }

        $transient_key = 'mg_design_download_' . get_current_user_id();
        set_transient($transient_key, array_map('intval', $order_ids), 120);

        $redirect_to = add_query_arg('mg_design_download', '1', $redirect_to);
        return $redirect_to;
    }

    /* ------------------------------------------------------------------ */

    /**
     * Runs early on admin_init.  If the trigger query arg is present and a valid
     * transient exists we build and stream the ZIP.
     */
    public static function maybe_stream_zip() {
        if (empty($_GET['mg_design_download'])) {
            return;
        }

        if (!current_user_can('edit_shop_orders')) {
            return;
        }

        $transient_key = 'mg_design_download_' . get_current_user_id();
        $order_ids     = get_transient($transient_key);
        if (!is_array($order_ids) || empty($order_ids)) {
            return;
        }
        delete_transient($transient_key);

        self::stream_zip($order_ids);
        exit;
    }

    /* ------------------------------------------------------------------ */

    /**
     * Builds the ZIP and streams it to the browser.
     *
     * @param int[] $order_ids
     */
    protected static function stream_zip(array $order_ids) {
        if (!class_exists('ZipArchive')) {
            wp_die(__('A ZIP letöltés nem támogatott a szerveren (ZipArchive hiányzik).', 'mg'));
        }

        $tmp_file = tempnam(sys_get_temp_dir(), 'mg_designs_') . '.zip';

        $zip = new ZipArchive();
        if ($zip->open($tmp_file, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            wp_die(__('Nem sikerült létrehozni a ZIP fájlt.', 'mg'));
        }

        $added = 0;

        foreach ($order_ids as $order_id) {
            $order = wc_get_order($order_id);
            if (!$order) {
                continue;
            }

            foreach ($order->get_items() as $item) {
                /** @var WC_Order_Item_Product $item */
                $quantity   = max(1, (int) $item->get_quantity());
                $product_id = (int) $item->get_product_id(); // parent product
                if ($product_id <= 0) {
                    continue;
                }

                $design_path = self::resolve_design_path($product_id);
                if ($design_path === '' || !file_exists($design_path)) {
                    continue;
                }

                $product_name = sanitize_file_name(get_the_title($product_id));
                if ($product_name === '') {
                    $product_name = 'product_' . $product_id;
                }

                for ($i = 1; $i <= $quantity; $i++) {
                    $zip_name = sprintf('%d_%s_%d.png', $order_id, $product_name, $i);
                    $zip->addFile($design_path, $zip_name);
                    $added++;
                }
            }
        }

        $zip->close();

        if ($added === 0 || !file_exists($tmp_file)) {
            @unlink($tmp_file);
            wp_die(__('Nem találhatók minta PNG fájlok a kijelölt rendelésekhez.', 'mg'));
        }

        $filename = 'mintak_' . date('Ymd_His') . '.zip';

        // Stream the file
        status_header(200);
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($tmp_file));
        header('Cache-Control: no-cache, must-revalidate');
        header('Pragma: no-cache');

        readfile($tmp_file);
        @unlink($tmp_file);
        exit;
    }

    /* ------------------------------------------------------------------ */

    /**
     * Resolves the base design PNG path for a product.
     * Falls back from _mg_last_design_path attachment to the attachment file itself.
     *
     * @param  int    $product_id
     * @return string Absolute file path, or '' if not found.
     */
    protected static function resolve_design_path(int $product_id): string {
        // 1. Direct path meta
        $path = get_post_meta($product_id, '_mg_last_design_path', true);
        if (is_string($path)) {
            $path = wp_normalize_path($path);
            if ($path !== '' && file_exists($path)) {
                return $path;
            }
        }

        // 2. Attachment meta → file on disk
        $attachment_id = absint(get_post_meta($product_id, '_mg_last_design_attachment', true));
        if ($attachment_id > 0) {
            $file = get_attached_file($attachment_id);
            if ($file && file_exists($file)) {
                return wp_normalize_path($file);
            }
        }

        return '';
    }
}
