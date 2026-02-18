<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class MG_Google_Customer_Reviews
 * 
 * Integrálja a Google Ügyfélvélemények (Google Customer Reviews) programot.
 * A rendelés visszaigazoló oldalon (thank you page) megjeleníti a feliratkozási kérdőívet.
 */
class MG_Google_Customer_Reviews {

    /** Google Merchant Center ID */
    const MERCHANT_ID = 5728646952;

    /** Becsült szállítási idő napokban (alapértelmezés) */
    const DEFAULT_DELIVERY_DAYS = 10;

    public static function init() {
        // Only on frontend
        if (is_admin() && !defined('DOING_AJAX')) {
            return;
        }

        add_action('woocommerce_thankyou', array(__CLASS__, 'output_survey_optin'), 20, 1);
    }

    /**
     * Output the Google Customer Reviews survey opt-in script on the thank you page.
     *
     * @param int $order_id WooCommerce order ID
     */
    public static function output_survey_optin($order_id) {
        if (!$order_id) {
            return;
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        // Collect order data
        $email = $order->get_billing_email();
        if (!$email) {
            return; // Email is required
        }

        // Delivery country: prefer shipping, fallback to billing
        $country = $order->get_shipping_country();
        if (!$country) {
            $country = $order->get_billing_country();
        }
        if (!$country) {
            $country = 'HU'; // Fallback
        }

        // Estimated delivery date
        $delivery_days = apply_filters('mg_gcr_estimated_delivery_days', self::DEFAULT_DELIVERY_DAYS, $order);
        $order_date = $order->get_date_created();
        if ($order_date) {
            $estimated_date = clone $order_date;
            $estimated_date->modify('+' . absint($delivery_days) . ' days');
            $estimated_delivery = $estimated_date->format('Y-m-d');
        } else {
            $estimated_delivery = date('Y-m-d', strtotime('+' . absint($delivery_days) . ' days'));
        }

        // Collect GTINs from order items (if available)
        $products = array();
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if (!$product) {
                continue;
            }
            // Try to get GTIN from common meta keys
            $gtin = '';
            $gtin_keys = array('_gtin', '_ean', 'gtin', 'ean', '_global_unique_id');
            foreach ($gtin_keys as $key) {
                $value = $product->get_meta($key);
                if (!empty($value)) {
                    $gtin = sanitize_text_field($value);
                    break;
                }
            }
            // Also check WooCommerce 9.2+ built-in GTIN
            if (empty($gtin) && method_exists($product, 'get_global_unique_id')) {
                $gtin = $product->get_global_unique_id();
            }
            if (!empty($gtin)) {
                $products[] = array('gtin' => $gtin);
            }
        }

        ?>
        <script src="https://apis.google.com/js/platform.js?onload=renderOptIn" async defer></script>
        <script>
        window.renderOptIn = function() {
            window.gapi.load('surveyoptin', function() {
                window.gapi.surveyoptin.render({
                    "merchant_id": <?php echo intval(self::MERCHANT_ID); ?>,
                    "order_id": "<?php echo esc_js($order->get_order_number()); ?>",
                    "email": "<?php echo esc_js($email); ?>",
                    "delivery_country": "<?php echo esc_js($country); ?>",
                    "estimated_delivery_date": "<?php echo esc_js($estimated_delivery); ?>"<?php
                    if (!empty($products)) {
                        echo ',';
                        echo "\n                    \"products\": " . wp_json_encode($products);
                    }
                    ?>
                });
            });
        }
        </script>
        <?php
    }
}
