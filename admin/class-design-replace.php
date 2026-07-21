<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * "Minta cseréje": replaces the base design of an already generated product.
 *
 * The corrected PNG is uploaded once, then a queue job regenerates every
 * mockup of the product's existing product types with the new design (the
 * regular existing-parent pipeline: same file names, so the live images are
 * simply overwritten). After a successful regeneration the stale files that
 * were not rewritten (removed colors/views, legacy leftovers) and the orphan
 * attachments pointing at them are cleaned up.
 */
class MG_Design_Replace {

    public static function init() {
        add_action('wp_ajax_mg_replace_design', array(__CLASS__, 'ajax_replace_design'));
    }

    public static function ajax_replace_design() {
        try {
            if (!current_user_can('edit_products')) {
                wp_send_json_error(array('message' => 'Jogosultság hiányzik.'), 403);
            }
            if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'mg_ajax_nonce')) {
                wp_send_json_error(array('message' => 'Érvénytelen kérés (nonce).'), 401);
            }

            $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
            $product = $product_id > 0 && function_exists('wc_get_product') ? wc_get_product($product_id) : null;
            if (!$product) {
                wp_send_json_error(array('message' => 'A termék nem található.'), 404);
            }
            $sku = $product->get_sku();
            if (!$sku) {
                wp_send_json_error(array('message' => 'A terméknek nincs SKU-ja, így nem mockup-generátoros termék – a csere nem értelmezhető.'), 400);
            }

            // A termék meglévő terméktípusai (pa_termektipus) metszete a katalógussal.
            $type_slugs = wp_get_object_terms($product_id, 'pa_termektipus', array('fields' => 'slugs'));
            if (is_wp_error($type_slugs)) {
                $type_slugs = array();
            }
            $catalog = function_exists('mg_get_catalog_products') ? mg_get_catalog_products() : get_option('mg_products', array());
            $catalog_keys = array();
            foreach ((array) $catalog as $p) {
                if (is_array($p) && !empty($p['key'])) {
                    $catalog_keys[] = $p['key'];
                }
            }
            $product_keys = array_values(array_intersect((array) $type_slugs, $catalog_keys));
            if (empty($product_keys)) {
                wp_send_json_error(array('message' => 'Ehhez a termékhez nem található mockup-terméktípus, a minta csere nem lehetséges.'), 400);
            }

            if (!isset($_FILES['design_file']) || empty($_FILES['design_file']['tmp_name'])) {
                wp_send_json_error(array('message' => 'Hiányzó design fájl.'), 400);
            }
            $uploaded = wp_handle_upload($_FILES['design_file'], array(
                'test_form' => false,
                'mimes' => array('png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp'),
            ));
            if (isset($uploaded['error'])) {
                wp_send_json_error(array('message' => 'Feltöltési hiba: ' . $uploaded['error']), 400);
            }

            if (!class_exists('MG_Bulk_Queue')) {
                wp_send_json_error(array('message' => 'A feldolgozó sor nem érhető el.'), 500);
            }

            $is_custom = class_exists('MG_Custom_Fields_Manager') ? (bool) MG_Custom_Fields_Manager::is_custom_product($product_id) : false;
            $sample_seo = (string) get_post_meta($product_id, '_mg_sample_seo', true);

            $payload = array(
                'design_path'    => $uploaded['file'],
                'product_keys'   => $product_keys,
                'parent_id'      => $product_id,
                'parent_name'    => $product->get_name(),
                'categories'     => array('main' => 0, 'subs' => array()),
                'defaults'       => array('type' => '', 'color' => '', 'size' => ''),
                'tags'           => array(),
                'custom_product' => $is_custom ? 1 : 0,
                'preset_id'      => '',
                'sample_seo'     => $sample_seo,
                'trigger'        => 'design_replace',
                'replace_design' => 1,
            );

            $job_id = MG_Bulk_Queue::enqueue($payload, true);

            wp_send_json_success(array(
                'job_id'  => $job_id,
                'message' => sprintf('A csere sorba állítva (%d terméktípus). A mockupok a háttérben perceken belül frissülnek.', count($product_keys)),
            ));
        } catch (Throwable $e) {
            wp_send_json_error(array('message' => $e->getMessage()), 500);
        }
    }

    /**
     * Removes stale mockup files and orphan attachments after a successful
     * design replacement. Called by the queue worker once the regeneration
     * finished, so the live product never shows missing images in between.
     *
     * @param int $product_id
     * @param int $started_at Unix timestamp taken before the regeneration.
     */
    public static function cleanup_after_replace($product_id, $started_at) {
        $product = function_exists('wc_get_product') ? wc_get_product($product_id) : null;
        if (!$product) {
            return;
        }
        $sku = $product->get_sku();
        if (!$sku) {
            return;
        }

        $uploads = function_exists('wp_get_upload_dir') ? wp_get_upload_dir() : wp_upload_dir();
        $base_dir = isset($uploads['basedir']) ? wp_normalize_path($uploads['basedir']) : '';
        if ($base_dir === '') {
            return;
        }
        $dir = trailingslashit($base_dir) . 'mg_mockups/' . $sku;
        if (!is_dir($dir)) {
            return;
        }

        // 60s órajel-csúszás tűrés: amit a mostani futás írt (felülírt), az
        // marad; ami régebbi, az az elavult mintához tartozott.
        $threshold = intval($started_at) - 60;

        $files = glob(trailingslashit($dir) . '*');
        if (is_array($files)) {
            foreach ($files as $file) {
                if (!is_file($file)) {
                    continue;
                }
                $mtime = @filemtime($file);
                if ($mtime !== false && $mtime < $threshold) {
                    @unlink($file);
                }
            }
        }

        // A már nem létező fájlokra mutató attachmentek törlése (a régi
        // kiemelt kép / galéria elemek ne maradjanak árván a médiatárban).
        $rel_prefix = 'mg_mockups/' . $sku . '/';
        $attachment_ids = get_posts(array(
            'post_type'      => 'attachment',
            'post_status'    => 'any',
            'numberposts'    => 200,
            'fields'         => 'ids',
            'meta_query'     => array(
                array(
                    'key'     => '_wp_attached_file',
                    'value'   => $rel_prefix,
                    'compare' => 'LIKE',
                ),
            ),
        ));
        foreach ((array) $attachment_ids as $att_id) {
            $rel = get_post_meta($att_id, '_wp_attached_file', true);
            if (!is_string($rel) || $rel === '') {
                continue;
            }
            $abs = trailingslashit($base_dir) . ltrim(wp_normalize_path($rel), '/');
            if (!file_exists($abs)) {
                wp_delete_attachment($att_id, true);
            }
        }
    }
}
