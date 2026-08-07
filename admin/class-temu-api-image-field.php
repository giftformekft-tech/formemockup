<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * API-only Temu product image metadata.
 *
 * This class deliberately lives outside the legacy CSV/XLSX exporter so the
 * existing fallback export remains unchanged.
 */
class MG_Temu_API_Image_Field {

    const META_IMAGE_ID = '_mg_temu_common_image_id';

    public static function init() {
        add_action('woocommerce_product_options_general_product_data', [self::class, 'render']);
        add_action('woocommerce_admin_process_product_object', [self::class, 'save']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_media']);
    }

    public static function enqueue_media($hook) {
        if (!in_array($hook, ['post.php', 'post-new.php'], true)) {
            return;
        }
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || $screen->post_type !== 'product') {
            return;
        }
        wp_enqueue_media();
    }

    public static function render() {
        global $post;
        if (!$post || !current_user_can('edit_post', $post->ID)) {
            return;
        }

        $image_id = absint(get_post_meta($post->ID, self::META_IMAGE_ID, true));
        $image_url = $image_id ? wp_get_attachment_image_url($image_id, 'medium') : '';
        wp_nonce_field('mg_temu_api_image', 'mg_temu_api_image_nonce');
        ?>
        <div class="options_group mg-temu-api-image-field">
            <p class="form-field">
                <label><?php esc_html_e('Temu API közös termékkép', 'mockup-generator'); ?></label>
                <span style="display:inline-flex;align-items:flex-start;gap:12px;flex-wrap:wrap;">
                    <span class="mg-temu-api-image-preview" style="display:<?php echo $image_url ? 'block' : 'none'; ?>;">
                        <img src="<?php echo esc_url($image_url); ?>" alt="" style="display:block;width:120px;height:120px;object-fit:cover;border:1px solid #dcdcde;border-radius:6px;" />
                    </span>
                    <span>
                        <input type="hidden" name="<?php echo esc_attr(self::META_IMAGE_ID); ?>" value="<?php echo esc_attr($image_id); ?>" />
                        <button type="button" class="button mg-temu-api-image-select"><?php esc_html_e('Kép kiválasztása', 'mockup-generator'); ?></button>
                        <button type="button" class="button-link-delete mg-temu-api-image-remove" style="display:<?php echo $image_url ? 'inline-block' : 'none'; ?>;margin-left:8px;"><?php esc_html_e('Eltávolítás', 'mockup-generator'); ?></button>
                        <span class="description" style="display:block;max-width:520px;margin-top:8px;"><?php esc_html_e('Csak az új Temu API-export használja; a régi CSV/XLSX export változatlan marad.', 'mockup-generator'); ?></span>
                    </span>
                </span>
            </p>
        </div>
        <script>
        jQuery(function($){
            var $field = $('.mg-temu-api-image-field');
            if (!$field.length || $field.data('mgTemuReady')) return;
            $field.data('mgTemuReady', true);
            $field.on('click', '.mg-temu-api-image-select', function(event){
                event.preventDefault();
                var frame = wp.media({title: '<?php echo esc_js(__('Temu API közös termékkép', 'mockup-generator')); ?>', button: {text: '<?php echo esc_js(__('Kép használata', 'mockup-generator')); ?>'}, multiple: false, library: {type: 'image'}});
                frame.on('select', function(){
                    var image = frame.state().get('selection').first().toJSON();
                    var preview = image.sizes && image.sizes.medium ? image.sizes.medium.url : image.url;
                    $field.find('input[type="hidden"]').val(image.id);
                    $field.find('.mg-temu-api-image-preview').show().find('img').attr('src', preview);
                    $field.find('.mg-temu-api-image-remove').show();
                });
                frame.open();
            });
            $field.on('click', '.mg-temu-api-image-remove', function(event){
                event.preventDefault();
                $field.find('input[type="hidden"]').val('');
                $field.find('.mg-temu-api-image-preview').hide().find('img').attr('src', '');
                $(this).hide();
            });
        });
        </script>
        <?php
    }

    public static function save($product) {
        if (!isset($_POST['mg_temu_api_image_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['mg_temu_api_image_nonce'])), 'mg_temu_api_image')) {
            return;
        }
        $image_id = isset($_POST[self::META_IMAGE_ID]) ? absint($_POST[self::META_IMAGE_ID]) : 0;
        if ($image_id && wp_attachment_is_image($image_id)) {
            $product->update_meta_data(self::META_IMAGE_ID, $image_id);
        } else {
            $product->delete_meta_data(self::META_IMAGE_ID);
        }
    }

    public static function get_image_url($product_id) {
        $image_id = absint(get_post_meta($product_id, self::META_IMAGE_ID, true));
        return $image_id ? (string) wp_get_attachment_url($image_id) : '';
    }
}
