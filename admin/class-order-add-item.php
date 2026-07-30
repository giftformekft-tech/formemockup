<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * MG_Order_Add_Item
 *
 * Adds a "➕ Tétel hozzáadása" button to the WooCommerce order edit screen so
 * customer service can put an extra item into an already placed order when the
 * customer asks for it afterwards.
 *
 * The new line is a normal virtual variant: the same product type / color /
 * size selection and the same order item meta the checkout writes, produced by
 * MG_Virtual_Variant_Manager::apply_variant_meta_to_item().
 *
 * Because the extra amount is collected on delivery, this is only allowed on
 * cash-on-delivery (utánvét) orders — there the courier simply collects the new
 * total, so no online payment has to be re-taken. It stays available while the
 * order is already in production; in that case the admin is warned that the new
 * item still has to be sent to the supplier separately.
 */
class MG_Order_Add_Item {

    const NONCE_ACTION = 'mg_order_add_item';

    /** @var bool Set while enqueueing, so the footer knows the modal is needed. */
    protected static $assets_loaded = false;

    public static function init() {
        add_action('woocommerce_order_item_add_action_buttons', array(__CLASS__, 'render_button'));
        add_action('admin_footer', array(__CLASS__, 'render_modal'));
        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue_assets'));
        add_action('wp_ajax_mg_order_add_item_search', array(__CLASS__, 'ajax_search_products'));
        add_action('wp_ajax_mg_order_add_item_options', array(__CLASS__, 'ajax_get_options'));
        add_action('wp_ajax_mg_order_add_item_save', array(__CLASS__, 'ajax_add_item'));
    }

    /**
     * True on both the classic (CPT) and the HPOS order edit screen.
     */
    protected static function is_order_edit_screen($hook = '') {
        if ($hook === 'post.php' && isset($_GET['post'])) {
            if (get_post_type(absint($_GET['post'])) === 'shop_order') {
                return true;
            }
        }

        if (isset($_GET['page']) && $_GET['page'] === 'wc-orders'
            && isset($_GET['action']) && $_GET['action'] === 'edit') {
            return true;
        }

        return false;
    }

    /**
     * Payment methods that are collected on delivery.
     */
    public static function is_cod_order($order) {
        if (!$order instanceof WC_Order) {
            return false;
        }

        $method = $order->get_payment_method();
        $cod_gateways = apply_filters('mg_order_add_item_cod_gateways', array('cod', 'wc_cod', 'utanvet', 'cod_gateway'));
        if (in_array($method, $cod_gateways, true)) {
            return true;
        }

        // Some HU gateways ship under their own id but say "utánvét" in the title.
        $haystack = strtolower($method . ' ' . $order->get_payment_method_title());
        foreach (array('utánvét', 'utanvet', 'cash on delivery', 'cash-on-delivery') as $needle) {
            if ($needle !== '' && strpos($haystack, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Statuses where an order is finished or void, so nothing may be added.
     * "manufacturing" is deliberately NOT here: adding is allowed during
     * production, the admin just gets a warning.
     */
    protected static function get_blocked_statuses() {
        return apply_filters(
            'mg_order_add_item_blocked_statuses',
            array('completed', 'cancelled', 'refunded', 'failed', 'checkout-draft')
        );
    }

    /**
     * Statuses where the order has most likely already been exported to the
     * supplier, so the extra item needs to be forwarded by hand.
     */
    protected static function get_in_production_statuses() {
        return apply_filters('mg_order_add_item_in_production_statuses', array('manufacturing'));
    }

    /**
     * @return true|WP_Error True when an item may be added to this order.
     */
    public static function check_eligibility($order) {
        if (!$order instanceof WC_Order) {
            return new WP_Error('mg_no_order', __('A rendelés nem található.', 'mgdtp'));
        }

        if (!self::is_cod_order($order)) {
            return new WP_Error(
                'mg_not_cod',
                __('Utólag csak utánvétes rendeléshez adható tétel – ennél a rendelésnél a különbözetet nem tudja a futár beszedni.', 'mgdtp')
            );
        }

        if (in_array($order->get_status(), self::get_blocked_statuses(), true)) {
            return new WP_Error(
                'mg_bad_status',
                sprintf(
                    /* translators: %s: order status label */
                    __('Ebben az állapotban (%s) már nem bővíthető a rendelés.', 'mgdtp'),
                    wc_get_order_status_name($order->get_status())
                )
            );
        }

        return true;
    }

    protected static function is_in_production($order) {
        return $order instanceof WC_Order
            && in_array($order->get_status(), self::get_in_production_statuses(), true);
    }

    public static function enqueue_assets($hook) {
        if (!self::is_order_edit_screen($hook)) {
            return;
        }

        $plugin_url = plugin_dir_url(dirname(__FILE__));
        $plugin_dir = plugin_dir_path(dirname(__FILE__));

        // filemtime beats MG_VERSION here: an optimizer plugin (LiteSpeed) keeps
        // serving the combined stylesheet until the URL actually changes.
        $css_path = $plugin_dir . 'assets/css/order-add-item.css';
        $js_path  = $plugin_dir . 'assets/js/order-add-item.js';
        $fallback = defined('MG_VERSION') ? MG_VERSION : '1.0.0';
        $css_ver  = file_exists($css_path) ? filemtime($css_path) : $fallback;
        $js_ver   = file_exists($js_path) ? filemtime($js_path) : $fallback;

        self::$assets_loaded = true;

        wp_enqueue_style('mg-order-add-item', $plugin_url . 'assets/css/order-add-item.css', array(), $css_ver);
        wp_enqueue_script('mg-order-add-item', $plugin_url . 'assets/js/order-add-item.js', array('jquery'), $js_ver, true);

        wp_localize_script('mg-order-add-item', 'mgOrderAddItem', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce(self::NONCE_ACTION),
            'currency' => html_entity_decode(get_woocommerce_currency_symbol()),
            'i18n'     => array(
                'title'          => __('Tétel hozzáadása a rendeléshez', 'mgdtp'),
                'search_label'   => __('Termék keresése (név vagy cikkszám):', 'mgdtp'),
                'search_hint'    => __('Írj be legalább 3 karaktert.', 'mgdtp'),
                'no_results'     => __('Nincs találat.', 'mgdtp'),
                'selected'       => __('Kiválasztott termék:', 'mgdtp'),
                'change'         => __('Másik termék', 'mgdtp'),
                'type_label'     => __('Terméktípus:', 'mgdtp'),
                'color_label'    => __('Szín:', 'mgdtp'),
                'size_label'     => __('Méret:', 'mgdtp'),
                'qty_label'      => __('Darabszám:', 'mgdtp'),
                'price_label'    => __('Egységár (bruttó):', 'mgdtp'),
                'price_hint'     => __('A típus alapára + méret felár. Felülírható.', 'mgdtp'),
                'notify_label'   => __('Vevő értesítése e-mailben a módosításról', 'mgdtp'),
                'select_type'    => __('— Terméktípus kiválasztása —', 'mgdtp'),
                'select_color'   => __('— Szín kiválasztása —', 'mgdtp'),
                'select_size'    => __('— Méret kiválasztása —', 'mgdtp'),
                'incomplete'     => __('Válassz terméket, típust, színt és méretet.', 'mgdtp'),
                'add'            => __('Hozzáadás', 'mgdtp'),
                'adding'         => __('Hozzáadás…', 'mgdtp'),
                'cancel'         => __('Mégse', 'mgdtp'),
                'loading'        => __('Betöltés…', 'mgdtp'),
                'error'          => __('Hiba történt, próbáld újra.', 'mgdtp'),
            ),
        ));
    }

    /**
     * Render the trigger button under the order items table.
     */
    public static function render_button($order) {
        if (!$order instanceof WC_Order) {
            return;
        }

        $eligible = self::check_eligibility($order);

        if (is_wp_error($eligible)) {
            printf(
                '<span class="mg-add-item-blocked" title="%s">%s</span>',
                esc_attr($eligible->get_error_message()),
                esc_html__('➕ Tétel hozzáadása – nem elérhető', 'mgdtp')
            );
            return;
        }

        printf(
            '<button type="button" class="button mg-add-item-btn" data-order-id="%d" data-in-production="%d">%s</button>',
            esc_attr($order->get_id()),
            self::is_in_production($order) ? 1 : 0,
            esc_html__('➕ Tétel hozzáadása', 'mgdtp')
        );
    }

    public static function render_modal() {
        if (!self::$assets_loaded) {
            return;
        }
        ?>
        <style id="mg-add-item-critical">
        /* Printed inline so the modal is never laid out in the page flow (and
           hidden behind the admin menu) if the stylesheet is delayed, combined
           or cached away by an optimizer plugin. */
        #mg-add-item-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.55);
            z-index: 160000;
            align-items: center;
            justify-content: center;
        }
        #mg-add-item-overlay .mg-add-item-modal {
            background: #fff;
            width: 560px;
            max-width: 95vw;
            max-height: 90vh;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            border-radius: 4px;
            box-shadow: 0 4px 24px rgba(0, 0, 0, 0.25);
        }
        #mg-add-item-overlay .mg-add-item-body {
            padding: 18px;
            overflow-y: auto;
            flex: 1;
        }
        #mg-add-item-overlay .mg-add-item-results {
            max-height: 240px;
            overflow-y: auto;
        }
        </style>
        <div id="mg-add-item-overlay" class="mg-add-item-overlay" style="display:none;">
            <div class="mg-add-item-modal">
                <div class="mg-add-item-header">
                    <h3><?php esc_html_e('Tétel hozzáadása a rendeléshez', 'mgdtp'); ?></h3>
                    <button type="button" class="mg-add-item-close" aria-label="<?php esc_attr_e('Bezárás', 'mgdtp'); ?>">&times;</button>
                </div>
                <div class="mg-add-item-body">
                    <div id="mg-add-item-production-warning" class="mg-add-item-warning" style="display:none;">
                        <?php esc_html_e('Ez a rendelés már gyártás alatt van. A tétel bekerül a rendelésbe és az utánvét összegébe, de a nagykernek külön kell továbbítani – az exportot ez nem küldi újra.', 'mgdtp'); ?>
                    </div>

                    <p class="mg-add-item-field">
                        <label for="mg-add-item-search"><?php esc_html_e('Termék keresése (név vagy cikkszám):', 'mgdtp'); ?></label>
                        <input type="search" id="mg-add-item-search" class="widefat" autocomplete="off" />
                        <span class="description"><?php esc_html_e('Írj be legalább 3 karaktert.', 'mgdtp'); ?></span>
                    </p>

                    <ul id="mg-add-item-results" class="mg-add-item-results"></ul>

                    <div id="mg-add-item-selected" class="mg-add-item-selected" style="display:none;">
                        <img id="mg-add-item-thumb" src="" alt="" />
                        <div>
                            <strong id="mg-add-item-product-name"></strong>
                            <div class="description" id="mg-add-item-product-sku"></div>
                        </div>
                        <button type="button" class="button-link" id="mg-add-item-change"><?php esc_html_e('Másik termék', 'mgdtp'); ?></button>
                    </div>

                    <div id="mg-add-item-variant" style="display:none;">
                        <table class="mg-add-item-table form-table">
                            <tbody>
                                <tr>
                                    <th><label for="mg-add-item-type"><?php esc_html_e('Terméktípus:', 'mgdtp'); ?></label></th>
                                    <td><select id="mg-add-item-type" class="widefat"></select></td>
                                </tr>
                                <tr>
                                    <th><label for="mg-add-item-color"><?php esc_html_e('Szín:', 'mgdtp'); ?></label></th>
                                    <td><select id="mg-add-item-color" class="widefat"></select></td>
                                </tr>
                                <tr>
                                    <th><label for="mg-add-item-size"><?php esc_html_e('Méret:', 'mgdtp'); ?></label></th>
                                    <td><select id="mg-add-item-size" class="widefat"></select></td>
                                </tr>
                                <tr>
                                    <th><label for="mg-add-item-qty"><?php esc_html_e('Darabszám:', 'mgdtp'); ?></label></th>
                                    <td><input type="number" id="mg-add-item-qty" min="1" step="1" value="1" /></td>
                                </tr>
                                <tr>
                                    <th><label for="mg-add-item-price"><?php esc_html_e('Egységár (bruttó):', 'mgdtp'); ?></label></th>
                                    <td>
                                        <input type="number" id="mg-add-item-price" min="0" step="1" value="0" />
                                        <span class="description"><?php esc_html_e('A típus alapára + méret felár. Felülírható.', 'mgdtp'); ?></span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>

                        <p>
                            <label>
                                <input type="checkbox" id="mg-add-item-notify" checked="checked" />
                                <?php esc_html_e('Vevő értesítése e-mailben a módosításról', 'mgdtp'); ?>
                            </label>
                        </p>
                    </div>

                    <div id="mg-add-item-message" class="notice" style="display:none;"><p></p></div>
                </div>
                <div class="mg-add-item-footer">
                    <button type="button" id="mg-add-item-save" class="button button-primary" disabled="disabled">
                        <?php esc_html_e('Hozzáadás', 'mgdtp'); ?>
                    </button>
                    <button type="button" class="button mg-add-item-close"><?php esc_html_e('Mégse', 'mgdtp'); ?></button>
                </div>
            </div>
        </div>
        <?php
    }

    protected static function verify_request() {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        if (!current_user_can('edit_shop_orders')) {
            wp_send_json_error(array('message' => __('Nincs jogosultságod.', 'mgdtp')));
        }
    }

    /**
     * AJAX: product search by title or SKU.
     */
    public static function ajax_search_products() {
        self::verify_request();

        $term = isset($_POST['q']) ? sanitize_text_field(wp_unslash($_POST['q'])) : '';
        if (mb_strlen($term) < 3) {
            wp_send_json_success(array('products' => array()));
        }

        $ids = wc_get_products(array(
            'status' => array('publish', 'private'),
            'limit'  => 20,
            's'      => $term,
            'return' => 'ids',
        ));

        // wc_get_products' "s" does not cover SKUs, so look them up separately.
        $by_sku = wc_get_products(array(
            'status' => array('publish', 'private'),
            'limit'  => 20,
            'sku'    => $term,
            'return' => 'ids',
        ));

        $ids = array_slice(array_unique(array_merge((array) $ids, (array) $by_sku)), 0, 20);

        $products = array();
        foreach ($ids as $product_id) {
            $product = wc_get_product($product_id);
            if (!$product || $product->is_type('variation')) {
                continue;
            }
            $image_id = $product->get_image_id();
            $products[] = array(
                'id'    => $product->get_id(),
                'name'  => $product->get_name(),
                'sku'   => $product->get_sku(),
                'thumb' => $image_id ? wp_get_attachment_image_url($image_id, 'thumbnail') : wc_placeholder_img_src('thumbnail'),
            );
        }

        wp_send_json_success(array('products' => $products));
    }

    /**
     * AJAX: the virtual variant catalog (types, colors, sizes, prices) for the
     * selected product.
     */
    public static function ajax_get_options() {
        self::verify_request();

        $product_id = absint($_POST['product_id'] ?? 0);
        $product = $product_id ? wc_get_product($product_id) : null;
        if (!$product) {
            wp_send_json_error(array('message' => __('A termék nem található.', 'mgdtp')));
        }

        if (!class_exists('MG_Variant_Display_Manager')) {
            wp_send_json_error(array('message' => __('A katalógus nem elérhető.', 'mgdtp')));
        }

        $catalog = MG_Variant_Display_Manager::get_catalog_index();
        if (empty($catalog)) {
            wp_send_json_error(array('message' => __('A katalógus üres – állítsd be a terméktípusokat.', 'mgdtp')));
        }

        $types = array();
        foreach ($catalog as $type_slug => $type_meta) {
            $colors = array();
            if (!empty($type_meta['colors']) && is_array($type_meta['colors'])) {
                foreach ($type_meta['colors'] as $color_slug => $color_meta) {
                    $colors[] = array(
                        'slug'  => $color_slug,
                        'label' => $color_meta['label'] ?? $color_slug,
                        'hex'   => $color_meta['hex'] ?? '',
                    );
                }
            }

            $sizes = array();
            if (!empty($type_meta['sizes']) && is_array($type_meta['sizes'])) {
                foreach ($type_meta['sizes'] as $size) {
                    $sizes[] = array(
                        'value' => $size,
                        'price' => class_exists('MG_Virtual_Variant_Manager')
                            ? MG_Virtual_Variant_Manager::calculate_variant_price($type_slug, $size)
                            : 0.0,
                    );
                }
            }

            $types[] = array(
                'slug'   => $type_slug,
                'label'  => $type_meta['label'] ?? $type_slug,
                'colors' => $colors,
                'sizes'  => $sizes,
            );
        }

        $default = class_exists('MG_Virtual_Variant_Manager')
            ? MG_Virtual_Variant_Manager::get_default_selection($product)
            : array();

        wp_send_json_success(array(
            'types'   => $types,
            'default' => $default,
        ));
    }

    /**
     * AJAX: build the line item, attach the virtual variant meta and push it
     * into the order.
     */
    public static function ajax_add_item() {
        self::verify_request();

        $order_id   = absint($_POST['order_id'] ?? 0);
        $product_id = absint($_POST['product_id'] ?? 0);
        $type_slug  = sanitize_title($_POST['type'] ?? '');
        $color_slug = sanitize_title($_POST['color'] ?? '');
        $size_value = sanitize_text_field(wp_unslash($_POST['size'] ?? ''));
        $quantity   = max(1, absint($_POST['qty'] ?? 1));
        $notify     = !empty($_POST['notify']) && $_POST['notify'] !== 'false';
        $unit_price = isset($_POST['price']) ? wc_format_decimal(wp_unslash($_POST['price'])) : '';

        $order = $order_id ? wc_get_order($order_id) : null;
        $eligible = self::check_eligibility($order);
        if (is_wp_error($eligible)) {
            wp_send_json_error(array('message' => $eligible->get_error_message()));
        }

        $product = $product_id ? wc_get_product($product_id) : null;
        if (!$product) {
            wp_send_json_error(array('message' => __('A termék nem található.', 'mgdtp')));
        }

        if ($type_slug === '' || $color_slug === '' || $size_value === '') {
            wp_send_json_error(array('message' => __('Válassz terméktípust, színt és méretet.', 'mgdtp')));
        }

        $catalog = class_exists('MG_Variant_Display_Manager') ? MG_Variant_Display_Manager::get_catalog_index() : array();
        if (!isset($catalog[$type_slug])) {
            wp_send_json_error(array('message' => __('Ismeretlen terméktípus.', 'mgdtp')));
        }
        if (!isset($catalog[$type_slug]['colors'][$color_slug])) {
            wp_send_json_error(array('message' => __('Ez a szín nem tartozik a választott terméktípushoz.', 'mgdtp')));
        }
        $type_sizes = isset($catalog[$type_slug]['sizes']) ? (array) $catalog[$type_slug]['sizes'] : array();
        if (!empty($type_sizes) && !in_array($size_value, $type_sizes, true)) {
            wp_send_json_error(array('message' => __('Ez a méret nem tartozik a választott terméktípushoz.', 'mgdtp')));
        }

        // Price: the admin may override it, otherwise the catalog price applies.
        $price = ($unit_price === '') ? null : (float) $unit_price;
        if ($price === null || $price < 0) {
            $price = class_exists('MG_Virtual_Variant_Manager')
                ? MG_Virtual_Variant_Manager::calculate_variant_price($type_slug, $size_value)
                : 0.0;
        }
        if ($price <= 0) {
            $price = (float) $product->get_price();
        }

        $item = new WC_Order_Item_Product();
        $item->set_product($product);
        $item->set_quantity($quantity);

        // wc_get_price_excluding_tax() strips the tax when the shop stores gross
        // prices, so the line total matches what the cart would have produced.
        $line_total = wc_get_price_excluding_tax($product, array('qty' => $quantity, 'price' => $price));
        $item->set_subtotal($line_total);
        $item->set_total($line_total);

        $preview_url = class_exists('MG_Virtual_Variant_Manager')
            ? MG_Virtual_Variant_Manager::resolve_preview_url($product_id, $type_slug, $color_slug)
            : '';

        $values = array(
            'product_id'        => $product_id,
            'mg_product_type'   => $type_slug,
            'mg_color'          => $color_slug,
            'mg_size'           => $size_value,
            'mg_design_id'      => class_exists('MG_Virtual_Variant_Manager')
                ? MG_Virtual_Variant_Manager::get_design_id($product)
                : $product_id,
            'mg_preview_url'    => $preview_url,
            'mg_render_version' => class_exists('MG_Virtual_Variant_Manager')
                ? MG_Virtual_Variant_Manager::get_render_version($product)
                : '',
        );

        if (class_exists('MG_Virtual_Variant_Manager')) {
            MG_Virtual_Variant_Manager::apply_variant_meta_to_item($item, $values);
        }

        $was_in_production = self::is_in_production($order);

        $item->add_meta_data('_mg_added_after_order', current_time('mysql'), true);
        $item->add_meta_data('_mg_added_by', get_current_user_id(), true);

        $old_total = (float) $order->get_total();

        $order->add_item($item);
        $order->calculate_taxes();
        $order->calculate_totals(false);

        if ($was_in_production) {
            $order->update_meta_data('_mg_items_added_after_export', 'yes');
        }

        $order->save();

        $new_total = (float) $order->get_total();

        $type_label  = $catalog[$type_slug]['label'] ?? $type_slug;
        $color_label = $catalog[$type_slug]['colors'][$color_slug]['label'] ?? $color_slug;

        $note = sprintf(
            /* translators: 1: product name, 2: type, 3: color, 4: size, 5: quantity, 6: old total, 7: new total */
            __('Utólagos tétel hozzáadva: %1$s (%2$s / %3$s / %4$s) × %5$d. Végösszeg: %6$s → %7$s (utánvéttel fizetendő).', 'mgdtp'),
            $product->get_name(),
            $type_label,
            $color_label,
            $size_value,
            $quantity,
            wp_strip_all_tags(wc_price($old_total)),
            wp_strip_all_tags(wc_price($new_total))
        );

        if ($was_in_production) {
            $note .= ' ' . __('FIGYELEM: a rendelés már gyártás alatt volt, a tételt külön kell a nagykernek elküldeni.', 'mgdtp');
        }

        $order->add_order_note($note);

        if ($notify) {
            $order->add_order_note(
                sprintf(
                    /* translators: 1: product name, 2: type, 3: color, 4: size, 5: quantity, 6: new total */
                    __('Kérésedre kiegészítettük a rendelésedet: %1$s (%2$s / %3$s / %4$s) × %5$d. Az utánvétnél fizetendő új végösszeg: %6$s.', 'mgdtp'),
                    $product->get_name(),
                    $type_label,
                    $color_label,
                    $size_value,
                    $quantity,
                    wp_strip_all_tags(wc_price($new_total))
                ),
                true
            );
        }

        /**
         * Fires after an item was added to an existing order from the admin.
         *
         * @param WC_Order_Item_Product $item  The new line item.
         * @param WC_Order              $order The order it was added to.
         */
        do_action('mg_order_item_added_after_purchase', $item, $order);

        wp_send_json_success(array(
            'message'   => __('Tétel hozzáadva a rendeléshez.', 'mgdtp'),
            'new_total' => wp_strip_all_tags(wc_price($new_total)),
        ));
    }
}
