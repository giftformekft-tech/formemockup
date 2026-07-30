<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Builds the canonical CSV consumed by the companion allegro-sync app.
 *
 * WooCommerce stores the selectable variants in the Mockup Generator's
 * virtual catalogue. Allegro, however, expects one offer per exact
 * type + colour + size combination. This class is the boundary between the
 * two models and deliberately refuses unknown dictionary values.
 */
class MG_Allegro_Exporter {

    const META_EXPORTED_TYPES = '_mg_allegro_exported_types';

    /** @return array<int,string> */
    public static function headers() {
        return array(
            'sku', 'parent_sku', 'name', 'description', 'type', 'type_label',
            'color', 'manufacturer_color', 'size', 'price_huf', 'stock',
            'image_url', 'weight_g', 'brand', 'material', 'ai_content',
            'category_id',
        );
    }

    /**
     * Allegro.hu parameter 249512 (Szín) values used in the clothing
     * categories. Keys include common Hungarian/English Woo slugs.
     *
     * @return array<string,string>
     */
    public static function default_color_map() {
        return array(
            'szintelen' => 'színtelen', 'transparent' => 'színtelen',
            'bezs' => 'bézs', 'beige' => 'bézs', 'krem' => 'bézs', 'cream' => 'bézs',
            'feher' => 'fehér', 'white' => 'fehér',
            'barna' => 'barna', 'brown' => 'barna',
            'fekete' => 'fekete', 'black' => 'fekete',
            'piros' => 'piros', 'red' => 'piros', 'bordo' => 'piros', 'burgundy' => 'piros',
            'lila' => 'lila', 'purple' => 'lila',
            'kek' => 'kék', 'blue' => 'kék', 'sotetkek' => 'kék', 'navy' => 'kék',
            'kiralykek' => 'kék', 'royal-blue' => 'kék', 'vilagoskek' => 'kék',
            'narancssarga' => 'narancssárga', 'narancs' => 'narancssárga', 'orange' => 'narancssárga',
            'rozsaszin' => 'rózsaszín', 'pink' => 'rózsaszín',
            'ezust' => 'ezüst', 'silver' => 'ezüst',
            'szurke' => 'szürke', 'grey' => 'szürke', 'gray' => 'szürke',
            'antracit' => 'szürke', 'sotetszurke' => 'szürke', 'vilagosszurke' => 'szürke',
            'sokszinu' => 'sokszínű', 'multicolor' => 'sokszínű', 'multi' => 'sokszínű',
            'zold' => 'zöld', 'green' => 'zöld', 'sotetzold' => 'zöld', 'vilagoszold' => 'zöld',
            'arany' => 'arany', 'gold' => 'arany',
            'sarga' => 'sárga', 'yellow' => 'sárga',
            'egyeb' => 'egyéb', 'other' => 'egyéb',
        );
    }

    /** @return array<int,string> */
    public static function allowed_colors() {
        return array(
            'színtelen', 'bézs', 'fehér', 'barna', 'fekete', 'piros', 'lila',
            'kék', 'narancssárga', 'rózsaszín', 'ezüst', 'szürke', 'sokszínű',
            'zöld', 'arany', 'sárga', 'egyéb',
        );
    }

    /** Exact category defaults for product-type slugs used by this plugin. */
    public static function default_category_map() {
        return array(
            'ferfi-polo' => '87913',
            'premium-ferfi-polo' => '87913',
            'noi-polo' => '76104',
            'premium-noi-polo' => '76104',
            'gyerek-polo' => '89528',
        );
    }

    /**
     * Canonical size aliases verified against parameter 54 (Méret) in the
     * Allegro.hu men's, women's and children's T-shirt categories.
     * Age ranges are intentionally not guessed because brands use different
     * height tables; those remain explicit admin mappings.
     *
     * @return array<string,string>
     */
    public static function default_size_map() {
        $map = array(
            '5xs' => '5XS', '4xs' => '4XS', '3xs' => '3XS', 'xxs' => 'XXS',
            'xs' => 'XS', 's' => 'S', 'm' => 'M', 'l' => 'L', 'xl' => 'XL',
            'xxl' => 'XXL', '2xl' => 'XXL', 'xxxl' => '3XL', '3xl' => '3XL',
            'xxxxl' => '4XL', '4xl' => '4XL', 'xxxxxl' => '5XL', '5xl' => '5XL',
            '6xl' => '6XL', '7xl' => '7XL', '8xl' => '8XL', '9xl' => '9XL',
            '10xl' => '10XL', '11xl' => '11XL', '12xl' => '12XL',
            'univerzalis' => 'univerzális', 'one-size' => 'one size', 'one size' => 'one size',
        );
        foreach (array('1', '2', '3', '4', '5', '6', '7', '8') as $value) {
            $map[$value] = $value;
        }
        foreach (array(
            '30', '32', '34', '36', '38', '40', '42', '44', '46', '48', '50',
            '52', '54', '56', '58', '60', '62', '64', '66', '68', '70', '72',
            '74', '76', '78', '80', '82', '86', '92', '98', '104', '110',
            '116', '122', '128', '134', '140', '146', '152', '158', '164',
            '170', '176', '182', '188',
        ) as $value) {
            $map[$value] = $value;
        }
        foreach (array(
            '50/56', '62/68', '68/74', '74/80', '80/86', '86-92', '92/98',
            '98/104', '104-110', '110/116', '116-122', '116/124', '122/128',
            '128-134', '134/140', '140-146', '146/152', '152/158', '158/164',
            '164/170', '170-176', '176-182',
        ) as $value) {
            $map[self::mapping_key($value)] = $value;
        }
        return $map;
    }

    public static function mapping_key($value) {
        $value = trim(wp_strip_all_tags((string) $value));
        if (function_exists('remove_accents')) {
            $value = remove_accents($value);
        }
        $value = strtolower($value);
        $value = preg_replace('/[^a-z0-9\/ -]+/', '', $value);
        return trim(preg_replace('/\s+/', ' ', $value));
    }

    public static function map_color($slug, $label, $custom_map = array()) {
        $keys = array_unique(array_filter(array(
            sanitize_title($slug), self::mapping_key($slug), self::mapping_key($label),
        )));
        $defaults = self::default_color_map();
        foreach ($keys as $key) {
            if (isset($custom_map[$key]) && in_array($custom_map[$key], self::allowed_colors(), true)) {
                return $custom_map[$key];
            }
        }
        foreach ($keys as $key) {
            if (isset($defaults[$key])) {
                return $defaults[$key];
            }
        }
        return '';
    }

    public static function map_size($type_slug, $size, $custom_map = array()) {
        $key = self::mapping_key($size);
        $type_slug = sanitize_title($type_slug);
        if (isset($custom_map[$type_slug][$key]) && trim($custom_map[$type_slug][$key]) !== '') {
            return sanitize_text_field($custom_map[$type_slug][$key]);
        }
        $defaults = self::default_size_map();
        return isset($defaults[$key]) ? $defaults[$key] : '';
    }

    public static function build_parent_sku($base_sku, $type_slug) {
        return self::sku_part($base_sku) . '-' . self::sku_part($type_slug);
    }

    public static function build_sku($base_sku, $type_slug, $color_slug, $size) {
        return implode('-', array(
            self::sku_part($base_sku), self::sku_part($type_slug),
            self::sku_part($color_slug), self::sku_part($size),
        ));
    }

    private static function sku_part($value) {
        $value = self::mapping_key($value);
        $value = strtoupper(str_replace(array('/', ' '), '-', $value));
        $value = preg_replace('/[^A-Z0-9_-]+/', '', $value);
        return trim($value, '-_');
    }

    /**
     * Returns all source dictionary values currently present in the virtual
     * catalogue, ready for the mapping settings UI.
     *
     * @return array{types:array<string,string>,colors:array<string,array>,sizes:array<string,array>}
     */
    public static function source_dictionary() {
        $result = array('types' => array(), 'colors' => array(), 'sizes' => array());
        if (!class_exists('MG_Variant_Display_Manager')) {
            return $result;
        }
        $catalog = MG_Variant_Display_Manager::get_catalog_index();
        foreach ((array) $catalog as $type_slug => $type_meta) {
            if (!is_array($type_meta)) {
                continue;
            }
            $type_slug = sanitize_title($type_slug);
            $result['types'][$type_slug] = !empty($type_meta['label']) ? wp_strip_all_tags($type_meta['label']) : $type_slug;
            $result['sizes'][$type_slug] = array_values(array_unique(array_map('strval', (array) ($type_meta['sizes'] ?? array()))));
            foreach ((array) ($type_meta['colors'] ?? array()) as $color_slug => $color_meta) {
                $label = is_array($color_meta) && !empty($color_meta['label']) ? $color_meta['label'] : $color_slug;
                $key = sanitize_title($color_slug);
                $result['colors'][$key] = array('slug' => $key, 'label' => wp_strip_all_tags($label));
            }
        }
        ksort($result['types']);
        ksort($result['colors']);
        return $result;
    }

    /**
     * @param array<string,mixed> $options
     * @return array{rows:array<int,array<string,mixed>>,errors:array<int,string>,product_types:array<int,array{pid:int,type:string}>}
     */
    public static function build_rows($type_filter = array(), $only_unexported = false, $options = array()) {
        $rows = array();
        $errors = array();
        $product_types = array();
        $color_map = isset($options['color_map']) && is_array($options['color_map']) ? $options['color_map'] : array();
        $size_map = isset($options['size_map']) && is_array($options['size_map']) ? $options['size_map'] : array();
        $category_map = isset($options['category_map']) && is_array($options['category_map']) ? $options['category_map'] : array();
        $default_stock = max(1, intval($options['default_stock'] ?? 10));
        $brand = sanitize_text_field($options['brand'] ?? 'márkanév nélkül');
        $material = sanitize_text_field($options['material'] ?? 'pamut');
        $brand = $brand !== '' ? $brand : 'márkanév nélkül';
        $material = $material !== '' ? $material : 'pamut';
        $type_filter = array_values(array_filter(array_map('sanitize_title', (array) $type_filter)));

        $products = wc_get_products(array('status' => 'publish', 'limit' => -1, 'return' => 'objects'));
        foreach ((array) $products as $product) {
            if (!$product || !$product->is_in_stock()) {
                continue;
            }
            $pid = (int) $product->get_id();
            $base_sku = trim((string) $product->get_sku());
            if ($base_sku === '') {
                $errors[] = sprintf('#%d %s: hiányzik a WooCommerce SKU.', $pid, $product->get_name());
                continue;
            }
            $config = MG_Virtual_Variant_Manager::get_frontend_config($product);
            if (empty($config['types'])) {
                continue;
            }
            $exported = self::get_exported_types($pid);
            foreach ($config['types'] as $type_slug => $type_meta) {
                $type_slug = sanitize_title($type_slug);
                if ($type_filter && !in_array($type_slug, $type_filter, true)) {
                    continue;
                }
                if ($only_unexported && isset($exported[$type_slug])) {
                    continue;
                }
                $type_label = wp_strip_all_tags($type_meta['label'] ?? $type_slug);
                $category_id = trim((string) ($category_map[$type_slug] ?? ''));
                if ($category_id !== '' && !preg_match('/^\d+$/', $category_id)) {
                    $errors[] = sprintf('%s: az Allegro kategóriaazonosító csak szám lehet.', $type_label);
                    continue;
                }
                $added_for_type = false;
                foreach ((array) ($type_meta['colors'] ?? array()) as $color_slug => $color_meta) {
                    $source_color = wp_strip_all_tags($color_meta['label'] ?? $color_slug);
                    $color = self::map_color($color_slug, $source_color, $color_map);
                    if ($color === '') {
                        $errors[] = sprintf('%s / %s: nincs Allegro főszín-leképezés.', $type_label, $source_color);
                        continue;
                    }
                    $sizes = (array) ($color_meta['sizes'] ?? array());
                    foreach ($sizes as $source_size) {
                        $size = self::map_size($type_slug, $source_size, $size_map);
                        if ($size === '') {
                            $errors[] = sprintf('%s / %s: nincs Allegro méretleképezés.', $type_label, $source_size);
                            continue;
                        }
                        $price = MG_Virtual_Variant_Manager::calculate_variant_price($type_slug, $source_size);
                        if ($price <= 0) {
                            $errors[] = sprintf('%s / %s: az ár nem pozitív.', $type_label, $source_size);
                            continue;
                        }
                        $image_url = MG_Virtual_Variant_Manager::build_sku_preview_url($product, $type_slug, $color_slug);
                        if ($image_url === '') {
                            $errors[] = sprintf('%s / %s / %s: hiányzik a front mockup kép.', $product->get_name(), $type_label, $source_color);
                            continue;
                        }
                        $description = (string) $product->get_description();
                        if ($description === '') {
                            $description = (string) $product->get_short_description();
                        }
                        if ($description === '') {
                            $errors[] = sprintf('#%d %s: hiányzik a termékleírás.', $pid, $product->get_name());
                            continue;
                        }
                        $stock = $product->managing_stock() ? intval($product->get_stock_quantity()) : $default_stock;
                        if ($stock <= 0) {
                            $stock = $default_stock;
                        }
                        $weight_g = 0;
                        if ($product->get_weight() !== '' && function_exists('wc_get_weight')) {
                            $weight_g = (int) round(wc_get_weight($product->get_weight(), 'g'));
                        }
                        $rows[] = array(
                            'sku' => self::build_sku($base_sku, $type_slug, $color_slug, $source_size),
                            'parent_sku' => self::build_parent_sku($base_sku, $type_slug),
                            'name' => wp_strip_all_tags($product->get_name()),
                            'description' => $description,
                            'type' => $type_slug,
                            'type_label' => $type_label,
                            'color' => $color,
                            'manufacturer_color' => $source_color,
                            'size' => $size,
                            'price_huf' => (string) round($price),
                            'stock' => (string) $stock,
                            'image_url' => $image_url,
                            'weight_g' => $weight_g ? (string) $weight_g : '',
                            'brand' => $brand,
                            'material' => $material,
                            'ai_content' => '0',
                            'category_id' => $category_id,
                        );
                        $added_for_type = true;
                    }
                }
                if ($added_for_type) {
                    $product_types[] = array('pid' => $pid, 'type' => $type_slug);
                }
            }
        }
        return array(
            'rows' => $rows,
            'errors' => array_values(array_unique($errors)),
            'product_types' => $product_types,
        );
    }

    public static function get_exported_types($product_id) {
        $value = get_post_meta((int) $product_id, self::META_EXPORTED_TYPES, true);
        return is_array($value) ? $value : array();
    }

    public static function mark_exported($product_types) {
        $by_product = array();
        foreach ((array) $product_types as $item) {
            $pid = intval($item['pid'] ?? 0);
            $type = sanitize_title($item['type'] ?? '');
            if ($pid && $type !== '') {
                $by_product[$pid][$type] = current_time('mysql');
            }
        }
        foreach ($by_product as $pid => $types) {
            update_post_meta($pid, self::META_EXPORTED_TYPES, array_merge(self::get_exported_types($pid), $types));
        }
    }
}
