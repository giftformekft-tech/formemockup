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
 * The export itself runs as a chunked background job driven by AJAX
 * polling (see ajax_export_start/step/download below) instead of a single
 * synchronous request, so large batches don't hit reverse-proxy timeouts
 * (e.g. Cloudflare's 524) — the admin sees a progress-bar popup instead.
 *
 * Supports both legacy orders (CPT) and HPOS.
 */
class MG_Order_Design_Download {

    /**
     * Fixed export resolution used to convert a configured print width
     * (cm) into a target pixel width for the production PNG.
     */
    const EXPORT_DPI = 300;

    /**
     * Fixed shorter-side print size (cm) used for designs whose order item
     * has the "nagy méret PNG" surcharge option enabled. Such designs are
     * always exported landscape, regardless of the source orientation or
     * any per-type/size configured print height.
     */
    const LARGE_PRINT_SHORT_SIDE_CM = 30;

    /**
     * Tasks (one PNG copy each) processed per AJAX step. Kept small so a
     * single request never approaches the ~100s proxy timeout (e.g.
     * Cloudflare's 524), no matter how many orders are selected.
     */
    const EXPORT_BATCH_SIZE = 3;

    const JOB_TRANSIENT_PREFIX = 'mg_design_export_job_';
    const JOB_TTL = HOUR_IN_SECONDS;

    /**
     * Order IDs pending export, set by maybe_prepare_export_modal() during
     * admin_init so maybe_enqueue_export_assets() can pick them up later in
     * the same request.
     *
     * @var int[]|null
     */
    protected static $pending_export_order_ids = null;

    public static function init() {
        // Register bulk action – HPOS list table
        add_filter('bulk_actions-woocommerce_page_wc-orders', array(__CLASS__, 'register_bulk_action'));
        // Register bulk action – legacy CPT list table
        add_filter('bulk_actions-edit-shop_order', array(__CLASS__, 'register_bulk_action'));

        // Handle the action – HPOS
        add_filter('handle_bulk_actions-woocommerce_page_wc-orders', array(__CLASS__, 'handle_bulk_action'), 10, 3);
        // Handle the action – legacy
        add_filter('handle_bulk_actions-edit-shop_order', array(__CLASS__, 'handle_bulk_action'), 10, 3);

        // Picks up the pending order IDs so the progress-bar modal's assets
        // can be enqueued further down in the same request.
        add_action('admin_init', array(__CLASS__, 'maybe_prepare_export_modal'));
        add_action('admin_enqueue_scripts', array(__CLASS__, 'maybe_enqueue_export_assets'));

        // Chunked export AJAX endpoints (start a job, process a small batch,
        // stream the finished ZIP) – this is what avoids the request-timeout
        // a single synchronous export hit on larger order batches.
        add_action('wp_ajax_mg_design_export_start', array(__CLASS__, 'ajax_export_start'));
        add_action('wp_ajax_mg_design_export_step', array(__CLASS__, 'ajax_export_step'));
        add_action('wp_ajax_mg_design_export_download', array(__CLASS__, 'ajax_export_download'));

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
     * Called by WooCommerce after the bulk action. We store the order IDs in
     * a transient and redirect back; maybe_prepare_export_modal() picks them
     * up on the next page load and hands them to the frontend, which then
     * drives the export through the chunked AJAX endpoints below (instead of
     * a single long-running synchronous request).
     */
    public static function handle_bulk_action($redirect_to, $action, $order_ids) {
        if ($action !== 'mg_download_designs') {
            return $redirect_to;
        }

        if (empty($order_ids) || !is_array($order_ids)) {
            return $redirect_to;
        }

        $transient_key = 'mg_design_export_pending_' . get_current_user_id();
        set_transient($transient_key, array_map('intval', $order_ids), 120);

        $redirect_to = add_query_arg('mg_design_export', '1', $redirect_to);
        return $redirect_to;
    }

    /* ------------------------------------------------------------------ */

    /**
     * Runs early on admin_init. If the trigger query arg is present and a
     * valid transient exists, stashes the order IDs for
     * maybe_enqueue_export_assets() to localize into the progress-bar modal
     * script later in the same request.
     */
    public static function maybe_prepare_export_modal() {
        if (empty($_GET['mg_design_export'])) {
            return;
        }

        if (!current_user_can('edit_shop_orders')) {
            return;
        }

        $transient_key = 'mg_design_export_pending_' . get_current_user_id();
        $order_ids     = get_transient($transient_key);
        if (!is_array($order_ids) || empty($order_ids)) {
            return;
        }
        delete_transient($transient_key);

        self::$pending_export_order_ids = array_values(array_map('intval', $order_ids));
    }

    /* ------------------------------------------------------------------ */

    public static function maybe_enqueue_export_assets() {
        if (empty(self::$pending_export_order_ids)) {
            return;
        }

        $base_file = dirname(__DIR__) . '/mockup-generator.php';

        wp_enqueue_style('mg-order-export', plugins_url('assets/css/order-export.css', $base_file), array(), MG_VERSION);
        wp_enqueue_script('mg-order-export', plugins_url('assets/js/order-export.js', $base_file), array(), MG_VERSION, true);

        wp_localize_script('mg-order-export', 'MG_ORDER_EXPORT', array(
            'ajax_url'  => admin_url('admin-ajax.php'),
            'nonce'     => wp_create_nonce('mg_design_export_nonce'),
            'order_ids' => self::$pending_export_order_ids,
            'i18n'      => array(
                'title'           => __('Minták exportálása', 'mg'),
                'choice_question' => __('Fekete szín kivételével exportáljon (átlátszóvá tesz minden fekete részt), vagy normál módon?', 'mg'),
                'choice_strip'    => __('Fekete nélkül', 'mg'),
                'choice_normal'   => __('Normál export', 'mg'),
                'processing'      => __('Feldolgozás…', 'mg'),
                'done'            => __('Kész!', 'mg'),
                'download'        => __('ZIP letöltése', 'mg'),
                'error'           => __('Hiba történt az export közben.', 'mg'),
                'close'           => __('Bezárás', 'mg'),
            ),
        ));
    }

    /* ------------------------------------------------------------------ */

    /**
     * Builds the full, sequence-numbered list of PNG export tasks (one per
     * ordered quantity copy) for the given orders. Cheap metadata-only work
     * (no Imagick involved) so it can run in a single request even for
     * large batches; the actual image processing happens later, a few tasks
     * at a time, in ajax_export_step().
     *
     * @param int[] $order_ids
     * @return array<int, array{design_path:string,type:string,size:string,zip_name:string}>
     */
    protected static function build_export_tasks(array $order_ids) {
        $orders   = self::sort_orders_by_date($order_ids);
        $tasks    = array();
        $sequence = 0;

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

                $context          = self::resolve_item_context($item);
                $large_size       = self::item_has_large_size_png_option($item);
                $is_black_garment = ($context['color'] === 'fekete');

                $product_name = sanitize_file_name(get_the_title($product_id));
                if ($product_name === '') {
                    $product_name = 'product_' . $product_id;
                }
                $size_segment = $context['size'] !== '' ? sanitize_file_name($context['size']) : 'na';

                for ($i = 1; $i <= $quantity; $i++) {
                    $sequence++;
                    $tasks[] = array(
                        'design_path'      => $design_path,
                        'type'             => $context['type'],
                        'size'             => $context['size'],
                        'large_size'       => $large_size,
                        'is_black_garment' => $is_black_garment,
                        'zip_name'         => sprintf('%04d_%d_%s_%s.png', $sequence, $order_id, $product_name, $size_segment),
                    );
                }
            }
        }

        return $tasks;
    }

    /* ------------------------------------------------------------------ */

    /**
     * AJAX: starts an export job for the given orders. Builds the task list,
     * creates an empty ZIP on disk, and stores both in a transient keyed by
     * a generated job ID. Returns the job ID and total task count so the
     * frontend can start polling ajax_export_step().
     */
    public static function ajax_export_start() {
        try {
            if (!current_user_can('edit_shop_orders')) {
                wp_send_json_error(array('message' => __('Jogosultság hiányzik.', 'mg')), 403);
            }
            if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'mg_design_export_nonce')) {
                wp_send_json_error(array('message' => __('Érvénytelen kérés (nonce).', 'mg')), 401);
            }
            if (!class_exists('ZipArchive')) {
                wp_send_json_error(array('message' => __('A ZIP letöltés nem támogatott a szerveren (ZipArchive hiányzik).', 'mg')), 500);
            }

            $order_ids = isset($_POST['order_ids']) ? array_map('intval', (array) $_POST['order_ids']) : array();
            $order_ids = array_values(array_filter($order_ids));
            if (empty($order_ids)) {
                wp_send_json_error(array('message' => __('Nincsenek kiválasztott rendelések.', 'mg')), 400);
            }

            $tasks = self::build_export_tasks($order_ids);
            if (empty($tasks)) {
                wp_send_json_error(array('message' => __('Nem találhatók minta PNG fájlok a kijelölt rendelésekhez.', 'mg')), 404);
            }

            $strip_black = !empty($_POST['strip_black']) && $_POST['strip_black'] === '1';

            $zip_path = tempnam(sys_get_temp_dir(), 'mg_designs_') . '.zip';
            $zip      = new ZipArchive();
            if ($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                wp_send_json_error(array('message' => __('Nem sikerült létrehozni a ZIP fájlt.', 'mg')), 500);
            }
            $zip->close();

            $job_id = 'mgexp_' . wp_generate_uuid4();
            $job    = array(
                'tasks'       => $tasks,
                'next_index'  => 0,
                'total'       => count($tasks),
                'completed'   => 0,
                'zip_path'    => $zip_path,
                'strip_black' => $strip_black,
                'cache'       => array(),
                'temp_files'  => array(),
                'status'      => 'processing',
                'user_id'     => get_current_user_id(),
            );
            set_transient(self::JOB_TRANSIENT_PREFIX . $job_id, $job, self::JOB_TTL);

            wp_send_json_success(array('job_id' => $job_id, 'total' => $job['total']));
        } catch (Throwable $e) {
            wp_send_json_error(array('message' => $e->getMessage()), 500);
        }
    }

    /* ------------------------------------------------------------------ */

    /**
     * AJAX: processes the next small batch of tasks (self::EXPORT_BATCH_SIZE)
     * for a job, appending each finished PNG into the job's ZIP. Designed to
     * be called repeatedly until the response reports done=true, so no
     * single request ever has to process the whole batch.
     */
    public static function ajax_export_step() {
        try {
            if (!current_user_can('edit_shop_orders')) {
                wp_send_json_error(array('message' => __('Jogosultság hiányzik.', 'mg')), 403);
            }
            if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'mg_design_export_nonce')) {
                wp_send_json_error(array('message' => __('Érvénytelen kérés (nonce).', 'mg')), 401);
            }

            @set_time_limit(120);
            @ini_set('memory_limit', '512M');

            $job_id        = isset($_POST['job_id']) ? sanitize_text_field($_POST['job_id']) : '';
            $transient_key = self::JOB_TRANSIENT_PREFIX . $job_id;
            $job           = $job_id !== '' ? get_transient($transient_key) : false;
            if (!is_array($job)) {
                wp_send_json_error(array('message' => __('A feladat lejárt vagy nem található.', 'mg')), 404);
            }

            if ($job['status'] === 'completed') {
                wp_send_json_success(array(
                    'completed' => $job['completed'],
                    'total'     => $job['total'],
                    'percent'   => 100,
                    'done'      => true,
                ));
            }

            $zip = new ZipArchive();
            if ($zip->open($job['zip_path'], ZipArchive::CREATE) !== true) {
                wp_send_json_error(array('message' => __('Nem sikerült megnyitni a ZIP fájlt.', 'mg')), 500);
            }

            $cache      = $job['cache'];
            $temp_files = $job['temp_files'];
            $in_batch   = 0;

            while ($in_batch < self::EXPORT_BATCH_SIZE && $job['next_index'] < $job['total']) {
                $task        = $job['tasks'][$job['next_index']];
                $strip_black = !empty($job['strip_black']) && !empty($task['is_black_garment']);
                $export_path = self::prepare_export_png($task['design_path'], $task['type'], $task['size'], $cache, $temp_files, !empty($task['large_size']), $strip_black);
                $zip->addFile($export_path, $task['zip_name']);
                $job['next_index']++;
                $job['completed']++;
                $in_batch++;
            }

            $zip->close();

            $job['cache']      = $cache;
            $job['temp_files'] = $temp_files;

            $done = $job['next_index'] >= $job['total'];
            if ($done) {
                $job['status'] = 'completed';
                foreach ($job['temp_files'] as $temp_file) {
                    @unlink($temp_file);
                }
                $job['temp_files'] = array();
            }

            set_transient($transient_key, $job, self::JOB_TTL);

            $percent = $job['total'] > 0 ? (int) round(($job['completed'] / $job['total']) * 100) : 100;

            wp_send_json_success(array(
                'completed' => $job['completed'],
                'total'     => $job['total'],
                'percent'   => $percent,
                'done'      => $done,
            ));
        } catch (Throwable $e) {
            wp_send_json_error(array('message' => $e->getMessage()), 500);
        }
    }

    /* ------------------------------------------------------------------ */

    /**
     * Streams the finished ZIP for a completed job, then deletes the job and
     * its temp file. URL: admin-ajax.php?action=mg_design_export_download&job_id=...&nonce=...
     */
    public static function ajax_export_download() {
        if (!current_user_can('edit_shop_orders')) {
            wp_die(__('Jogosultság hiányzik.', 'mg'), '', array('response' => 403));
        }
        if (!isset($_GET['nonce']) || !wp_verify_nonce($_GET['nonce'], 'mg_design_export_nonce')) {
            wp_die(__('Érvénytelen biztonsági token.', 'mg'), '', array('response' => 403));
        }

        $job_id        = isset($_GET['job_id']) ? sanitize_text_field($_GET['job_id']) : '';
        $transient_key = self::JOB_TRANSIENT_PREFIX . $job_id;
        $job           = $job_id !== '' ? get_transient($transient_key) : false;
        if (!is_array($job) || $job['status'] !== 'completed' || !file_exists($job['zip_path'])) {
            wp_die(__('A ZIP fájl nem található vagy lejárt.', 'mg'), '', array('response' => 404));
        }

        $zip_path = $job['zip_path'];
        delete_transient($transient_key);

        $filename = 'mintak_' . date('Ymd_His') . '.zip';

        status_header(200);
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($zip_path));
        header('Cache-Control: no-cache, must-revalidate');
        header('Pragma: no-cache');

        readfile($zip_path);
        @unlink($zip_path);
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
     * Resolves the product type (slug), size (label, as configured in the
     * product type's "sizes" list), and garment color (slug) for an order
     * line item, trying the order item meta first and falling back to the
     * variation/product attributes — same fallback chain used by the
     * supplier CSV export.
     *
     * @return array{type:string,size:string}
     */
    protected static function resolve_item_context($item) {
        if (!($item instanceof WC_Order_Item_Product)) {
            return array('type' => '', 'size' => '');
        }

        $type_slug  = $item->get_meta('mg_product_type') ?: $item->get_meta('product_type') ?: $item->get_meta('termektipus') ?: $item->get_meta('pa_termektipus');
        $size_label = $item->get_meta('mg_size') ?: $item->get_meta('Méret') ?: $item->get_meta('size') ?: $item->get_meta('meret');
        $color_slug = $item->get_meta('mg_color') ?: $item->get_meta('color') ?: $item->get_meta('Szín') ?: $item->get_meta('pa_szin');

        if (empty($type_slug) || empty($size_label) || empty($color_slug)) {
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
                if (empty($color_slug)) {
                    $color_slug = $attributes['pa_szin'] ?? ($attributes['pa_color'] ?? '');
                }
            }
        }

        return array(
            'type'  => sanitize_title((string) $type_slug),
            'size'  => sanitize_text_field((string) $size_label),
            'color' => sanitize_title((string) $color_slug),
        );
    }

    /* ------------------------------------------------------------------ */

    /**
     * Whether the given order line item has a "nagy méret PNG" surcharge
     * option enabled. Mirrors MG_Express_Order_Flag's name-matching
     * approach: enabled surcharges are stored as order item meta keyed by
     * the surcharge's name (see MG_Surcharge_Frontend::add_order_item_meta()).
     */
    protected static function item_has_large_size_png_option($item) {
        if (!($item instanceof WC_Order_Item_Product)) {
            return false;
        }
        $names = self::get_large_size_png_surcharge_names();
        if (empty($names)) {
            return false;
        }
        foreach ($item->get_meta_data() as $meta) {
            $data = $meta->get_data();
            if (in_array(mb_strtolower(trim((string) $data['key'])), $names, true)) {
                return true;
            }
        }
        return false;
    }

    protected static function get_large_size_png_surcharge_names() {
        static $names = null;
        if ($names !== null) {
            return $names;
        }
        $names = array();
        if (!class_exists('MG_Surcharge_Manager')) {
            return $names;
        }
        foreach (MG_Surcharge_Manager::get_surcharges(false) as $surcharge) {
            if (!empty($surcharge['is_large_size_png']) && !empty($surcharge['name'])) {
                $names[] = mb_strtolower(trim($surcharge['name']));
            }
        }
        return $names;
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
     * Results are cached per (design path, type, size, large-size flag) so
     * repeated items (multiple quantities, or identical designs across
     * orders) are only processed once.
     *
     * @param bool $large_size_png Whether the order item has the "nagy
     *     méret PNG" surcharge option enabled. When true, the configured
     *     per-type/size print height is ignored entirely: the design is
     *     always forced landscape and sized so its shorter side is exactly
     *     self::LARGE_PRINT_SHORT_SIDE_CM.
     * @param bool $strip_black Admin's per-export choice (asked before the
     *     bulk ZIP job starts): if true, black pixels in the design are
     *     made transparent so they don't get printed on black garments.
     * @return string Path to the file to add to the ZIP.
     */
    protected static function prepare_export_png($design_path, $type_slug, $size_label, array &$cache, array &$temp_files, $large_size_png = false, $strip_black = false) {
        $cache_key = $design_path . '|' . $type_slug . '|' . $size_label . '|' . ($large_size_png ? 'large' : 'normal') . '|' . ($strip_black ? 'noblack' : 'asis');
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
            if ($strip_black) {
                MG_Image_Utils::strip_color_to_transparent($image, 'black');
            }
            MG_Image_Utils::trim_transparent_bounds($image);

            if ($large_size_png) {
                MG_Image_Utils::rotate_portrait_to_landscape($image);
                $target_height_px = (int) round(self::LARGE_PRINT_SHORT_SIDE_CM * self::EXPORT_DPI / 2.54);
                if ($target_height_px > 0 && method_exists($image, 'thumbnailImage')) {
                    $image->thumbnailImage(0, $target_height_px);
                }
            } else {
                MG_Image_Utils::rotate_landscape_to_portrait($image);

                $print_height_cm = ($type_slug !== '' && $size_label !== '' && function_exists('mgsc_get_print_height_cm'))
                    ? floatval(mgsc_get_print_height_cm($type_slug, $size_label))
                    : 0.0;

                if ($print_height_cm > 0) {
                    $target_height_px = (int) round($print_height_cm * self::EXPORT_DPI / 2.54);
                    if ($target_height_px > 0 && method_exists($image, 'thumbnailImage')) {
                        $image->thumbnailImage(0, $target_height_px);
                    }
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

        $context    = self::resolve_item_context($item);
        $large_size = self::item_has_large_size_png_option($item);

        $nonce = wp_create_nonce('mg_dl_design_' . $product_id);
        $url   = add_query_arg(array(
            'action'     => 'mg_download_design_single',
            'product_id' => $product_id,
            'type'       => $context['type'],
            'size'       => $context['size'],
            'large_size' => $large_size ? '1' : '0',
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
        $large_size = isset($_GET['large_size']) && $_GET['large_size'] === '1';

        $cache       = array();
        $temp_files  = array();
        $export_path = self::prepare_export_png($design_path, $type_slug, $size_label, $cache, $temp_files, $large_size);

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
