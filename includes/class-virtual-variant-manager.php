<?php
if (!defined('ABSPATH')) {
    exit;
}

class MG_Virtual_Variant_Manager {
    const NONCE_ACTION = 'mg_virtual_variant_preview';

    protected static $config_cache = array();
    protected static $generated_preview_cache = array();

    public static function init() {
        add_action('wp_enqueue_scripts', array(__CLASS__, 'enqueue_assets'), 20);
        add_action('woocommerce_before_add_to_cart_button', array(__CLASS__, 'render_selection_ui'), 5);
        add_filter('woocommerce_add_to_cart_validation', array(__CLASS__, 'validate_add_to_cart'), 10, 5);
        add_filter('woocommerce_add_cart_item_data', array(__CLASS__, 'add_cart_item_data'), 10, 4);
        add_filter('woocommerce_get_cart_item_from_session', array(__CLASS__, 'restore_cart_item'), 10, 2);
        add_filter('woocommerce_get_item_data', array(__CLASS__, 'render_cart_item_data'), 10, 2);
        add_action('woocommerce_checkout_create_order_line_item', array(__CLASS__, 'add_order_item_meta'), 10, 4);
        add_action('woocommerce_before_calculate_totals', array(__CLASS__, 'apply_cart_pricing'), 20, 1);
        add_filter('woocommerce_cart_item_thumbnail', array(__CLASS__, 'filter_cart_thumbnail'), 10, 3);
        add_filter('woocommerce_blocks_cart_item_image', array(__CLASS__, 'filter_cart_thumbnail'), 10, 3);
        add_filter('woocommerce_blocks_cart_item_thumbnail', array(__CLASS__, 'filter_cart_thumbnail'), 10, 3);
        add_filter('woocommerce_store_api_cart_item_images', array(__CLASS__, 'filter_store_api_cart_item_images'), 10, 2);
        add_filter('woocommerce_store_api_cart_item', array(__CLASS__, 'filter_store_api_cart_item'), 10, 2);
        add_filter('woocommerce_cart_item_price', array(__CLASS__, 'format_mini_cart_price'), PHP_INT_MAX, 3);
        add_filter('woocommerce_blocks_cart_item_price', array(__CLASS__, 'format_mini_cart_price'), PHP_INT_MAX, 3);
        add_filter('woocommerce_widget_cart_item_quantity', array(__CLASS__, 'format_widget_cart_item_quantity'), PHP_INT_MAX, 3);
        add_filter('woocommerce_order_item_thumbnail', array(__CLASS__, 'filter_order_thumbnail'), 10, 3);
        add_filter('woocommerce_hidden_order_itemmeta', array(__CLASS__, 'hide_order_item_meta'), 10, 1);
        add_filter('woocommerce_order_item_get_formatted_meta_data', array(__CLASS__, 'filter_order_item_meta_display'), 10, 2);
        add_action('wp_ajax_mg_virtual_preview', array(__CLASS__, 'ajax_preview'));
        add_action('wp_ajax_nopriv_mg_virtual_preview', array(__CLASS__, 'ajax_preview'));
    }

    protected static function is_supported_product($product) {
        if (!$product || !is_a($product, 'WC_Product')) {
            return false;
        }
        return $product->is_type('simple');
    }

    public static function enqueue_assets() {
        if (!function_exists('is_product') || !is_product()) {
            return;
        }

        global $post;
        if (!$post) {
            return;
        }

        $product = wc_get_product($post->ID);
        if (!self::is_supported_product($product)) {
            return;
        }

        $config = self::get_frontend_config($product);
        if (empty($config) || empty($config['types'])) {
            return;
        }

        $base_file = dirname(__DIR__) . '/mockup-generator.php';
        $style_path = dirname(__DIR__) . '/assets/css/variant-display.css';
        $script_path = dirname(__DIR__) . '/assets/js/virtual-variant-display.js';

        wp_enqueue_style(
            'mg-variant-display',
            plugins_url('assets/css/variant-display.css', $base_file),
            array(),
            file_exists($style_path) ? filemtime($style_path) : '1.0.0'
        );

        wp_enqueue_script(
            'mg-virtual-variant-display',
            plugins_url('assets/js/virtual-variant-display.js', $base_file),
            array('jquery'),
            file_exists($script_path) ? filemtime($script_path) : time(),
            true
        );

        $config['ajax'] = array(
            'url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce(self::NONCE_ACTION),
        );
        
        // Merge with existing product config to preserve SKU
        $product_extras = array(
            'id' => $product->get_id(),
            'design_id' => self::get_design_id($product),
            'render_version' => self::get_render_version($product),
        );
        
        if (isset($config['product']) && is_array($config['product'])) {
            $config['product'] = array_merge($config['product'], $product_extras);
        } else {
            $config['product'] = $product_extras;
        }

        wp_localize_script('mg-virtual-variant-display', 'MG_VIRTUAL_VARIANTS', $config);
    }

    public static function render_selection_ui() {
        global $product;
        if (!self::is_supported_product($product)) {
            return;
        }

        $config = self::get_frontend_config($product);
        if (empty($config) || empty($config['types'])) {
            return;
        }

        $design_id = self::get_design_id($product);
        $render_version = self::get_render_version($product);

        echo '<div class="mg-virtual-variant" data-mg-virtual="1"></div>';
        echo '<input type="hidden" name="mg_product_type" value="" />';
        echo '<input type="hidden" name="mg_color" value="" />';
        echo '<input type="hidden" name="mg_size" value="" />';
        echo '<input type="hidden" name="mg_preview_url" value="" />';
        echo '<input type="hidden" name="mg_design_id" value="' . esc_attr($design_id) . '" />';
        echo '<input type="hidden" name="mg_render_version" value="' . esc_attr($render_version) . '" />';
    }

    public static function get_frontend_config($product) {
        $product_id = $product ? $product->get_id() : 0;
        if (!$product_id) {
            return array();
        }
        if (isset(self::$config_cache[$product_id])) {
            return self::$config_cache[$product_id];
        }

        // Ensure Product Creator is available
        if (!class_exists('MG_Product_Creator')) {
            $creator_path = dirname(__DIR__) . '/includes/class-product-creator.php';
            if (file_exists($creator_path)) {
                require_once $creator_path;
            }
        }

        // Get or generate SKU for predictable file structure
        $sku = $product->get_sku();
        if ((!$sku || $sku === '') && class_exists('MG_Product_Creator')) {
            $sku = MG_Product_Creator::generate_product_sku($product_id, $product->get_name());
            if ($sku) {
                // Update the product object instance as well
                $product->set_sku($sku);
            }
        }

        $catalog = class_exists('MG_Variant_Display_Manager') ? MG_Variant_Display_Manager::get_catalog_index() : array();
        if (empty($catalog)) {
            self::$config_cache[$product_id] = array();
            return array();
        }

        $settings = self::get_settings($catalog);
        $products = function_exists('mgsc_get_products') ? mgsc_get_products() : array();
        
        // Product configuration
        $product_config = array(
            'id' => $product_id,
            'name' => $product->get_name(),
        );
        
        // Add SKU if available
        if ($sku) {
            $product_config['sku'] = $sku;
        } else {
            $product_config['sku'] = $product->get_sku();
        }
        
        // Mockup configuration for predictable URLs
        $uploads = wp_upload_dir();
        $mockup_config = array(
            'baseUrl' => isset($uploads['baseurl']) ? trailingslashit($uploads['baseurl']) . 'mg_mockups' : '',
            'pattern' => '{sku}/{sku}_{type}_{color}_{view}.webp'
        );
        
        // Product-specific type filtering removed - all products now show all available types
        // from the global catalog (global-attributes.php or mg_products option)
        
        $types_payload = array();
        $type_order = array();
        foreach ($catalog as $type_slug => $type_meta) {
            $type_slug = sanitize_title($type_slug);
            if ($type_slug === '' || !is_array($type_meta)) {
                continue;
            }
            $type_order[] = $type_slug;
            $colors_payload = array();
            $color_order = array();
            $size_chart = isset($settings['size_charts'][$type_slug]) ? $settings['size_charts'][$type_slug] : '';
            if ($size_chart !== '') {
                $size_chart = do_shortcode($size_chart);
            }
            $size_chart_models = isset($settings['size_chart_models'][$type_slug]) ? $settings['size_chart_models'][$type_slug] : '';
            if ($size_chart_models !== '') {
                $size_chart_models = do_shortcode($size_chart_models);
            }
            $type_description = isset($type_meta['description']) ? $type_meta['description'] : '';
            if ($type_description !== '') {
                $type_description = apply_filters('mg_variant_display_type_description', $type_description, $type_slug, $product);
            }

            $raw_type = isset($products[$type_slug]) && is_array($products[$type_slug]) ? $products[$type_slug] : array();
            $color_surcharges = array();
            if (!empty($raw_type['colors']) && is_array($raw_type['colors'])) {
                foreach ($raw_type['colors'] as $color_entry) {
                    if (!is_array($color_entry)) {
                        continue;
                    }
                    $raw_slug = isset($color_entry['slug']) ? sanitize_title($color_entry['slug']) : '';
                    if ($raw_slug === '') {
                        continue;
                    }
                    $color_surcharges[$raw_slug] = isset($color_entry['surcharge']) ? floatval($color_entry['surcharge']) : 0.0;
                }
            }

            foreach ($type_meta['colors'] as $color_slug => $color_meta) {
                $color_order[] = $color_slug;
                $default_hex = isset($color_meta['hex']) ? $color_meta['hex'] : '';
                $color_settings = self::get_color_settings($settings, $type_slug, $color_slug, $default_hex);
                $swatch = isset($color_settings['swatch']) ? $color_settings['swatch'] : '';
                if ($swatch === '' && $default_hex !== '') {
                    $candidate = sanitize_hex_color($default_hex);
                    if ($candidate) {
                        $swatch = $candidate;
                    }
                }
                $colors_payload[$color_slug] = array(
                    'label' => $color_meta['label'],
                    'swatch' => $swatch,
                    'sizes' => self::sizes_for_color($type_meta, $color_slug),
                    'surcharge' => isset($color_surcharges[$color_slug]) ? $color_surcharges[$color_slug] : 0.0,
                );
            }

            $type_price = isset($raw_type['price']) ? floatval($raw_type['price']) : 0.0;
            $size_surcharges = array();
            if (!empty($raw_type['size_surcharges']) && is_array($raw_type['size_surcharges'])) {
                foreach ($raw_type['size_surcharges'] as $size_label => $surcharge) {
                    $size_key = sanitize_text_field($size_label);
                    if ($size_key === '') {
                        continue;
                    }
                    $size_surcharges[$size_key] = floatval($surcharge);
                }
            }

            // Generate preview URL for type selector
            $preview_url = '';
            if ($sku && !empty($color_order)) {
                $preview_color = reset($color_order);
                $uploads = wp_upload_dir();
                $base_url = isset($uploads['baseurl']) ? trailingslashit($uploads['baseurl']) . 'mg_mockups' : '';
                if ($base_url !== '') {
                    $filename = $sku . '_' . $type_slug . '_' . $preview_color . '_front.webp';
                    $preview_url = $base_url . '/' . $sku . '/' . $filename;
                }
            }

            $types_payload[$type_slug] = array(
                'label' => $type_meta['label'],
                'color_order' => $color_order,
                'colors' => $colors_payload,
                'size_order' => isset($type_meta['sizes']) ? $type_meta['sizes'] : array(),
                'size_chart' => $size_chart,
                'size_chart_models' => $size_chart_models,
                'description' => $type_description,
                'price' => $type_price,
                'size_surcharges' => $size_surcharges,
                'preview_url' => $preview_url,
            );
        }

        if (empty($types_payload)) {
            self::$config_cache[$product_id] = array();
            return array();
        }

        $description_targets = apply_filters(
            'mg_variant_display_description_targets',
            array(
                '.woocommerce-product-details__short-description',
                '#tab-description',
                '.woocommerce-Tabs-panel--description',
            ),
            $product
        );

        if (!is_array($description_targets)) {
            $description_targets = array();
        }
        $description_targets = array_values(array_filter(array_unique(array_map('strval', $description_targets))));

        $type_urls = apply_filters('mg_virtual_variant_type_urls', array(), $product, $types_payload);
        $type_urls = is_array($type_urls) ? $type_urls : array();
        $meta_urls = get_post_meta($product->get_id(), '_mg_type_urls', true);
        if (is_array($meta_urls)) {
            $type_urls = array_merge($type_urls, $meta_urls);
        }

        $default_type = apply_filters('mg_virtual_variant_default_type', '', $product, $types_payload);
        if ($default_type === '' && !empty($type_order)) {
            $default_type = reset($type_order);
        }
        if ($default_type === '' && !empty($types_payload)) {
            $type_keys = array_keys($types_payload);
            $default_type = $type_keys ? reset($type_keys) : '';
        }
        $default_color = apply_filters('mg_virtual_variant_default_color', '', $product, $default_type, $types_payload);
        if ($default_color === '' && $default_type !== '' && !empty($types_payload[$default_type]['color_order'])) {
            $default_color = reset($types_payload[$default_type]['color_order']);
        }
        if ($default_color === '' && $default_type !== '' && !empty($types_payload[$default_type]['colors'])) {
            $color_keys = array_keys($types_payload[$default_type]['colors']);
            $default_color = $color_keys ? reset($color_keys) : '';
        }
        $default_size = apply_filters('mg_virtual_variant_default_size', '', $product, $default_type, $default_color, $types_payload);
        if ($default_size === '' && $default_type !== '' && $default_color !== '' && !empty($types_payload[$default_type]['colors'][$default_color]['sizes'])) {
            $default_size = reset($types_payload[$default_type]['colors'][$default_color]['sizes']);
        }

        $total_colors = 0;
        foreach ($types_payload as $type_meta) {
            if (!empty($type_meta['colors']) && is_array($type_meta['colors'])) {
                $total_colors += count($type_meta['colors']);
            }
        }

        $type_mockups_default = $total_colors < 80;
        $type_mockups_enabled = (bool) apply_filters('mg_virtual_variant_type_mockups_enabled', $type_mockups_default, $product, $types_payload, $total_colors);
        $type_mockups = $type_mockups_enabled ? self::get_type_mockups($product->get_id(), $types_payload) : array();
        $visuals = array(
            'defaults' => array(
                'pattern' => self::resolve_design_url($product->get_id()),
            ),
            'typeMockups' => $type_mockups,
        );

        $default_cache_limit = 60;
        if ($total_colors >= 100) {
            $default_cache_limit = 0;
        } elseif ($total_colors >= 80) {
            $default_cache_limit = 20;
        } elseif ($total_colors >= 40) {
            $default_cache_limit = 30;
        }
        $preview_cache_limit = absint(apply_filters('mg_virtual_variant_preview_cache_limit', $default_cache_limit, $product, $types_payload, $total_colors));
        $preview_preload_default = $total_colors < 80;
        $preview_preload = (bool) apply_filters('mg_virtual_variant_preview_preload', $preview_preload_default, $product, $types_payload, $total_colors);

        $config = array(
            'product' => $product_config,
            'mockup' => $mockup_config,
            'types' => $types_payload,
            'order' => array(
                'types' => $type_order,
            ),
            'typeUrls' => $type_urls,
            'preview_cache_limit' => $preview_cache_limit,
            'preview_preload' => $preview_preload,
            'priceFormat' => array(
                'currencySymbol' => function_exists('get_woocommerce_currency_symbol') ? get_woocommerce_currency_symbol() : '',
                'priceFormat' => function_exists('get_woocommerce_price_format') ? get_woocommerce_price_format() : '%1$s%2$s',
                'decimalSeparator' => function_exists('wc_get_price_decimal_separator') ? wc_get_price_decimal_separator() : '.',
                'thousandSeparator' => function_exists('wc_get_price_thousand_separator') ? wc_get_price_thousand_separator() : ',',
                'decimals' => function_exists('wc_get_price_decimals') ? wc_get_price_decimals() : 2,
            ),
            'text' => array(
                'type' => __('Terméktípus:', 'mgvd'),
                'typePrompt' => __('Válassz terméket:', 'mgvd'),
                'color' => __('Szín', 'mgvd'),
                'size' => __('Méret', 'mgvd'),
                'typePlaceholder' => __('Válassz terméktípust', 'mgvd'),
                'typeModalTitle' => __('Válaszd ki a terméktípust', 'mgvd'),
                'typeModalClose' => __('Bezárás', 'mgvd'),
                'chooseTypeFirst' => __('Először válassz terméktípust.', 'mgvd'),
                'chooseColorFirst' => __('Először válassz színt.', 'mgvd'),
                'noColors' => __('Ehhez a terméktípushoz nincs elérhető szín.', 'mgvd'),
                'noSizes' => __('Ehhez a kombinációhoz nincs elérhető méret.', 'mgvd'),
                'sizeChartLink' => __('📏 Mérettáblázat megnyitása', 'mgvd'),
                'sizeChartTitle' => __('Mérettáblázat', 'mgvd'),
                'sizeChartModelsTitle' => __('Modelleken', 'mgvd'),
                'sizeChartModelsLink' => __('Nézd meg modelleken', 'mgvd'),
                'sizeChartBack' => __('Vissza a mérettáblázatra', 'mgvd'),
                'sizeChartClose' => __('Bezárás', 'mgvd'),
                'previewButton' => __('Minta nagyban', 'mgvd'),
                'previewClose' => __('Bezárás', 'mgvd'),
                'previewUnavailable' => __('Ehhez a variációhoz nem érhető el minta.', 'mgvd'),
                'previewNoColor' => __('Ehhez a variációhoz nem található háttérszín.', 'mgvd'),
                'previewWatermark' => __('www.forme.hu', 'mgvd'),
                'chooseSizeFirst' => __('Válassz méretet.', 'mgvd'),
                'inStock' => __('Raktáron', 'mgvd'),
                'outOfStock' => __('Nincs raktáron', 'mgvd'),
            ),
            'default' => array(
                'type' => $default_type,
                'color' => $default_color,
                'size' => $default_size,
            ),
            'descriptionTargets' => $description_targets,
            'visuals' => $visuals,
        );

        self::$config_cache[$product_id] = $config;
        return $config;
    }

    public static function get_default_selection($product) {
        if (!$product instanceof WC_Product) {
            return array();
        }
        $config = self::get_frontend_config($product);
        if (empty($config['default']) || !is_array($config['default'])) {
            return array();
        }
        return array(
            'type' => sanitize_title($config['default']['type'] ?? ''),
            'color' => sanitize_title($config['default']['color'] ?? ''),
            'size' => sanitize_text_field($config['default']['size'] ?? ''),
        );
    }

    protected static function get_design_id($product) {
        $product_id = $product ? $product->get_id() : 0;
        return apply_filters('mg_virtual_variant_design_id', $product_id, $product);
    }

    protected static function get_render_version($product) {
        $default_version = 'v4';
        $version = apply_filters('mg_virtual_variant_render_version', $default_version, $product);
        $version = sanitize_title($version);
        return $version !== '' ? $version : $default_version;
    }

    protected static function get_render_base_dir() {
        $uploads = function_exists('wp_get_upload_dir') ? wp_get_upload_dir() : wp_upload_dir();
        $base_dir = isset($uploads['basedir']) ? wp_normalize_path($uploads['basedir']) : '';
        if ($base_dir === '') {
            return '';
        }
        return wp_normalize_path(trailingslashit($base_dir) . 'mockup-renders');
    }

    protected static function get_render_base_url() {
        $uploads = function_exists('wp_get_upload_dir') ? wp_get_upload_dir() : wp_upload_dir();
        $base_url = isset($uploads['baseurl']) ? rtrim($uploads['baseurl'], '/') : '';
        if ($base_url === '') {
            return '';
        }
        return trailingslashit($base_url) . 'mockup-renders';
    }

    protected static function format_design_folder($design_id) {
        $design_id = absint($design_id);
        if ($design_id <= 0) {
            return '';
        }
        return 'd' . sprintf('%03d', $design_id);
    }

    protected static function build_render_path($render_version, $design_id, $type_slug, $color_slug) {
        $base_dir = self::get_render_base_dir();
        if ($base_dir === '') {
            return '';
        }
        $render_version = sanitize_title($render_version);
        $design_folder = self::format_design_folder($design_id);
        $type_slug = sanitize_title($type_slug);
        $color_slug = sanitize_title($color_slug);
        if ($render_version === '' || $design_folder === '' || $type_slug === '' || $color_slug === '') {
            return '';
        }
        return wp_normalize_path(trailingslashit($base_dir) . $render_version . '/' . $design_folder . '/' . $type_slug . '/' . $color_slug . '.webp');
    }

    protected static function build_render_url($render_version, $design_id, $type_slug, $color_slug) {
        $base_url = self::get_render_base_url();
        if ($base_url === '') {
            return '';
        }
        $render_version = sanitize_title($render_version);
        $design_folder = self::format_design_folder($design_id);
        $type_slug = sanitize_title($type_slug);
        $color_slug = sanitize_title($color_slug);
        if ($render_version === '' || $design_folder === '' || $type_slug === '' || $color_slug === '') {
            return '';
        }
        $path = $render_version . '/' . $design_folder . '/' . $type_slug . '/' . $color_slug . '.webp';
        return esc_url_raw(trailingslashit($base_url) . $path);
    }

    protected static function persist_render_file($source_path, $destination_path) {
        if ($source_path === '' || $destination_path === '') {
            return false;
        }
        $directory = wp_normalize_path(dirname($destination_path));
        if (!wp_mkdir_p($directory)) {
            return false;
        }
        if (file_exists($destination_path)) {
            return true;
        }
        if (@rename($source_path, $destination_path)) {
            return true;
        }
        if (@copy($source_path, $destination_path)) {
            @unlink($source_path);
            return true;
        }
        return false;
    }

    protected static function get_settings($catalog) {
        $raw = get_option('mg_variant_display', array());
        if (!is_array($catalog)) {
            $catalog = array();
        }
        $sanitized = MG_Variant_Display_Manager::sanitize_settings_block($raw, $catalog);
        return wp_parse_args($sanitized, array(
            'colors' => array(),
            'size_charts' => array(),
            'size_chart_models' => array(),
        ));
    }

    protected static function get_color_settings($settings, $type_slug, $color_slug, $fallback_hex = '') {
        if (!empty($settings['colors'][$type_slug][$color_slug]) && is_array($settings['colors'][$type_slug][$color_slug])) {
            $entry = $settings['colors'][$type_slug][$color_slug];
            if (!empty($entry['swatch'])) {
                return $entry;
            }
        }

        $fallback_hex = sanitize_hex_color($fallback_hex);
        if ($fallback_hex) {
            return array('swatch' => $fallback_hex);
        }

        return array();
    }

    protected static function sizes_for_color($type_meta, $color_slug) {
        $sizes = isset($type_meta['sizes']) ? $type_meta['sizes'] : array();
        if (empty($sizes) || !is_array($sizes)) {
            return array();
        }

        $matrix = isset($type_meta['matrix']) && is_array($type_meta['matrix']) ? $type_meta['matrix'] : array();
        if (empty($matrix)) {
            return $sizes;
        }

        $allowed = array();
        foreach ($sizes as $size_label) {
            if (!isset($matrix[$size_label]) || !is_array($matrix[$size_label])) {
                continue;
            }
            if (in_array($color_slug, $matrix[$size_label], true)) {
                $allowed[] = $size_label;
            }
        }

        return $allowed;
    }

    protected static function resolve_design_url($post_id) {
        $post_id = absint($post_id);
        if ($post_id <= 0) {
            return '';
        }

        $attachment_id = (int) get_post_meta($post_id, '_mg_last_design_attachment', true);
        if ($attachment_id > 0 && function_exists('wp_get_attachment_url')) {
            $attachment_url = wp_get_attachment_url($attachment_id);
            if ($attachment_url) {
                return esc_url_raw($attachment_url);
            }
        }

        $design_path = get_post_meta($post_id, '_mg_last_design_path', true);
        $design_path = is_string($design_path) ? wp_normalize_path($design_path) : '';
        if ($design_path !== '' && file_exists($design_path)) {
            $uploads = function_exists('wp_upload_dir') ? wp_upload_dir() : array();
            $base_dir = isset($uploads['basedir']) ? wp_normalize_path($uploads['basedir']) : '';
            $base_url = isset($uploads['baseurl']) ? $uploads['baseurl'] : '';
            if ($base_dir !== '' && $base_url !== '' && strpos($design_path, $base_dir) === 0) {
                $relative = ltrim(str_replace($base_dir, '', $design_path), '/');
                $relative = str_replace('\\', '/', $relative);
                $url = trailingslashit($base_url) . $relative;
                return esc_url_raw($url);
            }
        }

        return '';
    }

    protected static function get_type_mockups($product_id, $types_payload) {
        $mockups = array();
        if (empty($types_payload)) {
            return $mockups;
        }
        
        $product = wc_get_product($product_id);
        if (!$product) {
            return $mockups;
        }
        
        $sku = $product->get_sku();
        if (!$sku) {
            return $mockups;
        }
        
        $uploads = wp_upload_dir();
        $base_url = isset($uploads['baseurl']) ? trailingslashit($uploads['baseurl']) . 'mg_mockups' : '';
        if ($base_url === '') {
            return $mockups;
        }

        foreach ($types_payload as $type_slug => $type_meta) {
            $color_order = isset($type_meta['color_order']) ? $type_meta['color_order'] : array();
            $fallback_color = $color_order ? reset($color_order) : '';
            if ($fallback_color === '' && !empty($type_meta['colors']) && is_array($type_meta['colors'])) {
                $color_keys = array_keys($type_meta['colors']);
                $fallback_color = $color_keys ? reset($color_keys) : '';
            }
            $color_slug = $fallback_color;
            if ($color_slug === '') {
                continue;
            }
            
            // Pattern: {baseUrl}/{SKU}/{SKU}_{TYPE}_{COLOR}_front.webp
            $filename = $sku . '_' . $type_slug . '_' . $color_slug . '_front.webp';
            $url = $base_url . '/' . $sku . '/' . $filename;
            
            $mockups[$type_slug] = $url;
        }
        return $mockups;
    }

    protected static function resolve_preview_url_from_entry($entry) {
        if (!is_array($entry)) {
            return '';
        }
        $source = isset($entry['source']) && is_array($entry['source']) ? $entry['source'] : array();
        if (!empty($source['attachment_ids']) && is_array($source['attachment_ids'])) {
            foreach ($source['attachment_ids'] as $attachment_id) {
                $attachment_id = absint($attachment_id);
                if ($attachment_id <= 0) {
                    continue;
                }
                $url = wp_get_attachment_image_url($attachment_id, 'large');
                if ($url) {
                    return $url;
                }
            }
        }

        $images = array();
        if (!empty($source['images']) && is_array($source['images'])) {
            $images = $source['images'];
        } elseif (!empty($source['last_generated_files']) && is_array($source['last_generated_files'])) {
            $images = $source['last_generated_files'];
        }
        foreach ($images as $path) {
            if (!is_string($path) || $path === '') {
                continue;
            }
            $url = self::convert_path_to_url($path);
            if ($url !== '') {
                return $url;
            }
        }

        return '';
    }

    protected static function convert_path_to_url($path) {
        $uploads = function_exists('wp_get_upload_dir') ? wp_get_upload_dir() : wp_upload_dir();
        if (empty($uploads['basedir']) || empty($uploads['baseurl'])) {
            return '';
        }
        $normalized_base = wp_normalize_path($uploads['basedir']);
        $normalized_path = wp_normalize_path($path);
        if (strpos($normalized_path, $normalized_base) !== 0) {
            return '';
        }
        $relative = ltrim(str_replace($normalized_base, '', $normalized_path), '/');
        return trailingslashit($uploads['baseurl']) . str_replace('\\', '/', $relative);
    }

    public static function validate_add_to_cart($passed, $product_id, $quantity, $variation_id = 0, $variations = array()) {
        if (!$passed) {
            return $passed;
        }
        $product = wc_get_product($product_id);
        if (!self::is_supported_product($product)) {
            return $passed;
        }

        $type_slug = sanitize_title($_POST['mg_product_type'] ?? '');
        $color_slug = sanitize_title($_POST['mg_color'] ?? '');
        $size_value = sanitize_text_field($_POST['mg_size'] ?? '');

        if ($type_slug === '' || $color_slug === '' || $size_value === '') {
            wc_add_notice(__('Kérjük válassz terméktípust, színt és méretet.', 'mgdtp'), 'error');
            return false;
        }

        $catalog = class_exists('MG_Variant_Display_Manager') ? MG_Variant_Display_Manager::get_catalog_index() : array();
        if (empty($catalog[$type_slug]) || !is_array($catalog[$type_slug])) {
            self::log_error('Ismeretlen terméktípus a kosárba tételnél.', array('type' => $type_slug, 'product_id' => $product_id));
            wc_add_notice(__('A kiválasztott terméktípus nem elérhető.', 'mgdtp'), 'error');
            return false;
        }

        $type_meta = $catalog[$type_slug];
        if (empty($type_meta['colors'][$color_slug])) {
            self::log_error('Érvénytelen szín a kosárba tételnél.', array('type' => $type_slug, 'color' => $color_slug, 'product_id' => $product_id));
            wc_add_notice(__('A kiválasztott szín nem elérhető.', 'mgdtp'), 'error');
            return false;
        }

        $allowed_sizes = self::sizes_for_color($type_meta, $color_slug);
        if (!empty($allowed_sizes) && !in_array($size_value, $allowed_sizes, true)) {
            self::log_error('Érvénytelen méret a kosárba tételnél.', array('type' => $type_slug, 'color' => $color_slug, 'size' => $size_value, 'product_id' => $product_id));
            wc_add_notice(__('A kiválasztott méret nem elérhető.', 'mgdtp'), 'error');
            return false;
        }

        $preview_url = isset($_POST['mg_preview_url']) ? esc_url_raw(wp_unslash($_POST['mg_preview_url'])) : '';
        if ($preview_url === '') {
            $cache_key = $product_id . '|' . $type_slug . '|' . $color_slug;
            $preview = self::get_or_generate_preview_url($product_id, $type_slug, $color_slug);
            if (is_wp_error($preview)) {
                self::log_error('Nem sikerült a mockup előnézet.', array(
                    'product_id' => $product_id,
                    'type' => $type_slug,
                    'color' => $color_slug,
                    'error' => $preview->get_error_message(),
                ));
                wc_add_notice(__('Nem sikerült előnézetet generálni, kérjük próbáld újra.', 'mgdtp'), 'error');
                return false;
            }
            self::$generated_preview_cache[$cache_key] = $preview;
        }

        return $passed;
    }

    public static function add_cart_item_data($cart_item_data, $product_id, $variation_id, $quantity) {
        $product = wc_get_product($product_id);
        if (!self::is_supported_product($product)) {
            return $cart_item_data;
        }

        $type_slug = sanitize_title($_POST['mg_product_type'] ?? '');
        $color_slug = sanitize_title($_POST['mg_color'] ?? '');
        $size_value = sanitize_text_field($_POST['mg_size'] ?? '');
        if ($type_slug === '' || $color_slug === '' || $size_value === '') {
            return $cart_item_data;
        }

        $preview_url = isset($_POST['mg_preview_url']) ? esc_url_raw(wp_unslash($_POST['mg_preview_url'])) : '';
        $cache_key = $product_id . '|' . $type_slug . '|' . $color_slug;
        if ($preview_url === '' && isset(self::$generated_preview_cache[$cache_key])) {
            $preview_url = self::$generated_preview_cache[$cache_key];
        }
        if ($preview_url === '') {
            $preview = self::get_or_generate_preview_url($product_id, $type_slug, $color_slug);
            if (!is_wp_error($preview)) {
                $preview_url = $preview;
            }
        }

        $design_id = absint($_POST['mg_design_id'] ?? 0);
        if (!$design_id) {
            $design_id = $product_id;
        }
        $render_version = sanitize_text_field($_POST['mg_render_version'] ?? '');

        $cart_item_data['mg_product_type'] = $type_slug;
        $cart_item_data['mg_color'] = $color_slug;
        $cart_item_data['mg_size'] = $size_value;
        $cart_item_data['mg_design_id'] = $design_id;
        $cart_item_data['mg_preview_url'] = $preview_url;
        $cart_item_data['mg_render_version'] = $render_version;

        $cart_item_data['unique_key'] = md5('mg_virtual|' . $product_id . '|' . $type_slug . '|' . $color_slug . '|' . $size_value . '|' . $preview_url);
        return $cart_item_data;
    }

    public static function restore_cart_item($cart_item, $values) {
        $fields = array('mg_product_type', 'mg_color', 'mg_size', 'mg_design_id', 'mg_preview_url', 'mg_render_version');
        foreach ($fields as $field) {
            if (isset($values[$field])) {
                $cart_item[$field] = $values[$field];
            }
        }
        return $cart_item;
    }

    public static function render_cart_item_data($item_data, $cart_item) {
        if (empty($cart_item['mg_product_type']) || empty($cart_item['mg_color']) || empty($cart_item['mg_size'])) {
            return $item_data;
        }

        $catalog = class_exists('MG_Variant_Display_Manager') ? MG_Variant_Display_Manager::get_catalog_index() : array();
        $type_slug = sanitize_title($cart_item['mg_product_type']);
        $color_slug = sanitize_title($cart_item['mg_color']);
        $size_value = sanitize_text_field($cart_item['mg_size']);

        $type_label = $type_slug;
        $color_label = $color_slug;
        if (isset($catalog[$type_slug]['label'])) {
            $type_label = $catalog[$type_slug]['label'];
        }
        if (isset($catalog[$type_slug]['colors'][$color_slug]['label'])) {
            $color_label = $catalog[$type_slug]['colors'][$color_slug]['label'];
        }

        $item_data[] = array(
            'name' => __('Terméktípus', 'mgdtp'),
            'value' => sanitize_text_field($type_label),
        );
        $item_data[] = array(
            'name' => __('Szín', 'mgdtp'),
            'value' => sanitize_text_field($color_label),
        );
        $item_data[] = array(
            'name' => __('Méret', 'mgdtp'),
            'value' => sanitize_text_field($size_value),
        );

        return $item_data;
    }

    public static function add_order_item_meta($item, $cart_item_key, $values, $order) {
        if (empty($values['mg_product_type']) || empty($values['mg_color']) || empty($values['mg_size'])) {
            return;
        }

        $type_slug = sanitize_text_field($values['mg_product_type']);
        $color_slug = sanitize_text_field($values['mg_color']);
        $size_value = sanitize_text_field($values['mg_size']);
        $design_id = isset($values['mg_design_id']) ? absint($values['mg_design_id']) : 0;
        $preview_url = isset($values['mg_preview_url']) ? esc_url_raw($values['mg_preview_url']) : '';
        $render_version = isset($values['mg_render_version']) ? sanitize_text_field($values['mg_render_version']) : '';

        $catalog = class_exists('MG_Variant_Display_Manager') ? MG_Variant_Display_Manager::get_catalog_index() : array();
        $type_label = $type_slug;
        $color_label = $color_slug;
        if (isset($catalog[$type_slug]['label'])) {
            $type_label = $catalog[$type_slug]['label'];
        }
        if (isset($catalog[$type_slug]['colors'][$color_slug]['label'])) {
            $color_label = $catalog[$type_slug]['colors'][$color_slug]['label'];
        }

        $item->add_meta_data(__('Terméktípus', 'mgdtp'), $type_label, true);
        $item->add_meta_data(__('Szín', 'mgdtp'), $color_label, true);
        $item->add_meta_data(__('Méret', 'mgdtp'), $size_value, true);
        $item->add_meta_data('mg_product_type', $type_slug, true);
        $item->add_meta_data('mg_color', $color_slug, true);
        $item->add_meta_data('mg_size', $size_value, true);
        $item->add_meta_data('product_type', $type_slug, true);
        $item->add_meta_data('color', $color_slug, true);
        $item->add_meta_data('size', $size_value, true);
        if ($design_id) {
            $item->add_meta_data('mg_design_id', $design_id, true);
        }
        if ($preview_url !== '') {
            $item->add_meta_data('mg_preview_url', $preview_url, true);
            $item->add_meta_data('preview_image_url', $preview_url, true);
        }
        if ($render_version !== '') {
            $item->add_meta_data('mg_render_version', $render_version, true);
            $item->add_meta_data('render_version', $render_version, true);
        }

        // Add design reference for download functionality
        $design_reference = self::capture_design_reference_for_order_item($item, $values);
        if (!empty($design_reference)) {
            $item->add_meta_data('_mg_print_design_reference', $design_reference, true);
        }
    }

    protected static function capture_design_reference_for_order_item($item, $values) {
        $product_id = isset($values['product_id']) ? absint($values['product_id']) : 0;
        $design_id = isset($values['mg_design_id']) ? absint($values['mg_design_id']) : 0;
        
        // Try to use design_id if available, otherwise fall back to product_id
        $source_id = $design_id > 0 ? $design_id : $product_id;
        
        // DEBUG: Log what we're checking
        error_log('MG Design Reference Debug - Product ID: ' . $product_id . ', Design ID: ' . $design_id . ', Source ID: ' . $source_id);
        
        if ($source_id <= 0) {
            error_log('MG Design Reference: Source ID is 0, returning empty');
            return array();
        }

        // Get design file information from product meta
        $design_path = get_post_meta($source_id, '_mg_last_design_path', true);
        $design_path = is_string($design_path) ? wp_normalize_path($design_path) : '';
        
        $design_attachment_id = absint(get_post_meta($source_id, '_mg_last_design_attachment', true));
        
        // DEBUG: Log what we found
        error_log('MG Design Reference: Path = ' . ($design_path ? $design_path : 'EMPTY') . ', Attachment ID = ' . $design_attachment_id);
        
        // If no path or attachment, return empty
        if ($design_path === '' && $design_attachment_id <= 0) {
            error_log('MG Design Reference: No design path or attachment found, returning empty');
            return array();
        }

        // Resolve design URL
        $design_url = '';
        if ($design_attachment_id > 0 && function_exists('wp_get_attachment_url')) {
            $design_url = wp_get_attachment_url($design_attachment_id);
        }
        
        if ($design_url === '' && $design_path !== '' && file_exists($design_path)) {
            $uploads = function_exists('wp_upload_dir') ? wp_upload_dir() : array();
            $base_dir = isset($uploads['basedir']) ? wp_normalize_path($uploads['basedir']) : '';
            $base_url = isset($uploads['baseurl']) ? $uploads['baseurl'] : '';
            if ($base_dir !== '' && $base_url !== '' && strpos($design_path, $base_dir) === 0) {
                $relative = ltrim(str_replace($base_dir, '', $design_path), '/');
                $relative = str_replace('\\', '/', $relative);
                $design_url = trailingslashit($base_url) . $relative;
            }
        }

        // Get filename
        $filename = '';
        if ($design_path !== '') {
            $filename = function_exists('wp_basename') ? wp_basename($design_path) : basename($design_path);
        } elseif ($design_url !== '') {
            $url_path = wp_parse_url($design_url, PHP_URL_PATH);
            if (!empty($url_path)) {
                $filename = function_exists('wp_basename') ? wp_basename($url_path) : basename($url_path);
            }
        }

        $reference = array(
            'ordered_product_id'   => $product_id,
            'source_product_id'    => $source_id,
            'design_path'          => $design_path,
            'design_filename'      => $filename,
            'design_url'           => $design_url,
            'design_attachment_id' => $design_attachment_id,
            'captured_at'          => function_exists('current_time') ? current_time('mysql') : gmdate('Y-m-d H:i:s'),
        );
        
        error_log('MG Design Reference: Successfully captured, filename = ' . $filename);
        
        return $reference;
    }

    public static function apply_cart_pricing($cart) {
        if (is_admin() && !defined('DOING_AJAX')) {
            return;
        }
        if (!function_exists('mgsc_get_products')) {
            return;
        }
        $products = mgsc_get_products();
        foreach ($cart->get_cart() as $cart_item_key => $cart_item) {
            if (empty($cart_item['mg_product_type']) || empty($cart_item['mg_size'])) {
                continue;
            }
            $product = isset($cart_item['data']) ? $cart_item['data'] : null;
            if (!$product instanceof WC_Product) {
                continue;
            }
            $type_slug = sanitize_title($cart_item['mg_product_type']);
            if (empty($products[$type_slug])) {
                continue;
            }
            $base_price = isset($products[$type_slug]['price']) ? floatval($products[$type_slug]['price']) : 0.0;
            $size_extra = function_exists('mgsc_get_size_surcharge') ? floatval(mgsc_get_size_surcharge($type_slug, $cart_item['mg_size'])) : 0.0;
            $final_price = max(0, $base_price + $size_extra);
            $product->set_price($final_price);
            $cart->cart_contents[$cart_item_key]['mg_custom_fields_base_price'] = $final_price;
        }
    }

    public static function format_mini_cart_price($price, $cart_item, $cart_item_key) {
        if (function_exists('is_cart') && is_cart()) {
            return $price;
        }
        $final_price = self::get_virtual_variant_price($cart_item);
        if ($final_price === null) {
            return $price;
        }
        return wc_price($final_price);
    }

    public static function format_widget_cart_item_quantity($quantity, $cart_item, $cart_item_key) {
        if (function_exists('is_cart') && is_cart()) {
            return $quantity;
        }
        $final_price = self::get_virtual_variant_price($cart_item);
        if ($final_price === null) {
            return $quantity;
        }
        $product_quantity = isset($cart_item['quantity']) ? max(1, intval($cart_item['quantity'])) : 1;
        return sprintf('%s &times; %s', $product_quantity, wc_price($final_price));
    }

    private static function get_virtual_variant_price($cart_item) {
        if (empty($cart_item['mg_product_type']) || empty($cart_item['mg_size'])) {
            return null;
        }
        if (!function_exists('mgsc_get_products')) {
            return null;
        }
        $products = mgsc_get_products();
        $type_slug = sanitize_title($cart_item['mg_product_type']);
        if (empty($products[$type_slug])) {
            return null;
        }
        $base_price = isset($products[$type_slug]['price']) ? floatval($products[$type_slug]['price']) : 0.0;
        $size_extra = function_exists('mgsc_get_size_surcharge') ? floatval(mgsc_get_size_surcharge($type_slug, $cart_item['mg_size'])) : 0.0;
        $final_price = max(0, $base_price + $size_extra);
        $product = isset($cart_item['data']) ? $cart_item['data'] : null;
        if ($product instanceof WC_Product) {
            $final_price = wc_get_price_to_display($product, array('price' => $final_price));
        }
        return $final_price;
    }

    public static function filter_cart_thumbnail($thumbnail, $cart_item, $cart_item_key) {
        $preview_url = self::get_cart_item_preview_url($cart_item);
        if ($preview_url === '') {
            return $thumbnail;
        }

        if (is_array($thumbnail)) {
            return self::map_preview_image_array($thumbnail, $preview_url, $cart_item);
        }

        return self::render_preview_image_html($preview_url, $cart_item);
    }

    public static function filter_store_api_cart_item_images($images, $cart_item) {
        $preview_url = self::get_cart_item_preview_url($cart_item);
        if ($preview_url === '' || empty($images) || !is_array($images)) {
            return $images;
        }

        $images[0] = self::map_preview_image_array($images[0], $preview_url, $cart_item);
        return $images;
    }

    public static function filter_store_api_cart_item($cart_item_data, $cart_item) {
        $preview_url = self::get_cart_item_preview_url($cart_item);
        if ($preview_url === '') {
            return $cart_item_data;
        }

        $images = array();
        if (!empty($cart_item_data['images']) && is_array($cart_item_data['images'])) {
            $images = $cart_item_data['images'];
        }

        if (!empty($images)) {
            $images[0] = self::map_preview_image_array($images[0], $preview_url, $cart_item);
        } else {
            $images[] = self::build_store_api_image($preview_url, $cart_item);
        }

        $cart_item_data['images'] = $images;
        return $cart_item_data;
    }

    private static function get_cart_item_preview_url($cart_item) {
        if (!empty($cart_item['mg_preview_url'])) {
            $preview_url = esc_url($cart_item['mg_preview_url']);
            if ($preview_url !== '') {
                return $preview_url;
            }
        }

        if (empty($cart_item['product_id'])) {
            return '';
        }

        $product = wc_get_product($cart_item['product_id']);
        if (!$product) {
            return '';
        }

        $sku = $product->get_sku();
        if (!$sku || $sku === '') {
            return '';
        }

        $type_slug = sanitize_title($cart_item['mg_product_type'] ?? '');
        $color_slug = sanitize_title($cart_item['mg_color'] ?? '');
        if ($type_slug === '' || $color_slug === '') {
            return '';
        }

        $uploads = wp_upload_dir();
        $base_url = isset($uploads['baseurl']) ? trailingslashit($uploads['baseurl']) . 'mg_mockups' : '';
        if ($base_url === '') {
            return '';
        }

        $filename = $sku . '_' . $type_slug . '_' . $color_slug . '_front.webp';
        return $base_url . '/' . $sku . '/' . $filename;
    }

    private static function render_preview_image_html($preview_url, $cart_item) {
        $size = wc_get_image_size('woocommerce_thumbnail');
        $width = isset($size['width']) ? intval($size['width']) : 300;
        $height = isset($size['height']) ? intval($size['height']) : 300;
        $alt = self::get_cart_item_image_alt($cart_item);

        return sprintf(
            '<img src="%s" alt="%s" width="%d" height="%d" class="attachment-woocommerce_thumbnail size-woocommerce_thumbnail" />',
            esc_url($preview_url),
            esc_attr($alt),
            $width,
            $height
        );
    }

    private static function map_preview_image_array($image, $preview_url, $cart_item) {
        if (!is_array($image)) {
            $image = array();
        }

        $alt = self::get_cart_item_image_alt($cart_item);
        $image['src'] = esc_url($preview_url);
        $image['thumbnail'] = esc_url($preview_url);
        $image['srcset'] = '';
        $image['sizes'] = '';
        $image['alt'] = $alt;
        if (empty($image['name'])) {
            $image['name'] = $alt;
        }
        if (!isset($image['id'])) {
            $image['id'] = 0;
        }

        return $image;
    }

    private static function build_store_api_image($preview_url, $cart_item) {
        $alt = self::get_cart_item_image_alt($cart_item);
        return array(
            'id' => 0,
            'src' => esc_url($preview_url),
            'thumbnail' => esc_url($preview_url),
            'srcset' => '',
            'sizes' => '',
            'name' => $alt,
            'alt' => $alt,
        );
    }

    private static function get_cart_item_image_alt($cart_item) {
        if (!empty($cart_item['data']) && $cart_item['data'] instanceof WC_Product) {
            return $cart_item['data']->get_name();
        }
        if (!empty($cart_item['product_name'])) {
            return $cart_item['product_name'];
        }
        return __('Mockup előnézet', 'mgdtp');
    }

    public static function filter_order_thumbnail($thumbnail, $item, $order = null) {
        if (!is_a($item, 'WC_Order_Item_Product')) {
            return $thumbnail;
        }
        $preview_url = $item->get_meta('mg_preview_url');
        if (!$preview_url) {
            return $thumbnail;
        }
        $url = esc_url($preview_url);
        if ($url === '') {
            return $thumbnail;
        }
        $size = wc_get_image_size('woocommerce_thumbnail');
        $width = isset($size['width']) ? intval($size['width']) : 300;
        $height = isset($size['height']) ? intval($size['height']) : 300;
        return sprintf(
            '<img src="%s" alt="%s" width="%d" height="%d" class="attachment-woocommerce_thumbnail size-woocommerce_thumbnail" />',
            $url,
            esc_attr__('Mockup előnézet', 'mgdtp'),
            $width,
            $height
        );
    }

    public static function hide_order_item_meta($hidden) {
        $hidden = is_array($hidden) ? $hidden : array();
        $hidden[] = 'mg_product_type';
        $hidden[] = 'mg_color';
        $hidden[] = 'mg_size';
        $hidden[] = 'mg_design_id';
        $hidden[] = 'mg_preview_url';
        $hidden[] = 'mg_render_version';
        $hidden[] = 'preview_image_url';
        $hidden[] = 'render_version';
        $hidden[] = 'product_type';
        $hidden[] = 'color';
        $hidden[] = 'size';
        return array_unique($hidden);
    }

    public static function filter_order_item_meta_display($formatted_meta, $item) {
        if (is_admin() && !wp_doing_ajax()) {
            return $formatted_meta;
        }

        if (function_exists('is_order_received_page') && !is_order_received_page()) {
            return $formatted_meta;
        }

        $allowed_labels = array(
            __('Terméktípus', 'mgdtp'),
            __('Szín', 'mgdtp'),
            __('Méret', 'mgdtp'),
        );

        foreach ($formatted_meta as $meta_id => $meta) {
            if (empty($meta->display_key) || !in_array($meta->display_key, $allowed_labels, true)) {
                unset($formatted_meta[$meta_id]);
            }
        }

        return $formatted_meta;
    }

    public static function ajax_preview() {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');
        $product_id = absint($_POST['product_id'] ?? 0);
        $type_slug = sanitize_title($_POST['product_type'] ?? '');
        $color_slug = sanitize_title($_POST['color'] ?? '');
        if ($product_id <= 0 || $type_slug === '' || $color_slug === '') {
            wp_send_json_error(array('message' => __('Hiányzó adatok.', 'mgdtp')));
        }

        $preview = self::get_or_generate_preview_url($product_id, $type_slug, $color_slug);
        if (is_wp_error($preview)) {
            self::log_error('Mockup preview ajax hiba.', array(
                'product_id' => $product_id,
                'type' => $type_slug,
                'color' => $color_slug,
                'error' => $preview->get_error_message(),
            ));
            wp_send_json_error(array('message' => $preview->get_error_message()));
        }

        wp_send_json_success(array('preview_url' => esc_url_raw($preview)));
    }

    public static function get_or_generate_preview_path($product_id, $type_slug, $color_slug, $design_path = '') {
        $product_id = absint($product_id);
        $type_slug = sanitize_title($type_slug);
        $color_slug = sanitize_title($color_slug);
        if ($product_id <= 0 || $type_slug === '' || $color_slug === '') {
            return new WP_Error('invalid_request', __('Hiányzó adatok.', 'mgdtp'));
        }

        $product = wc_get_product($product_id);
        $render_version = self::get_render_version($product);
        $design_id = self::get_design_id($product);
        $render_path = self::build_render_path($render_version, $design_id, $type_slug, $color_slug);
        if ($render_path !== '' && file_exists($render_path)) {
            return $render_path;
        }
        return new WP_Error('preview_missing', __('Az előnézet nincs legenerálva ehhez a variációhoz.', 'mgdtp'));
    }

    protected static function get_or_generate_preview_url($product_id, $type_slug, $color_slug) {
        $product_id = absint($product_id);
        $type_slug = sanitize_title($type_slug);
        $color_slug = sanitize_title($color_slug);
        if ($product_id <= 0 || $type_slug === '' || $color_slug === '') {
            return new WP_Error('invalid_request', __('Hiányzó adatok.', 'mgdtp'));
        }

        $product = wc_get_product($product_id);
        if (!$product) {
            return new WP_Error('product_missing', __('A termék nem található.', 'mgdtp'));
        }

        // NEW: Pattern-based resolution
        $sku = $product->get_sku();
        if ($sku) {
            $uploads = wp_upload_dir();
            $base_dir = isset($uploads['basedir']) ? trailingslashit($uploads['basedir']) . 'mg_mockups' : '';
            $base_url = isset($uploads['baseurl']) ? trailingslashit($uploads['baseurl']) . 'mg_mockups' : '';
            
            if ($base_dir !== '' && $base_url !== '') {
                $filename = $sku . '_' . $type_slug . '_' . $color_slug . '_front.webp';
                $file_path = $base_dir . '/' . $sku . '/' . $filename;
                $file_url = $base_url . '/' . $sku . '/' . $filename;
                
                if (file_exists($file_path)) {
                    return $file_url;
                }
            }
        }

        // Fallback to old system (just in case)
        $render_version = self::get_render_version($product);
        $design_id = self::get_design_id($product);
        $render_path = self::build_render_path($render_version, $design_id, $type_slug, $color_slug);
        $render_url = self::build_render_url($render_version, $design_id, $type_slug, $color_slug);
        if ($render_path !== '' && $render_url !== '' && file_exists($render_path)) {
            return $render_url;
        }

        return new WP_Error('preview_missing', __('Az előnézet nincs legenerálva ehhez a variációhoz.', 'mgdtp'));
    }

    protected static function resolve_design_path($product_id) {
        $product_id = absint($product_id);
        if ($product_id <= 0) {
            return '';
        }
        $attachment_id = absint(get_post_meta($product_id, '_mg_last_design_attachment', true));
        if ($attachment_id > 0 && function_exists('get_attached_file')) {
            $file = get_attached_file($attachment_id);
            if ($file && file_exists($file)) {
                return wp_normalize_path($file);
            }
        }
        $design_path = get_post_meta($product_id, '_mg_last_design_path', true);
        $design_path = is_string($design_path) ? wp_normalize_path($design_path) : '';
        if ($design_path !== '' && file_exists($design_path)) {
            return $design_path;
        }
        return '';
    }

    protected static function log_error($message, $context = array()) {
        if (!function_exists('wc_get_logger')) {
            return;
        }
        $logger = wc_get_logger();
        $logger->error($message, array_merge(array('source' => 'mg_virtual_variants'), $context));
    }
}
