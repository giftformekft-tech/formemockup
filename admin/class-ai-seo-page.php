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
    }

    public static function add_submenu_page() {
        add_submenu_page(
            'mockup-generator',
            __('AI Minta SEO', 'mockup-generator'),
            __('AI Minta SEO', 'mockup-generator'),
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
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('AI Minta SEO leírás (GPT-5 mini)', 'mockup-generator'); ?></h1>

            <?php if (isset($_GET['updated'])): ?>
            <div class="notice notice-success is-dismissible"><p><strong><?php esc_html_e('Beállítások elmentve.', 'mockup-generator'); ?></strong></p></div>
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
        <?php
    }
}
