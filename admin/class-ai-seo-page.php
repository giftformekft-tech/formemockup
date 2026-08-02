<?php
if (!defined('ABSPATH')) exit;

/**
 * Admin oldal a minta SEO leírás AI (GPT-5 mini) generátorhoz.
 * Beállítások: API kulcs, modell, prompt.
 * Eszköz: a meglévő mintákhoz (termékek kiemelt/mockup képe alapján)
 * tömegesen legenerálja a `_mg_sample_seo` leírást ({sample_seo} változó).
 */
class MG_AI_SEO_Page {

    const MENU_SLUG = 'mg-ai-seo';

    public static function init() {
        add_action('admin_post_mg_ai_seo_save', array(__CLASS__, 'handle_save'));
        add_action('admin_post_mg_ai_tag_save', array(__CLASS__, 'handle_tag_save'));
    }

    public static function add_submenu_page() {
        add_submenu_page(
            'mockup-generator',
            __('AI Minta SEO és tagelés', 'mockup-generator'),
            __('AI Minta SEO és tagelés', 'mockup-generator'),
            'manage_options',
            self::MENU_SLUG,
            array(__CLASS__, 'render_page')
        );
    }

    public static function handle_save() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        check_admin_referer('mg_ai_seo_save_action');

        if (!class_exists('MG_AI_SEO_Generator')) {
            wp_die('MG_AI_SEO_Generator not loaded.');
        }

        $input = isset($_POST['mg_ai_seo_settings']) ? wp_unslash($_POST['mg_ai_seo_settings']) : array();
        MG_AI_SEO_Generator::save_settings(is_array($input) ? $input : array());

        wp_redirect(admin_url('admin.php?page=mockup-generator&mg_tab=ai_seo&updated=1'));
        exit;
    }

    public static function handle_tag_save() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        check_admin_referer('mg_ai_tag_save_action');

        if (!class_exists('MG_AI_Tag_Generator')) {
            wp_die('MG_AI_Tag_Generator not loaded.');
        }

        $input = isset($_POST['mg_ai_tag_settings']) ? wp_unslash($_POST['mg_ai_tag_settings']) : array();
        MG_AI_Tag_Generator::save_settings(is_array($input) ? $input : array());

        wp_redirect(admin_url('admin.php?page=mockup-generator&mg_tab=ai_seo&tag_updated=1'));
        exit;
    }

    public static function render_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        if (!class_exists('MG_AI_SEO_Generator')) {
            echo '<div class="wrap"><p>MG_AI_SEO_Generator nem tölthető be.</p></div>';
            return;
        }

        $settings = MG_AI_SEO_Generator::get_settings();
        $ajax_nonce = wp_create_nonce(MG_AI_SEO_Generator::NONCE_ACTION);
        $tag_settings = class_exists('MG_AI_Tag_Generator') ? MG_AI_Tag_Generator::get_settings() : array();
        $tag_ajax_nonce = class_exists('MG_AI_Tag_Generator') ? wp_create_nonce(MG_AI_Tag_Generator::NONCE_ACTION) : '';
        $tag_dictionary = class_exists('MG_AI_Tag_Generator') ? MG_AI_Tag_Generator::get_dictionary() : new WP_Error('missing', '');
        $tag_dictionary_count = is_wp_error($tag_dictionary) ? 0 : count($tag_dictionary['labels']);
        $tag_dictionary_version = is_wp_error($tag_dictionary) ? '' : $tag_dictionary['version'];
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('AI Minta SEO és tagelés (GPT-5 mini)', 'mockup-generator'); ?></h1>

            <?php if (isset($_GET['updated'])): ?>
            <div class="notice notice-success is-dismissible"><p><strong><?php esc_html_e('Beállítások elmentve.', 'mockup-generator'); ?></strong></p></div>
            <?php endif; ?>
            <?php if (isset($_GET['tag_updated'])): ?>
            <div class="notice notice-success is-dismissible"><p><strong><?php esc_html_e('AI minta-tagelési beállítások elmentve.', 'mockup-generator'); ?></strong></p></div>
            <?php endif; ?>

            <p><?php esc_html_e('Ez a modul a termék kiemelt (már legenerált mockup) képe alapján egyedi SEO leírást ír a mintához. Az eredmény a "_mg_sample_seo" mezőbe kerül, amit a terméktípus leírás sablonjában a {sample_seo} változóval illeszthetsz be.', 'mockup-generator'); ?></p>

            <h2 class="title"><?php esc_html_e('Beállítások', 'mockup-generator'); ?></h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="mg_ai_seo_save">
                <?php wp_nonce_field('mg_ai_seo_save_action'); ?>

                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="mg_ai_seo_enabled"><?php esc_html_e('AI generálás engedélyezve', 'mockup-generator'); ?></label></th>
                        <td>
                            <input type="checkbox" id="mg_ai_seo_enabled" name="mg_ai_seo_settings[enabled]" value="1" <?php checked(!empty($settings['enabled'])); ?> />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="mg_ai_seo_api_key"><?php esc_html_e('OpenAI API kulcs', 'mockup-generator'); ?></label></th>
                        <td>
                            <input type="text" id="mg_ai_seo_api_key" name="mg_ai_seo_settings[api_key]" value="<?php echo esc_attr($settings['api_key']); ?>" class="large-text" placeholder="sk-..." autocomplete="off" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="mg_ai_seo_model"><?php esc_html_e('Modell', 'mockup-generator'); ?></label></th>
                        <td>
                            <input type="text" id="mg_ai_seo_model" name="mg_ai_seo_settings[model]" value="<?php echo esc_attr($settings['model']); ?>" class="regular-text" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="mg_ai_seo_endpoint"><?php esc_html_e('API endpoint', 'mockup-generator'); ?></label></th>
                        <td>
                            <input type="text" id="mg_ai_seo_endpoint" name="mg_ai_seo_settings[endpoint]" value="<?php echo esc_attr($settings['endpoint']); ?>" class="large-text" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="mg_ai_seo_prompt"><?php esc_html_e('Prompt', 'mockup-generator'); ?></label></th>
                        <td>
                            <textarea id="mg_ai_seo_prompt" name="mg_ai_seo_settings[prompt]" rows="8" class="large-text"><?php echo esc_textarea($settings['prompt']); ?></textarea>
                            <p class="description"><?php esc_html_e('Elérhető változók:', 'mockup-generator'); ?> <code>{product_name}</code> <code>{product_category}</code></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="mg_ai_seo_max_tokens"><?php esc_html_e('Max kimeneti token', 'mockup-generator'); ?></label></th>
                        <td>
                            <input type="number" id="mg_ai_seo_max_tokens" name="mg_ai_seo_settings[max_output_tokens]" value="<?php echo esc_attr($settings['max_output_tokens']); ?>" min="50" class="small-text" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="mg_ai_seo_delay"><?php esc_html_e('Késleltetés hívások között (ms)', 'mockup-generator'); ?></label></th>
                        <td>
                            <input type="number" id="mg_ai_seo_delay" name="mg_ai_seo_settings[delay_ms]" value="<?php echo esc_attr($settings['delay_ms']); ?>" min="0" class="small-text" />
                            <p class="description"><?php esc_html_e('Tömeges futtatásnál ennyit vár a rendszer két API hívás között (rate limit védelem).', 'mockup-generator'); ?></p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(__('Beállítások mentése', 'mockup-generator')); ?>
            </form>

            <button type="button" class="button" id="mg-ai-seo-test">Kapcsolat tesztelése</button>
            <span id="mg-ai-seo-test-result"></span>

            <hr />

            <h2 class="title"><?php esc_html_e('Meglévő minták elemzése', 'mockup-generator'); ?></h2>
            <p class="description"><?php esc_html_e('Végigmegy a meglévő termékeken, és a kiemelt (mockup) kép alapján legenerálja a minta SEO leírását.', 'mockup-generator'); ?></p>

            <p>
                <label><input type="checkbox" id="mg-ai-seo-force" /> <?php esc_html_e('Már meglévő leírások felülírása (force)', 'mockup-generator'); ?></label>
            </p>
            <p>
                <button type="button" class="button button-primary" id="mg-ai-seo-start"><?php esc_html_e('Futtatás indítása', 'mockup-generator'); ?></button>
                <button type="button" class="button" id="mg-ai-seo-stop" disabled><?php esc_html_e('Leállítás', 'mockup-generator'); ?></button>
            </p>
            <div id="mg-ai-seo-progress-wrap" style="max-width:600px;background:#e2e4e7;border-radius:4px;overflow:hidden;display:none;">
                <div id="mg-ai-seo-progress-bar" style="height:18px;width:0;background:#2271b1;"></div>
            </div>
            <p id="mg-ai-seo-progress-text"></p>
            <ul id="mg-ai-seo-log" style="max-height:300px;overflow:auto;background:#fff;border:1px solid #ccd0d4;padding:10px;max-width:700px;"></ul>

            <hr />

            <h2 class="title"><?php esc_html_e('AI minta-tagelés és kategorizálás', 'mockup-generator'); ?></h2>
            <p class="description">
                <?php esc_html_e('Ez ugyanazt a kanonikus taglistát és kategória-választási logikát használja, mint az ai_rename_gui5.py. A kiemelt képet elemzi, csak a szótárból választ taget, és az eredményt WooCommerce product_tag-ként menti.', 'mockup-generator'); ?>
            </p>
            <p>
                <strong><?php esc_html_e('Beépített szótár:', 'mockup-generator'); ?></strong>
                <?php echo esc_html($tag_dictionary_count); ?> <?php esc_html_e('kanonikus tag', 'mockup-generator'); ?>
                <?php if ($tag_dictionary_version !== ''): ?>
                    (<?php echo esc_html($tag_dictionary_version); ?>)
                <?php else: ?>
                    <span style="color:#b32d2e;"><?php esc_html_e('nem tölthető be', 'mockup-generator'); ?></span>
                <?php endif; ?>
            </p>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="mg_ai_tag_save">
                <?php wp_nonce_field('mg_ai_tag_save_action'); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="mg_ai_tag_enabled"><?php esc_html_e('AI minta-tagelés engedélyezve', 'mockup-generator'); ?></label></th>
                        <td><input type="checkbox" id="mg_ai_tag_enabled" name="mg_ai_tag_settings[enabled]" value="1" <?php checked(!empty($tag_settings['enabled'])); ?> /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="mg_ai_tag_max_tokens"><?php esc_html_e('Max. kimeneti token', 'mockup-generator'); ?></label></th>
                        <td>
                            <input type="number" id="mg_ai_tag_max_tokens" name="mg_ai_tag_settings[max_output_tokens]" value="<?php echo esc_attr($tag_settings['max_output_tokens'] ?? MG_AI_Tag_Generator::DEFAULT_MAX_TOKENS); ?>" min="<?php echo (int) MG_AI_Tag_Generator::MIN_MAX_TOKENS; ?>" max="4000" class="small-text" />
                            <p class="description"><?php esc_html_e('A képes GPT-5 strukturált válasz miatt legalább 4000 tokenes keret szükséges; a tényleges JSON ennél jóval rövidebb.', 'mockup-generator'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="mg_ai_tag_delay"><?php esc_html_e('Késleltetés hívások között (ms)', 'mockup-generator'); ?></label></th>
                        <td><input type="number" id="mg_ai_tag_delay" name="mg_ai_tag_settings[delay_ms]" value="<?php echo esc_attr($tag_settings['delay_ms'] ?? MG_AI_Tag_Generator::DEFAULT_DELAY_MS); ?>" min="0" class="small-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="mg_ai_tag_workers"><?php esc_html_e('Párhuzamos AI workerek', 'mockup-generator'); ?></label></th>
                        <td>
                            <input type="number" id="mg_ai_tag_workers" name="mg_ai_tag_settings[workers]" value="<?php echo esc_attr($tag_settings['workers'] ?? MG_AI_Tag_Generator::DEFAULT_WORKERS); ?>" min="1" max="<?php echo (int) MG_AI_Tag_Generator::MAX_WORKERS; ?>" class="small-text" />
                            <p class="description"><?php esc_html_e('Ennyi minta elemzése futhat párhuzamosan. 4 az alapérték; nano modellnél ne állítsd magasra a TPM-limit miatt.', 'mockup-generator'); ?></p>
                        </td>
                    </tr>
                </table>
                <p><?php esc_html_e('Az OpenAI kulcs, modell és endpoint a fenti AI SEO beállításból öröklődik. A workerek az admin queue-ban párhuzamos HTTP-kéréseket jelentenek, nem egyetlen API-kérésen belüli modell-szálakat.', 'mockup-generator'); ?></p>
                <?php submit_button(__('Tagelési beállítások mentése', 'mockup-generator'), 'secondary', 'submit', false); ?>
            </form>

            <div style="max-width:760px;border:1px solid #ccd0d4;background:#fff;padding:14px 16px;margin:18px 0;">
                <h3 style="margin-top:0;"><?php esc_html_e('Új fogalom-javaslatok', 'mockup-generator'); ?></h3>
                <p class="description">
                    <?php esc_html_e('A mentett AI-elemzések kanonikus listán kívüli, keresés szempontjából hasznos javaslatai összesítve tölthetők le. A zajos javaslatokat az export is kiszűri.', 'mockup-generator'); ?>
                </p>
                <p>
                    <a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=mg_ai_tag_unmatched_export'), 'mg_ai_tag_unmatched_export')); ?>">
                        <?php esc_html_e('Új fogalom-javaslatok letöltése (CSV)', 'mockup-generator'); ?>
                    </a>
                </p>
            </div>

            <div id="mg-ai-tag-test" style="max-width:760px;border:1px solid #ccd0d4;background:#fff;padding:14px 16px;margin:18px 0;">
                <h3 style="margin-top:0;"><?php esc_html_e('Tesztelemzés egy mintán', 'mockup-generator'); ?></h3>
                <p class="description">
                    <?php esc_html_e('Keress rá egy mintára név vagy ID alapján, válaszd ki, majd futtasd le az elemzést. Ez előnézet: a rendszer nem ment taget, kategóriát vagy metaadatot.', 'mockup-generator'); ?>
                </p>
                <p>
                    <label for="mg-ai-tag-test-search"><strong><?php esc_html_e('Minta keresése', 'mockup-generator'); ?></strong></label><br />
                    <input type="search" id="mg-ai-tag-test-search" class="regular-text" placeholder="<?php echo esc_attr__('Minta neve vagy ID', 'mockup-generator'); ?>" />
                    <button type="button" class="button" id="mg-ai-tag-test-search-btn"><?php esc_html_e('Minták keresése', 'mockup-generator'); ?></button>
                </p>
                <p>
                    <label for="mg-ai-tag-test-product"><strong><?php esc_html_e('Kiválasztott minta', 'mockup-generator'); ?></strong></label><br />
                    <select id="mg-ai-tag-test-product" style="min-width:560px;max-width:100%;">
                        <option value=""><?php esc_html_e('Előbb keress mintát…', 'mockup-generator'); ?></option>
                    </select>
                    <button type="button" class="button button-primary" id="mg-ai-tag-test-run" disabled><?php esc_html_e('Kiválasztott minta elemzése', 'mockup-generator'); ?></button>
                </p>
                <div id="mg-ai-tag-test-result" style="white-space:pre-wrap;background:#f6f7f7;border:1px solid #ccd0d4;padding:10px;min-height:40px;"></div>
            </div>

            <p class="description" style="max-width:760px;">
                <?php esc_html_e('A „régi tagek cseréje” bekapcsolva eltávolítja az adott termék korábbi product_tag tageit, és csak az AI által kiválasztott kanonikus tageket hagyja meg. Kikapcsolva a kanonikus tagek hozzáadódnak a régiekhez. A kategóriák frissítése külön kapcsolható.', 'mockup-generator'); ?>
            </p>
            <p>
                <label><input type="checkbox" id="mg-ai-tag-force" /> <?php esc_html_e('Már tagelt minták újraelemzése (force)', 'mockup-generator'); ?></label><br />
                <label><input type="checkbox" id="mg-ai-tag-replace" /> <?php esc_html_e('Régi product_tag tagek cseréje (különben csak hozzáadás)', 'mockup-generator'); ?></label><br />
                <label><input type="checkbox" id="mg-ai-tag-categories" /> <?php esc_html_e('WooCommerce kategóriák frissítése az AI választása alapján', 'mockup-generator'); ?></label>
            </p>
            <p>
                <button type="button" class="button button-primary" id="mg-ai-tag-start"><?php esc_html_e('Meglévő minták újratagelése', 'mockup-generator'); ?></button>
                <button type="button" class="button" id="mg-ai-tag-stop" disabled><?php esc_html_e('Leállítás', 'mockup-generator'); ?></button>
            </p>
            <div id="mg-ai-tag-progress-wrap" style="max-width:600px;background:#e2e4e7;border-radius:4px;overflow:hidden;display:none;">
                <div id="mg-ai-tag-progress-bar" style="height:18px;width:0;background:#2271b1;"></div>
            </div>
            <p id="mg-ai-tag-progress-text"></p>
            <ul id="mg-ai-tag-log" style="max-height:300px;overflow:auto;background:#fff;border:1px solid #ccd0d4;padding:10px;max-width:760px;"></ul>
        </div>
        <script>
        (function ($) {
            var ajaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
            var nonce = <?php echo wp_json_encode($ajax_nonce); ?>;
            var stopped = false;

            function logLine(text, isError) {
                var $li = $('<li></li>').text(text);
                if (isError) { $li.css('color', '#b32d2e'); }
                $('#mg-ai-seo-log').prepend($li);
            }

            function post(action, data) {
                return $.ajax({
                    url: ajaxUrl,
                    method: 'POST',
                    dataType: 'json',
                    data: $.extend({ action: action, nonce: nonce }, data || {})
                });
            }

            $('#mg-ai-seo-test').on('click', function () {
                var $result = $('#mg-ai-seo-test-result');
                $result.text('Tesztelés...');
                post('mg_ai_seo_test_connection').done(function (resp) {
                    if (resp && resp.success) {
                        $result.text('OK');
                    } else {
                        $result.text('Hiba: ' + (resp && resp.data && resp.data.message ? resp.data.message : 'ismeretlen hiba'));
                    }
                }).fail(function () {
                    $result.text('Hiba a kapcsolat tesztelésekor.');
                });
            });

            function runNext(ids, index, force, stats) {
                if (stopped || index >= ids.length) {
                    $('#mg-ai-seo-start').prop('disabled', false);
                    $('#mg-ai-seo-stop').prop('disabled', true);
                    $('#mg-ai-seo-progress-text').text('Kész: ' + stats.done + ' / ' + ids.length + ' (siker: ' + stats.ok + ', hiba: ' + stats.error + ')');
                    return;
                }
                var productId = ids[index];
                post('mg_ai_seo_generate_one', { product_id: productId, force: force ? '1' : '0' }).always(function (resp) {
                    stats.done++;
                    if (resp && resp.success) {
                        stats.ok++;
                        logLine('#' + productId + ': OK');
                    } else {
                        stats.error++;
                        var msg = (resp && resp.data && resp.data.message) ? resp.data.message : 'ismeretlen hiba';
                        logLine('#' + productId + ': ' + msg, true);
                    }
                    var pct = Math.round((stats.done / ids.length) * 100);
                    $('#mg-ai-seo-progress-bar').css('width', pct + '%');
                    $('#mg-ai-seo-progress-text').text(stats.done + ' / ' + ids.length + ' (siker: ' + stats.ok + ', hiba: ' + stats.error + ')');
                    setTimeout(function () { runNext(ids, index + 1, force, stats); }, <?php echo (int) $settings['delay_ms']; ?>);
                });
            }

            $('#mg-ai-seo-start').on('click', function () {
                stopped = false;
                var force = $('#mg-ai-seo-force').is(':checked');
                $('#mg-ai-seo-log').empty();
                $('#mg-ai-seo-progress-wrap').show();
                $('#mg-ai-seo-progress-bar').css('width', '0%');
                $('#mg-ai-seo-progress-text').text('Minták lekérése...');
                $('#mg-ai-seo-start').prop('disabled', true);
                $('#mg-ai-seo-stop').prop('disabled', false);
                post('mg_ai_seo_candidates', { force: force ? '1' : '0' }).done(function (resp) {
                    if (!resp || !resp.success) {
                        $('#mg-ai-seo-progress-text').text('Nem sikerült lekérni a mintákat.');
                        $('#mg-ai-seo-start').prop('disabled', false);
                        $('#mg-ai-seo-stop').prop('disabled', true);
                        return;
                    }
                    var ids = resp.data.ids || [];
                    if (!ids.length) {
                        $('#mg-ai-seo-progress-text').text('Nincs feldolgozandó minta.');
                        $('#mg-ai-seo-start').prop('disabled', false);
                        $('#mg-ai-seo-stop').prop('disabled', true);
                        return;
                    }
                    runNext(ids, 0, force, { done: 0, ok: 0, error: 0 });
                });
            });

            $('#mg-ai-seo-stop').on('click', function () {
                stopped = true;
                $(this).prop('disabled', true);
            });
        })(jQuery);
        </script>
        <script>
        (function ($) {
            var ajaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
            var nonce = <?php echo wp_json_encode($tag_ajax_nonce); ?>;
            var stopped = false;

            function tagLogLine(text, isError) {
                var $li = $('<li></li>').text(text);
                if (isError) { $li.css('color', '#b32d2e'); }
                $('#mg-ai-tag-log').prepend($li);
            }

            function tagPost(action, data) {
                return $.ajax({
                    url: ajaxUrl,
                    method: 'POST',
                    dataType: 'json',
                    data: $.extend({ action: action, nonce: nonce }, data || {})
                });
            }

            function loadTagTestCandidates() {
                var $searchButton = $('#mg-ai-tag-test-search-btn');
                var $select = $('#mg-ai-tag-test-product');
                var search = $.trim($('#mg-ai-tag-test-search').val() || '');
                $searchButton.prop('disabled', true);
                $select.prop('disabled', true).empty().append($('<option></option>').text('Minták keresése...'));
                tagPost('mg_ai_tag_test_candidates', { search: search }).done(function (resp) {
                    if (!resp || !resp.success) {
                        $select.empty().append($('<option></option>').val('').text('Hiba a minták lekérésekor.'));
                        $('#mg-ai-tag-test-run').prop('disabled', true);
                        return;
                    }
                    var products = resp.data ? (resp.data.products || []) : [];
                    $select.empty();
                    if (!products.length) {
                        $select.append($('<option></option>').val('').text('Nincs találat képpel rendelkező mintára.'));
                        $('#mg-ai-tag-test-run').prop('disabled', true);
                        return;
                    }
                    $.each(products, function (_, product) {
                        var label = '#' + product.id + ' – ' + (product.name || 'Névtelen minta');
                        if (product.sku) { label += ' (SKU: ' + product.sku + ')'; }
                        $select.append($('<option></option>').val(product.id).text(label));
                    });
                    $select.prop('disabled', false).prop('selectedIndex', 0);
                    $('#mg-ai-tag-test-run').prop('disabled', false);
                }).fail(function () {
                    $select.empty().append($('<option></option>').val('').text('Hiba a minták lekérésekor.'));
                    $('#mg-ai-tag-test-run').prop('disabled', true);
                }).always(function () {
                    $searchButton.prop('disabled', false);
                });
            }

            $('#mg-ai-tag-test-search-btn').on('click', function () {
                loadTagTestCandidates();
            });

            $('#mg-ai-tag-test-search').on('keydown', function (event) {
                if (event.key === 'Enter') {
                    event.preventDefault();
                    loadTagTestCandidates();
                }
            });

            $('#mg-ai-tag-test-run').on('click', function () {
                var $button = $(this);
                var productId = parseInt($('#mg-ai-tag-test-product').val(), 10) || 0;
                if (!$('#mg_ai_tag_enabled').is(':checked')) {
                    window.alert('Előbb engedélyezd az AI minta-tagelést, majd mentsd el a beállítást.');
                    return;
                }
                if (!productId) {
                    window.alert('Válassz ki egy mintát.');
                    return;
                }

                var replaceTags = $('#mg-ai-tag-replace').is(':checked');
                var updateCategories = $('#mg-ai-tag-categories').is(':checked');
                var $result = $('#mg-ai-tag-test-result');
                $button.prop('disabled', true);
                $result.text('Elemzés folyamatban…');
                tagPost('mg_ai_tag_one', {
                    product_id: productId,
                    replace_tags: replaceTags ? '1' : '0',
                    update_categories: updateCategories ? '1' : '0',
                    preview: '1',
                    cache_shard: 0,
                    cache_shards: 1
                }).done(function (resp) {
                    if (!resp || !resp.success) {
                        $result.text('Hiba: ' + (resp && resp.data && resp.data.message ? resp.data.message : 'ismeretlen hiba'));
                        return;
                    }
                    var data = resp.data || {};
                    var tags = (data.tags || []).join(', ') || 'nincs kanonikus tag';
                    var categories = (data.category_names || []).join(' > ');
                    if (!categories) {
                        var categoryIds = data.categories || {};
                        categories = 'ID-k: ' + (categoryIds.main_id || 0) + ' / ' + (categoryIds.sub_id || 0);
                    }
                    var unmatched = (data.unmatched_concepts || []).join(', ') || 'nincs';
                    var confidence = typeof data.confidence === 'number'
                        ? Math.round(data.confidence * 100) + '%'
                        : 'nincs adat';
                    var cache = data.cache_usage || {};
                    var lines = [
                        'ELŐNÉZET – #' + productId + (data.product_name ? ' ' + data.product_name : ''),
                        '',
                        'Javasolt SEO cím: ' + (data.title_hu || 'nincs'),
                        'Javasolt tagek: ' + tags,
                        'Javasolt kategória: ' + categories,
                        'Bizonyosság: ' + confidence,
                        'Nem illeszkedő fogalmak: ' + unmatched,
                        '',
                        'Mentés: NEM történt (sem tag, sem kategória, sem metaadat).',
                        'Cache: ' + (cache.cached_tokens || 0) + ' token'
                    ];
                    $result.text(lines.join('\n'));
                }).fail(function () {
                    $result.text('Hiba az elemzés kérésekor.');
                }).always(function () {
                    $button.prop('disabled', false);
                });
            });

            var configuredTagWorkers = <?php echo (int) ($tag_settings['workers'] ?? MG_AI_Tag_Generator::DEFAULT_WORKERS); ?>;
            var configuredTagDelay = <?php echo (int) ($tag_settings['delay_ms'] ?? MG_AI_Tag_Generator::DEFAULT_DELAY_MS); ?>;

            function updateTagProgress(state) {
                var stats = state.stats;
                var pct = Math.round((stats.done / state.ids.length) * 100);
                $('#mg-ai-tag-progress-bar').css('width', pct + '%');
                $('#mg-ai-tag-progress-text').text(stats.done + ' / ' + state.ids.length + ' (siker: ' + stats.ok + ', hiba: ' + stats.error + ', futó: ' + state.active + ')');
            }

            function finishTagRun(state) {
                if (!state || state.finished || state.active > 0) {
                    return;
                }
                if (!stopped && state.nextIndex < state.ids.length) {
                    return;
                }
                state.finished = true;
                $('#mg-ai-tag-start').prop('disabled', false);
                $('#mg-ai-tag-stop').prop('disabled', true);
                $('#mg-ai-tag-progress-text').text((stopped ? 'Leállítva: ' : 'Kész: ') + state.stats.done + ' / ' + state.ids.length + ' (siker: ' + state.stats.ok + ', hiba: ' + state.stats.error + ')');
            }

            function runTagWorker(state) {
                if (!state || state.finished) {
                    return;
                }
                if (stopped || state.nextIndex >= state.ids.length) {
                    finishTagRun(state);
                    return;
                }

                var index = state.nextIndex++;
                var productId = state.ids[index];
                state.active++;
                tagPost('mg_ai_tag_one', {
                    product_id: productId,
                    replace_tags: state.replaceTags ? '1' : '0',
                    update_categories: state.updateCategories ? '1' : '0',
                    cache_shard: state.cacheShards > 1 ? (index % state.cacheShards) : 0,
                    cache_shards: state.cacheShards
                }).always(function (resp) {
                    state.active--;
                    state.stats.done++;
                    if (resp && resp.success) {
                        state.stats.ok++;
                        var data = resp.data || {};
                        var tags = (data.tags || []).join(', ');
                        var unmatched = (data.unmatched_concepts || []).join(', ');
                        var cache = data.cache_usage || {};
                        var cacheText = cache.cached_tokens ? (' | cache: ' + cache.cached_tokens + ' token') : '';
                        tagLogLine('#' + productId + ': OK | ' + (tags || 'nincs kanonikus tag') + cacheText + (unmatched ? ' | új fogalom: ' + unmatched : ''));
                    } else {
                        state.stats.error++;
                        var msg = (resp && resp.data && resp.data.message) ? resp.data.message : 'ismeretlen hiba';
                        tagLogLine('#' + productId + ': ' + msg, true);
                    }
                    updateTagProgress(state);

                    if (stopped || state.nextIndex >= state.ids.length) {
                        finishTagRun(state);
                    } else {
                        state.timers.push(setTimeout(function () {
                            runTagWorker(state);
                        }, state.delayMs));
                    }
                });
            }

            function runTagParallel(ids, replaceTags, updateCategories, cacheShards, workers, delayMs) {
                var workerCount = parseInt(workers, 10) || 1;
                workerCount = Math.max(1, Math.min(workerCount, 8, ids.length));
                var state = {
                    ids: ids,
                    replaceTags: replaceTags,
                    updateCategories: updateCategories,
                    cacheShards: cacheShards,
                    delayMs: Math.max(0, parseInt(delayMs, 10) || 0),
                    nextIndex: 0,
                    active: 0,
                    finished: false,
                    timers: [],
                    stats: { done: 0, ok: 0, error: 0 }
                };
                for (var i = 0; i < workerCount; i++) {
                    runTagWorker(state);
                }
            }

            $('#mg-ai-tag-start').on('click', function () {
                if (!$('#mg_ai_tag_enabled').is(':checked')) {
                    window.alert('Előbb engedélyezd az AI minta-tagelést, majd mentsd el a beállítást.');
                    return;
                }
                stopped = false;
                var force = $('#mg-ai-tag-force').is(':checked');
                var replaceTags = $('#mg-ai-tag-replace').is(':checked');
                var updateCategories = $('#mg-ai-tag-categories').is(':checked');
                if (replaceTags || updateCategories) {
                    var warning = 'A futtatás módosítani fogja a meglévő termékadatokat.';
                    if (replaceTags) { warning += '\n\nA régi product_tag tagek törlődnek, és csak az AI kanonikus tagei maradnak.'; }
                    if (updateCategories) { warning += '\n\nA WooCommerce kategóriák az AI választása alapján cserélődnek.'; }
                    if (!window.confirm(warning + '\n\nFolytatod?')) { return; }
                }
                $('#mg-ai-tag-log').empty();
                $('#mg-ai-tag-progress-wrap').show();
                $('#mg-ai-tag-progress-bar').css('width', '0%');
                $('#mg-ai-tag-progress-text').text('Minták lekérése...');
                $('#mg-ai-tag-start').prop('disabled', true);
                $('#mg-ai-tag-stop').prop('disabled', false);
                tagLogLine('Beállítás: ' + (replaceTags ? 'régi tagek cseréje' : 'tagek hozzáadása') + (updateCategories ? ', kategóriák frissítése' : ''));
                tagPost('mg_ai_tag_candidates', { force: force ? '1' : '0' }).done(function (resp) {
                    if (!resp || !resp.success) {
                        $('#mg-ai-tag-progress-text').text('Nem sikerült lekérni a mintákat.');
                        $('#mg-ai-tag-start').prop('disabled', false);
                        $('#mg-ai-tag-stop').prop('disabled', true);
                        return;
                    }
                    var ids = resp.data.ids || [];
                    var cacheShards = parseInt(resp.data.cache_shards || 1, 10);
                    if (!cacheShards || cacheShards < 1) { cacheShards = 1; }
                    tagLogLine('Szótár: ' + (resp.data.dictionary_count || 0) + ' tag | verzió: ' + (resp.data.dictionary_version || 'ismeretlen'));
                    tagLogLine('Prompt cache: ' + cacheShards + ' stabil kulcs a nagyobb köteghez.');
                    tagLogLine('Párhuzamos workerek: ' + Math.max(1, Math.min(parseInt(configuredTagWorkers, 10) || 1, 8)) + ' | késleltetés workerenként: ' + configuredTagDelay + ' ms');
                    if (!ids.length) {
                        $('#mg-ai-tag-progress-text').text('Nincs feldolgozandó minta.');
                        $('#mg-ai-tag-start').prop('disabled', false);
                        $('#mg-ai-tag-stop').prop('disabled', true);
                        return;
                    }
                    runTagParallel(ids, replaceTags, updateCategories, cacheShards, configuredTagWorkers, configuredTagDelay);
                }).fail(function () {
                    $('#mg-ai-tag-progress-text').text('Hiba a minták lekérésekor.');
                    $('#mg-ai-tag-start').prop('disabled', false);
                    $('#mg-ai-tag-stop').prop('disabled', true);
                });
            });

            $('#mg-ai-tag-stop').on('click', function () {
                stopped = true;
                $(this).prop('disabled', true);
            });
        })(jQuery);
        </script>
        <?php
    }
}
