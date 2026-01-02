<?php
if (!defined('ABSPATH')) {
    exit;
}

class MG_Delivery_Estimate {
    const OPTION_KEY = 'mg_delivery_estimate';

    public static function init() {
        add_action('wp_enqueue_scripts', array(__CLASS__, 'enqueue_assets'));
        add_action('woocommerce_after_add_to_cart_button', array(__CLASS__, 'render_tile'), 15);
    }

    public static function enqueue_assets() {
        if (!function_exists('is_product') || !is_product()) {
            return;
        }
        $base_file = dirname(__DIR__) . '/mockup-generator.php';
        $style_path = dirname(__DIR__) . '/assets/css/delivery-estimate.css';
        $style_url = plugins_url('assets/css/delivery-estimate.css', $base_file);
        wp_enqueue_style(
            'mg-delivery-estimate',
            $style_url,
            array(),
            file_exists($style_path) ? filemtime($style_path) : '1.0.0'
        );
    }

    public static function get_settings() {
        $defaults = array(
            'enabled' => true,
            'normal_days' => 3,
            'express_days' => 1,
            'normal_label' => __('Normál kézbesítés várható:', 'mockup-generator'),
            'express_label' => __('SOS kézbesítés:', 'mockup-generator'),
            'cheapest_label' => __('Legolcsóbb szállítás:', 'mockup-generator'),
            'cheapest_text' => '',
            'icon_id' => 0,
            'icon_url' => '',
            'holidays' => array(),
            'cutoff_time' => '',
            'cutoff_extra_days' => 1,
        );
        $settings = get_option(self::OPTION_KEY, array());
        if (!is_array($settings)) {
            $settings = array();
        }
        $merged = array_merge($defaults, $settings);
        $merged['enabled'] = !empty($merged['enabled']);
        $merged['normal_days'] = max(0, intval($merged['normal_days']));
        $merged['express_days'] = max(0, intval($merged['express_days']));
        $merged['normal_label'] = is_string($merged['normal_label']) ? $merged['normal_label'] : $defaults['normal_label'];
        $merged['express_label'] = is_string($merged['express_label']) ? $merged['express_label'] : $defaults['express_label'];
        $merged['cheapest_label'] = is_string($merged['cheapest_label']) ? $merged['cheapest_label'] : $defaults['cheapest_label'];
        $merged['cheapest_text'] = is_string($merged['cheapest_text']) ? $merged['cheapest_text'] : $defaults['cheapest_text'];
        $merged['icon_id'] = max(0, intval($merged['icon_id']));
        $merged['icon_url'] = is_string($merged['icon_url']) ? $merged['icon_url'] : $defaults['icon_url'];
        $merged['holidays'] = is_array($merged['holidays']) ? $merged['holidays'] : array();
        $merged['cutoff_time'] = is_string($merged['cutoff_time']) ? $merged['cutoff_time'] : $defaults['cutoff_time'];
        $merged['cutoff_extra_days'] = max(0, intval($merged['cutoff_extra_days']));
        if ($merged['icon_id'] > 0 && function_exists('wp_get_attachment_url')) {
            $attachment_url = wp_get_attachment_url($merged['icon_id']);
            if ($attachment_url) {
                $merged['icon_url'] = $attachment_url;
            }
        }
        return $merged;
    }

    public static function render_tile() {
        if (!function_exists('is_product') || !is_product()) {
            return;
        }
        $settings = self::get_settings();
        if (empty($settings['enabled'])) {
            return;
        }
        $timezone = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('UTC');
        $holiday_lookup = self::build_holiday_lookup($settings['holidays']);
        $extra_days = self::get_cutoff_extra_days($settings, $timezone, $holiday_lookup);
        $normal_date = self::add_business_days($settings['normal_days'] + $extra_days, $settings['holidays'], $timezone);
        $express_date = self::add_business_days($settings['express_days'] + $extra_days, $settings['holidays'], $timezone);
        $format = 'F j. l';

        $normal_label = wp_kses_post($settings['normal_label']);
        $express_label = wp_kses_post($settings['express_label']);
        $cheapest_label = wp_kses_post($settings['cheapest_label']);
        $cheapest_text = wp_kses_post($settings['cheapest_text']);
        $icon_url = esc_url($settings['icon_url']);
        ?>
        <div class="mg-delivery-estimate" role="note" aria-live="polite">
            <div class="mg-delivery-estimate__content">
                <div class="mg-delivery-estimate__row mg-delivery-estimate__row--normal">
                    <span class="mg-delivery-estimate__label"><?php echo $normal_label; ?></span>
                    <strong class="mg-delivery-estimate__date"><?php echo esc_html(wp_date($format, $normal_date->getTimestamp(), $timezone)); ?></strong>
                </div>
                <div class="mg-delivery-estimate__row mg-delivery-estimate__row--express">
                    <span class="mg-delivery-estimate__label"><?php echo $express_label; ?></span>
                    <strong class="mg-delivery-estimate__date"><?php echo esc_html(wp_date($format, $express_date->getTimestamp(), $timezone)); ?></strong>
                </div>
                <div class="mg-delivery-estimate__row mg-delivery-estimate__row--cheapest">
                    <span class="mg-delivery-estimate__label"><?php echo $cheapest_label; ?></span>
                    <strong class="mg-delivery-estimate__date"><?php echo $cheapest_text; ?></strong>
                </div>
            </div>
            <?php if ($icon_url !== '') : ?>
                <div class="mg-delivery-estimate__icon">
                    <img src="<?php echo esc_url($icon_url); ?>" alt="" />
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    public static function add_business_days($days, $holidays, $timezone) {
        $days = max(0, intval($days));
        $date = new DateTime('now', $timezone);
        if ($days === 0) {
            return $date;
        }
        $holiday_lookup = self::build_holiday_lookup($holidays);

        $added = 0;
        while ($added < $days) {
            $date->modify('+1 day');
            if (self::is_business_day($date, $holiday_lookup)) {
                $added++;
            }
        }
        return $date;
    }

    protected static function build_holiday_lookup($holidays) {
        $holiday_lookup = array();
        if (is_array($holidays)) {
            foreach ($holidays as $holiday) {
                if (!is_string($holiday) || $holiday === '') {
                    continue;
                }
                $holiday_lookup[$holiday] = true;
            }
        }
        return $holiday_lookup;
    }

    protected static function is_business_day(DateTime $date, $holiday_lookup) {
        $day_of_week = intval($date->format('N'));
        if ($day_of_week >= 6) {
            return false;
        }
        $key = $date->format('Y-m-d');
        return empty($holiday_lookup[$key]);
    }

    protected static function get_cutoff_extra_days($settings, $timezone, $holiday_lookup) {
        $cutoff_time = isset($settings['cutoff_time']) ? trim((string) $settings['cutoff_time']) : '';
        $extra_days = isset($settings['cutoff_extra_days']) ? max(0, intval($settings['cutoff_extra_days'])) : 0;
        if ($cutoff_time === '' || $extra_days === 0) {
            return 0;
        }
        if (!preg_match('/^\d{2}:\d{2}$/', $cutoff_time)) {
            return 0;
        }
        $now = new DateTime('now', $timezone);
        $cutoff = clone $now;
        list($hours, $minutes) = array_map('intval', explode(':', $cutoff_time));
        $cutoff->setTime($hours, $minutes, 0);
        if ($now <= $cutoff) {
            return 0;
        }
        if (!self::is_business_day($now, $holiday_lookup)) {
            return 0;
        }
        return $extra_days;
    }

    public static function normalize_holiday_line($line) {
        $line = trim($line);
        if ($line === '') {
            return '';
        }
        $line = str_replace(array('.', '/'), '-', $line);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $line)) {
            return '';
        }
        $date = DateTime::createFromFormat('Y-m-d', $line);
        if (!$date) {
            return '';
        }
        return $date->format('Y-m-d');
    }
}
