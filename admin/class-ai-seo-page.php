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

        wp_redirect(admin_url('admin.php?page=' . self::MENU_SLUG . '&updated=1'));
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

        wp_redirect(admin_url('admin.php?page=' . self::MENU_SLUG . '&tag_updated=1'));
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
                        <td><input type="number" id="mg_ai_tag_max_tokens" name="mg_ai_tag_settings[max_output_tokens]" value="<?php echo esc_attr($tag_settings['max_output_tokens'] ?? MG_AI_Tag_Generator::DEFAULT_MAX_TOKENS); ?>" min="200" max="4000" class="small-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="mg_ai_tag_delay"><?php esc_html_e('Késleltetés hívások között (ms)', 'mockup-generator'); ?></label></th>
                        <td><input type="number" id="mg_ai_tag_delay" name="mg_ai_tag_settings[delay_ms]" value="<?php echo esc_attr($tag_settings['delay_ms'] ?? MG_AI_Tag_Generator::DEFAULT_DELAY_MS); ?>" min="0" class="small-text" /></td>
                    </tr>
                </table>
                <p><?php esc_html_e('Az OpenAI kulcs, modell és endpoint a fenti AI SEO beállításból öröklődik.', 'mockup-generator'); ?></p>
                <?php submit_button(__('Tagelési beállítások mentése', 'mockup-generator'), 'secondary', 'submit', false); ?>
            </form>

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

            function runTagNext(ids, index, force, replaceTags, updateCategories, cacheShards, stats) {
                if (stopped || index >= ids.length) {
                    $('#mg-ai-tag-start').prop('disabled', false);
                    $('#mg-ai-tag-stop').prop('disabled', true);
                    $('#mg-ai-tag-progress-text').text('Kész: ' + stats.done + ' / ' + ids.length + ' (siker: ' + stats.ok + ', hiba: ' + stats.error + ')');
                    return;
                }
                var productId = ids[index];
                tagPost('mg_ai_tag_one', {
                    product_id: productId,
                    replace_tags: replaceTags ? '1' : '0',
                    update_categories: updateCategories ? '1' : '0',
                    cache_shard: cacheShards > 1 ? (index % cacheShards) : 0,
                    cache_shards: cacheShards
                }).always(function (resp) {
                    stats.done++;
                    if (resp && resp.success) {
                        stats.ok++;
                        var data = resp.data || {};
                        var tags = (data.tags || []).join(', ');
                        var unmatched = (data.unmatched_concepts || []).join(', ');
                        var cache = data.cache_usage || {};
                        var cacheText = cache.cached_tokens ? (' | cache: ' + cache.cached_tokens + ' token') : '';
                        tagLogLine('#' + productId + ': OK | ' + (tags || 'nincs kanonikus tag') + cacheText + (unmatched ? ' | új fogalom: ' + unmatched : ''));
                    } else {
                        stats.error++;
                        var msg = (resp && resp.data && resp.data.message) ? resp.data.message : 'ismeretlen hiba';
                        tagLogLine('#' + productId + ': ' + msg, true);
                    }
                    var pct = Math.round((stats.done / ids.length) * 100);
                    $('#mg-ai-tag-progress-bar').css('width', pct + '%');
                    $('#mg-ai-tag-progress-text').text(stats.done + ' / ' + ids.length + ' (siker: ' + stats.ok + ', hiba: ' + stats.error + ')');
                    setTimeout(function () {
                        runTagNext(ids, index + 1, force, replaceTags, updateCategories, cacheShards, stats);
                    }, <?php echo (int) ($tag_settings['delay_ms'] ?? MG_AI_Tag_Generator::DEFAULT_DELAY_MS); ?>);
                });
            }

            $('#mg-ai-tag-start').on('click', function () {
                if (!$('#mg-ai-tag-enabled').is(':checked')) {
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
                    if (!ids.length) {
                        $('#mg-ai-tag-progress-text').text('Nincs feldolgozandó minta.');
                        $('#mg-ai-tag-start').prop('disabled', false);
                        $('#mg-ai-tag-stop').prop('disabled', true);
                        return;
                    }
                    runTagNext(ids, 0, force, replaceTags, updateCategories, cacheShards, { done: 0, ok: 0, error: 0 });
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
