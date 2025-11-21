<?php
/**
 * Pattern Showcase Manager Class
 *
 * Manages pattern showcase data, CRUD operations, and mockup generation
 *
 * @package MockupGenerator
 * @since 1.3.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MG_Pattern_Showcase_Manager {

    /**
     * Option name for storing showcases
     */
    const OPTION_NAME = 'mg_pattern_showcases';

    /**
     * Get all showcases
     *
     * @return array Array of showcase objects
     */
    public static function get_showcases() {
        $showcases = get_option(self::OPTION_NAME, array());

        // Ensure it's an array
        if (!is_array($showcases)) {
            $showcases = array();
        }

        return $showcases;
    }

    /**
     * Get a single showcase by ID
     *
     * @param string $id Showcase ID
     * @return array|null Showcase data or null if not found
     */
    public static function get_showcase($id) {
        $showcases = self::get_showcases();

        return isset($showcases[$id]) ? $showcases[$id] : null;
    }

    /**
     * Save a showcase (create or update)
     *
     * @param array $data Showcase data
     * @return array|WP_Error Saved showcase or error
     */
    public static function save_showcase($data) {
        // Validate required fields
        if (empty($data['name'])) {
            return new WP_Error('missing_name', __('Showcase name is required', 'mockup-generator'));
        }

        if (empty($data['design_file'])) {
            return new WP_Error('missing_design', __('Design file is required', 'mockup-generator'));
        }

        if (empty($data['product_types']) || !is_array($data['product_types'])) {
            return new WP_Error('missing_products', __('At least one product type is required', 'mockup-generator'));
        }

        // Get existing showcases
        $showcases = self::get_showcases();

        // Generate ID if new showcase
        if (empty($data['id'])) {
            $data['id'] = 'showcase_' . uniqid();
            $data['created'] = current_time('mysql');
        } else {
            // Keep original created date
            if (isset($showcases[$data['id']]['created'])) {
                $data['created'] = $showcases[$data['id']]['created'];
            }
        }

        // Set modified timestamp
        $data['modified'] = current_time('mysql');

        // Set defaults
        $data['color_strategy'] = !empty($data['color_strategy']) ? $data['color_strategy'] : 'first';
        $data['layout'] = !empty($data['layout']) ? $data['layout'] : 'carousel';
        $data['columns'] = !empty($data['columns']) ? intval($data['columns']) : 4;
        $data['custom_colors'] = !empty($data['custom_colors']) ? $data['custom_colors'] : array();
        $data['mockups'] = !empty($data['mockups']) ? $data['mockups'] : array();
        $data['group_by_category'] = isset($data['group_by_category']) ? (bool)$data['group_by_category'] : true;

        // Save showcase
        $showcases[$data['id']] = $data;
        update_option(self::OPTION_NAME, $showcases);

        return $data;
    }

    /**
     * Delete a showcase
     *
     * @param string $id Showcase ID
     * @return bool Success status
     */
    public static function delete_showcase($id) {
        $showcases = self::get_showcases();

        if (!isset($showcases[$id])) {
            return false;
        }

        // Delete generated mockup attachments (optional - prevents orphaned media)
        if (!empty($showcases[$id]['mockups'])) {
            foreach ($showcases[$id]['mockups'] as $attachment_id) {
                if (is_numeric($attachment_id)) {
                    wp_delete_attachment($attachment_id, true);
                }
            }
        }

        unset($showcases[$id]);
        update_option(self::OPTION_NAME, $showcases);

        return true;
    }

    /**
     * Generate mockups for a showcase
     *
     * @param string $showcase_id Showcase ID
     * @return array|WP_Error Generated mockup data or error
     */
    public static function generate_mockups($showcase_id) {
        $showcase = self::get_showcase($showcase_id);

        if (!$showcase) {
            return new WP_Error('showcase_not_found', __('Showcase not found', 'mockup-generator'));
        }

        // Get design file path
        $design_file = get_attached_file($showcase['design_file']);
        if (!$design_file || !file_exists($design_file)) {
            return new WP_Error('design_not_found', __('Design file not found', 'mockup-generator'));
        }

        // Get all product types
        $products = get_option('mg_products', array());

        $generated_mockups = array();
        $errors = array();

        // Loop through selected product types
        foreach ($showcase['product_types'] as $type_key) {
            if (!isset($products[$type_key])) {
                $errors[] = sprintf(__('Product type "%s" not found', 'mockup-generator'), $type_key);
                continue;
            }

            $product = $products[$type_key];

            // Get colors to generate based on strategy
            $colors_to_generate = self::get_colors_for_product($showcase, $type_key, $product);

            // Generate mockup for each color
            foreach ($colors_to_generate as $color_slug) {
                $color_data = self::find_color_data($product, $color_slug);

                if (!$color_data) {
                    $errors[] = sprintf(__('Color "%s" not found for product "%s"', 'mockup-generator'), $color_slug, $type_key);
                    continue;
                }

                // Generate mockup for front view (primary view)
                $mockup_result = self::generate_single_mockup(
                    $design_file,
                    $product,
                    $color_data,
                    $type_key,
                    'front'
                );

                if (is_wp_error($mockup_result)) {
                    $errors[] = $mockup_result->get_error_message();
                } else {
                    $mockup_key = $type_key . '_' . $color_slug;
                    $generated_mockups[$mockup_key] = $mockup_result;
                }
            }
        }

        // Update showcase with generated mockups
        $showcase['mockups'] = $generated_mockups;
        $showcase['last_generated'] = current_time('mysql');
        self::save_showcase($showcase);

        return array(
            'success' => true,
            'mockups' => $generated_mockups,
            'errors' => $errors
        );
    }

    /**
     * Get colors to generate for a product based on strategy
     *
     * @param array $showcase Showcase data
     * @param string $type_key Product type key
     * @param array $product Product data
     * @return array Array of color slugs
     */
    private static function get_colors_for_product($showcase, $type_key, $product) {
        $colors = array();

        switch ($showcase['color_strategy']) {
            case 'custom':
                // Use custom color for this product type
                if (isset($showcase['custom_colors'][$type_key])) {
                    $colors[] = $showcase['custom_colors'][$type_key];
                } else {
                    // Fallback to first color
                    if (!empty($product['colors'])) {
                        $colors[] = $product['colors'][0]['slug'];
                    }
                }
                break;

            case 'all':
                // Use all colors
                if (!empty($product['colors'])) {
                    foreach ($product['colors'] as $color) {
                        $colors[] = $color['slug'];
                    }
                }
                break;

            case 'first':
            default:
                // Use first color
                if (!empty($product['colors'])) {
                    $colors[] = $product['colors'][0]['slug'];
                }
                break;
        }

        return $colors;
    }

    /**
     * Find color data in product
     *
     * @param array $product Product data
     * @param string $color_slug Color slug
     * @return array|null Color data or null
     */
    public static function find_color_data($product, $color_slug) {
        if (empty($product['colors'])) {
            return null;
        }

        foreach ($product['colors'] as $color) {
            if ($color['slug'] === $color_slug) {
                return $color;
            }
        }

        return null;
    }

    /**
     * Generate a single mockup
     *
     * @param string $design_file Path to design file
     * @param array $product Product data
     * @param array $color_data Color data
     * @param string $type_key Product type key
     * @param string $view View (front/back)
     * @return int|WP_Error Attachment ID or error
     */
    private static function generate_single_mockup($design_file, $product, $color_data, $type_key, $view = 'front') {
        // Check if MG_Generator class exists
        if (!class_exists('MG_Generator')) {
            return new WP_Error('generator_not_found', __('MG_Generator class not found', 'mockup-generator'));
        }

        // Get mockup template for this color/view
        $mockup_file = self::get_mockup_template_path($product, $color_data, $view);

        if (!$mockup_file || !file_exists($mockup_file)) {
            return new WP_Error('mockup_template_not_found', __('Mockup template not found', 'mockup-generator'));
        }

        // Get print area settings
        $print_area = isset($color_data['print_area'][$view]) ? $color_data['print_area'][$view] : array(
            'x' => 10,
            'y' => 10,
            'w' => 60,
            'h' => 60,
            'unit' => 'pct'
        );

        // Generate temporary output file
        $upload_dir = wp_upload_dir();
        $temp_file = $upload_dir['path'] . '/pattern_showcase_' . uniqid() . '.webp';

        // Use MG_Generator to create mockup
        try {
            $result = MG_Generator::generate(
                $mockup_file,
                $design_file,
                $temp_file,
                $print_area
            );

            if (!$result || !file_exists($temp_file)) {
                return new WP_Error('generation_failed', __('Mockup generation failed', 'mockup-generator'));
            }

            // Import to media library
            $filename = sprintf(
                'pattern-showcase-%s-%s-%s.webp',
                sanitize_title($type_key),
                sanitize_title($color_data['slug']),
                $view
            );

            $attachment_id = self::import_to_media_library($temp_file, $filename);

            // Clean up temp file
            @unlink($temp_file);

            return $attachment_id;

        } catch (Exception $e) {
            return new WP_Error('generation_exception', $e->getMessage());
        }
    }

    /**
     * Get mockup template path for a product/color/view
     *
     * @param array $product Product data
     * @param array $color_data Color data
     * @param string $view View (front/back)
     * @return string|null Template file path
     */
    private static function get_mockup_template_path($product, $color_data, $view) {
        // Check for mockup override first
        if (!empty($color_data['mockups'][$view])) {
            return get_attached_file($color_data['mockups'][$view]);
        }

        // Use template base path
        if (!empty($product['template_base'])) {
            $template_path = trailingslashit($product['template_base']) . $color_data['slug'] . '_' . $view . '.png';

            if (file_exists($template_path)) {
                return $template_path;
            }
        }

        return null;
    }

    /**
     * Import file to media library
     *
     * @param string $file_path File path
     * @param string $filename Desired filename
     * @return int|WP_Error Attachment ID or error
     */
    private static function import_to_media_library($file_path, $filename) {
        $upload_dir = wp_upload_dir();
        $target_file = $upload_dir['path'] . '/' . $filename;

        // Copy file to uploads directory
        if (!copy($file_path, $target_file)) {
            return new WP_Error('import_failed', __('Failed to import file to media library', 'mockup-generator'));
        }

        // Create attachment
        $filetype = wp_check_filetype($filename);

        $attachment = array(
            'post_mime_type' => $filetype['type'],
            'post_title'     => sanitize_file_name(pathinfo($filename, PATHINFO_FILENAME)),
            'post_content'   => '',
            'post_status'    => 'inherit'
        );

        $attachment_id = wp_insert_attachment($attachment, $target_file);

        if (is_wp_error($attachment_id)) {
            return $attachment_id;
        }

        // Generate attachment metadata
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        $attach_data = wp_generate_attachment_metadata($attachment_id, $target_file);
        wp_update_attachment_metadata($attachment_id, $attach_data);

        return $attachment_id;
    }

    /**
     * Get product type category (Férfi/Női)
     *
     * @param string $type_key Product type key
     * @param array $product Product data
     * @return string Category name
     */
    public static function get_product_category($type_key, $product) {
        $label = isset($product['label']) ? $product['label'] : $type_key;

        // Check for common patterns in product key or label
        $ferfi_patterns = array('ferfi', 'men', 'male', 'férfi');
        $noi_patterns = array('noi', 'női', 'women', 'female', 'woman');

        $lower_key = strtolower($type_key);
        $lower_label = strtolower($label);

        foreach ($ferfi_patterns as $pattern) {
            if (strpos($lower_key, $pattern) !== false || strpos($lower_label, $pattern) !== false) {
                return __('Men', 'mockup-generator');
            }
        }

        foreach ($noi_patterns as $pattern) {
            if (strpos($lower_key, $pattern) !== false || strpos($lower_label, $pattern) !== false) {
                return __('Women', 'mockup-generator');
            }
        }

        // Default category
        return __('Other', 'mockup-generator');
    }

    /**
     * Get showcases grouped by product category
     *
     * @param string $showcase_id Showcase ID
     * @return array Grouped mockup data
     */
    public static function get_grouped_mockups($showcase_id) {
        $showcase = self::get_showcase($showcase_id);

        if (!$showcase || empty($showcase['mockups'])) {
            return array();
        }

        $products = get_option('mg_products', array());
        $grouped = array();

        foreach ($showcase['mockups'] as $mockup_key => $attachment_id) {
            // Parse mockup key: type_key_color_slug
            $parts = explode('_', $mockup_key);
            $color_slug = array_pop($parts);
            $type_key = implode('_', $parts);

            if (!isset($products[$type_key])) {
                continue;
            }

            $product = $products[$type_key];
            $category = self::get_product_category($type_key, $product);

            if (!isset($grouped[$category])) {
                $grouped[$category] = array();
            }

            $color_data = self::find_color_data($product, $color_slug);

            $grouped[$category][] = array(
                'type_key' => $type_key,
                'type_label' => $product['label'],
                'color_slug' => $color_slug,
                'color_name' => $color_data ? $color_data['name'] : $color_slug,
                'attachment_id' => $attachment_id,
                'image_url' => wp_get_attachment_image_url($attachment_id, 'large'),
                'thumb_url' => wp_get_attachment_image_url($attachment_id, 'medium')
            );
        }

        return $grouped;
    }
}
