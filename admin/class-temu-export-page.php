<?php
if (!defined('ABSPATH')) {
    exit;
}

class MG_Temu_Export_Page {

    const META_COMMON_IMAGE_ID = '_mg_temu_common_image_id';

    public static function init() {
        add_action('wp_ajax_mg_temu_get_products', [self::class, 'ajax_get_products']);
        add_action('wp_ajax_mg_temu_get_variants', [self::class, 'ajax_get_variants']);
        add_action('wp_ajax_mg_temu_generate_export', [self::class, 'ajax_generate_export']);
        add_action('wp_ajax_mg_temu_mark_exported', [self::class, 'ajax_mark_exported']);
        add_action('wp_ajax_mg_temu_save_name_suffix', [self::class, 'ajax_save_name_suffix']);
        // Temu XLSX export (sebészi ZIP-módszer, lásd MG_Temu_Xlsx_Writer)
        add_action('wp_ajax_mg_temu_generate_xlsx', [self::class, 'ajax_generate_xlsx']);
        add_action('admin_post_mg_temu_download_xlsx', [self::class, 'handle_download_xlsx']);
        add_action('admin_post_mg_temu_upload_template', [self::class, 'handle_upload_template']);
        add_action('admin_post_mg_temu_delete_template', [self::class, 'handle_delete_template']);
        add_action('wp_ajax_mg_temu_save_bullets', [self::class, 'ajax_save_bullets']);
        add_action('woocommerce_product_options_general_product_data', [self::class, 'render_common_image_field']);
        add_action('woocommerce_admin_process_product_object', [self::class, 'save_common_image_field']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_product_media_picker']);
    }

    /**
     * Betölti a WordPress médiaválasztót a WooCommerce termékszerkesztőn.
     */
    public static function enqueue_product_media_picker($hook) {
        if (!in_array($hook, ['post.php', 'post-new.php'], true)) {
            return;
        }
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || $screen->post_type !== 'product') {
            return;
        }
        wp_enqueue_media();
    }

    /**
     * Termékszintű, minden Temu-variánsnál közösen használt galériakép.
     */
    public static function render_common_image_field() {
        global $post;
        if (!$post || !current_user_can('edit_post', $post->ID)) {
            return;
        }

        $image_id = absint(get_post_meta($post->ID, self::META_COMMON_IMAGE_ID, true));
        $image_url = $image_id ? wp_get_attachment_image_url($image_id, 'medium') : '';
        wp_nonce_field('mg_temu_common_image', 'mg_temu_common_image_nonce');
        ?>
        <div class="options_group mg-temu-common-image-field">
            <p class="form-field">
                <label><?php esc_html_e('Temu közös termékkép', 'mockup-generator'); ?></label>
                <span style="display:inline-flex;align-items:flex-start;gap:12px;flex-wrap:wrap;">
                    <span class="mg-temu-common-image-preview" style="display:<?php echo $image_url ? 'block' : 'none'; ?>;">
                        <img src="<?php echo esc_url($image_url); ?>" alt="" style="display:block;width:120px;height:120px;object-fit:cover;border:1px solid #dcdcde;border-radius:6px;" />
                    </span>
                    <span>
                        <input type="hidden" name="<?php echo esc_attr(self::META_COMMON_IMAGE_ID); ?>" value="<?php echo esc_attr($image_id); ?>" />
                        <button type="button" class="button mg-temu-select-common-image"><?php esc_html_e('Kép kiválasztása', 'mockup-generator'); ?></button>
                        <button type="button" class="button-link-delete mg-temu-remove-common-image" style="display:<?php echo $image_url ? 'inline-block' : 'none'; ?>;margin-left:8px;"><?php esc_html_e('Eltávolítás', 'mockup-generator'); ?></button>
                        <span class="description" style="display:block;max-width:520px;margin-top:8px;"><?php esc_html_e('Nem szín- vagy méretfüggő kép. A Temu export minden kiválasztott variánsához ezt az egy közös galériaképet adja.', 'mockup-generator'); ?></span>
                    </span>
                </span>
            </p>
        </div>
        <script>
        jQuery(function($){
            var $field = $('.mg-temu-common-image-field');
            if (!$field.length || $field.data('mgTemuReady')) return;
            $field.data('mgTemuReady', true);
            $field.on('click', '.mg-temu-select-common-image', function(event){
                event.preventDefault();
                var frame = wp.media({title: '<?php echo esc_js(__('Temu közös termékkép', 'mockup-generator')); ?>', button: {text: '<?php echo esc_js(__('Kép használata', 'mockup-generator')); ?>'}, multiple: false, library: {type: 'image'}});
                frame.on('select', function(){
                    var image = frame.state().get('selection').first().toJSON();
                    var preview = image.sizes && image.sizes.medium ? image.sizes.medium.url : image.url;
                    $field.find('input[type="hidden"]').val(image.id);
                    $field.find('.mg-temu-common-image-preview').show().find('img').attr('src', preview);
                    $field.find('.mg-temu-remove-common-image').show();
                });
                frame.open();
            });
            $field.on('click', '.mg-temu-remove-common-image', function(event){
                event.preventDefault();
                $field.find('input[type="hidden"]').val('');
                $field.find('.mg-temu-common-image-preview').hide().find('img').attr('src', '');
                $(this).hide();
            });
        });
        </script>
        <?php
    }

    public static function save_common_image_field($product) {
        if (!isset($_POST['mg_temu_common_image_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['mg_temu_common_image_nonce'])), 'mg_temu_common_image')) {
            return;
        }
        $image_id = isset($_POST[self::META_COMMON_IMAGE_ID]) ? absint($_POST[self::META_COMMON_IMAGE_ID]) : 0;
        if ($image_id && wp_attachment_is_image($image_id)) {
            $product->update_meta_data(self::META_COMMON_IMAGE_ID, $image_id);
        } else {
            $product->delete_meta_data(self::META_COMMON_IMAGE_ID);
        }
    }

    public static function get_common_image_url($product_id) {
        $image_id = absint(get_post_meta($product_id, self::META_COMMON_IMAGE_ID, true));
        return $image_id ? (string) wp_get_attachment_url($image_id) : '';
    }

    public static function render_page() {
        ?>
        <?php
        // A sablon-feltöltés visszajelzése a beállítások blokkban jelenik meg,
        // ezért ha van ilyen, nyitva indul a panel.
        $settings_open = isset($_GET['mg_temu_tpl']) && $_GET['mg_temu_tpl'] !== '';
        ?>
        <div class="mg-panel-body mg-panel-body--temu-export">
            <section class="mg-panel-section">
                <div class="mg-panel-section__header mg-temu-header">
                    <div>
                        <h2><?php esc_html_e('Temu Export', 'mockup-generator'); ?></h2>
                        <p><?php esc_html_e('Generálj Temu-kompatibilis export fájlt a termékeidből két egyszerű lépésben.', 'mockup-generator'); ?></p>
                    </div>
                    <button type="button" class="button<?php echo $settings_open ? ' is-open' : ''; ?>" id="mg-temu-settings-toggle" aria-expanded="<?php echo $settings_open ? 'true' : 'false'; ?>" aria-controls="mg-temu-settings">
                        <span class="dashicons dashicons-admin-generic" aria-hidden="true" style="vertical-align:-4px;margin-right:4px;"></span><?php esc_html_e('Beállítások', 'mockup-generator'); ?>
                    </button>
                </div>

                <div id="mg-temu-settings" class="mg-temu-settings"<?php echo $settings_open ? '' : ' hidden'; ?>>
                <?php
                // Névhez fűzendő egyedi mező: típusonként külön érték adható meg,
                // az üresen hagyott típusokra az alap érték vonatkozik.
                $suffix_types = get_option('mg_temu_name_suffix_types', []);
                if (!is_array($suffix_types)) {
                    $suffix_types = [];
                }
                ?>
                <div style="margin-bottom:16px;padding:10px 14px;background:#fff;border:1px solid #ddd;border-radius:8px;">
                    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:8px;">
                        <strong><?php esc_html_e('Névhez hozzáfűzendő egyedi mező', 'mockup-generator'); ?></strong>
                        <span style="color:#888;font-size:12px;"><?php esc_html_e('Exportban: Terméknév + típus + ez a mező. Ha egy típusnál üres, az alap érték kerül a névbe.', 'mockup-generator'); ?></span>
                        <button type="button" class="button" id="mg-temu-save-suffix"><?php esc_html_e('Mentés', 'mockup-generator'); ?></button>
                        <span id="mg-temu-suffix-status" style="font-style:italic;color:#666;font-size:12px;"></span>
                    </div>
                    <table class="widefat striped" style="max-width:640px;">
                        <tbody>
                            <tr>
                                <td style="white-space:nowrap;width:220px;"><?php esc_html_e('Alap (minden típus)', 'mockup-generator'); ?></td>
                                <td><input type="text" id="mg-temu-name-suffix" value="<?php echo esc_attr(get_option('mg_temu_name_suffix', '')); ?>" style="width:100%;max-width:360px;" placeholder="pl. Hungary" /></td>
                            </tr>
                            <?php foreach (self::get_all_types() as $type_slug => $type_label): ?>
                            <tr>
                                <td style="white-space:nowrap;"><?php echo esc_html($type_label); ?></td>
                                <td><input type="text" class="mg-temu-name-suffix-type" data-type="<?php echo esc_attr($type_slug); ?>" value="<?php echo esc_attr(isset($suffix_types[$type_slug]) ? $suffix_types[$type_slug] : ''); ?>" style="width:100%;max-width:360px;" placeholder="<?php esc_attr_e('üres = alap érték', 'mockup-generator'); ?>" /></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php
                // --- Temu Bullet Pointok kategóriánként ---
                // A sablon 6 "Bullet Point" oszlopába kerülő rövid termékkiemelők.
                // Az itt beállított értékek a termék WooCommerce-kategóriája
                // alapján kerülnek minden exportsorba; ha egy kategóriához nincs
                // beállítás, a mestersablon mintasorának értéke másolódik.
                $bullet_map = get_option('mg_temu_bullet_points', []);
                if (!is_array($bullet_map)) {
                    $bullet_map = [];
                }
                $all_cats = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false, 'orderby' => 'name']);
                $cats_by_parent = [];
                foreach ((array) $all_cats as $ct) {
                    $cats_by_parent[$ct->parent][] = $ct;
                }
                $cat_options = [];
                $walk_cats = function ($parent, $depth) use (&$walk_cats, &$cat_options, $cats_by_parent) {
                    if (empty($cats_by_parent[$parent])) {
                        return;
                    }
                    foreach ($cats_by_parent[$parent] as $ct) {
                        $cat_options[] = ['id' => $ct->term_id, 'label' => str_repeat('— ', $depth) . $ct->name];
                        $walk_cats($ct->term_id, $depth + 1);
                    }
                };
                $walk_cats(0, 0);
                ?>
                <div style="margin-bottom:16px;padding:10px 14px;background:#fff;border:1px solid #ddd;border-radius:8px;">
                    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:8px;">
                        <strong><?php esc_html_e('Temu Bullet Pointok (kategóriánként)', 'mockup-generator'); ?></strong>
                        <span style="color:#888;font-size:12px;"><?php esc_html_e('Max. 6 rövid termékkiemelő mondat az XLSX "Bullet Point" oszlopaiba. A termék kategóriája alapján kerül be; ha üres, a mestersablon mintasora számít.', 'mockup-generator'); ?></span>
                    </div>
                    <div style="display:flex;align-items:flex-start;gap:16px;flex-wrap:wrap;">
                        <div style="min-width:320px;flex:1;max-width:560px;">
                            <select id="mg-temu-bullet-cat" style="width:100%;margin-bottom:6px;">
                                <option value=""><?php esc_html_e('— válassz kategóriát —', 'mockup-generator'); ?></option>
                                <?php foreach ($cat_options as $opt): ?>
                                    <option value="<?php echo esc_attr($opt['id']); ?>"><?php echo esc_html($opt['label']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php for ($bi = 1; $bi <= 6; $bi++): ?>
                                <input type="text" class="mg-temu-bullet-input" data-idx="<?php echo $bi; ?>" maxlength="200" style="width:100%;margin-bottom:4px;" placeholder="<?php echo esc_attr(sprintf(__('%d. bullet point (pl. 100%% prémium pamut a kényelemért.)', 'mockup-generator'), $bi)); ?>" disabled>
                            <?php endfor; ?>
                            <div style="display:flex;align-items:center;gap:8px;">
                                <button type="button" class="button" id="mg-temu-save-bullets" disabled><?php esc_html_e('Bullet pointok mentése', 'mockup-generator'); ?></button>
                                <span id="mg-temu-bullet-status" style="font-style:italic;color:#666;font-size:12px;"></span>
                            </div>
                        </div>
                        <div style="min-width:260px;flex:1;">
                            <div style="font-weight:600;margin-bottom:4px;"><?php esc_html_e('Beállított kategóriák:', 'mockup-generator'); ?></div>
                            <ul id="mg-temu-bullet-list" style="margin:0;color:#555;font-size:12px;"></ul>
                        </div>
                    </div>
                </div>

                <?php
                // --- Temu XLSX mestersablonok: alap + terméktípusonként külön ---
                $tpl_meta_all = self::get_template_meta_all();
                $tpl_notice = isset($_GET['mg_temu_tpl']) ? sanitize_key(wp_unslash($_GET['mg_temu_tpl'])) : '';
                $tpl_msg    = isset($_GET['mg_temu_msg']) ? sanitize_text_field(rawurldecode(wp_unslash($_GET['mg_temu_msg']))) : '';
                ?>
                <div style="margin-bottom:16px;padding:10px 14px;background:#fff;border:1px solid #ddd;border-radius:8px;">
                    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:8px;">
                        <strong><?php esc_html_e('Temu XLSX mestersablonok', 'mockup-generator'); ?></strong>
                        <span style="color:#888;font-size:12px;"><?php esc_html_e('Minden virtuális terméktípushoz saját sablon tölthető fel; ha egy típusnak nincs sajátja, az alap sablont használja az export.', 'mockup-generator'); ?></span>
                        <?php if ($tpl_notice === 'ok'): ?>
                            <span style="color:#1a7a35;font-size:12px;font-weight:600;">✓ <?php esc_html_e('Sablon elmentve.', 'mockup-generator'); ?></span>
                        <?php elseif ($tpl_notice === 'del'): ?>
                            <span style="color:#1a7a35;font-size:12px;font-weight:600;">✓ <?php esc_html_e('Sablon törölve.', 'mockup-generator'); ?></span>
                        <?php elseif ($tpl_notice === 'err'): ?>
                            <span style="color:#d63638;font-size:12px;font-weight:600;"><?php echo esc_html($tpl_msg !== '' ? $tpl_msg : __('A sablon feltöltése nem sikerült.', 'mockup-generator')); ?></span>
                        <?php endif; ?>
                    </div>
                    <table class="widefat striped" style="max-width:900px;">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('Terméktípus', 'mockup-generator'); ?></th>
                                <th><?php esc_html_e('Sablon', 'mockup-generator'); ?></th>
                                <th><?php esc_html_e('Feltöltés / csere', 'mockup-generator'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach (self::get_template_slots() as $slot => $slot_label):
                                $slot_exists = file_exists(self::get_template_path($slot));
                                $slot_meta = isset($tpl_meta_all[$slot]) ? $tpl_meta_all[$slot] : [];
                            ?>
                            <tr>
                                <td style="white-space:nowrap;"><?php echo esc_html($slot_label); ?></td>
                                <td>
                                    <?php if ($slot_exists): ?>
                                        <span style="color:#1a7a35;font-size:12px;">✓ <?php
                                            echo esc_html(!empty($slot_meta['name']) ? $slot_meta['name'] : basename(self::get_template_path($slot)));
                                            if (!empty($slot_meta['uploaded'])) {
                                                echo ' — ' . esc_html($slot_meta['uploaded']);
                                            }
                                        ?></span>
                                    <?php else: ?>
                                        <span style="color:#888;font-size:12px;">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                                        <form method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:flex;align-items:center;gap:8px;margin:0;">
                                            <input type="hidden" name="action" value="mg_temu_upload_template">
                                            <input type="hidden" name="mg_temu_slot" value="<?php echo esc_attr($slot); ?>">
                                            <?php wp_nonce_field('mg_temu_upload_template'); ?>
                                            <input type="file" name="mg_temu_template" accept=".xlsx" required>
                                            <button type="submit" class="button"><?php esc_html_e('Feltöltés', 'mockup-generator'); ?></button>
                                        </form>
                                        <?php if ($slot_exists): ?>
                                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin:0;" onsubmit="return confirm('<?php echo esc_js(__('Biztosan törlöd ezt a sablont?', 'mockup-generator')); ?>');">
                                            <input type="hidden" name="action" value="mg_temu_delete_template">
                                            <input type="hidden" name="mg_temu_slot" value="<?php echo esc_attr($slot); ?>">
                                            <?php wp_nonce_field('mg_temu_delete_template'); ?>
                                            <button type="submit" class="button button-link-delete"><?php esc_html_e('Törlés', 'mockup-generator'); ?></button>
                                        </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                </div><!-- /#mg-temu-settings -->

                <script>
                jQuery(function ($) {
                    $('#mg-temu-settings-toggle').on('click', function () {
                        var panel = document.getElementById('mg-temu-settings');
                        if (!panel) {
                            return;
                        }
                        var isHidden = panel.hasAttribute('hidden');
                        if (isHidden) {
                            panel.removeAttribute('hidden');
                        } else {
                            panel.setAttribute('hidden', 'hidden');
                        }
                        $(this).toggleClass('is-open', isHidden).attr('aria-expanded', isHidden ? 'true' : 'false');
                    });
                });
                </script>

                <div id="mg-temu-app" class="mg-temu-app">
                    <!-- Step 1: Product Selection -->
                    <div id="mg-temu-step-1" class="mg-temu-step">
                        <div class="mg-temu-toolbar" style="flex-wrap:wrap;gap:10px;">
                            <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap;">
                                <label><?php esc_html_e('Termékek oldalanként:', 'mockup-generator'); ?>
                                    <select id="mg-temu-per-page">
                                        <option value="25">25</option>
                                        <option value="50">50</option>
                                        <option value="100">100</option>
                                    </select>
                                </label>
                                <label><?php esc_html_e('Terméktípus:', 'mockup-generator'); ?>
                                    <select id="mg-temu-filter-type">
                                        <option value=""><?php esc_html_e('— mind —', 'mockup-generator'); ?></option>
                                        <?php foreach (self::get_all_types() as $type_slug => $type_label): ?>
                                            <option value="<?php echo esc_attr($type_slug); ?>"><?php echo esc_html($type_label); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <label style="display:flex;align-items:center;gap:6px;font-weight:600;color:#1a7a35;cursor:pointer;">
                                    <input type="checkbox" id="mg-temu-filter-unexported">
                                    <?php esc_html_e('Csak nem exportált', 'mockup-generator'); ?>
                                </label>
                                <label><?php esc_html_e('Főkategória:', 'mockup-generator'); ?>
                                    <select id="mg-temu-filter-parent-cat">
                                        <option value=""><?php esc_html_e('— mind —', 'mockup-generator'); ?></option>
                                        <?php
                                        $parent_cats = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false, 'parent' => 0, 'orderby' => 'name']);
                                        foreach ($parent_cats as $cat) {
                                            echo '<option value="' . esc_attr($cat->term_id) . '">' . esc_html($cat->name) . '</option>';
                                        }
                                        ?>
                                    </select>
                                </label>
                                <label id="mg-temu-subcat-wrap" style="display:none;"><?php esc_html_e('Alkategória:', 'mockup-generator'); ?>
                                    <select id="mg-temu-filter-child-cat">
                                        <option value=""><?php esc_html_e('— mind —', 'mockup-generator'); ?></option>
                                    </select>
                                </label>
                            </div>
                            <div class="mg-temu-actions">
                                <button type="button" class="button" id="mg-temu-select-all-page"><?php esc_html_e('Összes kijelölése az oldalon', 'mockup-generator'); ?></button>
                                <button type="button" class="button" id="mg-temu-mark-exported" style="display:none;"><?php esc_html_e('✔ Megjelölés: exportálva', 'mockup-generator'); ?></button>
                                <button type="button" class="button button-primary" id="mg-temu-next-step"><?php esc_html_e('Tovább a variációkhoz', 'mockup-generator'); ?></button>
                            </div>
                        </div>

                        <div class="mg-table-wrap">
                            <table class="widefat fixed striped">
                                <thead>
                                    <tr>
                                        <td id="cb" class="manage-column column-cb check-column">
                                            <input id="cb-select-all-1" type="checkbox">
                                        </td>
                                                        <th><?php esc_html_e('Kép', 'mockup-generator'); ?></th>
                                        <th><?php esc_html_e('Terméknév', 'mockup-generator'); ?></th>
                                        <th><?php esc_html_e('Base SKU', 'mockup-generator'); ?></th>
                                        <th><?php esc_html_e('Kategória', 'mockup-generator'); ?></th>
                                        <th><?php esc_html_e('Temu export (típusonként)', 'mockup-generator'); ?></th>
                                    </tr>
                                </thead>
                                <tbody id="mg-temu-product-list">
                                    <tr><td colspan="5"><?php esc_html_e('Betöltés...', 'mockup-generator'); ?></td></tr>
                                </tbody>
                            </table>
                        </div>
                        
                        <div class="mg-temu-pagination-controls" id="mg-temu-pagination-controls"></div>
                    </div>

                    <!-- Step 2: Variant Selection -->
                    <div id="mg-temu-step-2" class="mg-temu-step" style="display:none;">
                         <div class="mg-temu-toolbar">
                            <button type="button" class="button" id="mg-temu-back-step"><?php esc_html_e('« Vissza a termékekhez', 'mockup-generator'); ?></button>
                            <div style="display:flex;gap:8px;">
                                <button type="button" class="button button-primary" id="mg-temu-generate"><?php esc_html_e('CSV Export Generálása', 'mockup-generator'); ?></button>
                                <button type="button" class="button button-primary" id="mg-temu-generate-xlsx"><?php esc_html_e('Temu XLSX letöltése', 'mockup-generator'); ?></button>
                            </div>
                        </div>

                        <div id="mg-temu-xlsx-results" style="display:none;margin-bottom:15px;padding:10px 14px;background:#fff;border:1px solid #ddd;border-radius:8px;"></div>
                        
                        <div id="mg-temu-variant-list"></div>
                    </div>
                </div>
            </section>
        </div>
        
        <?php self::render_scripts(); ?>
        <style>
            .mg-temu-toolbar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; background: #fff; padding: 10px; border-radius: 8px; border: 1px solid #ddd; }
            .mg-temu-step { animation: fadeIn 0.3s ease; }
            .mg-temu-variant-group { background: #fff; border: 1px solid #ddd; border-radius: 8px; margin-bottom: 15px; overflow: hidden; }
            .mg-temu-variant-header { background: #f0f0f1; padding: 10px 15px; font-weight: bold; display: flex; align-items: center; justify-content: space-between; }
            .mg-temu-variant-body { padding: 15px; }
            .mg-temu-variant-row { display: flex; align-items: center; gap: 10px; padding: 5px 0; border-bottom: 1px solid #eee; }
            .mg-temu-variant-row:last-child { border-bottom: none; }
            .mg-temu-pagination-controls { display: flex; justify-content: center; gap: 5px; margin-top: 15px; }
            .mg-temu-pagination-controls button { min-width: 30px; }
            .mg-chip { display: inline-block; padding: 2px 6px; background: #eee; border-radius: 4px; font-size: 11px; margin-right: 4px; }
            .mg-temu-exported-badge { display: inline-block; padding: 2px 7px; background: #d7f5de; color: #1a7a35; border: 1px solid #a8d5b5; border-radius: 3px; font-size: 11px; font-weight: 600; white-space: nowrap; }
            .mg-temu-type-chip { display: inline-block; padding: 2px 7px; margin: 0 2px 3px 0; background: #f1f1f1; color: #777; border: 1px solid #ddd; border-radius: 3px; font-size: 11px; white-space: nowrap; }
            .mg-temu-type-chip.is-exported { background: #d7f5de; color: #1a7a35; border-color: #a8d5b5; font-weight: 600; }
            @keyframes fadeIn { from { opacity: 0; transform: translateY(5px); } to { opacity: 1; transform: translateY(0); } }
        </style>
        <?php
    }

    public static function render_scripts() {
        ?>
        <script>
        // Alkategóriák PHP-ból
        var mgTemuChildCats = <?php
            $all_children = [];
            $child_terms = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false, 'parent__not_in' => [0]]);
            foreach ((array)$child_terms as $ct) {
                $all_children[$ct->parent][] = ['id' => $ct->term_id, 'name' => $ct->name];
            }
            echo wp_json_encode($all_children);
        ?>;

        var mgTemuBullets = <?php
            $bm = get_option('mg_temu_bullet_points', []);
            echo wp_json_encode(is_array($bm) ? (object) $bm : new stdClass());
        ?>;

        jQuery(document).ready(function($) {
            let currentPage = 1;
            let productsPerPage = 25;
            let selectedProducts = {};

            // --- Bullet pointok kategóriánként ---
            function refreshBulletList() {
                let html = '';
                $('#mg-temu-bullet-cat option').each(function() {
                    const tid = $(this).val();
                    if (tid && mgTemuBullets[tid] && mgTemuBullets[tid].some(v => v && v.length)) {
                        const count = mgTemuBullets[tid].filter(v => v && v.length).length;
                        html += '<li>' + $(this).text().replace(/^(— )+/, '') + ' <span style="color:#999">(' + count + ' db)</span></li>';
                    }
                });
                $('#mg-temu-bullet-list').html(html || '<li style="color:#aaa;"><?php echo esc_js(__('még nincs', 'mockup-generator')); ?></li>');
            }

            $('#mg-temu-bullet-cat').on('change', function() {
                const tid = $(this).val();
                const enabled = !!tid;
                $('.mg-temu-bullet-input').prop('disabled', !enabled);
                $('#mg-temu-save-bullets').prop('disabled', !enabled);
                const vals = (tid && mgTemuBullets[tid]) ? mgTemuBullets[tid] : [];
                $('.mg-temu-bullet-input').each(function() {
                    $(this).val(vals[$(this).data('idx') - 1] || '');
                });
            });

            $('#mg-temu-save-bullets').on('click', function() {
                const tid = $('#mg-temu-bullet-cat').val();
                if (!tid) return;
                const bullets = [];
                $('.mg-temu-bullet-input').each(function() {
                    bullets[$(this).data('idx') - 1] = $(this).val();
                });
                const $btn = $(this).prop('disabled', true).text('Mentés...');
                $('#mg-temu-bullet-status').text('');
                $.post(ajaxurl, {
                    action: 'mg_temu_save_bullets',
                    term_id: tid,
                    bullets: JSON.stringify(bullets),
                    nonce: '<?php echo wp_create_nonce('mg_temu_nonce'); ?>'
                }, function(resp) {
                    $btn.prop('disabled', false).text('<?php echo esc_js(__('Bullet pointok mentése', 'mockup-generator')); ?>');
                    if (resp.success) {
                        if (resp.data && resp.data.cleared) {
                            delete mgTemuBullets[tid];
                        } else {
                            mgTemuBullets[tid] = bullets;
                        }
                        refreshBulletList();
                        $('#mg-temu-bullet-status').text('✓ Elmentve').css('color', '#1a7a35');
                        setTimeout(() => $('#mg-temu-bullet-status').text(''), 3000);
                    } else {
                        $('#mg-temu-bullet-status').text('Hiba!').css('color', '#d63638');
                    }
                }).fail(function() {
                    $btn.prop('disabled', false).text('<?php echo esc_js(__('Bullet pointok mentése', 'mockup-generator')); ?>');
                    $('#mg-temu-bullet-status').text('Kommunikációs hiba.').css('color', '#d63638');
                });
            });

            refreshBulletList();

            // --- Egyedi névmező mentése (alap + típusonként) ---
            $('#mg-temu-save-suffix').on('click', function() {
                const val = $('#mg-temu-name-suffix').val();
                const types = {};
                $('.mg-temu-name-suffix-type').each(function() {
                    types[$(this).data('type')] = $(this).val();
                });
                const $btn = $(this).prop('disabled', true).text('Mentés...');
                $('#mg-temu-suffix-status').text('');
                $.post(ajaxurl, {
                    action: 'mg_temu_save_name_suffix',
                    value: val,
                    types: JSON.stringify(types),
                    nonce: '<?php echo wp_create_nonce('mg_temu_nonce'); ?>'
                }, function(resp) {
                    $btn.prop('disabled', false).text('Mentés');
                    if (resp.success) {
                        $('#mg-temu-suffix-status').text('✓ Elmentve').css('color', '#1a7a35');
                        setTimeout(() => $('#mg-temu-suffix-status').text(''), 3000);
                    } else {
                        $('#mg-temu-suffix-status').text('Hiba!').css('color', '#d63638');
                    }
                }).fail(function() {
                    $btn.prop('disabled', false).text('Mentés');
                    $('#mg-temu-suffix-status').text('Kommunikációs hiba.').css('color', '#d63638');
                });
            });

            // --- Step 1: Product List ---

            $('#mg-temu-filter-unexported').on('change', function() {
                loadProducts(1);
            });

            $('#mg-temu-filter-type').on('change', function() {
                loadProducts(1);
            });

            function loadProducts(page) {
                currentPage = page;
                productsPerPage = $('#mg-temu-per-page').val();
                const onlyUnexported = $('#mg-temu-filter-unexported').is(':checked') ? 1 : 0;
                const typeFilter = $('#mg-temu-filter-type').val() || '';
                const childCat  = $('#mg-temu-filter-child-cat').val();
                const parentCat = $('#mg-temu-filter-parent-cat').val();
                const categoryId = childCat || parentCat || '';

                $('#mg-temu-product-list').html('<tr><td colspan="6">Betöltés...</td></tr>');

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'mg_temu_get_products',
                        page: currentPage,
                        per_page: productsPerPage,
                        only_unexported: onlyUnexported,
                        type_filter: typeFilter,
                        category_id: categoryId,
                        nonce: '<?php echo wp_create_nonce('mg_temu_nonce'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            renderProductList(response.data.products, response.data.total_pages);
                        } else {
                            $('#mg-temu-product-list').html('<tr><td colspan="5">Hiba: ' + response.data + '</td></tr>');
                        }
                    },
                    error: function() {
                        $('#mg-temu-product-list').html('<tr><td colspan="5">Kommunikációs hiba.</td></tr>');
                    }
                });
            }

            function renderProductList(products, totalPages) {
                let html = '';
                if (products.length === 0) {
                     html = '<tr><td colspan="5">Nincs megjeleníthető termék.</td></tr>';
                } else {
                    products.forEach(p => {
                        const isChecked = selectedProducts[p.id] ? 'checked' : '';
                        let typesHtml;
                        if (p.types && p.types.length) {
                            typesHtml = p.types.map(t => {
                                if (t.exported_at) {
                                    return `<span class="mg-temu-type-chip is-exported" title="Exportálva: ${t.exported_at}">${t.label} ✓</span>`;
                                }
                                return `<span class="mg-temu-type-chip">${t.label}</span>`;
                            }).join(' ');
                        } else {
                            typesHtml = '<span style="color:#aaa;font-size:11px;">—</span>';
                        }
                        html += `
                            <tr>
                                <th scope="row" class="check-column">
                                    <input type="checkbox" class="mg-temu-prod-cb" value="${p.id}" ${isChecked}>
                                </th>
                                <td><img src="${p.image}" width="80" height="80" style="border-radius:4px;object-fit:cover;"></td>
                                <td><strong>${p.name}</strong></td>
                                <td>${p.sku}</td>
                                <td>${p.category}</td>
                                <td>${typesHtml}</td>
                            </tr>
                        `;
                    });
                }
                $('#mg-temu-product-list').html(html);
                renderPagination(totalPages);
            }

            function renderPagination(totalPages) {
                let html = '';
                if (totalPages > 1) {
                    if (currentPage > 1) html += `<button type="button" class="button mg-temu-page-btn" data-page="${currentPage - 1}">«</button>`;
                    
                    for (let i = 1; i <= totalPages; i++) {
                         let activeClass = (i === currentPage) ? 'button-primary' : 'button';
                         if (i <= 3 || i >= totalPages - 2 || (i >= currentPage - 1 && i <= currentPage + 1)) {
                             html += `<button type="button" class="button mg-temu-page-btn ${activeClass}" data-page="${i}">${i}</button>`;
                         } else if (html.slice(-3) !== '...') {
                             html += '...';
                         }
                    }

                    if (currentPage < totalPages) html += `<button type="button" class="button mg-temu-page-btn" data-page="${currentPage + 1}">»</button>`;
                }
                $('#mg-temu-pagination-controls').html(html);
            }

            // --- Events Step 1 ---

            $('#mg-temu-per-page').on('change', function() { loadProducts(1); });

            $('#mg-temu-filter-parent-cat').on('change', function() {
                const parentId = $(this).val();
                const children = parentId && mgTemuChildCats[parentId] ? mgTemuChildCats[parentId] : [];
                let opts = '<option value=""><?php echo esc_js(__('— mind —', 'mockup-generator')); ?></option>';
                children.forEach(c => { opts += `<option value="${c.id}">${c.name}</option>`; });
                $('#mg-temu-filter-child-cat').html(opts);
                $('#mg-temu-subcat-wrap').toggle(children.length > 0);
                loadProducts(1);
            });

            $('#mg-temu-filter-child-cat').on('change', function() { loadProducts(1); });

            $(document).on('click', '.mg-temu-page-btn', function() {
                loadProducts($(this).data('page'));
            });

            $(document).on('change', '.mg-temu-prod-cb', function() {
                const pid = $(this).val();
                if ($(this).is(':checked')) {
                    selectedProducts[pid] = true;
                } else {
                    delete selectedProducts[pid];
                }
                const count = Object.keys(selectedProducts).length;
                $('#mg-temu-mark-exported').toggle(count > 0).text(`✔ Megjelölés: exportálva (${count})`);
            });
            
             $('#mg-temu-select-all-page').on('click', function() {
                $('.mg-temu-prod-cb').prop('checked', true).trigger('change');
            });
            
            $('#cb-select-all-1').on('click', function() {
                const checked = $(this).is(':checked');
                $('.mg-temu-prod-cb').prop('checked', checked).trigger('change');
            });


            // --- Manuális exportálva jelölés ---

            $('#mg-temu-mark-exported').on('click', function() {
                const ids = Object.keys(selectedProducts);
                if (ids.length === 0) return;
                if (!confirm(ids.length + ' terméket exportáltként jelölsz meg. Folytatod?')) return;

                const $btn = $(this).prop('disabled', true).text('Mentés...');

                $.post(ajaxurl, {
                    action: 'mg_temu_mark_exported',
                    ids: ids,
                    nonce: '<?php echo wp_create_nonce('mg_temu_nonce'); ?>'
                }, function(resp) {
                    $btn.prop('disabled', false);
                    if (!resp.success) { alert('Hiba: ' + (resp.data || 'ismeretlen')); return; }
                    selectedProducts = {};
                    $btn.hide();
                    loadProducts(currentPage);
                }).fail(function() {
                    $btn.prop('disabled', false).text('✔ Megjelölés: exportálva');
                    alert('Kommunikációs hiba.');
                });
            });

            // --- Step 2: Variant Selection ---

            $('#mg-temu-next-step').on('click', function() {
                const pids = Object.keys(selectedProducts);
                if (pids.length === 0) {
                    alert('Kérlek válassz legalább egy terméket!');
                    return;
                }
                
                $('#mg-temu-step-1').hide();
                $('#mg-temu-step-2').show();
                loadVariants(pids);
            });
            
            $('#mg-temu-back-step').on('click', function() {
                $('#mg-temu-step-2').hide();
                $('#mg-temu-step-1').show();
            });

            function loadVariants(pids) {
                 $('#mg-temu-variant-list').html('<p style="padding:20px;text-align:center;">Virtuális variációk letöltése és elemzése...</p>');
                 $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'mg_temu_get_variants',
                        product_ids: pids,
                        nonce: '<?php echo wp_create_nonce('mg_temu_nonce'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            renderVariantList(response.data);
                        } else {
                            $('#mg-temu-variant-list').html('<p>Hiba: ' + response.data + '</p>');
                        }
                    }
                 });
            }

            let variantsCache = []; // Store full data

            function renderVariantList(data) {
                if (!data || data.length === 0) {
                     $('#mg-temu-variant-list').html('<p>Nem található konfigurált terméktípus ezekhez a termékekhez.</p>');
                     return;
                }
                
                variantsCache = data;

                // 1. Aggregate Unique Attributes
                const types = {};
                const colors = {};
                const sizes = {};
                
                let totalProducts = data.length;

                data.forEach(item => {
                    item.variants.forEach(v => {
                        // Type
                        if (!types[v.type]) types[v.type] = { label: v.type_label, count: 0 };
                        types[v.type].count++;
                        
                        // Color
                        if (!colors[v.color]) colors[v.color] = { label: v.color_label, count: 0 };
                        colors[v.color].count++;
                        
                        // Size
                        if (!sizes[v.size]) sizes[v.size] = { label: v.size, count: 0 };
                        sizes[v.size].count++;
                    });
                });

                // 2. Render UI Columns
                let html = `
                    <div class="mg-temu-filters-grid" style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 20px;">
                        
                        <!-- Types -->
                        <div class="mg-temu-filter-col">
                            <h3>Terméktípusok</h3>
                            <div class="mg-temu-filter-list">
                                <label style="display:block;margin-bottom:8px;font-weight:bold;">
                                    <input type="checkbox" id="mg-filter-all-types" checked> Összes kijelölése
                                </label>
                                <hr style="margin:5px 0 10px;">
                                ${Object.keys(types).map(k => `
                                    <label style="display:block;margin-bottom:5px;">
                                        <input type="checkbox" class="mg-filter-type" value="${k}" checked> 
                                        ${types[k].label} <span style="color:#888;font-size:11px;">(${types[k].count})</span>
                                    </label>
                                `).join('')}
                            </div>
                        </div>

                        <!-- Colors -->
                        <div class="mg-temu-filter-col">
                            <h3>Színek</h3>
                            <div class="mg-temu-filter-list">
                                <label style="display:block;margin-bottom:8px;font-weight:bold;">
                                    <input type="checkbox" id="mg-filter-all-colors" checked> Összes kijelölése
                                </label>
                                <hr style="margin:5px 0 10px;">
                                ${Object.keys(colors).map(k => `
                                    <label style="display:block;margin-bottom:5px;">
                                        <input type="checkbox" class="mg-filter-color" value="${k}" checked> 
                                        ${colors[k].label} <span style="color:#888;font-size:11px;">(${colors[k].count})</span>
                                    </label>
                                `).join('')}
                            </div>
                        </div>

                        <!-- Sizes -->
                        <div class="mg-temu-filter-col">
                            <h3>Méretek</h3>
                            <div class="mg-temu-filter-list">
                                <label style="display:block;margin-bottom:8px;font-weight:bold;">
                                    <input type="checkbox" id="mg-filter-all-sizes" checked> Összes kijelölése
                                </label>
                                <hr style="margin:5px 0 10px;">
                                ${Object.keys(sizes).map(k => `
                                    <label style="display:block;margin-bottom:5px;">
                                        <input type="checkbox" class="mg-filter-size" value="${k}" checked> 
                                        ${sizes[k].label} <span style="color:#888;font-size:11px;">(${sizes[k].count})</span>
                                    </label>
                                `).join('')}
                            </div>
                        </div>

                    </div>

                    <div style="margin-top:20px; padding:15px; background:#f9f9f9; border-top:1px solid #ddd; text-align:right;">
                        <span id="mg-temu-export-summary" style="font-weight:bold; margin-right:15px;">Kalkuláció...</span>
                    </div>
                `;
                
                $('#mg-temu-variant-list').html(html);
                updateSummary();
            }

            // --- Filter Logic ---
            function updateSummary() {
                const selTypes = $('.mg-filter-type:checked').map((i, el) => el.value).get();
                const selColors = $('.mg-filter-color:checked').map((i, el) => el.value).get();
                const selSizes = $('.mg-filter-size:checked').map((i, el) => el.value).get();

                let count = 0;
                variantsCache.forEach(item => {
                    item.variants.forEach(v => {
                        if (selTypes.includes(v.type) && selColors.includes(v.color) && selSizes.includes(v.size)) {
                            count++;
                        }
                    });
                });
                
                $('#mg-temu-export-summary').html(`Összesen ${count} variáció kerül exportálásra (${variantsCache.length} termékből). <small style='color:#888'>(v1.2)</small>`);
            }

            $(document).on('change', '.mg-filter-type, .mg-filter-color, .mg-filter-size', updateSummary);
            
            $(document).on('change', '#mg-filter-all-types', function() {
                $('.mg-filter-type').prop('checked', $(this).is(':checked')).trigger('change');
            });
            $(document).on('change', '#mg-filter-all-colors', function() {
                $('.mg-filter-color').prop('checked', $(this).is(':checked')).trigger('change');
            });
            $(document).on('change', '#mg-filter-all-sizes', function() {
                $('.mg-filter-size').prop('checked', $(this).is(':checked')).trigger('change');
            });

            // --- Export ---

            // A szűrők alapján kiválasztott variációk összegyűjtése (CSV és XLSX közös)
            function collectSelection() {
                const selTypes = $('.mg-filter-type:checked').map((i, el) => el.value).get();
                const selColors = $('.mg-filter-color:checked').map((i, el) => el.value).get();
                const selSizes = $('.mg-filter-size:checked').map((i, el) => el.value).get();

                const selection = [];
                variantsCache.forEach(item => {
                    item.variants.forEach(v => {
                        if (selTypes.includes(v.type) && selColors.includes(v.color) && selSizes.includes(v.size)) {
                            selection.push({
                                pid: item.product_id,
                                type: v.type,
                                color: v.color,
                                size: v.size
                            });
                        }
                    });
                });
                return selection;
            }

            $('#mg-temu-generate').on('click', function() {
                const selection = collectSelection();

                if (selection.length === 0) {
                    alert('Nincs kiválasztott variáció.');
                    return;
                }

                const $btn = $(this);
                $btn.prop('disabled', true).text('Generálás...');

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'mg_temu_generate_export',
                        selection: JSON.stringify(selection),
                        nonce: '<?php echo wp_create_nonce('mg_temu_nonce'); ?>'
                    },
                    success: function(response) {
                        $btn.prop('disabled', false).text('CSV Export Generálása');
                        if (response.success) {
                            const link = document.createElement('a');
                            link.href = response.data.url;
                            link.download = response.data.filename;
                            document.body.appendChild(link);
                            link.click();
                            document.body.removeChild(link);
                        } else {
                            alert('Hiba: ' + response.data);
                        }
                    },
                    error: function() {
                        $btn.prop('disabled', false).text('CSV Export Generálása');
                        alert('Kommunikációs hiba.');
                    }
                });
            });

            // --- Temu XLSX export (kész, Temura feltölthető fájl) ---
            $('#mg-temu-generate-xlsx').on('click', function() {
                const selection = collectSelection();

                if (selection.length === 0) {
                    alert('Nincs kiválasztott variáció.');
                    return;
                }

                const $btn = $(this);
                $btn.prop('disabled', true).text('XLSX generálása...');

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'mg_temu_generate_xlsx',
                        selection: JSON.stringify(selection),
                        nonce: '<?php echo wp_create_nonce('mg_temu_nonce'); ?>'
                    },
                    success: function(response) {
                        $btn.prop('disabled', false).text('Temu XLSX letöltése');
                        if (response.success) {
                            if (response.data.unknown_colors && response.data.unknown_colors.length) {
                                alert('FIGYELEM — ismeretlen szín(ek), változatlanul kerültek az xlsx-be:\n' +
                                    response.data.unknown_colors.join(', '));
                            }
                            if (response.data.unknown_sizes && response.data.unknown_sizes.length) {
                                alert('FIGYELEM — a sablon Size listája nem tartalmazza ezeket a méreteket:\n' +
                                    response.data.unknown_sizes.join(', ') +
                                    '\nA Temu emiatt visszadobhatja ezeket a sorokat.');
                            }
                            // Típusonként külön fájl készül — linklista + automatikus letöltés
                            const files = response.data.files || [];
                            let html = '<strong>Kész XLSX fájlok (típusonként külön):</strong><ul style="margin:8px 0 0;">';
                            files.forEach(f => {
                                html += '<li><a href="' + f.download_url + '">⬇ ' + f.filename + '</a>' +
                                    ' <span style="color:#888;font-size:12px;">(' + f.type_label + ', ' + f.rows + ' sor)</span></li>';
                            });
                            html += '</ul><p style="color:#888;font-size:12px;margin:6px 0 0;">A letöltési linkek 15 percig érvényesek.</p>';
                            $('#mg-temu-xlsx-results').html(html).show();
                            // az elsőt automatikusan elindítjuk, a többi a linkről tölthető le
                            if (files.length) {
                                window.location.href = files[0].download_url;
                            }
                        } else {
                            alert('Hiba: ' + response.data);
                        }
                    },
                    error: function() {
                        $btn.prop('disabled', false).text('Temu XLSX letöltése');
                        alert('Kommunikációs hiba.');
                    }
                });
            });

            // Initial load
            loadProducts(1);
        });
        </script>
        <?php
    }

    public static function ajax_get_products() {
        check_ajax_referer('mg_temu_nonce', 'nonce');
        
        $page            = isset($_POST['page']) ? intval($_POST['page']) : 1;
        $per_page        = isset($_POST['per_page']) ? intval($_POST['per_page']) : 25;
        $only_unexported = ! empty($_POST['only_unexported']);
        $type_filter     = isset($_POST['type_filter']) ? sanitize_title(wp_unslash($_POST['type_filter'])) : '';
        $category_id     = isset($_POST['category_id']) ? intval($_POST['category_id']) : 0;

        $args = [
            'status'   => 'publish',
            'limit'    => $per_page,
            'page'     => $page,
            'paginate' => true,
        ];

        if ($type_filter !== '') {
            // Type-scoped filtering: build the list of products already exported for THIS type.
            $pids_with_type = self::get_pids_with_type_exported($type_filter);
            if ($only_unexported) {
                // Products where this type has NOT been exported yet.
                $args['exclude'] = !empty($pids_with_type) ? $pids_with_type : [-1];
            } else {
                // Products where this type HAS already been exported.
                $args['include'] = !empty($pids_with_type) ? $pids_with_type : [-1];
            }
        } elseif ($only_unexported) {
            // No type selected: legacy whole-product filter (never exported at all).
            global $wpdb;
            $exported_ids = $wpdb->get_col(
                "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_mg_temu_exported' AND meta_value != ''"
            );
            $args['exclude'] = !empty($exported_ids) ? $exported_ids : [-1];
        }

        if ($category_id > 0) {
            $args['category'] = [get_term($category_id, 'product_cat')->slug ?? ''];
        }
        
        $results = wc_get_products($args);
        $products = [];
        
        foreach ($results->products as $product) {
            $image_id = $product->get_image_id();
            $image_url = $image_id ? wp_get_attachment_image_url($image_id, 'thumbnail') : wc_placeholder_img_src();
            
            // Get category
            $cats = wc_get_product_term_ids($product->get_id(), 'product_cat');
            $cat_name = '';
            if (!empty($cats)) {
                $term = get_term($cats[0], 'product_cat');
                if ($term && !is_wp_error($term)) $cat_name = $term->name;
            }

            $exported_at = get_post_meta($product->get_id(), '_mg_temu_exported', true);

            // Per-type export status for this product (types are global across all products).
            $exported_types = self::get_exported_types($product->get_id());
            $types_status = [];
            foreach (self::get_all_types() as $t_slug => $t_label) {
                $types_status[] = [
                    'slug' => $t_slug,
                    'label' => $t_label,
                    'exported_at' => isset($exported_types[$t_slug]) ? $exported_types[$t_slug] : '',
                ];
            }

            $products[] = [
                'id' => $product->get_id(),
                'name' => $product->get_name(),
                'sku' => $product->get_sku(),
                'category' => $cat_name,
                'image' => $image_url,
                'exported_at' => $exported_at ?: '',
                'types' => $types_status,
            ];
        }

        wp_send_json_success([
            'products' => $products,
            'total_pages' => $results->max_num_pages,
            'total' => $results->total
        ]);
    }

    public static function ajax_get_variants() {
        check_ajax_referer('mg_temu_nonce', 'nonce');
        
        if (!class_exists('MG_Virtual_Variant_Manager')) {
            wp_send_json_error('MG_Virtual_Variant_Manager class not found');
        }

        $product_ids = isset($_POST['product_ids']) ? (array) $_POST['product_ids'] : [];
        $data = [];

        foreach ($product_ids as $pid) {
            $product = wc_get_product($pid);
            if (!$product) continue;

            $config = MG_Virtual_Variant_Manager::get_frontend_config($product);
            
            // Config structure:
            // 'types' => [ slug => [ 'label', 'colors' => [ slug => [ 'label', 'sizes' => [...] ] ] ] ]
            
            $variants = [];
            $base_sku = $config['product']['sku'] ?? $product->get_sku();

            if (!empty($config['types'])) {
                foreach ($config['types'] as $type_slug => $type_meta) {
                    if (empty($type_meta['colors'])) continue;
                    
                    foreach ($type_meta['colors'] as $color_slug => $color_meta) {
                        $sizes = $color_meta['sizes'] ?? [];
                        if (empty($sizes)) {
                            // Should we output a variant without size?
                            // Requirement: "ha egy terméknek ... több mérete van akkor méretenként külön sor"
                            // If no sizes, maybe one row? Let's skip for now as clothing usually has sizes if configured.
                            continue; 
                        }

                        // SKU Logic: {BaseSKU} (Simple, same for all rows)
                        $sku_generated = $base_sku;

                        foreach ($sizes as $size) {
                            $variants[] = [
                                'type' => $type_slug,
                                'type_label' => $type_meta['label'],
                                'color' => $color_slug,
                                'color_label' => $color_meta['label'],
                                'size' => $size,
                                'sku' => $sku_generated
                            ];
                        }
                    }
                }
            }

            $data[] = [
                'product_id' => $product->get_id(),
                'product_name' => $product->get_name(),
                'sku_base' => $base_sku,
                'variants' => $variants
            ];
        }

        wp_send_json_success($data);
    }

    public static function ajax_generate_export() {
        check_ajax_referer('mg_temu_nonce', 'nonce');
        
        $selection = isset($_POST['selection']) ? wp_unslash($_POST['selection']) : ''; 
        if (is_string($selection)) {
            $selection = json_decode($selection, true);
        }
        if (!is_array($selection)) {
            $selection = [];
        }
        // selection = [ { pid, type, color, size }, ... ]

        // CSV Header
        $header = ['Termék neve', 'SKU', 'Sub SKU', 'Szín', 'Méret', 'Leírás', 'Kép URL', 'Közös kép URL'];

        $rows = self::build_export_rows($selection);

        // Exportált termékek megjelölése – típusonként a selection alapján
        self::mark_selection_exported($selection);

        // Export
        $upload_dir = wp_upload_dir();
        // Versioned filename to ensure freshness
        $filename = 'temu-export-v2-' . date('Y-m-d-H-i-s') . '.csv';
        $filepath = $upload_dir['path'] . '/' . $filename;
        $fileurl = $upload_dir['url'] . '/' . $filename;

        $fp = fopen($filepath, 'w');
        // BOM for Excel compatibility
        fprintf($fp, chr(0xEF).chr(0xBB).chr(0xBF));

        fputcsv($fp, $header, ';');
        foreach ($rows as $row) {
            fputcsv($fp, [
                $row['name'],
                $row['sku'],
                $row['sub_sku'],
                $row['color'],
                $row['size'],
                $row['desc'],
                $row['img'],
                $row['common_img'],
            ], ';');
        }
        fclose($fp);

        wp_send_json_success([
            'url' => $fileurl,
            'filename' => $filename
        ]);
    }

    /**
     * A kiválasztott variációkból az exportsorok összeállítása.
     * Ezt használja a CSV ÉS az XLSX export is — az adatlekérdezés garantáltan
     * azonos, csak a kimeneti formátum tér el.
     *
     * @param array $selection [ { pid, type, color, size }, ... ]
     * @return array<int,array{name:string,sku:string,sub_sku:string,color:string,size:string,desc:string,img:string,common_img:string}>
     */
    private static function build_export_rows(array $selection) {
        $rows = [];

        // Cache products to avoid reloading
        $product_cache = [];
        $config_cache = [];
        $bullets_cache = [];

        foreach ($selection as $item) {
            $pid = $item['pid'];
            if (!isset($product_cache[$pid])) {
                $product_cache[$pid] = wc_get_product($pid);
                $config_cache[$pid] = MG_Virtual_Variant_Manager::get_frontend_config($product_cache[$pid]);
                // Kategóriánkénti fix Bullet Pointok (csak az XLSX exportban használt)
                $bullets_cache[$pid] = self::get_bullets_for_product($pid);
            }
            $product = $product_cache[$pid];
            $config = $config_cache[$pid];
            
            if (!$product) continue;

            $type_slug = $item['type'];
            $color_slug = $item['color'];
            $size = $item['size'];
            
            // Map sizes for Temu
            $normalized_size = strtolower(trim($size));
            $temu_size_map = [
                'xs' => 'XS',
                's'  => 'S',
                'm'  => 'M',
                'l'  => 'L',
                'xl' => 'XL',
                '2xl' => 'XXL',
                'xxl' => 'XXL',
                '3xl' => 'XXXL',
                'xxxl' => 'XXXL',
                '4xl' => 'XXXXL',
                'xxxxl' => 'XXXXL',
                '5xl' => 'XXXXXL',
                'xxxxxl' => 'XXXXXL',
                '2'  => '2Y',
                '4'  => '4Y',
                '6'  => '6Y',
                '8'  => '8Y',
                '10' => '10Y',
                '12' => '12Y',
                // Páros gyerekméretek (pl. gyerek pulcsi) -> Temu tartományos alak.
                // A 9/11 és 12/13 nem létezik a Temu listában, a legközelebbi
                // tartományra képezzük (9-10Y ill. 11-12Y).
                '1/2'   => '1-2Y',
                '3/4'   => '3-4Y',
                '5/6'   => '5-6Y',
                '7/8'   => '7-8Y',
                '9/11'  => '9-10Y',
                '12/13' => '11-12Y',
            ];
            
            if (isset($temu_size_map[$normalized_size])) {
                $size = $temu_size_map[$normalized_size];
            } else {
                $size = strtoupper($size);
            }
            
            $base_sku = $config['product']['sku'] ?? $product->get_sku();
            // Fallback for SKU
            if (empty($base_sku)) $base_sku = 'SKU_' . $pid;
            
            $sku_generated = $base_sku;

            // Ha gyerekméret (2..12 vagy páros 1/2..12/13), hozzátesszük az SKU-hoz, hogy GYEREK
            if (in_array($normalized_size, ['2', '4', '6', '8', '10', '12', '1/2', '3/4', '5/6', '7/8', '9/11', '12/13'], true)) {
                $sku_generated .= '-GYEREK';
            }
            
            // Generate Sub SKU: sku + random 3 letters + 3 numbers
            $letters = substr(str_shuffle("ABCDEFGHIJKLMNOPQRSTUVWXYZ"), 0, 3);
            $numbers = substr(str_shuffle("0123456789"), 0, 3);
            $sub_sku = $sku_generated . $letters . $numbers;

            // Labels
            $type_label = $config['types'][$type_slug]['label'] ?? $type_slug;
            $color_label = $config['types'][$type_slug]['colors'][$color_slug]['label'] ?? $color_slug;
            
            // Image Logic
            // Pattern: /mg_mockups/{SKU}/{SKU}_{TYPE}_{COLOR}_front.webp
            // Note: Use Base SKU for directory structure as per convention observed
            $uploads = wp_upload_dir();
            $base_url = isset($uploads['baseurl']) ? trailingslashit($uploads['baseurl']) . 'mg_mockups' : '';
            
            $filename = $base_sku . '_' . $type_slug . '_' . $color_slug . '_front.webp';
            $img_url = $base_url . '/' . $base_sku . '/' . $filename;
            $common_img_url = self::get_common_image_url($pid);
            
            // Verify file exists? User didn't strictly ask to verify, just "img url". 
            // Better to provide the predicted URL so they can fix missing files later.
            // But let's check validation if possible? No, faster to just output predicted for bulk.

            // Termék leírás helyett Category SEO
            $export_description = '';
            if (function_exists('mgtd__build_description_context')) {
                $desc_context = mgtd__build_description_context($product);
                // "category seos" is requested, but use fallback just in case
                $export_description = !empty($desc_context['category_seos']) ? $desc_context['category_seos'] : ($desc_context['category_seo'] ?? '');
            }
            if (empty($export_description)) {
                $export_description = $product->get_description(); // Fallback ha egyáltalán nincs SEO leírás
            }

            // Egyedi mező: a típus saját értéke, ha van, különben az alap
            $suffix_types = get_option('mg_temu_name_suffix_types', []);
            $name_suffix = (is_array($suffix_types) && isset($suffix_types[$type_slug]) && $suffix_types[$type_slug] !== '')
                ? $suffix_types[$type_slug]
                : get_option('mg_temu_name_suffix', '');
            $export_name = trim($product->get_name() . ' ' . $type_label . ' ' . $name_suffix);

            // Bullet Point mezők a termék kategóriája alapján (bullet_1..bullet_6)
            $bullet_fields = [];
            if (!empty($bullets_cache[$pid])) {
                foreach (array_values($bullets_cache[$pid]) as $bi => $btext) {
                    if ($btext !== '') {
                        $bullet_fields['bullet_' . ($bi + 1)] = $btext;
                    }
                }
            }

            $rows[] = array_merge([
                'name'    => $export_name,   // Termék név + típus + egyedi mező
                'sku'     => $sku_generated, // SKU
                'sub_sku' => $sub_sku,       // Sub SKU
                'color'   => $color_label,         // Szín
                'size'    => $size,                // Méret
                'desc'    => $export_description,  // Leírás
                'img'     => $img_url,             // Variáns kép URL
                'common_img' => $common_img_url    // Termékszintű közös kép URL
            ], $bullet_fields);

            // Ha gyerekpóló (12-es méret, ami 12Y lett), csinálünk egy extra '14Y' sort a Temu miatt
            if ($normalized_size === '12') {
                $letters_14y = substr(str_shuffle("ABCDEFGHIJKLMNOPQRSTUVWXYZ"), 0, 3);
                $numbers_14y = substr(str_shuffle("0123456789"), 0, 3);
                $sub_sku_14y = $sku_generated . $letters_14y . $numbers_14y;

                $rows[] = array_merge([
                    'name'    => $export_name,   // Termék név + típus + egyedi mező
                    'sku'     => $sku_generated, // SKU
                    'sub_sku' => $sub_sku_14y,   // Kamu méret új Sub SKU-ja
                    'color'   => $color_label,         // Szín
                    'size'    => '14Y',                // Kamu méret
                    'desc'    => $export_description,  // Leírás
                    'img'     => $img_url,             // Img URL (A 12-es méreté hasznosítva)
                    'common_img' => $common_img_url
                ], $bullet_fields);
            }
        }

        return $rows;
    }

    /**
     * Exportált termékek megjelölése – típusonként a selection alapján.
     *
     * @param array $selection [ { pid, type, color, size }, ... ]
     */
    private static function mark_selection_exported(array $selection) {
        $now = current_time('Y-m-d H:i');
        $types_by_pid = [];
        foreach ($selection as $item) {
            $pid = isset($item['pid']) ? (int) $item['pid'] : 0;
            $tslug = isset($item['type']) ? sanitize_title($item['type']) : '';
            if ($pid <= 0 || $tslug === '') {
                continue;
            }
            $types_by_pid[$pid][$tslug] = true;
        }
        foreach ($types_by_pid as $pid => $slug_map) {
            self::mark_types_exported($pid, array_keys($slug_map), $now);
        }
    }

    // ------------------------------------------------------------------
    // Temu XLSX export — a mestersablon kitöltése sebészi ZIP-módszerrel
    // ------------------------------------------------------------------

    /**
     * A mestersablon védett tárolási könyvtára (wp-content/uploads/temu-template).
     *
     * @return string
     */
    private static function get_template_dir() {
        $up = wp_upload_dir();
        return trailingslashit($up['basedir']) . 'temu-template';
    }

    /**
     * Egy sablon-slot fájlútvonala. Üres vagy 'default' slot = alap sablon,
     * egyébként a virtuális terméktípus saját sablonja.
     *
     * @param string $slot 'default' vagy terméktípus slug
     * @return string
     */
    private static function get_template_path($slot = 'default') {
        $slot = sanitize_title($slot);
        if ($slot === '' || $slot === 'default') {
            return self::get_template_dir() . '/temu-master.xlsx';
        }
        return self::get_template_dir() . '/temu-master-' . $slot . '.xlsx';
    }

    /**
     * A feltölthető sablon-slotok: 'default' + minden virtuális terméktípus.
     *
     * @return array<string,string> slot => címke
     */
    private static function get_template_slots() {
        $slots = ['default' => __('Alap sablon (ha a típusnak nincs sajátja)', 'mockup-generator')];
        foreach (self::get_all_types() as $slug => $label) {
            $slots[$slug] = $label;
        }
        return $slots;
    }

    /**
     * Sablon-metaadatok slotonként: [ slot => ['name','size','uploaded'] ].
     * A régi, egy-sablonos formátumot alap slotként olvassa tovább.
     *
     * @return array<string,array>
     */
    private static function get_template_meta_all() {
        $meta = get_option('mg_temu_template_meta', []);
        if (!is_array($meta)) {
            return [];
        }
        if (isset($meta['name'])) {
            $meta = ['default' => $meta];
        }
        return $meta;
    }

    /**
     * Egy terméktípushoz használandó sablon: a típus saját sablonja, ha van,
     * különben az alap sablon; ha egyik sincs, null.
     *
     * @param string $type_slug
     * @return string|null
     */
    private static function resolve_template_for_type($type_slug) {
        $typed = self::get_template_path($type_slug);
        if ($type_slug !== '' && file_exists($typed)) {
            return $typed;
        }
        $default = self::get_template_path('default');
        return file_exists($default) ? $default : null;
    }

    /**
     * Könyvtár létrehozása + közvetlen webes letöltés tiltása (.htaccess, index.php).
     */
    private static function ensure_template_dir() {
        $dir = self::get_template_dir();
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }
        if (!file_exists($dir . '/.htaccess')) {
            @file_put_contents(
                $dir . '/.htaccess',
                "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n"
            );
        }
        if (!file_exists($dir . '/index.php')) {
            @file_put_contents($dir . '/index.php', "<?php // Silence is golden.\n");
        }
    }

    /**
     * Mestersablon feltöltése (admin-post). Csak .xlsx, admin jogosultság,
     * nonce ellenőrzés; mentés előtt validáljuk, hogy tényleg Temu sablon-e.
     */
    public static function handle_upload_template() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Nincs jogosultság.', 'mockup-generator'));
        }
        check_admin_referer('mg_temu_upload_template');

        $redirect = wp_get_referer();
        if (!$redirect) {
            $redirect = admin_url('admin.php?page=mockup-generator');
        }
        $redirect = remove_query_arg(['mg_temu_tpl', 'mg_temu_msg'], $redirect);
        $fail = function ($msg) use ($redirect) {
            wp_safe_redirect(add_query_arg([
                'mg_temu_tpl' => 'err',
                'mg_temu_msg' => rawurlencode($msg),
            ], $redirect));
            exit;
        };

        $slot = isset($_POST['mg_temu_slot']) ? sanitize_title(wp_unslash($_POST['mg_temu_slot'])) : 'default';
        if (!array_key_exists($slot, self::get_template_slots())) {
            $fail(__('Ismeretlen sablon-slot.', 'mockup-generator'));
        }

        if (empty($_FILES['mg_temu_template']['tmp_name']) || !is_uploaded_file($_FILES['mg_temu_template']['tmp_name'])) {
            $fail(__('Nincs kiválasztott fájl.', 'mockup-generator'));
        }
        $orig_name = isset($_FILES['mg_temu_template']['name']) ? sanitize_file_name(wp_unslash($_FILES['mg_temu_template']['name'])) : '';
        if (strtolower(pathinfo($orig_name, PATHINFO_EXTENSION)) !== 'xlsx') {
            $fail(__('Csak .xlsx fájl tölthető fel.', 'mockup-generator'));
        }

        $tmp = $_FILES['mg_temu_template']['tmp_name'];
        try {
            // Feltöltéskor rögtön kiderül, ha nem a Temu sablon (nincs Template
            // lap, hiányzó mezőkulcsok, üres mintasor stb.)
            MG_Temu_Xlsx_Writer::validate_template($tmp);
        } catch (Exception $e) {
            $fail(sprintf(__('Érvénytelen sablon: %s', 'mockup-generator'), $e->getMessage()));
        }

        self::ensure_template_dir();
        $dest = self::get_template_path($slot);
        if (!@move_uploaded_file($tmp, $dest)) {
            $fail(__('A sablon mentése nem sikerült (írási jogosultság?).', 'mockup-generator'));
        }

        $meta = self::get_template_meta_all();
        $meta[$slot] = [
            'name'     => $orig_name,
            'size'     => (int) filesize($dest),
            'uploaded' => current_time('Y-m-d H:i'),
        ];
        update_option('mg_temu_template_meta', $meta);

        wp_safe_redirect(add_query_arg('mg_temu_tpl', 'ok', $redirect));
        exit;
    }

    /**
     * Sablon törlése egy slotból (admin-post).
     */
    public static function handle_delete_template() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Nincs jogosultság.', 'mockup-generator'));
        }
        check_admin_referer('mg_temu_delete_template');

        $redirect = wp_get_referer();
        if (!$redirect) {
            $redirect = admin_url('admin.php?page=mockup-generator');
        }
        $redirect = remove_query_arg(['mg_temu_tpl', 'mg_temu_msg'], $redirect);

        $slot = isset($_POST['mg_temu_slot']) ? sanitize_title(wp_unslash($_POST['mg_temu_slot'])) : '';
        if ($slot !== '' && array_key_exists($slot, self::get_template_slots())) {
            @unlink(self::get_template_path($slot));
            $meta = self::get_template_meta_all();
            unset($meta[$slot]);
            update_option('mg_temu_template_meta', $meta);
        }

        wp_safe_redirect(add_query_arg('mg_temu_tpl', 'del', $redirect));
        exit;
    }

    /**
     * XLSX generálása a kiválasztott variációkból (admin-ajax).
     * Ugyanazt az adatlekérdezést használja, mint a CSV export
     * (build_export_rows), csak a szín itt magyarról angolra fordul.
     */
    public static function ajax_generate_xlsx() {
        check_ajax_referer('mg_temu_nonce', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(__('Nincs jogosultság.', 'mockup-generator'));
        }

        $selection = isset($_POST['selection']) ? wp_unslash($_POST['selection']) : '';
        if (is_string($selection)) {
            $selection = json_decode($selection, true);
        }
        if (!is_array($selection) || empty($selection)) {
            wp_send_json_error(__('Nincs kiválasztott variáció.', 'mockup-generator'));
        }

        if (!class_exists('MG_Temu_Xlsx_Writer')) {
            wp_send_json_error(__('Az XLSX exporter modul nem érhető el.', 'mockup-generator'));
        }

        // Minden terméktípusnak saját sablonja (vagy az alap) — a kijelölést
        // típusonként csoportosítjuk, és típusonként külön xlsx készül.
        $by_type = [];
        foreach ($selection as $item) {
            $tslug = isset($item['type']) ? sanitize_title($item['type']) : '';
            $by_type[$tslug][] = $item;
        }

        $all_types = self::get_all_types();

        // Előbb ellenőrzünk minden típust, hogy ne készüljön félkész export.
        $missing_templates = [];
        $templates = [];
        foreach (array_keys($by_type) as $tslug) {
            $template = self::resolve_template_for_type($tslug);
            if ($template === null) {
                $missing_templates[] = isset($all_types[$tslug]) ? $all_types[$tslug] : $tslug;
            } else {
                $templates[$tslug] = $template;
            }
        }
        if ($missing_templates) {
            wp_send_json_error(sprintf(
                __('Nincs Temu mestersablon a következő típus(ok)hoz, és alap sablon sincs feltöltve: %s. Töltsd fel a lap tetején található beállításnál.', 'mockup-generator'),
                implode(', ', $missing_templates)
            ));
        }

        self::ensure_template_dir();
        self::cleanup_stale_exports();

        $stamp = date('Y-m-d-Hi', current_time('timestamp'));
        $unknown_colors = [];
        $unknown_sizes = [];
        $allowed_sizes_cache = [];
        $files = [];

        foreach ($by_type as $tslug => $items) {
            $rows = self::build_export_rows($items);
            if (empty($rows)) {
                continue;
            }

            // A sablon Size lenyílójának engedélyezett értékei (sablononként
            // eltérhet az írásmód: pl. a női sablon 3XL-t vár, nem XXXL-t).
            $tpl_path = $templates[$tslug];
            if (!array_key_exists($tpl_path, $allowed_sizes_cache)) {
                $allowed_sizes_cache[$tpl_path] = MG_Temu_Xlsx_Writer::get_allowed_sizes($tpl_path);
            }
            $allowed_sizes = $allowed_sizes_cache[$tpl_path];

            // Szín: magyar -> Temu angol érték; az ismeretlen színek
            // változatlanul mennek be, de figyelmeztetést küldünk a felületre.
            foreach ($rows as $i => $row) {
                list($color, $unknown) = MG_Temu_Xlsx_Writer::map_color($row['color']);
                if ($unknown !== null) {
                    $unknown_colors[$unknown] = true;
                }
                $rows[$i]['color'] = $color;

                // Méret a sablon listájához igazítva (XXXL <-> 3XL stb.)
                list($size, $size_ok) = MG_Temu_Xlsx_Writer::normalize_size_for_list($row['size'], $allowed_sizes);
                if (!$size_ok) {
                    $unknown_sizes[$size] = true;
                }
                $rows[$i]['size'] = $size;
            }

            $token    = strtolower(wp_generate_password(20, false));
            $filename = 'temu-upload-' . ($tslug !== '' ? $tslug . '-' : '') . $stamp . '.xlsx';
            $out_path = self::get_template_dir() . '/export-' . $token . '.xlsx';

            try {
                MG_Temu_Xlsx_Writer::generate($templates[$tslug], $rows, $out_path);
            } catch (Exception $e) {
                // a már legenerált fájlokat eldobjuk, hogy ne legyen félkész export
                foreach ($files as $f) {
                    @unlink($f['_path']);
                    delete_transient('mg_temu_xlsx_' . $f['_token']);
                }
                $type_label = isset($all_types[$tslug]) ? $all_types[$tslug] : $tslug;
                wp_send_json_error(sprintf('%s (%s)', $e->getMessage(), $type_label));
            }

            // Egyszer használatos letöltési token — a fájl védett könyvtárban
            // van, a letöltés admin-post handleren át, helyes headerekkel megy.
            set_transient('mg_temu_xlsx_' . $token, [
                'path'     => $out_path,
                'filename' => $filename,
            ], 15 * MINUTE_IN_SECONDS);

            $files[] = [
                'type'         => $tslug,
                'type_label'   => isset($all_types[$tslug]) ? $all_types[$tslug] : $tslug,
                'filename'     => $filename,
                'rows'         => count($rows),
                // FONTOS: nem wp_nonce_url(), mert az HTML-escapeli az &-t
                // (&#038;), és JSON-on át a böngésző fragmentként levágná a
                // nonce-ot -> "A követett hivatkozás érvényessége lejárt."
                'download_url' => add_query_arg([
                    'action'   => 'mg_temu_download_xlsx',
                    'token'    => $token,
                    '_wpnonce' => wp_create_nonce('mg_temu_download_xlsx'),
                ], admin_url('admin-post.php')),
                '_path'        => $out_path,
                '_token'       => $token,
            ];
        }

        if (empty($files)) {
            wp_send_json_error(__('A kiválasztásból nem készült egyetlen adatsor sem.', 'mockup-generator'));
        }

        self::mark_selection_exported($selection);

        // a belső mezőket nem küldjük ki a válaszban
        foreach ($files as $i => $f) {
            unset($files[$i]['_path'], $files[$i]['_token']);
        }

        wp_send_json_success([
            'files'          => array_values($files),
            'unknown_colors' => array_keys($unknown_colors),
            'unknown_sizes'  => array_keys($unknown_sizes),
        ]);
    }

    /**
     * A generált xlsx letöltése (admin-post) a megfelelő headerekkel.
     */
    public static function handle_download_xlsx() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Nincs jogosultság.', 'mockup-generator'));
        }
        check_admin_referer('mg_temu_download_xlsx');

        $token = isset($_GET['token']) ? preg_replace('/[^a-z0-9]/', '', (string) wp_unslash($_GET['token'])) : '';
        $data  = $token !== '' ? get_transient('mg_temu_xlsx_' . $token) : false;
        if (!$data || empty($data['path']) || !file_exists($data['path'])) {
            wp_die(esc_html__('A letöltési hivatkozás lejárt vagy érvénytelen. Generáld újra az exportot.', 'mockup-generator'));
        }

        nocache_headers();
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $data['filename'] . '"');
        header('Content-Length: ' . filesize($data['path']));
        readfile($data['path']);

        // A fájlt nem töröljük azonnal: a link a transient lejártáig (15 perc)
        // újra használható, a maradékot a cleanup_stale_exports() takarítja el.
        exit;
    }

    /**
     * Ottfelejtett (le nem töltött) exportfájlok törlése 1 nap után.
     */
    private static function cleanup_stale_exports() {
        $files = glob(self::get_template_dir() . '/export-*.xlsx');
        if (!is_array($files)) {
            return;
        }
        foreach ($files as $file) {
            $mtime = @filemtime($file);
            if ($mtime !== false && $mtime < time() - DAY_IN_SECONDS) {
                @unlink($file);
            }
        }
    }

    public static function ajax_mark_exported() {
        check_ajax_referer('mg_temu_nonce', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Nincs jogosultság.');
        }

        $ids = isset($_POST['ids']) ? array_map('intval', (array) $_POST['ids']) : [];
        $now = current_time('Y-m-d H:i');
        $updated = 0;

        // Manuális jelölés: a termék MINDEN típusát exportáltnak jelöljük.
        $all_type_slugs = array_keys(self::get_all_types());

        foreach ($ids as $id) {
            if ($id <= 0) continue;
            $post = get_post($id);
            if (!$post || $post->post_type !== 'product') continue;
            self::mark_types_exported($id, $all_type_slugs, $now);
            $updated++;
        }

        wp_send_json_success(['updated' => $updated]);
    }

    public static function ajax_save_name_suffix() {
        check_ajax_referer('mg_temu_nonce', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Nincs jogosultság.');
        }
        $value = isset($_POST['value']) ? sanitize_text_field(wp_unslash($_POST['value'])) : '';
        update_option('mg_temu_name_suffix', $value);

        // típusonkénti egyedi mezők (üres érték = az alap értéket használja)
        if (isset($_POST['types'])) {
            $types_raw = json_decode(wp_unslash($_POST['types']), true);
            $types = [];
            if (is_array($types_raw)) {
                foreach ($types_raw as $slug => $suffix) {
                    $slug = sanitize_title($slug);
                    if ($slug === '') {
                        continue;
                    }
                    $suffix = sanitize_text_field((string) $suffix);
                    if ($suffix !== '') {
                        $types[$slug] = $suffix;
                    }
                }
            }
            update_option('mg_temu_name_suffix_types', $types);
        }
        wp_send_json_success();
    }

    /**
     * Kategóriánkénti fix Bullet Pointok mentése (admin-ajax).
     * Option: mg_temu_bullet_points = [ term_id => [max 6 szöveg] ].
     */
    public static function ajax_save_bullets() {
        check_ajax_referer('mg_temu_nonce', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(__('Nincs jogosultság.', 'mockup-generator'));
        }
        $term_id = isset($_POST['term_id']) ? (int) $_POST['term_id'] : 0;
        $term = $term_id > 0 ? get_term($term_id, 'product_cat') : null;
        if (!$term || is_wp_error($term)) {
            wp_send_json_error(__('Érvénytelen kategória.', 'mockup-generator'));
        }

        $bullets_raw = isset($_POST['bullets']) ? json_decode(wp_unslash($_POST['bullets']), true) : [];
        $bullets = [];
        if (is_array($bullets_raw)) {
            foreach (array_slice(array_values($bullets_raw), 0, 6) as $b) {
                $bullets[] = sanitize_text_field((string) $b);
            }
        }
        // csupa üres = a kategória beállításának törlése
        $has_value = array_filter($bullets, function ($b) { return $b !== ''; });

        $map = get_option('mg_temu_bullet_points', []);
        if (!is_array($map)) {
            $map = [];
        }
        if ($has_value) {
            $map[$term_id] = $bullets;
        } else {
            unset($map[$term_id]);
        }
        update_option('mg_temu_bullet_points', $map);

        wp_send_json_success(['cleared' => empty($has_value)]);
    }

    /**
     * A termékhez tartozó fix Bullet Pointok: először a termék saját
     * kategóriái, utána azok szülőkategóriái közül az első, amelyikhez van
     * beállítás. Null, ha egyikhez sincs.
     *
     * @param int $product_id
     * @return string[]|null
     */
    private static function get_bullets_for_product($product_id) {
        $map = get_option('mg_temu_bullet_points', []);
        if (!is_array($map) || empty($map)) {
            return null;
        }
        $terms = wc_get_product_term_ids($product_id, 'product_cat');
        foreach ($terms as $tid) {
            if (!empty($map[$tid])) {
                return $map[$tid];
            }
        }
        foreach ($terms as $tid) {
            foreach (get_ancestors($tid, 'product_cat') as $aid) {
                if (!empty($map[$aid])) {
                    return $map[$aid];
                }
            }
        }
        return null;
    }

    /**
     * Global list of virtual product types: [ type_slug => label ].
     * Types are shared across all products (see MG_Virtual_Variant_Manager),
     * so this is fetched once and cached for the request.
     *
     * @return array<string,string>
     */
    private static function get_all_types() {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $cache = [];
        if (class_exists('MG_Variant_Display_Manager')) {
            $catalog = MG_Variant_Display_Manager::get_catalog_index();
            if (is_array($catalog)) {
                foreach ($catalog as $slug => $meta) {
                    $slug = sanitize_title($slug);
                    if ($slug === '') {
                        continue;
                    }
                    $cache[$slug] = (is_array($meta) && isset($meta['label']) && $meta['label'] !== '') ? $meta['label'] : $slug;
                }
            }
        }
        return $cache;
    }

    /**
     * Per-type export state for a product: [ type_slug => 'Y-m-d H:i' ].
     *
     * @param int $product_id
     * @return array<string,string>
     */
    private static function get_exported_types($product_id) {
        $raw = get_post_meta((int) $product_id, '_mg_temu_exported_types', true);
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $slug => $ts) {
            $slug = sanitize_title($slug);
            if ($slug === '') {
                continue;
            }
            $out[$slug] = is_string($ts) ? $ts : '';
        }
        return $out;
    }

    /**
     * Mark the given type slugs as exported for a product at the given timestamp.
     * Merges with existing state and keeps the legacy whole-product flag in sync.
     *
     * @param int      $product_id
     * @param string[] $type_slugs
     * @param string   $timestamp
     */
    private static function mark_types_exported($product_id, array $type_slugs, $timestamp) {
        $product_id = (int) $product_id;
        if ($product_id <= 0) {
            return;
        }
        $existing = self::get_exported_types($product_id);
        foreach ($type_slugs as $slug) {
            $slug = sanitize_title($slug);
            if ($slug === '') {
                continue;
            }
            $existing[$slug] = $timestamp;
        }
        update_post_meta($product_id, '_mg_temu_exported_types', $existing);
        // Keep the legacy overall flag updated for backward compatibility.
        update_post_meta($product_id, '_mg_temu_exported', $timestamp);
    }

    /**
     * IDs of products that already have the given type marked as exported.
     * Mirrors the existing whole-product exclude pattern, but reads the
     * per-type serialized meta in PHP to avoid fragile LIKE queries.
     *
     * @param string $type_slug
     * @return int[]
     */
    private static function get_pids_with_type_exported($type_slug) {
        $type_slug = sanitize_title($type_slug);
        if ($type_slug === '') {
            return [];
        }
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_mg_temu_exported_types'"
        );
        $pids = [];
        foreach ((array) $rows as $row) {
            $arr = maybe_unserialize($row->meta_value);
            if (is_array($arr) && !empty($arr[$type_slug])) {
                $pids[] = (int) $row->post_id;
            }
        }
        return $pids;
    }
}
