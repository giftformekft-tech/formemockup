<?php
if (!defined('ABSPATH')) exit;

function mg_normalize_global_colors($colors) {
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
        $normalized[] = $entry;
        $seen[$slug] = true;
    }
    return $normalized;
}

function mg_normalize_global_sizes($sizes) {
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

function mg_normalize_global_size_color_matrix($sizes, $colors, $matrix) {
    $size_values = mg_normalize_global_sizes($sizes);
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

function mg_get_global_catalog() {
    $defaults = array(
        'enabled' => false,
        'price' => 0,
        'sizes' => array(),
        'colors' => array(),
        'primary_color' => '',
        'primary_size' => '',
        'size_color_matrix' => array(),
    );
    $raw = get_option('mg_global_catalog', array());
    if (!is_array($raw)) {
        $raw = array();
    }
    $catalog = array_merge($defaults, $raw);
    $catalog['enabled'] = !empty($catalog['enabled']);
    $catalog['price'] = intval($catalog['price']);
    $catalog['sizes'] = mg_normalize_global_sizes($catalog['sizes']);
    $catalog['colors'] = mg_normalize_global_colors($catalog['colors']);
    $catalog['primary_color'] = sanitize_title($catalog['primary_color']);
    $catalog['primary_size'] = sanitize_text_field($catalog['primary_size']);
    $catalog['size_color_matrix'] = mg_normalize_global_size_color_matrix(
        $catalog['sizes'],
        $catalog['colors'],
        $catalog['size_color_matrix']
    );
    if (!empty($catalog['primary_color'])) {
        $valid = false;
        foreach ($catalog['colors'] as $color) {
            if (!empty($color['slug']) && $color['slug'] === $catalog['primary_color']) {
                $valid = true;
                break;
            }
        }
        if (!$valid) {
            $catalog['primary_color'] = '';
        }
    }
    if ($catalog['primary_size'] !== '' && !in_array($catalog['primary_size'], $catalog['sizes'], true)) {
        $catalog['primary_size'] = '';
    }
    return $catalog;
}

function mg_global_catalog_enabled() {
    $catalog = mg_get_global_catalog();
    return !empty($catalog['enabled']);
}

function mg_apply_global_catalog_to_product($product, $catalog = null) {
    if (!is_array($product)) {
        return $product;
    }
    if ($catalog === null) {
        $catalog = mg_get_global_catalog();
    }
    if (empty($catalog['enabled'])) {
        return $product;
    }
    $product['price'] = intval($catalog['price']);
    $product['sizes'] = $catalog['sizes'];
    $product['colors'] = $catalog['colors'];
    $product['primary_color'] = $catalog['primary_color'];
    $product['primary_size'] = $catalog['primary_size'];
    $product['size_color_matrix'] = $catalog['size_color_matrix'];
    return $product;
}

function mg_get_catalog_products($products = null) {
    if ($products === null) {
        $products = get_option('mg_products', array());
    }
    if (!is_array($products)) {
        $products = array();
    }
    $catalog = mg_get_global_catalog();
    if (empty($catalog['enabled'])) {
        return $products;
    }
    $out = array();
    foreach ($products as $key => $product) {
        if (!is_array($product)) {
            continue;
        }
        $out[$key] = mg_apply_global_catalog_to_product($product, $catalog);
    }
    return $out;
}
