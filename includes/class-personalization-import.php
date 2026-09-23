<?php
if (!defined('ABSPATH')) exit;

/** Resolves DesignFlow evidence against explicitly configured local presets. */
class MG_Personalization_Import {
    const OPTION = 'mg_personalization_mappings';

    public static function profiles() {
        return array(
            'month' => array('label' => 'Hónap', 'kinds' => array('month')),
            'name' => array('label' => 'Név', 'kinds' => array('name')),
            'name_year' => array('label' => 'Név + évszám', 'kinds' => array('name', 'year')),
            'age_year' => array('label' => 'Életkor / eltelt évek + évszám', 'kinds' => array('age', 'year')),
            'year' => array('label' => 'Évszám', 'kinds' => array('year')),
            'year_month' => array('label' => 'Évszám + hónap', 'kinds' => array('year', 'month')),
        );
    }

    public static function labels() {
        return array('month' => 'Hónap', 'name' => 'Név', 'year' => 'Évszám', 'age' => 'Életkor / eltelt évek');
    }

    public static function mappings() {
        $value = get_option(self::OPTION, array());
        return is_array($value) ? $value : array();
    }

    public static function save_mappings($raw) {
        if (!is_array($raw)) return new WP_Error('personalization_mapping', 'Hibás megfeleltetés.');
        $clean = array();
        foreach (self::profiles() as $key => $profile) {
            $entry = isset($raw[$key]) && is_array($raw[$key]) ? $raw[$key] : array();
            if (empty($entry['preset_id'])) continue;
            $preset_id = is_string($entry['preset_id']) ? sanitize_key($entry['preset_id']) : '';
            $fields = array();
            foreach ($profile['kinds'] as $kind) {
                $value = $entry['fields'][$kind] ?? '';
                $fields[$kind] = is_string($value) ? sanitize_key($value) : '';
            }
            $entry = array('preset_id' => $preset_id, 'fields' => $fields);
            $checked = self::validate_mapping($key, $entry);
            if (is_wp_error($checked)) return $checked;
            $clean[$key] = $entry;
        }
        update_option(self::OPTION, $clean, false);
        return true;
    }

    public static function validate_mapping($profile_key, $entry) {
        $profiles = self::profiles();
        $profile = $profiles[$profile_key] ?? null;
        $preset = is_array($entry) ? MG_Custom_Fields_Manager::get_preset($entry['preset_id'] ?? '') : null;
        if (!$profile || !$preset || empty($preset['fields'])) {
            return new WP_Error('personalization_mapping', 'Hiányzó vagy üres preset: ' . ($profile['label'] ?? $profile_key));
        }
        $available = array();
        foreach ($preset['fields'] as $field) $available[$field['id']] = $field;
        $selected = array();
        foreach ($profile['kinds'] as $kind) {
            $id = $entry['fields'][$kind] ?? '';
            $field = $available[$id] ?? null;
            $types = $kind === 'name' ? array('text') : ($kind === 'month' ? array('text', 'select') : array('text', 'number', 'select'));
            if (!$field || in_array($id, $selected, true) || !in_array($field['type'], $types, true)) {
                return new WP_Error('personalization_mapping', 'Válassz külön, megfelelő típusú mezőt: ' . $profile['label'] . ' / ' . self::labels()[$kind]);
            }
            $selected[] = $id;
        }
        foreach ($available as $id => $field) {
            if (!empty($field['required']) && !in_array($id, $selected, true)) {
                return new WP_Error('personalization_mapping', 'A preset további kötelező mezőt tartalmaz: ' . $profile['label']);
            }
        }
        return $preset;
    }

    public static function fold($text) {
        return strtolower(strtr(trim($text), array('Á'=>'a','É'=>'e','Í'=>'i','Ó'=>'o','Ö'=>'o','Ő'=>'o','Ú'=>'u','Ü'=>'u','Ű'=>'u','á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ö'=>'o','ő'=>'o','ú'=>'u','ü'=>'u','ű'=>'u')));
    }

    public static function month($value) {
        $months = array('január','február','március','április','május','június','július','augusztus','szeptember','október','november','december');
        $value = self::fold($value);
        foreach ($months as $month) {
            if (preg_match('/^' . self::fold($month) . '(?:ban|ben|i)?$/', $value)) return $month;
        }
        return '';
    }

    private static function review($message) {
        return array('state' => 'review', 'message' => $message, 'preset_id' => '');
    }

    public static function resolve($data) {
        if ($data === null) return array('state' => 'missing', 'message' => 'Nincs egyedi mező elemzés.', 'preset_id' => '');
        if (!is_array($data) || ($data['schema_version'] ?? null) !== 1 || !is_array($data['fields'] ?? null)) {
            return self::review('Hibás vagy nem támogatott egyedi mező elemzés.');
        }
        if (($data['basis'] ?? '') !== 'final_image') return self::review('A kész kép elemzése szükséges.');
        if (($data['status'] ?? '') === 'none' && !$data['fields']) {
            return array('state' => 'none', 'message' => 'Nem talált egyedi mezőt.', 'preset_id' => '');
        }
        if (($data['status'] ?? '') !== 'detected' || !$data['fields'] || count($data['fields']) > 4) {
            return self::review('Bizonytalan felismerés – válassz kézzel.');
        }
        $fields = array();
        foreach ($data['fields'] as $field) {
            if (!is_array($field)) return self::review('Hibás felismert mező.');
            $kind = $field['kind'] ?? '';
            if (!is_string($kind) || !isset(self::labels()[$kind]) || isset($fields[$kind]) || ($field['confidence'] ?? '') !== 'high') {
                return self::review('Nem egyértelmű mezőkombináció.');
            }
            foreach (array('value', 'visible_text', 'evidence') as $key) {
                if (!is_string($field[$key] ?? null) || trim($field[$key]) === '' || strlen($field[$key]) > 2000) {
                    return self::review('Hiányzó vagy hibás szövegbizonyíték.');
                }
            }
            $value = trim($field['value']);
            $visible = self::fold($field['visible_text']);
            if (strpos(self::fold($field['evidence']), $visible) === false) return self::review('A felismert szöveg nem szerepel az indoklásban.');
            if ($kind === 'month') {
                $value = self::month($value);
                if ($value === '' || self::month($field['visible_text']) !== $value) return self::review('Nem egyértelmű hónap.');
            } elseif ($kind === 'year' || $kind === 'age') {
                $pattern = $kind === 'year' ? '/^[1-9][0-9]{3}$/' : '/^(?:0|[1-9][0-9]{0,2})$/';
                if (!preg_match($pattern, $value) || !preg_match('/(?<![0-9])' . preg_quote($value, '/') . '(?![0-9])/', $visible)) return self::review('Nem egyértelmű évszám vagy életkor.');
            } elseif (strpos($visible, self::fold($value)) === false) {
                return self::review('A név nem olvasható egyértelműen.');
            }
            $fields[$kind] = $value;
        }
        $keys = array_keys($fields);
        sort($keys);
        $profile_key = '';
        foreach (self::profiles() as $key => $profile) {
            $expected = $profile['kinds']; sort($expected);
            if ($expected === $keys) $profile_key = $key;
        }
        if ($profile_key === '') return self::review('Ehhez a mezőkombinációhoz nincs felismerési profil.');
        $entry = self::mappings()[$profile_key] ?? null;
        if (!$entry) return self::review('Nincs adminos megfeleltetés: ' . self::profiles()[$profile_key]['label']);
        $preset = self::validate_mapping($profile_key, $entry);
        if (is_wp_error($preset)) return self::review($preset->get_error_message());
        foreach ($preset['fields'] as $field) {
            $kind = array_search($field['id'], $entry['fields'], true);
            if ($kind === false) continue;
            $value = $fields[$kind];
            if ($field['type'] === 'select') {
                $found = false;
                foreach ($field['options'] ?? array() as $option) {
                    if (($kind === 'month' ? self::month($option) : self::fold($option)) === ($kind === 'month' ? $value : self::fold($value))) $found = true;
                }
                if (!$found) return self::review('A felismert érték nincs a preset választási lehetőségei között.');
            }
            if ($kind === 'year' || $kind === 'age') {
                if ((isset($field['validation_min']) && $field['validation_min'] !== '' && is_numeric($field['validation_min']) && (float)$value < (float)$field['validation_min']) ||
                    (isset($field['validation_max']) && $field['validation_max'] !== '' && is_numeric($field['validation_max']) && (float)$value > (float)$field['validation_max'])) return self::review('A felismert érték kívül esik a preset határain.');
            }
        }
        return array('state' => 'matched', 'preset_id' => $entry['preset_id'], 'profile' => $profile_key,
            'message' => 'Felismert: ' . implode(' · ', array_values($fields)) . ' → ' . $preset['name']);
    }

    public static function ajax_resolve() {
        if (!current_user_can('edit_products') || !check_ajax_referer('mg_bulk_nonce', 'nonce', false)) wp_send_json_error(array('message' => 'Érvénytelen kérés.'), 403);
        $raw = isset($_POST['items']) && is_string($_POST['items']) ? wp_unslash($_POST['items']) : '';
        $items = strlen($raw) <= 500000 ? json_decode($raw, true) : null;
        if (!is_array($items) || count($items) > 50) wp_send_json_error(array('message' => 'Hibás elemzési lista.'), 400);
        $results = array();
        foreach ($items as $item) $results[] = self::resolve($item);
        wp_send_json_success($results);
    }

    public static function selection_from_request($request) {
        $mode = $request['personalization_action'] ?? 'manual';
        if ($mode === 'preserve') return array('mode' => 'preserve');
        if ($mode === 'auto') {
            $raw = $request['personalization'] ?? '';
            if (!is_string($raw) || strlen($raw) > 20000) return new WP_Error('personalization', 'Hibás egyedi mező elemzés.');
            $data = json_decode(wp_unslash($raw), true);
            $resolved = self::resolve($data);
            if ($resolved['state'] !== 'matched' || $resolved['preset_id'] !== ($request['preset_id'] ?? '')) return new WP_Error('personalization', 'A presetjavaslat megváltozott vagy nem érvényes. Ellenőrizd újra a sort.');
            return array('mode' => 'auto', 'custom' => true, 'preset_id' => $resolved['preset_id'], 'data' => $data);
        }
        if ($mode !== 'manual') return new WP_Error('personalization', 'Ismeretlen egyedi mező művelet.');
        $custom = isset($request['custom_product']) && $request['custom_product'] === '1';
        $id = $custom && is_string($request['preset_id'] ?? null) ? sanitize_key($request['preset_id']) : '';
        if ($id !== '' && !MG_Custom_Fields_Manager::get_preset($id)) return new WP_Error('personalization', 'A kiválasztott preset már nem létezik.');
        return array('mode' => 'manual', 'custom' => $custom, 'preset_id' => $id);
    }

    public static function validate_selection($selection) {
        if (!is_array($selection)) return new WP_Error('personalization', 'Hibás egyedi mező beállítás.');
        $mode = $selection['mode'] ?? '';
        if ($mode === 'preserve') return true;
        if ($mode === 'auto') {
            $resolved = self::resolve($selection['data'] ?? null);
            if ($resolved['state'] !== 'matched' || ($selection['preset_id'] ?? '') !== $resolved['preset_id']) return new WP_Error('personalization', 'A preset megfeleltetése megváltozott. Ellenőrizd a feltöltést.');
            return true;
        }
        if ($mode !== 'manual' || !is_bool($selection['custom'] ?? null) || !is_string($selection['preset_id'] ?? null)) return new WP_Error('personalization', 'Hibás egyedi mező beállítás.');
        if ($selection['preset_id'] !== '' && !MG_Custom_Fields_Manager::get_preset($selection['preset_id'])) return new WP_Error('personalization', 'A kiválasztott preset már nem létezik.');
        return true;
    }

    public static function apply_selection($product_id, $selection) {
        $valid = self::validate_selection($selection);
        if (is_wp_error($valid)) return $valid;
        if ($selection['mode'] === 'preserve') return true;
        if ($selection['mode'] === 'auto' && (MG_Custom_Fields_Manager::is_custom_product($product_id) || MG_Custom_Fields_Manager::get_fields_for_product($product_id))) return true;
        if (!empty($selection['custom']) && !empty($selection['preset_id'])) {
            return MG_Custom_Fields_Manager::apply_preset_to_product($product_id, $selection['preset_id'])
                ? true : new WP_Error('personalization', 'A presetet nem sikerült a termékhez rendelni.');
        }
        MG_Custom_Fields_Manager::set_custom_product($product_id, !empty($selection['custom']));
        return true;
    }

    public static function render_admin() {
        $presets = MG_Custom_Fields_Manager::get_presets();
        $mappings = self::mappings();
        echo '<section class="postbox" style="padding:20px;margin:20px 0"><h2>AI felismerés → preset megfeleltetés</h2>';
        echo '<p>A DesignFlow JSON-ból felismert mezőkhöz válassz meglévő presetet és azon belüli mezőket. Üres megfeleltetésnél kézi ellenőrzés szükséges.</p>';
        echo '<form method="post">';
        wp_nonce_field(MG_Custom_Fields_Page::NONCE_ACTION, MG_Custom_Fields_Page::NONCE_FIELD);
        echo '<input type="hidden" name="mg_custom_fields_action" value="save_personalization_mappings">';
        echo '<table class="widefat striped"><thead><tr><th>Felismert elemek</th><th>Preset</th><th>Preset mezői</th></tr></thead><tbody>';
        foreach (self::profiles() as $key => $profile) {
            $entry = $mappings[$key] ?? array();
            $preset_id = $entry['preset_id'] ?? '';
            echo '<tr class="mg-personalization-mapping"><td>' . esc_html($profile['label']) . '</td><td><select class="mg-personalization-preset" name="personalization_mappings[' . esc_attr($key) . '][preset_id]"><option value="">— Nincs megfeleltetés —</option>';
            if ($preset_id !== '' && !isset($presets[$preset_id])) echo '<option selected value="' . esc_attr($preset_id) . '">Törölt preset – válassz újat</option>';
            foreach ($presets as $id => $preset) echo '<option value="' . esc_attr($id) . '"' . selected($preset_id, $id, false) . '>' . esc_html($preset['name']) . '</option>';
            echo '</select></td><td>';
            foreach ($profile['kinds'] as $kind) {
                $id = $entry['fields'][$kind] ?? '';
                echo '<label style="display:block;margin-bottom:8px">' . esc_html(self::labels()[$kind]) . ' <select class="mg-personalization-field" name="personalization_mappings[' . esc_attr($key) . '][fields][' . esc_attr($kind) . ']"><option value="">— Válassz mezőt —</option>';
                $found = false;
                foreach ($presets[$preset_id]['fields'] ?? array() as $field) {
                    if ($field['id'] === $id) $found = true;
                    echo '<option value="' . esc_attr($field['id']) . '"' . selected($id, $field['id'], false) . '>' . esc_html($field['label']) . '</option>';
                }
                if ($id !== '' && !$found) echo '<option selected value="' . esc_attr($id) . '">Törölt mező – válassz újat</option>';
                echo '</select></label>';
            }
            if ($entry) {
                $valid = self::validate_mapping($key, $entry);
                if (is_wp_error($valid)) echo '<p style="color:#b32d2e">' . esc_html($valid->get_error_message()) . '</p>';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table><p><button class="button button-primary" type="submit">Megfeleltetések mentése</button></p></form></section>';
    }
}
