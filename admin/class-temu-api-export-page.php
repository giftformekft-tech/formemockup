<?php
if (!defined('ABSPATH')) {
    exit;
}

/** Dedicated UI and CSV download for the companion Temu V3 API app. */
class MG_Temu_API_Export_Page {

    const OPTION_KEY = 'mg_temu_api_export_settings';

    public static function init() {
        add_action('admin_post_mg_temu_api_save_settings', array(self::class, 'handle_save_settings'));
        add_action('admin_post_mg_temu_api_export_csv', array(self::class, 'handle_export_csv'));
        add_action('wp_ajax_mg_temu_api_get_products', array(self::class, 'ajax_get_products'));
        add_action('wp_ajax_mg_temu_api_get_variants', array(self::class, 'ajax_get_variants'));
    }

    public static function get_settings() {
        $saved = get_option(self::OPTION_KEY, array());
        return wp_parse_args(is_array($saved) ? $saved : array(), array(
            'default_stock' => 10,
            'default_weight_g' => 180,
            'brand' => 'márkanév nélkül',
            'material' => 'pamut',
        ));
    }

    private static function return_url($args = array()) {
        return add_query_arg(
            array_merge(array('page' => 'mockup-generator', 'mg_tab' => 'temu_api_export'), $args),
            admin_url('admin.php')
        );
    }

    public static function handle_save_settings() {
        if (!current_user_can('edit_products')) {
            wp_die(esc_html__('Nincs jogosultságod a Temu API export beállításaihoz.', 'mockup-generator'));
        }
        check_admin_referer('mg_temu_api_save_settings');
        update_option(self::OPTION_KEY, array(
            'default_stock' => max(1, absint($_POST['default_stock'] ?? 10)),
            'default_weight_g' => max(1, absint($_POST['default_weight_g'] ?? 180)),
            'brand' => sanitize_text_field(wp_unslash($_POST['brand'] ?? 'márkanév nélkül')),
            'material' => sanitize_text_field(wp_unslash($_POST['material'] ?? 'pamut')),
        ), false);
        wp_safe_redirect(self::return_url(array('mg_temu_api_saved' => '1')));
        exit;
    }

    public static function handle_export_csv() {
        if (!current_user_can('edit_products')) {
            wp_die(esc_html__('Nincs jogosultságod a Temu API exporthoz.', 'mockup-generator'));
        }
        check_admin_referer('mg_temu_api_export_csv');
        $selection = isset($_POST['selection']) ? json_decode(wp_unslash($_POST['selection']), true) : array();
        $selection = is_array($selection) ? array_values(array_filter($selection, 'is_array')) : array();
        $result = MG_Temu_API_Exporter::build_rows(self::get_settings(), $selection);
        if (!empty($result['errors'])) {
            wp_die(self::error_page($result['errors']), 'Temu API export ellenőrzési hiba', array('response' => 400));
        }
        if (empty($result['rows'])) {
            wp_die(self::error_page(array('Nincs exportálható Temu API variáns.')));
        }
        MG_Temu_API_Exporter::mark_exported($result['product_types']);
        nocache_headers();
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="temu-api-v3-export-' . gmdate('Y-m-d-His') . '.csv"');
        $output = fopen('php://output', 'w');
        fwrite($output, "\xEF\xBB\xBF");
        fputcsv($output, MG_Temu_API_Exporter::headers(), ';');
        foreach ($result['rows'] as $row) {
            $line = array();
            foreach (MG_Temu_API_Exporter::headers() as $header) {
                $line[] = isset($row[$header]) ? $row[$header] : '';
            }
            fputcsv($output, $line, ';');
        }
        fclose($output);
        exit;
    }

    public static function ajax_get_products() {
        check_ajax_referer('mg_temu_api_nonce', 'nonce');
        if (!current_user_can('edit_products')) {
            wp_send_json_error('Nincs jogosultságod a termékek lekéréséhez.', 403);
        }
        $page = max(1, absint($_POST['page'] ?? 1));
        $per_page = absint($_POST['per_page'] ?? 25);
        if (!in_array($per_page, array(25, 50, 100), true)) {
            $per_page = 25;
        }
        $category_id = absint($_POST['category_id'] ?? 0);
        $args = array('status' => 'publish', 'limit' => $per_page, 'page' => $page, 'paginate' => true);
        if ($category_id) {
            $term = get_term($category_id, 'product_cat');
            if ($term && !is_wp_error($term)) {
                $args['category'] = array($term->slug);
            }
        }
        $results = wc_get_products($args);
        $products = array();
        foreach ((array) $results->products as $product) {
            if (!$product || !$product->is_in_stock()) {
                continue;
            }
            $cats = wc_get_product_term_ids($product->get_id(), 'product_cat');
            $term = $cats ? get_term($cats[0], 'product_cat') : null;
            $products[] = array(
                'id' => (int) $product->get_id(),
                'name' => $product->get_name(),
                'sku' => $product->get_sku(),
                'category' => $term && !is_wp_error($term) ? $term->name : '',
                'image' => $product->get_image_id() ? wp_get_attachment_image_url($product->get_image_id(), 'thumbnail') : wc_placeholder_img_src(),
            );
        }
        wp_send_json_success(array(
            'products' => $products,
            'total_pages' => (int) $results->max_num_pages,
            'total' => (int) $results->total,
        ));
    }

    public static function ajax_get_variants() {
        check_ajax_referer('mg_temu_api_nonce', 'nonce');
        if (!current_user_can('edit_products')) {
            wp_send_json_error('Nincs jogosultságod a variációk lekéréséhez.', 403);
        }
        $ids = isset($_POST['product_ids']) ? array_values(array_unique(array_map('absint', (array) wp_unslash($_POST['product_ids'])))) : array();
        $data = array();
        foreach ($ids as $product_id) {
            $product = wc_get_product($product_id);
            if (!$product || !$product->is_in_stock()) {
                continue;
            }
            $exported = MG_Temu_API_Exporter::get_exported_types($product_id);
            $groups = array();
            $config = MG_Virtual_Variant_Manager::get_frontend_config($product);
            foreach ((array) ($config['types'] ?? array()) as $type_slug => $type_meta) {
                $type_slug = sanitize_title($type_slug);
                foreach ((array) ($type_meta['colors'] ?? array()) as $color_slug => $color_meta) {
                    $color_slug = sanitize_title($color_slug);
                    $groups[] = array(
                        'type' => $type_slug,
                        'type_label' => wp_strip_all_tags($type_meta['label'] ?? $type_slug),
                        'color' => $color_slug,
                        'color_label' => wp_strip_all_tags($color_meta['label'] ?? $color_slug),
                        'sizes' => array_values(array_map('strval', (array) ($color_meta['sizes'] ?? array()))),
                        'image' => MG_Virtual_Variant_Manager::build_sku_preview_url($product, $type_slug, $color_slug),
                        'exported_at' => isset($exported[$type_slug]) ? $exported[$type_slug] : '',
                    );
                }
            }
            $data[] = array(
                'product_id' => (int) $product_id,
                'product_name' => $product->get_name(),
                'common_image' => class_exists('MG_Temu_API_Image_Field') ? MG_Temu_API_Image_Field::get_image_url($product_id) : '',
                'groups' => $groups,
            );
        }
        wp_send_json_success($data);
    }

    private static function error_page($errors) {
        $html = '<div style="max-width:900px"><h2>A Temu API export nem készült el</h2><ul style="list-style:disc;padding-left:22px">';
        foreach (array_slice((array) $errors, 0, 100) as $error) {
            $html .= '<li>' . esc_html($error) . '</li>';
        }
        return $html . '</ul><p><a class="button" href="' . esc_url(self::return_url()) . '">Vissza a Temu API exporthoz</a></p></div>';
    }

    public static function render_page() {
        if (!current_user_can('edit_products')) {
            return;
        }
        $settings = self::get_settings();
        ?>
        <div class="mg-panel-body mg-panel-body--temu-api-export">
            <section class="mg-panel-section">
                <div class="mg-panel-section__header"><div><h2><?php esc_html_e('Temu API V3 export', 'mockup-generator'); ?></h2><p><?php esc_html_e('Ez külön export: nem módosítja sem az Allegro CSV-t, sem a régi Temu CSV/XLSX fájlokat.', 'mockup-generator'); ?></p></div></div>
                <?php if (!empty($_GET['mg_temu_api_saved'])): ?><div class="notice notice-success inline"><p><?php esc_html_e('A Temu API export alapadatai mentve.', 'mockup-generator'); ?></p></div><?php endif; ?>
                <div style="padding:14px 16px;margin:16px 0;background:#fff7ed;border-left:4px solid #f97316"><strong><?php esc_html_e('Külön API-formátum', 'mockup-generator'); ?></strong><p style="margin:4px 0 0"><?php esc_html_e('A Temu saját szín-, méret- és típusértékeket kap. A fájl SKU-i TEMU- előtagot kapnak, ezért nem ütköznek az Allegro importtal.', 'mockup-generator'); ?></p></div>

                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="mg_temu_api_save_settings"><?php wp_nonce_field('mg_temu_api_save_settings'); ?>
                    <table class="form-table" role="presentation"><tbody>
                        <tr><th><label for="mg-temu-api-brand"><?php esc_html_e('Alap márka', 'mockup-generator'); ?></label></th><td><input class="regular-text" id="mg-temu-api-brand" name="brand" value="<?php echo esc_attr($settings['brand']); ?>"></td></tr>
                        <tr><th><label for="mg-temu-api-material"><?php esc_html_e('Alap anyag', 'mockup-generator'); ?></label></th><td><input class="regular-text" id="mg-temu-api-material" name="material" value="<?php echo esc_attr($settings['material']); ?>"></td></tr>
                        <tr><th><label for="mg-temu-api-stock"><?php esc_html_e('Alap készlet', 'mockup-generator'); ?></label></th><td><input type="number" min="1" id="mg-temu-api-stock" name="default_stock" value="<?php echo esc_attr($settings['default_stock']); ?>"></td></tr>
                        <tr><th><label for="mg-temu-api-weight"><?php esc_html_e('Alap csomagsúly (g)', 'mockup-generator'); ?></label></th><td><input type="number" min="1" id="mg-temu-api-weight" name="default_weight_g" value="<?php echo esc_attr($settings['default_weight_g']); ?>"><p class="description"><?php esc_html_e('Csak akkor használjuk, ha a Woo-terméknél nincs súly.', 'mockup-generator'); ?></p></td></tr>
                    </tbody></table>
                    <p><button class="button button-primary" type="submit"><?php esc_html_e('Temu API alapadatok mentése', 'mockup-generator'); ?></button></p>
                </form>

                <hr style="margin:28px 0"><h3><?php esc_html_e('Exportálandó termékek kiválasztása', 'mockup-generator'); ?></h3>
                <div id="mg-temu-api-app">
                    <div id="mg-temu-api-product-step">
                        <div class="mg-temu-api-toolbar"><label><?php esc_html_e('Oldalanként:', 'mockup-generator'); ?> <select id="mg-temu-api-per-page"><option>25</option><option>50</option><option>100</option></select></label><label><?php esc_html_e('Főkategória:', 'mockup-generator'); ?> <select id="mg-temu-api-category"><option value=""><?php esc_html_e('— mind —', 'mockup-generator'); ?></option><?php foreach ((array) get_terms(array('taxonomy'=>'product_cat','hide_empty'=>false,'parent'=>0,'orderby'=>'name')) as $term): ?><option value="<?php echo esc_attr($term->term_id); ?>"><?php echo esc_html($term->name); ?></option><?php endforeach; ?></select></label><button type="button" class="button" id="mg-temu-api-select-page"><?php esc_html_e('Oldal kijelölése', 'mockup-generator'); ?></button><button type="button" class="button button-primary" id="mg-temu-api-next"><?php esc_html_e('Tovább a típus–szín sorokhoz', 'mockup-generator'); ?></button></div>
                        <table class="widefat fixed striped"><thead><tr><td class="check-column"><input type="checkbox" id="mg-temu-api-check-page"></td><th style="width:90px"><?php esc_html_e('Kép', 'mockup-generator'); ?></th><th><?php esc_html_e('Termék', 'mockup-generator'); ?></th><th><?php esc_html_e('SKU', 'mockup-generator'); ?></th><th><?php esc_html_e('Kategória', 'mockup-generator'); ?></th></tr></thead><tbody id="mg-temu-api-products"><tr><td colspan="5"><?php esc_html_e('Betöltés…', 'mockup-generator'); ?></td></tr></tbody></table><div id="mg-temu-api-pages" class="mg-temu-api-pages"></div>
                    </div>
                    <div id="mg-temu-api-variant-step" hidden><div class="mg-temu-api-toolbar"><button type="button" class="button" id="mg-temu-api-back"><?php esc_html_e('« Vissza', 'mockup-generator'); ?></button><button type="button" class="button button-primary button-hero" id="mg-temu-api-download"><?php esc_html_e('Kijelölt Temu API CSV letöltése', 'mockup-generator'); ?></button></div><div id="mg-temu-api-variants"></div><div id="mg-temu-api-summary" class="mg-temu-api-summary"></div></div>
                </div>
                <form id="mg-temu-api-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" hidden><input type="hidden" name="action" value="mg_temu_api_export_csv"><input type="hidden" name="selection" id="mg-temu-api-selection"><?php wp_nonce_field('mg_temu_api_export_csv'); ?></form>
                <style>.mg-temu-api-toolbar{display:flex;align-items:center;gap:14px;flex-wrap:wrap;background:#fff;border:1px solid #dcdcde;border-radius:9px;padding:12px;margin:14px 0}.mg-temu-api-pages{text-align:center;margin:14px}.mg-temu-api-pages .button{margin:2px}.mg-temu-api-product{display:grid;gap:10px;margin:15px 0}.mg-temu-api-product h3{margin:0}.mg-temu-api-row{display:grid;grid-template-columns:auto 72px minmax(180px,1fr) minmax(180px,2fr) auto;align-items:center;gap:12px;background:#fff;border:1px solid #dcdcde;border-radius:9px;padding:10px}.mg-temu-api-row img{width:64px;height:64px;object-fit:cover;border-radius:6px}.mg-temu-api-sizes span{display:inline-block;padding:3px 7px;margin:2px;border-radius:12px;background:#f0f0f1}.mg-temu-api-summary{margin-top:16px;padding:14px;background:#f0f6fc;border-left:4px solid #2271b1;font-weight:600}@media(max-width:800px){.mg-temu-api-row{grid-template-columns:auto 60px 1fr}.mg-temu-api-sizes,.mg-temu-api-exported{grid-column:2/-1}}</style>
                <script>
                jQuery(function($){
                    const nonce='<?php echo esc_js(wp_create_nonce('mg_temu_api_nonce')); ?>';let page=1,selected={},groups=[];
                    const esc=v=>$('<div>').text(v==null?'':String(v)).html();
                    function loadProducts(p){page=p;$('#mg-temu-api-products').html('<tr><td colspan="5">Betöltés…</td></tr>');$.post(ajaxurl,{action:'mg_temu_api_get_products',nonce:nonce,page:page,per_page:$('#mg-temu-api-per-page').val(),category_id:$('#mg-temu-api-category').val()}).done(r=>{if(!r.success){return;}$('#mg-temu-api-products').html(r.data.products.map(x=>'<tr><th class="check-column"><input class="mg-temu-api-product-check" type="checkbox" value="'+x.id+'" '+(selected[x.id]?'checked':'')+'></th><td><img src="'+esc(x.image)+'" width="72" height="72" style="object-fit:cover;border-radius:6px"></td><td><strong>'+esc(x.name)+'</strong></td><td>'+esc(x.sku)+'</td><td>'+esc(x.category)+'</td></tr>').join('')||'<tr><td colspan="5">Nincs termék.</td></tr>');let pages='';for(let i=1;i<=r.data.total_pages;i++)pages+='<button type="button" class="button '+(i===page?'button-primary':'')+' mg-temu-api-page" data-page="'+i+'">'+i+'</button>';$('#mg-temu-api-pages').html(pages);});}
                    $(document).on('change','.mg-temu-api-product-check',function(){if(this.checked)selected[this.value]=true;else delete selected[this.value];});$(document).on('click','.mg-temu-api-page',function(){loadProducts(Number($(this).data('page')));});$('#mg-temu-api-check-page').on('change',function(){$('.mg-temu-api-product-check').prop('checked',this.checked).trigger('change');});$('#mg-temu-api-select-page').on('click',function(){$('.mg-temu-api-product-check').prop('checked',true).trigger('change');});$('#mg-temu-api-per-page,#mg-temu-api-category').on('change',()=>loadProducts(1));
                    $('#mg-temu-api-next').on('click',function(){const ids=Object.keys(selected);if(!ids.length){alert('Válassz legalább egy terméket.');return;}$('#mg-temu-api-product-step').prop('hidden',true);$('#mg-temu-api-variant-step').prop('hidden',false);$('#mg-temu-api-variants').html('<p>Variánsok betöltése…</p>');$.post(ajaxurl,{action:'mg_temu_api_get_variants',nonce:nonce,product_ids:ids}).done(r=>{if(!r.success)return;groups=[];let html='';r.data.forEach(p=>{html+='<section class="mg-temu-api-product"><h3>'+esc(p.product_name)+'</h3>'+(p.common_image?'<small>Közös kép beállítva</small>':'<small style="color:#b32d2e">Nincs közös Temu API kép; a variánsképek ettől még exportálhatók.</small>');p.groups.forEach(g=>{const index=groups.push({pid:p.product_id,type:g.type,color:g.color})-1;html+='<label class="mg-temu-api-row"><input type="checkbox" class="mg-temu-api-row-check" value="'+index+'" checked>'+(g.image?'<img src="'+esc(g.image)+'">':'<span></span>')+'<span><strong>'+esc(g.type_label)+'</strong><small style="display:block">'+esc(g.color_label)+'</small></span><span class="mg-temu-api-sizes">'+g.sizes.map(s=>'<span>'+esc(s)+'</span>').join('')+'</span><small class="mg-temu-api-exported">'+(g.exported_at?'API-n már exportálva: '+esc(g.exported_at):'Még nincs API-n exportálva')+'</small></label>';});html+='</section>';});$('#mg-temu-api-variants').html(html);summary();});});
                    $('#mg-temu-api-back').on('click',function(){$('#mg-temu-api-variant-step').prop('hidden',true);$('#mg-temu-api-product-step').prop('hidden',false);});function selection(){return $('.mg-temu-api-row-check:checked').map((i,e)=>groups[Number(e.value)]).get();}function summary(){$('#mg-temu-api-summary').html('Összesen <strong>'+selection().length+'</strong> teljes típus–szín sor kerül a fájlba, minden hozzá tartozó mérettel.');}$(document).on('change','.mg-temu-api-row-check',summary);$('#mg-temu-api-download').on('click',function(){const rows=selection();if(!rows.length){alert('Nincs kiválasztott sor.');return;}$('#mg-temu-api-selection').val(JSON.stringify(rows));document.getElementById('mg-temu-api-form').submit();});loadProducts(1);
                });
                </script>
            </section>
        </div>
        <?php
    }
}
