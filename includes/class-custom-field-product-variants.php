<?php
/**
 * Service layer for PNG-backed linked products used by custom select fields.
 *
 * This class deliberately contains no controller, capability or nonce code.
 * Callers (admin actions, REST endpoints, or CLI jobs) are responsible for
 * authorisation before invoking a mutating method.
 */
if (!defined('ABSPATH')) {
    exit;
}

class MG_Custom_Field_Product_Variants {
    /** All field configurations for a product. */
    const META_VARIANTS = '_mg_custom_field_product_variants';
    /** Stable relation identifier written to every product in a group. */
    const META_GROUP = '_mg_custom_field_product_variant_group';
    /** Original product (the first option) for a group. */
    const META_PARENT = '_mg_custom_field_product_variant_parent';
    /** Select field ID used by a group. */
    const META_FIELD = '_mg_custom_field_product_variant_field';
    /** Option slug and label belonging to a product. */
    const META_OPTION_SLUG = '_mg_custom_field_product_variant_option';
    const META_OPTION_LABEL = '_mg_custom_field_product_variant_option_label';
    /** The product from which a linked product was copied. */
    const META_SOURCE = '_mg_custom_field_product_variant_source';
    /** The base name before the first option label was appended. */
    const META_BASE_NAME = '_mg_custom_field_product_variant_base_name';

    // Friendly aliases for integrations that prefer a CONFIG/MAPPING name.
    const META_CONFIG = '_mg_custom_field_product_variants';
    const META_MAPPING = '_mg_custom_field_product_variants';

    /**
     * Return saved configuration. With a field ID, only that field is returned.
     *
     * @param int    $product_id Product ID.
     * @param string $field_id   Select field ID, or empty for all fields.
     * @return array Configuration array, or an empty array when not configured.
     */
    public static function get_config($product_id, $field_id = '') {
        $product_id = absint($product_id);
        if ($product_id <= 0) {
            return array();
        }
        $stored = get_post_meta($product_id, self::META_VARIANTS, true);
        $stored = is_array($stored) ? $stored : array();
        $normalised = array();
        foreach ($stored as $key => $config) {
            $key = sanitize_key($key);
            if ($key === '' || !is_array($config)) {
                continue;
            }
            $normalised[$key] = self::normalise_config($key, $config);
        }
        $field_id = sanitize_key($field_id);
        if ($field_id !== '') {
            return isset($normalised[$field_id]) ? $normalised[$field_id] : array();
        }
        return $normalised;
    }

    /**
     * Save one field's linked-product configuration.
     *
     * Accepted keys are enabled/linked_product_variants, base_name, options,
     * mapping and group_id. Mapping is intentionally allowed to be incomplete;
     * generation is the operation that enforces PNG completeness.
     *
     * @param int    $product_id Product ID.
     * @param string $field_id   Select field ID.
     * @param array  $config     Configuration payload.
     * @return array|WP_Error Normalised configuration.
     */
    public static function save_config($product_id, $field_id, $config) {
        $product_id = absint($product_id);
        $field_id = sanitize_key($field_id);
        if ($product_id <= 0 || $field_id === '') {
            return new WP_Error('invalid_config', __('Érvénytelen termék vagy mezőazonosító.', 'mgcf'));
        }
        if (!is_array($config)) {
            $config = array();
        }

        $all = self::get_config($product_id);
        $previous = isset($all[$field_id]) ? $all[$field_id] : array();
        $merged = array_merge($previous, $config);
        $normalised = self::normalise_config($field_id, $merged);
        $all[$field_id] = $normalised;
        update_post_meta($product_id, self::META_VARIANTS, $all);
        return $normalised;
    }

    /**
     * Save just the option-to-attachment mapping for a field.
     *
     * @param int    $product_id Product ID.
     * @param string $field_id   Select field ID.
     * @param array  $mapping    Slug/label keyed mapping or option list.
     * @return array|WP_Error Normalised field configuration.
     */
    public static function save_mapping($product_id, $field_id, $mapping) {
        if (!is_array($mapping)) {
            return new WP_Error('invalid_mapping', __('A minta-hozzárendelés formátuma hibás.', 'mgcf'));
        }
        $existing = self::get_mapping($product_id, $field_id);
        foreach ($mapping as $key => &$entry) {
            if (!is_array($entry)) {
                $entry = array('attachment_id' => absint($entry));
            }
            $slug = !empty($entry['slug']) ? sanitize_title($entry['slug']) : sanitize_title(is_string($key) ? $key : '');
            if ($slug !== '' && empty($entry['product_id']) && !empty($existing[$slug]['product_id'])) {
                $entry['product_id'] = absint($existing[$slug]['product_id']);
            }
        }
        unset($entry);
        return self::save_config($product_id, $field_id, array(
            'linked_product_variants' => true,
            'mapping' => $mapping,
        ));
    }

    /**
     * Return the normalised mapping of one field.
     *
     * @return array Slug keyed mapping.
     */
    public static function get_mapping($product_id, $field_id) {
        $config = self::get_config($product_id, $field_id);
        return !empty($config['mapping']) && is_array($config['mapping']) ? $config['mapping'] : array();
    }

    /**
     * Whether this field is configured as a linked product selector.
     */
    public static function is_enabled($product_id, $field_id) {
        $config = self::get_config($product_id, $field_id);
        if (!empty($config)) {
            return !empty($config['linked_product_variants']);
        }

        // Keep this service compatible with a future manager that persists the
        // flag directly on a field definition. The current manager strips
        // unknown keys, so the private product config remains authoritative.
        $field = self::get_field_definition($product_id, $field_id);
        return !empty($field['linked_product_variants']);
    }

    /**
     * Validate that every select option has a real PNG attachment.
     *
     * A complete result is returned as an array. Partial or invalid mappings
     * always return WP_Error and include missing option details in error data.
     * No product or relation is changed by this method.
     *
     * @param int         $product_id Product ID.
     * @param string      $field_id   Select field ID.
     * @param array|null  $mapping    Optional unsaved mapping to validate.
     * @return array|WP_Error Normalised validation payload.
     */
    public static function validate_completeness($product_id, $field_id, $mapping = null) {
        $product_id = absint($product_id);
        $field_id = sanitize_key($field_id);
        if ($product_id <= 0 || $field_id === '') {
            return new WP_Error('invalid_mapping', __('Érvénytelen termék vagy mezőazonosító.', 'mgcf'));
        }

        $config = self::get_config($product_id, $field_id);
        if (empty($config['linked_product_variants'])) {
            $field = self::get_field_definition($product_id, $field_id);
            if (empty($field['linked_product_variants'])) {
                return new WP_Error('linked_variants_disabled', __('A mezőhöz nincs bekapcsolva a kapcsolt termék mód.', 'mgcf'));
            }
        }

        $field = self::get_field_definition($product_id, $field_id);
        $options = self::normalise_options(
            !empty($field['options']) && is_array($field['options']) ? $field['options'] :
                (!empty($config['options']) && is_array($config['options']) ? $config['options'] : array())
        );
        if (empty($options)) {
            return new WP_Error('options_missing', __('A kapcsolt mezőnek nincsenek választási értékei.', 'mgcf'));
        }

        $raw_mapping = null === $mapping
            ? (!empty($config['mapping']) && is_array($config['mapping']) ? $config['mapping'] : array())
            : $mapping;
        $mapping = self::normalise_mapping($raw_mapping, $options);
        $missing = array();
        foreach ($options as $option) {
            $slug = $option['slug'];
            $entry = isset($mapping[$slug]) ? $mapping[$slug] : array();
            $attachment_id = !empty($entry['attachment_id']) ? absint($entry['attachment_id']) : 0;
            if ($attachment_id <= 0 || !self::is_valid_png_attachment($attachment_id)) {
                $missing[] = array(
                    'slug'  => $slug,
                    'label' => $option['label'],
                    'attachment_id' => $attachment_id,
                );
            }
        }
        if (!empty($missing)) {
            return new WP_Error(
                'incomplete_mapping',
                __('Minden választási értékhez érvényes PNG csatolmány szükséges.', 'mgcf'),
                array(
                    'product_id' => $product_id,
                    'field_id'   => $field_id,
                    'missing'    => $missing,
                    'expected'   => $options,
                    'mapping'    => $mapping,
                )
            );
        }

        return array(
            'product_id' => $product_id,
            'field_id'   => $field_id,
            'base_name'  => self::resolve_base_name($product_id, $config),
            'options'    => $options,
            'mapping'    => $mapping,
            'group_id'   => !empty($config['group_id']) ? sanitize_key($config['group_id']) : '',
            'complete'   => true,
        );
    }

    /** Alias kept for integrations that call the operation a mapping check. */
    public static function validate_mapping($product_id, $field_id, $mapping = null) {
        return self::validate_completeness($product_id, $field_id, $mapping);
    }

    /**
     * Create or update all products in a linked group.
     *
     * The source/original product is always assigned to the first field
     * option. Existing mapped products are reused; no product is deleted.
     * New products are copied from the source and receive fresh SKUs and
     * design metadata. The relation is written only after all options finish.
     *
     * @param int    $product_id Source product ID.
     * @param string $field_id   Select field ID.
     * @param array  $args       Optional base_name, mapping and group_id.
     * @return array|WP_Error Result with group and product rows.
     */
    public static function generate_or_update($product_id, $field_id, $args = array()) {
        $product_id = absint($product_id);
        $field_id = sanitize_key($field_id);
        if ($product_id <= 0 || $field_id === '') {
            return new WP_Error('invalid_product', __('Érvénytelen termék vagy mezőazonosító.', 'mgcf'));
        }
        if (!function_exists('wc_get_product')) {
            return new WP_Error('woocommerce_missing', __('A WooCommerce nem érhető el.', 'mgcf'));
        }
        $source = wc_get_product($product_id);
        if (!$source || !$source->get_id()) {
            return new WP_Error('product_missing', __('A forrás termék nem található.', 'mgcf'));
        }
        if (method_exists($source, 'is_type') && $source->is_type('variation')) {
            return new WP_Error('source_variation', __('Variáció nem használható forrás termékként.', 'mgcf'));
        }

        $args = is_array($args) ? $args : array();
        $mapping = isset($args['mapping']) && is_array($args['mapping']) ? $args['mapping'] : null;
        $validated = self::validate_completeness($product_id, $field_id, $mapping);
        if (is_wp_error($validated)) {
            return $validated;
        }

        $config = self::get_config($product_id, $field_id);
        $base_name = isset($args['base_name']) ? trim(wp_strip_all_tags((string) $args['base_name'])) : '';
        if ($base_name === '') {
            $base_name = $validated['base_name'];
        }
        if ($base_name === '') {
            $base_name = method_exists($source, 'get_name') ? $source->get_name() : get_the_title($product_id);
        }
        if ($base_name === '') {
            return new WP_Error('base_name_missing', __('A kapcsolt termék alapneve hiányzik.', 'mgcf'));
        }
        if (get_post_meta($product_id, self::META_BASE_NAME, true) === '') {
            update_post_meta($product_id, self::META_BASE_NAME, $base_name);
        }

        $group_id = !empty($args['group_id']) ? sanitize_key($args['group_id']) : '';
        if ($group_id === '' && !empty($validated['group_id'])) {
            $group_id = sanitize_key($validated['group_id']);
        }
        if ($group_id === '') {
            $group_id = get_post_meta($product_id, self::META_GROUP, true);
            $group_id = sanitize_key($group_id);
        }
        if ($group_id === '') {
            $group_id = 'mgcfpv_' . (function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : uniqid());
            $group_id = sanitize_key($group_id);
        }

        $rows = array();
        $created_ids = array();
        $options = $validated['options'];
        foreach ($options as $index => $option) {
            $slug = $option['slug'];
            $entry = isset($validated['mapping'][$slug]) ? $validated['mapping'][$slug] : array();
            $target_id = $index === 0 ? $product_id : (!empty($entry['product_id']) ? absint($entry['product_id']) : 0);
            $target = $target_id > 0 ? wc_get_product($target_id) : false;

            // Never reuse an unrelated product accidentally supplied in a
            // mapping. It must already belong to this exact linked group.
            if ($index > 0 && $target && !self::belongs_to_group($target_id, $group_id, $field_id, $slug)) {
                $target = false;
                $target_id = 0;
            }
            if ($index > 0 && !$target) {
                $target_id = self::duplicate_product($product_id, $base_name . ' – ' . $option['label']);
                if (is_wp_error($target_id)) {
                    return new WP_Error(
                        'product_generation_failed',
                        $target_id->get_error_message(),
                        array('created_product_ids' => $created_ids, 'option' => $option)
                    );
                }
                $created_ids[] = absint($target_id);
                $target = wc_get_product($target_id);
            }
            if (!$target || !$target->get_id()) {
                return new WP_Error(
                    'product_missing_after_copy',
                    __('A kapcsolt termék létrehozása után nem tölthető be.', 'mgcf'),
                    array('created_product_ids' => $created_ids, 'option' => $option)
                );
            }

            $updated = self::prepare_target_product(
                $target,
                $base_name,
                array_merge($option, array(
                    'attachment_id' => !empty($entry['attachment_id']) ? absint($entry['attachment_id']) : 0,
                )),
                $product_id,
                $group_id,
                $field_id,
                $index === 0
            );
            if (is_wp_error($updated)) {
                return new WP_Error(
                    'product_generation_failed',
                    $updated->get_error_message(),
                    array('created_product_ids' => $created_ids, 'option' => $option)
                );
            }
            $rows[] = $updated;
        }

        // Relation/meta writes are deliberately deferred until every option
        // has a product. This prevents a partial group from being exposed.
        foreach ($rows as $row) {
            self::write_relation_meta($row['product_id'], $group_id, $product_id, $field_id, $row['slug'], $row['label']);
        }

        $config['field_id'] = $field_id;
        $config['linked_product_variants'] = true;
        $config['base_name'] = $base_name;
        $config['group_id'] = $group_id;
        $config['mapping'] = array();
        $config['options'] = $options;
        foreach ($rows as $row) {
            $config['mapping'][$row['slug']] = array(
                'slug' => $row['slug'],
                'label' => $row['label'],
                'attachment_id' => $row['attachment_id'],
                'product_id' => $row['product_id'],
            );
        }
        $all_configs = self::get_config($product_id);
        $all_configs[$field_id] = self::normalise_config($field_id, $config);
        update_post_meta($product_id, self::META_VARIANTS, $all_configs);

        return array(
            'product_id' => $product_id,
            'field_id'   => $field_id,
            'group_id'   => $group_id,
            'base_name'  => $base_name,
            'created_product_ids' => $created_ids,
            'products'   => $rows,
            'mapping'    => $all_configs[$field_id]['mapping'],
        );
    }

    /** Aliases for callers that use create/update terminology. */
    public static function generate_linked_products($product_id, $field_id, $args = array()) {
        return self::generate_or_update($product_id, $field_id, $args);
    }

    public static function update_linked_products($product_id, $field_id, $args = array()) {
        return self::generate_or_update($product_id, $field_id, $args);
    }

    /**
     * Find the relation group for a product.
     *
     * @return array Group metadata or an empty array.
     */
    public static function get_group($product_id, $field_id = '') {
        $product_id = absint($product_id);
        if ($product_id <= 0) {
            return array();
        }
        $group_id = sanitize_key(get_post_meta($product_id, self::META_GROUP, true));
        if ($group_id === '') {
            return array();
        }
        $field_id = sanitize_key($field_id);
        if ($field_id === '') {
            $field_id = sanitize_key(get_post_meta($product_id, self::META_FIELD, true));
        }
        $parent_id = absint(get_post_meta($product_id, self::META_PARENT, true));
        $products = self::get_group_products_by_meta($group_id, $field_id);
        return array(
            'group_id' => $group_id,
            'field_id' => $field_id,
            'parent_id' => $parent_id,
            'products' => $products,
        );
    }

    /** Alias for consumers that call this lookup a group-products method. */
    public static function get_group_products($product_id, $field_id = '') {
        $group = self::get_group($product_id, $field_id);
        return !empty($group['products']) && is_array($group['products']) ? $group['products'] : array();
    }

    /**
     * Build a safe frontend payload. URLs and labels are escaped by the
     * eventual renderer; values here remain plain data for JSON responses.
     */
    public static function get_frontend_payload($product_id, $field_id = '') {
        $group = self::get_group($product_id, $field_id);
        if (empty($group)) {
            return array();
        }
        $rows = $group['products'];
        $parent_config = self::get_config(absint($group['parent_id']), $group['field_id']);
        if (!empty($parent_config['mapping']) && is_array($parent_config['mapping'])) {
            $rows_by_slug = array();
            foreach ($rows as $row) {
                if (!empty($row['slug'])) {
                    $rows_by_slug[$row['slug']] = $row;
                }
            }
            $ordered_rows = array();
            foreach ($parent_config['mapping'] as $slug => $entry) {
                $slug = sanitize_title($slug);
                if (isset($rows_by_slug[$slug])) {
                    $ordered_rows[] = $rows_by_slug[$slug];
                    unset($rows_by_slug[$slug]);
                }
            }
            // Products belonging to options removed from the preset are kept
            // (generation never deletes products) but are no longer exposed
            // in the active selector.
            $rows = $ordered_rows;
        }
        $options = array();
        foreach ($rows as $row) {
            $target_id = absint($row['product_id']);
            $url = function_exists('get_permalink') ? get_permalink($target_id) : '';
            $image_url = '';
            if (function_exists('get_post_thumbnail_id') && function_exists('wp_get_attachment_image_url')) {
                $image_id = absint(get_post_thumbnail_id($target_id));
                if ($image_id > 0) {
                    $image_url = (string) wp_get_attachment_image_url($image_id, 'full');
                }
            }
            $options[] = array(
                'slug' => $row['slug'],
                'label' => $row['label'],
                'product_id' => $target_id,
                'url' => $url ? esc_url_raw($url) : '',
                'image_url' => $image_url ? esc_url_raw($image_url) : '',
                'attachment_id' => absint($row['attachment_id']),
            );
        }
        return array(
            'enabled' => true,
            'group_id' => $group['group_id'],
            'field_id' => $group['field_id'],
            'parent_id' => absint($group['parent_id']),
            'current_product_id' => absint($product_id),
            'options' => $options,
        );
    }

    /**
     * Explicitly render mockups for one linked product.
     *
     * Rendering is opt-in because the generator needs a product type. Pass
     * product_type in $args or store _mg_type_key on the product. The method
     * returns generator output and never changes group linkage on failure.
     *
     * @return array|WP_Error Generator result.
     */
    public static function render_mockups($product_id, $field_id, $option_slug, $args = array()) {
        $product_id = absint($product_id);
        $field_id = sanitize_key($field_id);
        $option_slug = sanitize_title($option_slug);
        $config = self::get_config($product_id, $field_id);
        $mapping = !empty($config['mapping']) && is_array($config['mapping']) ? $config['mapping'] : array();
        if (!isset($mapping[$option_slug])) {
            return new WP_Error('option_missing', __('A választási érték nem található.', 'mgcf'));
        }
        $target_id = absint($mapping[$option_slug]['product_id'] ?? $product_id);
        if ($target_id <= 0) {
            return new WP_Error('product_missing', __('A kapcsolt termék nem található.', 'mgcf'));
        }
        $design_path = self::resolve_attachment_path(absint($mapping[$option_slug]['attachment_id'] ?? 0));
        if ($design_path === '' || !file_exists($design_path)) {
            return new WP_Error('design_missing', __('A PNG minta fájlja nem található.', 'mgcf'));
        }
        $args = is_array($args) ? $args : array();
        $product_type = isset($args['product_type']) ? sanitize_title($args['product_type']) : '';
        if ($product_type === '') {
            $product_type = sanitize_title(get_post_meta($target_id, '_mg_type_key', true));
        }
        if ($product_type === '') {
            return new WP_Error('product_type_missing', __('A mockup rendereléséhez hiányzik a terméktípus.', 'mgcf'));
        }
        if (!class_exists('MG_Generator')) {
            $file = __DIR__ . '/class-generator.php';
            if (file_exists($file)) {
                require_once $file;
            }
        }
        if (!class_exists('MG_Generator')) {
            return new WP_Error('generator_missing', __('A mockup generátor nem érhető el.', 'mgcf'));
        }
        $generator = new MG_Generator();
        $context = array(
            'product_id' => $target_id,
            'design_id' => $target_id,
            'render_scope' => 'linked_custom_field',
        );
        foreach (array('render_version', 'view_filter', 'color_filter') as $key) {
            if (isset($args[$key])) {
                $context[$key] = $args[$key];
            }
        }
        $result = $generator->generate_for_product($product_type, $design_path, $context);
        if (is_wp_error($result)) {
            return $result;
        }
        return array(
            'product_id' => $target_id,
            'field_id' => $field_id,
            'option_slug' => $option_slug,
            'design_path' => $design_path,
            'files' => $result,
        );
    }

    /**
     * Render every configured catalogue type for every linked product and set
     * the first generated mockup as its featured image. Individual template
     * failures are reported without removing an otherwise valid product group.
     */
    public static function render_group_mockups($product_id, $field_id) {
        $product_id = absint($product_id);
        $field_id = sanitize_key($field_id);
        $config = self::get_config($product_id, $field_id);
        if (empty($config['mapping']) || !is_array($config['mapping'])) {
            return new WP_Error('mapping_missing', __('Nincs renderelhető kapcsolt termékcsoport.', 'mgcf'));
        }
        if (!class_exists('MG_Variant_Display_Manager') || !method_exists('MG_Variant_Display_Manager', 'get_catalog_index')) {
            return new WP_Error('catalog_missing', __('A mockup termékkatalógus nem érhető el.', 'mgcf'));
        }
        $catalog = MG_Variant_Display_Manager::get_catalog_index();
        if (empty($catalog) || !is_array($catalog)) {
            return new WP_Error('catalog_empty', __('A mockup termékkatalógus üres.', 'mgcf'));
        }

        $rows = array();
        foreach ($config['mapping'] as $option_slug => $entry) {
            $target_id = !empty($entry['product_id']) ? absint($entry['product_id']) : 0;
            $first_file = '';
            $generated_types = array();
            $errors = array();
            $product_types = array_keys($catalog);
            if ($target_id > 0 && class_exists('MG_Virtual_Variant_Manager') && method_exists('MG_Virtual_Variant_Manager', 'get_default_selection')) {
                $target_product = wc_get_product($target_id);
                $defaults = $target_product ? MG_Virtual_Variant_Manager::get_default_selection($target_product) : array();
                $default_type = !empty($defaults['type']) ? sanitize_title($defaults['type']) : '';
                $default_index = $default_type !== '' ? array_search($default_type, $product_types, true) : false;
                if ($default_index !== false) {
                    unset($product_types[$default_index]);
                    array_unshift($product_types, $default_type);
                }
            }
            foreach ($product_types as $product_type) {
                $result = self::render_mockups($product_id, $field_id, $option_slug, array(
                    'product_type' => $product_type,
                ));
                if (is_wp_error($result)) {
                    $errors[$product_type] = $result->get_error_message();
                    continue;
                }
                $generated_types[] = sanitize_title($product_type);
                if ($first_file === '' && !empty($result['files']) && is_array($result['files'])) {
                    foreach ($result['files'] as $files) {
                        foreach ((array) $files as $file) {
                            if (is_string($file) && $file !== '' && file_exists($file)) {
                                $first_file = $file;
                                break 2;
                            }
                        }
                    }
                }
            }
            $featured_id = 0;
            if ($target_id > 0 && $first_file !== '') {
                $featured_id = self::attach_generated_image($first_file, get_the_title($target_id));
                if ($featured_id > 0) {
                    set_post_thumbnail($target_id, $featured_id);
                }
            }
            $rows[] = array(
                'product_id' => $target_id,
                'option_slug' => sanitize_title($option_slug),
                'generated_types' => $generated_types,
                'featured_image_id' => $featured_id,
                'errors' => $errors,
            );
        }
        return array('products' => $rows);
    }

    /**
     * Normalise a saved config while preserving future extension keys only as
     * harmless scalar data. Mapping itself is normalised against options when
     * validation/generation is requested.
     */
    protected static function normalise_config($field_id, $config) {
        $config = is_array($config) ? $config : array();
        $field_id = sanitize_key($field_id);
        $enabled = !empty($config['linked_product_variants']) || !empty($config['enabled']);
        $base_name = isset($config['base_name']) ? trim(wp_strip_all_tags((string) $config['base_name'])) : '';
        $options = isset($config['options']) && is_array($config['options']) ? self::normalise_options($config['options']) : array();
        $mapping = isset($config['mapping']) && is_array($config['mapping']) ? self::normalise_mapping($config['mapping'], $options) : array();
        return array(
            'field_id' => $field_id,
            'linked_product_variants' => $enabled,
            'base_name' => $base_name,
            'options' => $options,
            'mapping' => $mapping,
            'group_id' => !empty($config['group_id']) ? sanitize_key($config['group_id']) : '',
            'updated_at' => current_time('mysql'),
        );
    }

    protected static function normalise_options($options) {
        $result = array();
        foreach ((array) $options as $key => $option) {
            $label = '';
            $slug = '';
            if (is_array($option)) {
                $label = isset($option['label']) ? $option['label'] : (isset($option['name']) ? $option['name'] : '');
                $slug = isset($option['slug']) ? $option['slug'] : '';
            } else {
                $label = $option;
            }
            $label = sanitize_text_field((string) $label);
            $slug = sanitize_title((string) $slug);
            if ($slug === '') {
                $slug = sanitize_title($label);
            }
            if ($slug === '' || $label === '' || isset($result[$slug])) {
                continue;
            }
            $result[$slug] = array('slug' => $slug, 'label' => $label);
        }
        return array_values($result);
    }

    protected static function normalise_mapping($mapping, $options = array()) {
        $mapping = is_array($mapping) ? $mapping : array();
        $by_slug = array();
        foreach ((array) $options as $option) {
            if (is_array($option) && !empty($option['slug'])) {
                $by_slug[sanitize_title($option['slug'])] = $option;
            }
        }
        $result = array();
        foreach ($mapping as $key => $entry) {
            if (is_numeric($key) && is_string($entry)) {
                $entry = array('label' => $entry);
            }
            $entry = is_array($entry) ? $entry : array();
            $slug = isset($entry['slug']) ? sanitize_title($entry['slug']) : sanitize_title(is_string($key) ? $key : '');
            $label = isset($entry['label']) ? sanitize_text_field($entry['label']) : '';
            if ($slug === '' && $label !== '') {
                $slug = sanitize_title($label);
            }
            if ($slug === '' && isset($by_slug[$slug])) {
                $slug = $by_slug[$slug]['slug'];
            }
            if ($slug === '') {
                continue;
            }
            if ($label === '' && isset($by_slug[$slug])) {
                $label = $by_slug[$slug]['label'];
            }
            $result[$slug] = array(
                'slug' => $slug,
                'label' => $label !== '' ? $label : $slug,
                'attachment_id' => !empty($entry['attachment_id']) ? absint($entry['attachment_id']) : 0,
                'product_id' => !empty($entry['product_id']) ? absint($entry['product_id']) : 0,
            );
        }
        return $result;
    }

    protected static function get_field_definition($product_id, $field_id) {
        $field_id = sanitize_key($field_id);
        if ($field_id === '') {
            return array();
        }
        $manager_field = array();
        $fields = class_exists('MG_Custom_Fields_Manager')
            ? MG_Custom_Fields_Manager::get_fields_for_product($product_id)
            : array();
        foreach ((array) $fields as $field) {
            if (is_array($field) && sanitize_key($field['id'] ?? '') === $field_id) {
                $manager_field = $field;
                break;
            }
        }
        // A manager version that keeps linked_product_variants in its raw
        // option can still be consumed without changing that manager here.
        $raw = get_option('mg_custom_fields', array());
        $raw_fields = isset($raw[$product_id]['fields']) && is_array($raw[$product_id]['fields']) ? $raw[$product_id]['fields'] : array();
        foreach ($raw_fields as $field) {
            if (is_array($field) && sanitize_key($field['id'] ?? '') === $field_id) {
                if (empty($manager_field)) {
                    return $field;
                }
                foreach (array('linked_product_variants', 'enabled') as $key) {
                    if (array_key_exists($key, $field)) {
                        $manager_field[$key] = !empty($field[$key]);
                    }
                }
                // Keep the manager's already-normalised options where
                // possible, but accept a legacy raw string as a fallback.
                if (empty($manager_field['options']) && !empty($field['options'])) {
                    $manager_field['options'] = is_array($field['options'])
                        ? $field['options']
                        : preg_split('/\r?\n|,/', (string) $field['options']);
                }
                break;
            }
        }
        return $manager_field;
    }

    protected static function resolve_base_name($product_id, $config) {
        $saved = get_post_meta(absint($product_id), self::META_BASE_NAME, true);
        if (is_string($saved) && trim($saved) !== '') {
            return sanitize_text_field($saved);
        }
        if (is_array($config) && !empty($config['base_name'])) {
            return sanitize_text_field($config['base_name']);
        }
        return '';
    }

    protected static function is_valid_png_attachment($attachment_id) {
        $attachment_id = absint($attachment_id);
        if ($attachment_id <= 0 || get_post_type($attachment_id) !== 'attachment') {
            return false;
        }
        $mime = (string) get_post_mime_type($attachment_id);
        $path = self::resolve_attachment_path($attachment_id);
        if (stripos($mime, 'image/png') !== 0 && !preg_match('/\.png$/i', $path)) {
            return false;
        }
        // A valid attachment normally has a local path. If WordPress cannot
        // resolve one (e.g. remote media), a PNG URL is still a valid asset.
        if ($path !== '' && file_exists($path)) {
            return true;
        }
        $url = function_exists('wp_get_attachment_url') ? wp_get_attachment_url($attachment_id) : '';
        return $url !== '' && (stripos($mime, 'image/png') === 0 || preg_match('/\.png(?:\?|$)/i', $url));
    }

    protected static function resolve_attachment_path($attachment_id) {
        $attachment_id = absint($attachment_id);
        if ($attachment_id <= 0) {
            return '';
        }
        $path = function_exists('get_attached_file') ? get_attached_file($attachment_id) : '';
        if (is_string($path) && $path !== '') {
            return wp_normalize_path($path);
        }
        $relative = get_post_meta($attachment_id, '_wp_attached_file', true);
        if (!is_string($relative) || $relative === '' || !function_exists('wp_upload_dir')) {
            return '';
        }
        $uploads = wp_upload_dir();
        $base = isset($uploads['basedir']) ? $uploads['basedir'] : '';
        return $base !== '' ? wp_normalize_path(trailingslashit($base) . ltrim($relative, '/\\')) : '';
    }

    protected static function attach_generated_image($path, $title = '') {
        $path = is_string($path) ? wp_normalize_path($path) : '';
        if ($path === '' || !file_exists($path)) {
            return 0;
        }
        $uploads = wp_upload_dir();
        $base_dir = !empty($uploads['basedir']) ? wp_normalize_path($uploads['basedir']) : '';
        if ($base_dir === '' || strpos($path, $base_dir) !== 0) {
            return 0;
        }
        $relative = ltrim(substr($path, strlen($base_dir)), '/\\');
        $existing = get_posts(array(
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'posts_per_page' => 1,
            'fields' => 'ids',
            'meta_key' => '_wp_attached_file',
            'meta_value' => $relative,
        ));
        if (!empty($existing)) {
            return absint($existing[0]);
        }
        $filetype = wp_check_filetype(wp_basename($path), null);
        $attachment_id = wp_insert_attachment(array(
            'guid' => trailingslashit($uploads['baseurl']) . str_replace('\\', '/', $relative),
            'post_mime_type' => !empty($filetype['type']) ? $filetype['type'] : 'image/webp',
            'post_title' => sanitize_text_field($title !== '' ? $title : pathinfo($path, PATHINFO_FILENAME)),
            'post_content' => '',
            'post_status' => 'inherit',
        ), $path);
        if (is_wp_error($attachment_id) || !$attachment_id) {
            return 0;
        }
        require_once ABSPATH . 'wp-admin/includes/image.php';
        update_attached_file($attachment_id, $path);
        $metadata = wp_generate_attachment_metadata($attachment_id, $path);
        if (is_array($metadata)) {
            wp_update_attachment_metadata($attachment_id, $metadata);
        }
        if ($title !== '') {
            update_post_meta($attachment_id, '_wp_attachment_image_alt', sanitize_text_field($title));
        }
        return absint($attachment_id);
    }

    protected static function belongs_to_group($product_id, $group_id, $field_id, $slug) {
        $product_id = absint($product_id);
        if ($product_id <= 0 || $group_id === '') {
            return false;
        }
        $stored_group = sanitize_key(get_post_meta($product_id, self::META_GROUP, true));
        $stored_field = sanitize_key(get_post_meta($product_id, self::META_FIELD, true));
        $stored_slug = sanitize_title(get_post_meta($product_id, self::META_OPTION_SLUG, true));
        return $stored_group === $group_id && $stored_field === sanitize_key($field_id) && $stored_slug === sanitize_title($slug);
    }

    protected static function duplicate_product($source_id, $name) {
        $source_id = absint($source_id);
        $source = function_exists('wc_get_product') ? wc_get_product($source_id) : false;
        if (!$source || !$source->get_id()) {
            return new WP_Error('source_missing', __('A forrás termék nem másolható.', 'mgcf'));
        }

        // WooCommerce's duplicator knows about product-specific data. It is
        // available in admin contexts on most installations; load it lazily.
        if (!class_exists('WC_Admin_Duplicate_Product')) {
            $file = defined('WC_ABSPATH') ? WC_ABSPATH . 'includes/admin/class-wc-admin-duplicate-product.php' : '';
            if ($file !== '' && file_exists($file)) {
                require_once $file;
            }
        }
        $new_id = 0;
        if (class_exists('WC_Admin_Duplicate_Product')) {
            try {
                $duplicator = new WC_Admin_Duplicate_Product();
                if (method_exists($duplicator, 'product_duplicate')) {
                    $duplicate = $duplicator->product_duplicate($source);
                    $new_id = is_object($duplicate) && method_exists($duplicate, 'get_id') ? absint($duplicate->get_id()) : absint($duplicate);
                } elseif (method_exists($duplicator, 'duplicate_product')) {
                    $duplicate = $duplicator->duplicate_product($source);
                    $new_id = is_object($duplicate) && method_exists($duplicate, 'get_id') ? absint($duplicate->get_id()) : absint($duplicate);
                }
            } catch (Throwable $e) {
                $new_id = 0;
            }
        }
        if ($new_id <= 0) {
            $post = get_post($source_id, ARRAY_A);
            if (!is_array($post)) {
                return new WP_Error('copy_failed', __('A forrás termék adatai nem olvashatók.', 'mgcf'));
            }
            unset($post['ID'], $post['post_name'], $post['guid'], $post['post_date'], $post['post_date_gmt'], $post['post_modified'], $post['post_modified_gmt']);
            $post['post_title'] = $name;
            $new_id = wp_insert_post(wp_slash($post), true);
            if (is_wp_error($new_id)) {
                return $new_id;
            }
            $new_id = absint($new_id);
        }
        if ($new_id <= 0) {
            return new WP_Error('copy_failed', __('A termék másolása sikertelen.', 'mgcf'));
        }

        self::copy_taxonomies_and_meta($source_id, $new_id);
        if (class_exists('MG_Custom_Fields_Manager')) {
            $fields = MG_Custom_Fields_Manager::get_fields_for_product($source_id);
            if (!empty($fields)) {
                MG_Custom_Fields_Manager::save_fields_for_product($new_id, $fields);
            }
        }
        return $new_id;
    }

    protected static function copy_taxonomies_and_meta($source_id, $target_id) {
        $exclude = self::excluded_meta_keys();
        $taxonomies = get_object_taxonomies('product');
        foreach ((array) $taxonomies as $taxonomy) {
            $terms = wp_get_object_terms($source_id, $taxonomy, array('fields' => 'ids'));
            if (!is_wp_error($terms) && is_array($terms)) {
                wp_set_object_terms($target_id, array_map('intval', $terms), $taxonomy, false);
            }
        }
        $meta = get_post_meta($source_id);
        foreach ((array) $meta as $key => $values) {
            if (self::is_excluded_meta_key($key, $exclude)) {
                continue;
            }
            delete_post_meta($target_id, $key);
            foreach ((array) $values as $value) {
                add_post_meta($target_id, $key, maybe_unserialize($value));
            }
        }
        foreach ($exclude as $key) {
            delete_post_meta($target_id, $key);
        }
    }

    protected static function excluded_meta_keys() {
        return array(
            '_sku', '_thumbnail_id', '_product_image_gallery', '_edit_lock', '_edit_last',
            '_mg_last_design_attachment', '_mg_last_design_path', '_mg_design_id',
            '_mg_preview_url', '_mg_render_version', self::META_VARIANTS,
            self::META_GROUP, self::META_PARENT, self::META_FIELD, self::META_OPTION_SLUG,
            self::META_OPTION_LABEL, self::META_SOURCE, self::META_BASE_NAME,
        );
    }

    protected static function is_excluded_meta_key($key, $exclude) {
        if (in_array($key, $exclude, true)) {
            return true;
        }
        $key = strtolower((string) $key);
        return strpos($key, 'custom_field_product_variant') !== false || strpos($key, 'cfpv') !== false;
    }

    protected static function prepare_target_product($product, $base_name, $option, $source_id, $group_id, $field_id, $is_source) {
        if (!is_object($product) || !method_exists($product, 'get_id')) {
            return new WP_Error('product_invalid', __('A cél termék objektuma hibás.', 'mgcf'));
        }
        $target_id = absint($product->get_id());
        $name = $base_name . ' – ' . $option['label'];
        if (method_exists($product, 'set_name')) {
            $product->set_name($name);
        }
        $source = function_exists('wc_get_product') ? wc_get_product(absint($source_id)) : null;
        if (!$is_source && $source && method_exists($source, 'get_status') && method_exists($product, 'set_status')) {
            $product->set_status($source->get_status());
        }
        if (method_exists($product, 'set_slug')) {
            $slug_base = sanitize_title($name);
            $status = method_exists($product, 'get_status') ? $product->get_status() : 'publish';
            $slug = wp_unique_post_slug($slug_base, $target_id, $status, 'product', 0);
            if ($slug !== '') {
                $product->set_slug($slug);
            }
        }
        // Existing source products may have no SKU (e.g. imported drafts).
        // The creator method returns an existing SKU when one is already set.
        $saved_id = $product->save();
        if (!$saved_id) {
            return new WP_Error('product_save_failed', __('A kapcsolt termék mentése sikertelen.', 'mgcf'));
        }
        $target_id = absint($saved_id);
        if (!class_exists('MG_Product_Creator')) {
            $file = __DIR__ . '/class-product-creator.php';
            if (file_exists($file)) {
                require_once $file;
            }
        }
        if (class_exists('MG_Product_Creator') && method_exists('MG_Product_Creator', 'generate_product_sku')) {
            $sku = MG_Product_Creator::generate_product_sku($target_id, $name);
            if ($sku === '') {
                return new WP_Error('sku_generation_failed', __('Az SKU létrehozása sikertelen.', 'mgcf'));
            }
            if (method_exists($product, 'set_sku')) {
                $product->set_sku($sku);
                $product->save();
            }
        }

        $attachment_id = absint($option['attachment_id']);
        $path = self::resolve_attachment_path($attachment_id);
        update_post_meta($target_id, '_mg_last_design_attachment', $attachment_id);
        if ($path !== '') {
            update_post_meta($target_id, '_mg_last_design_path', $path);
        } else {
            delete_post_meta($target_id, '_mg_last_design_path');
        }
        if (!$is_source && class_exists('MG_Custom_Fields_Manager')) {
            $source_fields = MG_Custom_Fields_Manager::get_fields_for_product(absint($source_id));
            if (!empty($source_fields)) {
                MG_Custom_Fields_Manager::save_fields_for_product($target_id, $source_fields);
                MG_Custom_Fields_Manager::set_custom_product($target_id, true);
            }
        }
        return array(
            'product_id' => $target_id,
            'slug' => $option['slug'],
            'label' => $option['label'],
            'attachment_id' => $attachment_id,
            'design_path' => $path,
            'is_source' => (bool) $is_source,
        );
    }

    protected static function write_relation_meta($product_id, $group_id, $parent_id, $field_id, $slug, $label) {
        update_post_meta($product_id, self::META_GROUP, sanitize_key($group_id));
        update_post_meta($product_id, self::META_PARENT, absint($parent_id));
        update_post_meta($product_id, self::META_FIELD, sanitize_key($field_id));
        update_post_meta($product_id, self::META_OPTION_SLUG, sanitize_title($slug));
        update_post_meta($product_id, self::META_OPTION_LABEL, sanitize_text_field($label));
        update_post_meta($product_id, self::META_SOURCE, absint($parent_id));
    }

    protected static function get_group_products_by_meta($group_id, $field_id = '') {
        $args = array(
            'post_type' => 'product',
            'post_status' => array('publish', 'pending', 'draft', 'future', 'private'),
            'posts_per_page' => -1,
            'orderby' => 'ID',
            'order' => 'ASC',
            'fields' => 'ids',
            'meta_query' => array(
                array('key' => self::META_GROUP, 'value' => $group_id),
            ),
        );
        if ($field_id !== '') {
            $args['meta_query'][] = array('key' => self::META_FIELD, 'value' => sanitize_key($field_id));
        }
        $ids = get_posts($args);
        $rows = array();
        foreach ((array) $ids as $id) {
            $id = absint($id);
            if ($id <= 0) {
                continue;
            }
            $rows[] = array(
                'product_id' => $id,
                'slug' => sanitize_title(get_post_meta($id, self::META_OPTION_SLUG, true)),
                'label' => sanitize_text_field(get_post_meta($id, self::META_OPTION_LABEL, true)),
                'attachment_id' => absint(get_post_meta($id, '_mg_last_design_attachment', true)),
                'product' => function_exists('wc_get_product') ? wc_get_product($id) : null,
            );
        }
        usort($rows, function($a, $b) {
            return strcmp($a['slug'], $b['slug']);
        });
        return $rows;
    }
}
