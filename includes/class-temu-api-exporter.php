<?php
if (!defined('ABSPATH')) {
    exit;
}

/** Builds the dedicated CSV consumed only by the Temu V3 API application. */
class MG_Temu_API_Exporter {

    const META_EXPORTED_TYPES = '_mg_temu_api_exported_types';

    /** @return array<int,string> */
    public static function headers() {
        return array(
            'marketplace', 'sku', 'parent_sku', 'name', 'description',
            'type', 'type_label', 'color', 'manufacturer_color', 'size',
            'price_huf', 'stock', 'image_url', 'temu_common_image_url',
            'weight_g', 'brand', 'material', 'ai_content', 'temu_category_name',
        );
    }

    public static function category_name($type_slug) {
        $type_slug = sanitize_title($type_slug);
        if (in_array($type_slug, array('gyerek-polo', 'gyerekpolo', 'kids-t-shirt'), true)) {
            return "Kids' Clothing / T-Shirts";
        }
        if (in_array($type_slug, array('noi-polo', 'premium-noi-polo', 'women-t-shirt'), true)) {
            return "Women's Clothing / T-Shirts";
        }
        if (in_array($type_slug, array('ferfi-polo', 'premium-ferfi-polo', 'men-t-shirt'), true)) {
            return "Men's Clothing / T-Shirts";
        }
        return 'Clothing / T-Shirts';
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

    private static function sku_part($value) {
        $value = self::mapping_key($value);
        $value = strtoupper(str_replace(array('/', ' '), '-', $value));
        $value = preg_replace('/[^A-Z0-9_-]+/', '', $value);
        return trim($value, '-_');
    }

    public static function build_parent_sku($base_sku, $type_slug = '') {
        $parts = array('TEMU', self::sku_part($base_sku));
        if ($type_slug !== '') {
            $parts[] = self::sku_part($type_slug);
        }
        return implode('-', $parts);
    }

    public static function build_sku($base_sku, $type_slug, $color_slug, $size) {
        return implode('-', array(
            'TEMU', self::sku_part($base_sku), self::sku_part($type_slug),
            self::sku_part($color_slug), self::sku_part($size),
        ));
    }

    /**
     * @param array<string,mixed> $options
     * @param array<int,array<string,mixed>> $selection One row per product/type/color.
     * @return array{rows:array<int,array<string,mixed>>,errors:array<int,string>,product_types:array<int,array{pid:int,type:string}>}
     */
    public static function build_rows($options = array(), $selection = array()) {
        $rows = array();
        $errors = array();
        $product_types = array();
        $default_stock = max(1, intval($options['default_stock'] ?? 10));
        $default_weight = max(1, intval($options['default_weight_g'] ?? 180));
        $brand = sanitize_text_field($options['brand'] ?? 'márkanév nélkül');
        $material = sanitize_text_field($options['material'] ?? 'pamut');
        $selection_lookup = array();

        foreach ((array) $selection as $item) {
            if (!is_array($item)) {
                continue;
            }
            $pid = absint($item['pid'] ?? 0);
            $type = sanitize_title($item['type'] ?? '');
            $color = sanitize_title($item['color'] ?? '');
            if ($pid && $type !== '' && $color !== '') {
                $selection_lookup[$pid][$type][$color] = true;
            }
        }
        if (!$selection_lookup) {
            return array('rows' => array(), 'errors' => array('Nincs kiválasztott Temu API variánssor.'), 'product_types' => array());
        }

        foreach (array_keys($selection_lookup) as $pid) {
            $product = wc_get_product($pid);
            if (!$product || !$product->is_in_stock()) {
                continue;
            }
            $base_sku = trim((string) $product->get_sku());
            if ($base_sku === '') {
                $errors[] = sprintf('#%d %s: hiányzik a WooCommerce SKU.', $pid, $product->get_name());
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
            $common_image = class_exists('MG_Temu_API_Image_Field')
                ? MG_Temu_API_Image_Field::get_image_url($pid)
                : '';
            $weight_g = $default_weight;
            if ($product->get_weight() !== '' && function_exists('wc_get_weight')) {
                $converted = (int) round(wc_get_weight($product->get_weight(), 'g'));
                if ($converted > 0) {
                    $weight_g = $converted;
                }
            }
            $stock = $product->managing_stock() ? intval($product->get_stock_quantity()) : $default_stock;
            if ($stock <= 0) {
                $stock = $default_stock;
            }
            $config = MG_Virtual_Variant_Manager::get_frontend_config($product);
            foreach ((array) ($config['types'] ?? array()) as $type_slug => $type_meta) {
                $type_slug = sanitize_title($type_slug);
                if (empty($selection_lookup[$pid][$type_slug])) {
                    continue;
                }
                $type_label = wp_strip_all_tags($type_meta['label'] ?? $type_slug);
                $temu_name = trim(wp_strip_all_tags($product->get_name()) . ' ' . $type_label);
                $added_for_type = false;
                foreach ((array) ($type_meta['colors'] ?? array()) as $color_slug => $color_meta) {
                    $color_slug = sanitize_title($color_slug);
                    if (empty($selection_lookup[$pid][$type_slug][$color_slug])) {
                        continue;
                    }
                    $color_label = wp_strip_all_tags($color_meta['label'] ?? $color_slug);
                    $image_url = MG_Virtual_Variant_Manager::build_sku_preview_url($product, $type_slug, $color_slug);
                    if ($image_url === '') {
                        $errors[] = sprintf('%s / %s / %s: hiányzik a variánskép.', $product->get_name(), $type_label, $color_label);
                        continue;
                    }
                    foreach ((array) ($color_meta['sizes'] ?? array()) as $source_size) {
                        $price = MG_Virtual_Variant_Manager::calculate_variant_price($type_slug, $source_size);
                        if ($price <= 0) {
                            $errors[] = sprintf('%s / %s: az ár nem pozitív.', $type_label, $source_size);
                            continue;
                        }
                        $rows[] = array(
                            'marketplace' => 'temu_api_v3',
                            'sku' => self::build_sku($base_sku, $type_slug, $color_slug, $source_size),
                            'parent_sku' => self::build_parent_sku($base_sku, $type_slug),
                            'name' => $temu_name,
                            'description' => $description,
                            'type' => $type_label,
                            'type_label' => $type_label,
                            'color' => $color_label,
                            'manufacturer_color' => $color_label,
                            'size' => wp_strip_all_tags((string) $source_size),
                            'price_huf' => (string) round($price),
                            'stock' => (string) $stock,
                            'image_url' => $image_url,
                            'temu_common_image_url' => $common_image,
                            'weight_g' => (string) $weight_g,
                            'brand' => $brand !== '' ? $brand : 'márkanév nélkül',
                            'material' => $material !== '' ? $material : 'pamut',
                            'ai_content' => '0',
                            'temu_category_name' => self::category_name($type_slug),
                        );
                        $added_for_type = true;
                    }
                }
                if ($added_for_type) {
                    $product_types[] = array('pid' => (int) $pid, 'type' => $type_slug);
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
