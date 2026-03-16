<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * MG_Order_Item_Editor
 *
 * Adds a "✏️ Szín/Méret módosítás" button to each WooCommerce order line item
 * that has mg_color or mg_size meta. Opens a popup modal where the admin
 * can change the color and/or size, then saves it via AJAX.
 */
class MG_Order_Item_Editor {

    public static function init() {
        add_action('woocommerce_after_order_itemmeta', array(__CLASS__, 'render_edit_button'), 10, 3);
        add_action('admin_footer', array(__CLASS__, 'render_modal'));
        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue_assets'));
        add_action('wp_ajax_mg_get_item_options', array(__CLASS__, 'ajax_get_item_options'));
        add_action('wp_ajax_mg_update_item_color_size', array(__CLASS__, 'ajax_update_item_color_size'));
    }

    /**
     * Enqueue JS and CSS only on order edit pages.
     */
    public static function enqueue_assets($hook) {
        $is_order_page = false;

        // Classic WC (CPT)
        if ($hook === 'post.php' && isset($_GET['post'])) {
            $post_type = get_post_type(absint($_GET['post']));
            if ($post_type === 'shop_order') {
                $is_order_page = true;
            }
        }

        // HPOS
        if (isset($_GET['page']) && $_GET['page'] === 'wc-orders' && isset($_GET['action']) && $_GET['action'] === 'edit') {
            $is_order_page = true;
        }

        if (!$is_order_page) {
            return;
        }

        $plugin_url = plugin_dir_url(dirname(__FILE__));

        wp_enqueue_style(
            'mg-order-item-editor',
            $plugin_url . 'assets/css/order-item-editor.css',
            array(),
            '1.0.0'
        );

        wp_enqueue_script(
            'mg-order-item-editor',
            $plugin_url . 'assets/js/order-item-editor.js',
            array('jquery'),
            '1.0.0',
            true
        );

        wp_localize_script('mg-order-item-editor', 'mgOrderItemEditor', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('mg_order_item_editor'),
            'i18n'     => array(
                'loading'        => __('Betöltés...', 'mgdtp'),
                'save'           => __('Mentés', 'mgdtp'),
                'cancel'         => __('Mégse', 'mgdtp'),
                'saved'          => __('Módosítás mentve!', 'mgdtp'),
                'error'          => __('Hiba történt, próbáld újra.', 'mgdtp'),
                'select_color'   => __('— Szín kiválasztása —', 'mgdtp'),
                'select_size'    => __('— Méret kiválasztása —', 'mgdtp'),
                'no_options'     => __('Nincs elérhető opció ehhez a terméktípushoz.', 'mgdtp'),
                'title'          => __('Szín / Méret módosítása', 'mgdtp'),
                'color_label'    => __('Szín:', 'mgdtp'),
                'size_label'     => __('Méret:', 'mgdtp'),
                'current_color'  => __('Jelenlegi szín:', 'mgdtp'),
                'current_size'   => __('Jelenlegi méret:', 'mgdtp'),
            ),
        ));
    }

    /**
     * Render the "✏️ Szín/Méret módosítás" button after order item meta.
     */
    public static function render_edit_button($item_id, $item, $product) {
        if (!$item_id || !($item instanceof WC_Order_Item_Product)) {
            return;
        }

        $color = $item->get_meta('mg_color');
        $size  = $item->get_meta('mg_size');
        $type  = $item->get_meta('mg_product_type');

        // Only show button if we have at least color or size meta
        if (empty($color) && empty($size)) {
            return;
        }

        $order_id = $item->get_order_id();

        printf(
            '<div class="mg-order-item-editor-wrap" style="margin-top:6px;">
                <button type="button"
                    class="button button-small mg-edit-item-btn"
                    data-item-id="%d"
                    data-order-id="%d"
                    data-type="%s"
                    data-color="%s"
                    data-size="%s">
                    ✏️ %s
                </button>
            </div>',
            esc_attr($item_id),
            esc_attr($order_id),
            esc_attr($type),
            esc_attr($color),
            esc_attr($size),
            esc_html__('Szín/Méret módosítás', 'mgdtp')
        );
    }

    /**
     * Render the hidden modal HTML in the admin footer (once per page).
     */
    public static function render_modal() {
        $screen = get_current_screen();
        if (!$screen) {
            return;
        }
        // Classic orders or HPOS
        $is_order = (
            ($screen->id === 'shop_order') ||
            ($screen->id === 'woocommerce_page_wc-orders' && isset($_GET['action']) && $_GET['action'] === 'edit')
        );
        if (!$is_order) {
            return;
        }
        ?>
        <div id="mg-item-editor-overlay" class="mg-item-editor-overlay" style="display:none;">
            <div class="mg-item-editor-modal">
                <div class="mg-item-editor-header">
                    <h3 id="mg-item-editor-title"><?php esc_html_e('Szín / Méret módosítása', 'mgdtp'); ?></h3>
                    <button type="button" class="mg-item-editor-close" aria-label="<?php esc_attr_e('Bezárás', 'mgdtp'); ?>">&times;</button>
                </div>
                <div class="mg-item-editor-body">
                    <div id="mg-item-editor-loading" class="mg-item-editor-loading">
                        <span class="spinner is-active"></span>
                    </div>
                    <div id="mg-item-editor-content" style="display:none;">
                        <table class="form-table mg-item-editor-table">
                            <tr>
                                <th><?php esc_html_e('Jelenlegi szín:', 'mgdtp'); ?></th>
                                <td><strong id="mg-current-color">—</strong></td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e('Jelenlegi méret:', 'mgdtp'); ?></th>
                                <td><strong id="mg-current-size">—</strong></td>
                            </tr>
                            <tr id="mg-color-row">
                                <th><label for="mg-new-color"><?php esc_html_e('Új szín:', 'mgdtp'); ?></label></th>
                                <td>
                                    <select id="mg-new-color" class="regular-text">
                                        <option value=""><?php esc_html_e('— Szín kiválasztása —', 'mgdtp'); ?></option>
                                    </select>
                                </td>
                            </tr>
                            <tr id="mg-size-row">
                                <th><label for="mg-new-size"><?php esc_html_e('Új méret:', 'mgdtp'); ?></label></th>
                                <td>
                                    <select id="mg-new-size" class="regular-text">
                                        <option value=""><?php esc_html_e('— Méret kiválasztása —', 'mgdtp'); ?></option>
                                    </select>
                                </td>
                            </tr>
                        </table>
                        <div id="mg-item-editor-message" class="mg-item-editor-message" style="display:none;"></div>
                    </div>
                </div>
                <div class="mg-item-editor-footer">
                    <button type="button" id="mg-item-editor-save" class="button button-primary" style="display:none;">
                        <?php esc_html_e('Mentés', 'mgdtp'); ?>
                    </button>
                    <button type="button" class="button mg-item-editor-close">
                        <?php esc_html_e('Mégse', 'mgdtp'); ?>
                    </button>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * AJAX: return available colors and sizes for the given product type.
     */
    public static function ajax_get_item_options() {
        check_ajax_referer('mg_order_item_editor', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Nincs jogosultságod.', 'mgdtp')));
        }

        $item_id = absint($_POST['item_id'] ?? 0);
        $type_slug = sanitize_title($_POST['type_slug'] ?? '');

        if (!$item_id) {
            wp_send_json_error(array('message' => __('Hiányzó tétel azonosító.', 'mgdtp')));
        }

        if (!class_exists('MG_Variant_Display_Manager')) {
            wp_send_json_error(array('message' => __('A katalógus nem elérhető.', 'mgdtp')));
        }

        $catalog = MG_Variant_Display_Manager::get_catalog_index();

        $colors = array();
        $sizes  = array();

        if (!empty($type_slug) && isset($catalog[$type_slug])) {
            $type_meta = $catalog[$type_slug];

            if (!empty($type_meta['colors']) && is_array($type_meta['colors'])) {
                foreach ($type_meta['colors'] as $color_slug => $color_data) {
                    $colors[] = array(
                        'slug'  => $color_slug,
                        'label' => $color_data['label'] ?? $color_slug,
                        'hex'   => $color_data['hex'] ?? '',
                    );
                }
            }

            if (!empty($type_meta['sizes']) && is_array($type_meta['sizes'])) {
                $sizes = $type_meta['sizes'];
            }
        } else {
            // No type or type not in catalog – return all colors/sizes from all types
            $all_colors = array();
            $all_sizes  = array();
            foreach ($catalog as $t_slug => $t_meta) {
                if (!empty($t_meta['colors'])) {
                    foreach ($t_meta['colors'] as $cs => $cd) {
                        $all_colors[$cs] = array(
                            'slug'  => $cs,
                            'label' => $cd['label'] ?? $cs,
                            'hex'   => $cd['hex'] ?? '',
                        );
                    }
                }
                if (!empty($t_meta['sizes'])) {
                    foreach ($t_meta['sizes'] as $s) {
                        if (!in_array($s, $all_sizes, true)) {
                            $all_sizes[] = $s;
                        }
                    }
                }
            }
            $colors = array_values($all_colors);
            $sizes  = $all_sizes;
        }

        wp_send_json_success(array(
            'colors' => $colors,
            'sizes'  => $sizes,
        ));
    }

    /**
     * AJAX: save new mg_color and/or mg_size to the order item meta.
     */
    public static function ajax_update_item_color_size() {
        check_ajax_referer('mg_order_item_editor', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Nincs jogosultságod.', 'mgdtp')));
        }

        $item_id   = absint($_POST['item_id'] ?? 0);
        $new_color = sanitize_text_field($_POST['new_color'] ?? '');
        $new_size  = sanitize_text_field($_POST['new_size'] ?? '');

        if (!$item_id) {
            wp_send_json_error(array('message' => __('Hiányzó tétel azonosító.', 'mgdtp')));
        }

        $item = WC_Order_Factory::get_order_item($item_id);
        if (!$item) {
            wp_send_json_error(array('message' => __('A rendelési tétel nem található.', 'mgdtp')));
        }

        $updated = false;

        if ($new_color !== '') {
            $item->update_meta_data('mg_color', $new_color);
            // Also update the human-readable meta if present
            $item->update_meta_data('Szín', $new_color);
            $updated = true;
        }

        if ($new_size !== '') {
            $item->update_meta_data('mg_size', $new_size);
            // Also update the human-readable meta if present (stored by MG_Size_Selection)
            $item->update_meta_data('Méret', $new_size);
            $updated = true;
        }

        if (!$updated) {
            wp_send_json_error(array('message' => __('Nem adtál meg módosítandó értéket.', 'mgdtp')));
        }

        $item->save();

        // Add order note
        $order = $item->get_order();
        if ($order) {
            $note_parts = array();
            if ($new_color !== '') {
                $note_parts[] = sprintf(__('Szín: %s', 'mgdtp'), $new_color);
            }
            if ($new_size !== '') {
                $note_parts[] = sprintf(__('Méret: %s', 'mgdtp'), $new_size);
            }
            $order->add_order_note(
                sprintf(
                    __('Admin módosítás – %s – %s', 'mgdtp'),
                    $item->get_name(),
                    implode(', ', $note_parts)
                )
            );
        }

        wp_send_json_success(array(
            'message'    => __('Módosítás mentve!', 'mgdtp'),
            'new_color'  => $new_color,
            'new_size'   => $new_size,
        ));
    }
}
