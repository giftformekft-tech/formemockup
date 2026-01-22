<?php
if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('MG_Global_Attributes')) {
    class MG_Global_Attributes {
        protected static $config = null;
        protected static $catalog = null;

        public static function load_config() {
            if (self::$config !== null) {
                return self::$config;
            }

            $path = dirname(__DIR__) . '/includes/config/global-attributes.php';
            if (!file_exists($path)) {
                self::$config = array();
                return self::$config;
            }

            $config = require $path;
            self::$config = is_array($config) ? $config : array();
            return self::$config;
        }

        public static function get_catalog() {
            if (self::$catalog !== null) {
                return self::$catalog;
            }

            $config = self::load_config();
            $products = isset($config['products']) && is_array($config['products']) ? $config['products'] : array();

            $defaults = array(
                'enabled' => true,
                'price' => 0,
                'sizes' => array(),
                'colors' => array(),
                'primary_color' => '',
                'primary_size' => '',
                'size_color_matrix' => array(),
                'size_surcharges' => array(),
                'type_description' => '',
            );

            $normalized = self::normalize_products($products, $defaults);
            self::$catalog = $normalized;
            return self::$catalog;
        }

        protected static function normalize_products($products, $defaults) {
            $out = array();

            if (!is_array($products)) {
                return $out;
            }

            foreach ($products as $product_key => $product) {
                if (!is_array($product)) {
                    continue;
                }

                $key = isset($product['key']) ? sanitize_title($product['key']) : '';
                if ($key === '' && is_string($product_key)) {
                    $key = sanitize_title($product_key);
                }
                if ($key === '') {
                    continue;
                }

                $entry = array_merge($defaults, $product);
                $entry['key'] = $key;
                $entry['label'] = isset($entry['label']) ? wp_kses_post($entry['label']) : $key;
                $entry['price'] = intval($entry['price']);
                $entry['sizes'] = self::normalize_sizes($entry['sizes']);
                $entry['colors'] = self::normalize_colors($entry['colors']);
                $entry['primary_color'] = sanitize_title($entry['primary_color']);
                $entry['primary_size'] = sanitize_text_field($entry['primary_size']);
                $entry['size_color_matrix'] = self::normalize_size_color_matrix(
                    $entry['sizes'],
                    $entry['colors'],
                    $entry['size_color_matrix']
                );
                $entry['size_surcharges'] = self::normalize_size_surcharges($entry['size_surcharges'], $entry['sizes']);
                $entry['type_description'] = isset($entry['type_description']) ? wp_kses_post($entry['type_description']) : '';

                if (!self::is_valid_primary_color($entry['primary_color'], $entry['colors'])) {
                    $entry['primary_color'] = '';
                }
                if ($entry['primary_size'] !== '' && !in_array($entry['primary_size'], $entry['sizes'], true)) {
                    $entry['primary_size'] = '';
                }

                $out[$key] = $entry;
            }

            return $out;
        }

        protected static function normalize_colors($colors) {
            $normalized = array();
            $seen = array();

            if (!is_array($colors)) {
                return $normalized;
            }

            foreach ($colors as $color) {
                if (is_string($color)) {
                    $color = array('name' => $color, 'slug' => $color);
                }
                if (!is_array($color)) {
                    continue;
                }

                $name = isset($color['name']) ? sanitize_text_field($color['name']) : '';
                $slug = isset($color['slug']) ? sanitize_title($color['slug']) : sanitize_title($name);
                if ($slug === '') {
                    continue;
                }
                if (isset($seen[$slug])) {
                    continue;
                }

                $entry = array(
                    'name' => $name !== '' ? $name : $slug,
                    'slug' => $slug,
                );
                if (!empty($color['hex'])) {
                    $hex = sanitize_hex_color($color['hex']);
                    if ($hex) {
                        $entry['hex'] = $hex;
                    }
                }
                if (isset($color['surcharge'])) {
                    $entry['surcharge'] = floatval($color['surcharge']);
                }

                $normalized[] = $entry;
                $seen[$slug] = true;
            }

            return $normalized;
        }

        protected static function normalize_sizes($sizes) {
            if (!is_array($sizes)) {
                return array();
            }

            $normalized = array();
            foreach ($sizes as $size) {
                if (!is_string($size)) {
                    continue;
                }
                $size = trim($size);
                if ($size === '' || in_array($size, $normalized, true)) {
                    continue;
                }
                $normalized[] = $size;
            }

            return array_values($normalized);
        }

        protected static function normalize_size_color_matrix($sizes, $colors, $matrix) {
            $size_values = self::normalize_sizes($sizes);
            $color_slugs = array();
            foreach ($colors as $color) {
                if (is_array($color) && !empty($color['slug'])) {
                    $slug = sanitize_title($color['slug']);
                    if ($slug !== '' && !in_array($slug, $color_slugs, true)) {
                        $color_slugs[] = $slug;
                    }
                }
            }

            $normalized = array();
            if (!is_array($matrix)) {
                return $normalized;
            }

            foreach ($matrix as $size_key => $color_list) {
                if (!is_string($size_key)) {
                    continue;
                }
                $size_key = trim($size_key);
                if ($size_key === '' || !in_array($size_key, $size_values, true)) {
                    continue;
                }
                $clean = array();
                if (is_array($color_list)) {
                    foreach ($color_list as $slug) {
                        $slug = sanitize_title($slug);
                        if ($slug === '' || !in_array($slug, $color_slugs, true)) {
                            continue;
                        }
                        if (!in_array($slug, $clean, true)) {
                            $clean[] = $slug;
                        }
                    }
                }
                $normalized[$size_key] = $clean;
            }

            return $normalized;
        }

        protected static function normalize_size_surcharges($surcharges, $sizes) {
            if (!is_array($surcharges) || empty($sizes)) {
                return array();
            }

            $normalized = array();
            foreach ($surcharges as $size_label => $value) {
                $size_label = sanitize_text_field($size_label);
                if ($size_label === '' || !in_array($size_label, $sizes, true)) {
                    continue;
                }
                $normalized[$size_label] = floatval($value);
            }

            return $normalized;
        }

        protected static function is_valid_primary_color($primary_color, $colors) {
            if ($primary_color === '') {
                return true;
            }
            foreach ($colors as $color) {
                if (!empty($color['slug']) && $color['slug'] === $primary_color) {
                    return true;
                }
            }
            return false;
        }
    }
}

function mg_get_global_catalog() {
    return MG_Global_Attributes::get_catalog();
}

function mg_build_global_catalog_from_products($products_raw) {
    $products_raw = is_array($products_raw) ? $products_raw : array();
    $products = array();

    foreach ($products_raw as $index => $product) {
        if (!is_array($product)) {
            continue;
        }
        $key = isset($product['key']) ? sanitize_title($product['key']) : '';
        if ($key === '' && is_string($index)) {
            $key = sanitize_title($index);
        }
        if ($key === '') {
            continue;
        }

        $colors = array();
        if (!empty($product['colors']) && is_array($product['colors'])) {
            foreach ($product['colors'] as $color) {
                if (is_string($color)) {
                    $color = array('slug' => $color, 'name' => $color);
                }
                if (!is_array($color)) {
                    continue;
                }
                $slug = isset($color['slug']) ? sanitize_title($color['slug']) : '';
                if ($slug === '' && isset($color['name'])) {
                    $slug = sanitize_title($color['name']);
                }
                if ($slug === '') {
                    continue;
                }
                $colors[] = array(
                    'slug' => $slug,
                    'name' => isset($color['name']) ? sanitize_text_field($color['name']) : $slug,
                );
            }
        }

        $sizes = array();
        if (!empty($product['sizes']) && is_array($product['sizes'])) {
            foreach ($product['sizes'] as $size_value) {
                if (!is_string($size_value)) {
                    continue;
                }
                $size_value = trim($size_value);
                if ($size_value === '' || in_array($size_value, $sizes, true)) {
                    continue;
                }
                $sizes[] = $size_value;
            }
        }

        $matrix = array();
        if (!empty($product['size_color_matrix']) && is_array($product['size_color_matrix'])) {
            foreach ($product['size_color_matrix'] as $size_label => $color_list) {
                if (!is_string($size_label)) {
                    continue;
                }
                $size_key = sanitize_text_field($size_label);
                if ($size_key === '') {
                    continue;
                }
                $matrix[$size_key] = array();
                if (is_array($color_list)) {
                    foreach ($color_list as $color_slug) {
                        $color_slug = sanitize_title($color_slug);
                        if ($color_slug === '') {
                            continue;
                        }
                        if (!in_array($color_slug, $matrix[$size_key], true)) {
                            $matrix[$size_key][] = $color_slug;
                        }
                    }
                }
            }
        }

        $products[] = array(
            'key' => $key,
            'label' => isset($product['label']) ? wp_strip_all_tags($product['label']) : $key,
            'price' => isset($product['price']) ? intval($product['price']) : 0,
            'sizes' => $sizes,
            'colors' => $colors,
            'primary_color' => isset($product['primary_color']) ? sanitize_title($product['primary_color']) : '',
            'primary_size' => isset($product['primary_size']) ? sanitize_text_field($product['primary_size']) : '',
            'size_color_matrix' => $matrix,
            'size_surcharges' => array(),
            'type_description' => isset($product['type_description']) ? wp_kses_post($product['type_description']) : '',
            'enabled' => true,
        );
    }

    return array(
        'products' => $products,
    );
}

function mg_update_global_catalog_from_products($products_raw) {
    $path = dirname(__DIR__) . '/includes/config/global-attributes.php';
    $data = mg_build_global_catalog_from_products($products_raw);
    $export = var_export($data, true);
    $contents = "<?php\n";
    $contents .= "/**\n";
    $contents .= " * Global attributes source of truth.\n";
    $contents .= " *\n";
    $contents .= " * IMPORTANT:\n";
    $contents .= " * - Keep this data in sync with your configured product types, colors, and sizes.\n";
    $contents .= " * - This file is the only source for global attributes (no DB storage).\n";
    $contents .= " */\n\n";
    $contents .= "return " . $export . ";\n";

    $dir = dirname($path);
    if (!is_dir($dir) || !is_writable($dir) || (file_exists($path) && !is_writable($path))) {
        return new WP_Error('mg_global_catalog_not_writable', __('A global-attributes.php fájl nem írható. Állítsd be az írási jogosultságot.', 'mgstp'));
    }

    $result = file_put_contents($path, $contents);
    if ($result === false) {
        return new WP_Error('mg_global_catalog_write_failed', __('Nem sikerült menteni a global-attributes.php fájlt.', 'mgstp'));
    }

    return true;
}

function mg_global_catalog_enabled() {
    $catalog = mg_get_global_catalog();
    return !empty($catalog);
}
