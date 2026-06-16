<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * MG_Order_Design_Download
 *
 * Adds a "Minták letöltése" bulk action to the WooCommerce orders list.
 * For each selected order it finds the base design PNG for every line item
 * (stored in _mg_last_design_path on the parent product), trims its
 * transparent margins, optionally resizes it to the configured production
 * print width (cm, per product type + size), and packs the result into a
 * ZIP file, repeating each file as many times as the ordered quantity.
 *
 * Files are numbered sequentially in order-age order (oldest order's items
 * get the lowest numbers) so the printer can process the batch in the
 * order the orders came in.
 *
 * Supports both legacy orders (CPT) and HPOS.
 */
class MG_Order_Design_Download {

    /**
     * Fixed export resolution used to convert a configured print width
     * (cm) into a target pixel width for the production PNG.
     */
    const EXPORT_DPI = 300;

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

        // Order quick-view: add a download link per line item
        add_action('woocommerce_admin_order_preview_line_item_html', array(__CLASS__, 'preview_line_item_download_btn'), 10, 3);

        // Single PNG download via AJAX (used by the preview button)
        add_action('wp_ajax_mg_download_design_single', array(__CLASS__, 'ajax_download_single'));
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

        // Each unique design now also gets trimmed/resized via Imagick, which
        // is slower than the previous plain file copy — large batches need
        // more headroom than the server's default execution time/memory.
        // Same limits used by the other large bulk exports in this plugin
        // (Google/Facebook feed generation).
        @set_time_limit(1200);
        @ini_set('memory_limit', '1024M');

        $orders = self::sort_orders_by_date($order_ids);

        $tmp_file = tempnam(sys_get_temp_dir(), 'mg_designs_') . '.zip';

        $zip = new ZipArchive();
        if ($zip->open($tmp_file, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            wp_die(__('Nem sikerült létrehozni a ZIP fájlt.', 'mg'));
        }

        $added       = 0;
        $sequence    = 0;
        $export_cache = array();
        $temp_files   = array();

        foreach ($orders as $order) {
            $order_id = $order->get_id();

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

                $context     = self::resolve_item_context($item);
                $export_path = self::prepare_export_png($design_path, $context['type'], $context['size'], $export_cache, $temp_files);

                $product_name = sanitize_file_name(get_the_title($product_id));
                if ($product_name === '') {
                    $product_name = 'product_' . $product_id;
                }
                $size_segment = $context['size'] !== '' ? sanitize_file_name($context['size']) : 'na';

                for ($i = 1; $i <= $quantity; $i++) {
                    $sequence++;
                    $zip_name = sprintf('%04d_%d_%s_%s.png', $sequence, $order_id, $product_name, $size_segment);
                    $zip->addFile($export_path, $zip_name);
                    $added++;
                }
            }
        }

        $zip->close();

        foreach ($temp_files as $temp_file) {
            @unlink($temp_file);
        }

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
     * Loads each order and sorts them oldest-first, so a global per-file
     * sequence number assigned while iterating reflects order age.
     *
     * @param int[] $order_ids
     * @return WC_Order[]
     */
    protected static function sort_orders_by_date(array $order_ids) {
        $orders = array();
        foreach ($order_ids as $order_id) {
            $order = wc_get_order($order_id);
            if ($order) {
                $orders[] = $order;
            }
        }

        usort($orders, function($a, $b) {
            $a_date = $a->get_date_created();
            $b_date = $b->get_date_created();
            $a_time = $a_date ? $a_date->getTimestamp() : 0;
            $b_time = $b_date ? $b_date->getTimestamp() : 0;
            if ($a_time === $b_time) {
                return $a->get_id() <=> $b->get_id();
            }
            return $a_time <=> $b_time;
        });

        return $orders;
    }

    /* ------------------------------------------------------------------ */

    /**
     * Resolves the product type (slug) and size (label, as configured in
     * the product type's "sizes" list) for an order line item, trying the
     * order item meta first and falling back to the variation/product
     * attributes — same fallback chain used by the supplier CSV export.
     *
     * @return array{type:string,size:string}
     */
    protected static function resolve_item_context($item) {
        if (!($item instanceof WC_Order_Item_Product)) {
            return array('type' => '', 'size' => '');
        }

        $type_slug  = $item->get_meta('mg_product_type') ?: $item->get_meta('product_type') ?: $item->get_meta('termektipus') ?: $item->get_meta('pa_termektipus');
        $size_label = $item->get_meta('mg_size') ?: $item->get_meta('Méret') ?: $item->get_meta('size') ?: $item->get_meta('meret');

        if (empty($type_slug) || empty($size_label)) {
            $variation_id = $item->get_variation_id();
            $wc_product   = $variation_id > 0 ? wc_get_product($variation_id) : wc_get_product($item->get_product_id());
            if ($wc_product) {
                $attributes = $wc_product->get_attributes();
                if (empty($type_slug)) {
                    $type_slug = $attributes['pa_termektipus'] ?? ($attributes['pa_product_type'] ?? '');
                }
                if (empty($size_label)) {
                    $size_label = $attributes['pa_meret'] ?? ($attributes['pa_size'] ?? ($attributes['meret'] ?? ''));
                }
            }
        }

        return array(
            'type' => sanitize_title((string) $type_slug),
            'size' => sanitize_text_field((string) $size_label),
        );
    }

    /* ------------------------------------------------------------------ */

    /**
     * Produces the production-ready PNG for a design: always trims the
     * transparent margins, and resizes to the configured print width (cm,
     * at self::EXPORT_DPI) for the given type+size, if one is set in the
     * product type settings. If no print width is configured, only the
     * trim is applied.
     *
     * The original file on disk is never modified — a temporary copy is
     * written and returned instead. Falls back to the original path if
     * Imagick processing isn't available or fails, so a single broken
     * design never aborts the whole batch.
     *
     * Results are cached per (design path, type, size) so repeated items
     * (multiple quantities, or identical designs across orders) are only
     * processed once.
     *
     * @return string Path to the file to add to the ZIP.
     */
    protected static function prepare_export_png($design_path, $type_slug, $size_label, array &$cache, array &$temp_files) {
        $cache_key = $design_path . '|' . $type_slug . '|' . $size_label;
        if (isset($cache[$cache_key])) {
            return $cache[$cache_key];
        }

        if (!class_exists('Imagick')) {
            $cache[$cache_key] = $design_path;
            return $design_path;
        }

        try {
            $image = new Imagick($design_path);
            if (method_exists($image, 'stripImage')) {
                $image->stripImage();
            }
            MG_Image_Utils::trim_transparent_bounds($image);

            $print_width_cm = ($type_slug !== '' && $size_label !== '' && function_exists('mgsc_get_print_width_cm'))
                ? floatval(mgsc_get_print_width_cm($type_slug, $size_label))
                : 0.0;

            if ($print_width_cm > 0) {
                $target_width_px = (int) round($print_width_cm * self::EXPORT_DPI / 2.54);
                if ($target_width_px > 0 && method_exists($image, 'thumbnailImage')) {
                    $image->thumbnailImage($target_width_px, 0);
                }
            }

            // stripImage() above removes the original upload's resolution tag
            // (often 96 DPI), which RIP software like CADlink can rely on to
            // compute physical print size instead of just the pixel count.
            // Always stamp 300 DPI explicitly so pixel dimensions and embedded
            // metadata agree, regardless of whether a cm-based resize ran.
            if (method_exists($image, 'setImageResolution')) {
                $image->setImageResolution(self::EXPORT_DPI, self::EXPORT_DPI);
            }
            if (method_exists($image, 'setImageUnits')) {
                $image->setImageUnits(Imagick::RESOLUTION_PIXELSPERINCH);
            }

            $image->setImageFormat('png');

            $temp_path = tempnam(sys_get_temp_dir(), 'mg_design_export_');
            $image->writeImage($temp_path);
            $image->clear();
            $image->destroy();

            // Belt-and-suspenders: setImageResolution()/setImageUnits() above
            // don't reliably survive into the written file across all
            // Imagick/libpng builds, so stamp the pHYs chunk directly on the
            // final file too — this is what guarantees the embedded DPI.
            MG_Image_Utils::force_png_dpi($temp_path, self::EXPORT_DPI);

            $cache[$cache_key] = $temp_path;
            $temp_files[]      = $temp_path;
            return $temp_path;
        } catch (Throwable $e) {
            $cache[$cache_key] = $design_path;
            return $design_path;
        }
    }

    /* ------------------------------------------------------------------ */

    /**
     * Renders a small "⬇ Minta letöltése" link in each line item row of the
     * WooCommerce order quick-view popup.
     *
     * Hook: woocommerce_admin_order_preview_line_item_html
     *
     * @param string                  $product_html  Existing line-item HTML.
     * @param WC_Order_Item_Product   $item
     * @param WC_Order                $order
     */
    public static function preview_line_item_download_btn($product_html, $item, $order) {
        if (!($item instanceof WC_Order_Item_Product)) {
            return $product_html;
        }

        $product_id  = (int) $item->get_product_id();
        if ($product_id <= 0) {
            return $product_html;
        }

        $design_path = self::resolve_design_path($product_id);
        if ($design_path === '') {
            return $product_html; // no design – nothing to add
        }

        $context = self::resolve_item_context($item);

        $nonce = wp_create_nonce('mg_dl_design_' . $product_id);
        $url   = add_query_arg(array(
            'action'     => 'mg_download_design_single',
            'product_id' => $product_id,
            'type'       => $context['type'],
            'size'       => $context['size'],
            '_wpnonce'   => $nonce,
        ), admin_url('admin-ajax.php'));

        $btn = sprintf(
            '<a href="%s" class="button button-small" style="margin-top:6px;display:inline-block;" target="_blank">&#8595; %s</a>',
            esc_url($url),
            esc_html__('Minta letöltése', 'mg')
        );

        return $product_html . $btn;
    }

    /* ------------------------------------------------------------------ */

    /**
     * AJAX endpoint: streams a single product design PNG to the browser.
     * URL: admin-ajax.php?action=mg_download_design_single&product_id=X&_wpnonce=Y
     */
    public static function ajax_download_single() {
        $product_id = isset($_GET['product_id']) ? absint($_GET['product_id']) : 0;
        if ($product_id <= 0) {
            wp_die(__('Érvénytelen termék azonosító.', 'mg'), '', array('response' => 400));
        }

        if (!wp_verify_nonce(isset($_GET['_wpnonce']) ? $_GET['_wpnonce'] : '', 'mg_dl_design_' . $product_id)) {
            wp_die(__('Érvénytelen biztonsági token.', 'mg'), '', array('response' => 403));
        }

        if (!current_user_can('edit_shop_orders')) {
            wp_die(__('Nincs jogosultság.', 'mg'), '', array('response' => 403));
        }

        $design_path = self::resolve_design_path($product_id);
        if ($design_path === '' || !file_exists($design_path)) {
            wp_die(__('A mintafájl nem található.', 'mg'), '', array('response' => 404));
        }

        $type_slug  = isset($_GET['type']) ? sanitize_title(wp_unslash($_GET['type'])) : '';
        $size_label = isset($_GET['size']) ? sanitize_text_field(wp_unslash($_GET['size'])) : '';

        $cache       = array();
        $temp_files  = array();
        $export_path = self::prepare_export_png($design_path, $type_slug, $size_label, $cache, $temp_files);

        $filename = sanitize_file_name(get_the_title($product_id));
        if ($size_label !== '') {
            $filename .= '_' . sanitize_file_name($size_label);
        }
        $filename .= '.png';

        status_header(200);
        header('Content-Type: image/png');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($export_path));
        header('Cache-Control: no-cache');

        readfile($export_path);

        foreach ($temp_files as $temp_file) {
            @unlink($temp_file);
        }
        exit;
    }

    /* ------------------------------------------------------------------ */

    /**
     * Resolves the base design PNG path for a product.
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
