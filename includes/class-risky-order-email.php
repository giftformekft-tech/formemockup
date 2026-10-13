<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Vevői levél a "Veszélyes rendelés" állapothoz: az egyedi terméket csak
 * előre utalás után tudjuk kiszállítani, ezért kérjük az összeg átutalását.
 */
class MG_Risky_Order_Email extends WC_Email {

    public function __construct() {
        $this->id             = 'mg_risky_order';
        $this->customer_email = true;
        $this->title          = 'Veszélyes rendelés – előre utalás kérése (vevőnek)';
        $this->description    = 'Ezt az emailt kapja a vevő, amikor a rendelése "Veszélyes rendelés" állapotra vált: az egyedi terméket csak előre utalás esetén szállítjuk ki.';
        $this->subject        = 'Fontos információ a #{order_number} számú rendelésedről – {site_title}';
        $this->heading        = 'Előre utalás szükséges a rendelésedhez';

        $this->template_html  = '';
        $this->template_plain = '';

        $this->placeholders = array(
            '{order_number}' => '',
            '{order_date}'   => '',
        );

        add_action('woocommerce_order_status_' . MG_Risky_Order::STATUS . '_notification', array($this, 'trigger'), 10, 2);

        parent::__construct();
    }

    public function init_form_fields() {
        $this->form_fields = array(
            'enabled' => array(
                'title'   => 'Engedélyezve',
                'type'    => 'checkbox',
                'label'   => 'Email engedélyezése',
                'default' => 'yes',
            ),
            'subject' => array(
                'title'       => 'Tárgy',
                'type'        => 'text',
                'desc_tip'    => true,
                'placeholder' => $this->subject,
                'default'     => '',
            ),
            'heading' => array(
                'title'       => 'Fejléc',
                'type'        => 'text',
                'desc_tip'    => true,
                'placeholder' => $this->heading,
                'default'     => '',
            ),
            'deadline_days' => array(
                'title'       => 'Határidő (munkanap)',
                'type'        => 'number',
                'description' => 'Ennyi munkanapon belül várjuk az utalást; utána a rendelést tárgytalannak tekintjük.',
                'desc_tip'    => true,
                'default'     => '5',
                'custom_attributes' => array('min' => '1', 'step' => '1'),
            ),
            'account_holder' => array(
                'title'   => 'Kedvezményezett',
                'type'    => 'text',
                'default' => 'Gift for me Kft.',
            ),
            'bank_name' => array(
                'title'   => 'Bank',
                'type'    => 'text',
                'default' => 'OTP Bank',
            ),
            'account_number' => array(
                'title'   => 'Bankszámlaszám',
                'type'    => 'text',
                'default' => '11738242-21420520',
            ),
        );
    }

    public function trigger($order_id, $order = null) {
        if (!$order_id) {
            return;
        }

        if (!$order instanceof WC_Order) {
            $order = wc_get_order($order_id);
        }

        if (!$order) {
            return;
        }

        $this->object = $order;
        $this->recipient = $order->get_billing_email();
        $this->placeholders['{order_number}'] = $order->get_order_number();
        $this->placeholders['{order_date}'] = wc_format_datetime($order->get_date_created());

        if (!$this->is_enabled() || !$this->get_recipient()) {
            return;
        }

        $this->send($this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments());
    }

    /**
     * A banki adatok, az összeg és a határidő, amit a levél mindkét
     * változata megjelenít.
     */
    protected function get_payment_details(WC_Order $order) {
        $days = max(1, absint($this->get_option('deadline_days', 5)));
        return array(
            'holder'   => $this->get_option('account_holder', 'Gift for me Kft.'),
            'bank'     => $this->get_option('bank_name', 'OTP Bank'),
            'account'  => $this->get_option('account_number', '11738242-21420520'),
            'amount'   => wc_price($order->get_total(), array('currency' => $order->get_currency())),
            'note'     => $order->get_order_number(),
            'days'     => $days,
            'deadline' => self::format_date(self::add_business_days(time(), $days)),
        );
    }

    /** Hétvégéket átugorva ad hozzá munkanapokat (ünnepnapokat nem ismer). */
    protected static function add_business_days($timestamp, $days) {
        while ($days > 0) {
            $timestamp += DAY_IN_SECONDS;
            if ((int) wp_date('N', $timestamp) < 6) {
                $days--;
            }
        }
        return $timestamp;
    }

    /** Magyar dátum (2026. október 20.), a webhely nyelvi beállításától függetlenül. */
    protected static function format_date($timestamp) {
        $months = array('január', 'február', 'március', 'április', 'május', 'június', 'július', 'augusztus', 'szeptember', 'október', 'november', 'december');
        return wp_date('Y', $timestamp) . '. ' . $months[(int) wp_date('n', $timestamp) - 1] . ' ' . wp_date('j', $timestamp) . '.';
    }

    public function get_content_html() {
        $order = $this->object;
        if (!$order instanceof WC_Order) {
            return '';
        }
        $p = $this->get_payment_details($order);
        $cell = 'padding:6px 10px;border:1px solid #e5e5e5;text-align:left;';

        ob_start();
        do_action('woocommerce_email_header', $this->get_heading(), $this);
        ?>
        <p>Kedves <?php echo esc_html($order->get_billing_first_name()); ?>!</p>
        <p>Köszönjük, hogy minket választottál, és leadtad <strong>#<?php echo esc_html($order->get_order_number()); ?></strong> számú rendelésedet.</p>
        <p>Rendelésed <strong>egyedi terméket</strong> tartalmaz, amelyet kifejezetten a te megrendelésedre gyártunk le, ezért más vásárló számára később nem értékesíthető. Erre tekintettel az ilyen rendeléseket <strong>kizárólag előre utalás (előrefizetés) esetén áll módunkban teljesíteni és kiszállítani</strong>; utánvétes fizetéssel sajnos nem tudjuk elküldeni.</p>
        <p>Amennyiben ez számodra megfelelő, kérjük, utald át a rendelés végösszegét az alábbi bankszámlára. <strong>A közlemény rovatban feltétlenül tüntesd fel a rendelésszámot</strong>, hogy az utalást a rendelésedhez tudjuk rendelni.</p>
        <table cellspacing="0" cellpadding="0" style="border-collapse:collapse;width:100%;margin:0 0 16px;">
            <tr><th style="<?php echo esc_attr($cell); ?>">Kedvezményezett</th><td style="<?php echo esc_attr($cell); ?>"><?php echo esc_html($p['holder']); ?></td></tr>
            <tr><th style="<?php echo esc_attr($cell); ?>">Bank</th><td style="<?php echo esc_attr($cell); ?>"><?php echo esc_html($p['bank']); ?></td></tr>
            <tr><th style="<?php echo esc_attr($cell); ?>">Bankszámlaszám</th><td style="<?php echo esc_attr($cell); ?>"><strong><?php echo esc_html($p['account']); ?></strong></td></tr>
            <tr><th style="<?php echo esc_attr($cell); ?>">Utalandó összeg</th><td style="<?php echo esc_attr($cell); ?>"><strong><?php echo wp_kses_post($p['amount']); ?></strong></td></tr>
            <tr><th style="<?php echo esc_attr($cell); ?>">Közlemény</th><td style="<?php echo esc_attr($cell); ?>"><strong><?php echo esc_html($p['note']); ?></strong></td></tr>
        </table>
        <p>Az összeg jóváírását követően haladéktalanul megkezdjük a termék gyártását, és a szállítás indulásáról külön értesítünk. Az utalással a rendelés fizetési módja előre utalásra módosul, további költség nem terhel.</p>
        <p>Kérjük, hogy az utalást <strong><?php echo esc_html($p['days']); ?> munkanapon belül, legkésőbb <?php echo esc_html($p['deadline']); ?> napjáig</strong> teljesítsd. Amennyiben eddig az időpontig nem érkezik meg az utalás, illetve nem jelzel vissza, a rendelést <strong>tárgytalannak tekintjük és töröljük</strong>; ebben az esetben részedről semmilyen fizetési kötelezettség nem keletkezik.</p>
        <p>Ha kérdésed van, vagy már nem szeretnéd a terméket megrendelni, egyszerűen válaszolj erre a levélre, és segítünk.</p>
        <p>Megértésedet és együttműködésedet köszönjük!</p>
        <p>Üdvözlettel,<br><?php echo esc_html($p['holder']); ?></p>
        <?php do_action('woocommerce_email_order_details', $order, false, false, $this); ?>
        <?php do_action('woocommerce_email_footer', $this); ?>
        <?php
        return ob_get_clean();
    }

    public function get_content_plain() {
        $order = $this->object;
        if (!$order instanceof WC_Order) {
            return '';
        }
        $p = $this->get_payment_details($order);
        $amount = html_entity_decode(wp_strip_all_tags($p['amount']), ENT_QUOTES, 'UTF-8');

        $text  = 'Kedves ' . $order->get_billing_first_name() . '!' . "\n\n";
        $text .= 'Köszönjük, hogy minket választottál, és leadtad #' . $order->get_order_number() . ' számú rendelésedet.' . "\n\n";
        $text .= 'Rendelésed egyedi terméket tartalmaz, amelyet kifejezetten a te megrendelésedre gyártunk le, ezért más vásárló számára később nem értékesíthető. Erre tekintettel az ilyen rendeléseket kizárólag előre utalás (előrefizetés) esetén áll módunkban teljesíteni és kiszállítani; utánvétes fizetéssel sajnos nem tudjuk elküldeni.' . "\n\n";
        $text .= 'Amennyiben ez számodra megfelelő, kérjük, utald át a rendelés végösszegét az alábbi bankszámlára. A közlemény rovatban feltétlenül tüntesd fel a rendelésszámot.' . "\n\n";
        $text .= 'Kedvezményezett: ' . $p['holder'] . "\n";
        $text .= 'Bank: ' . $p['bank'] . "\n";
        $text .= 'Bankszámlaszám: ' . $p['account'] . "\n";
        $text .= 'Utalandó összeg: ' . $amount . "\n";
        $text .= 'Közlemény: ' . $p['note'] . "\n\n";
        $text .= 'Az összeg jóváírását követően haladéktalanul megkezdjük a termék gyártását, és a szállítás indulásáról külön értesítünk. Az utalással a rendelés fizetési módja előre utalásra módosul, további költség nem terhel.' . "\n\n";
        $text .= 'Kérjük, hogy az utalást ' . $p['days'] . ' munkanapon belül, legkésőbb ' . $p['deadline'] . ' napjáig teljesítsd. Amennyiben eddig az időpontig nem érkezik meg az utalás, illetve nem jelzel vissza, a rendelést tárgytalannak tekintjük és töröljük; ebben az esetben részedről semmilyen fizetési kötelezettség nem keletkezik.' . "\n\n";
        $text .= 'Ha kérdésed van, vagy már nem szeretnéd a terméket megrendelni, egyszerűen válaszolj erre a levélre, és segítünk.' . "\n\n";
        $text .= 'Megértésedet és együttműködésedet köszönjük!' . "\n\n";
        $text .= 'Üdvözlettel,' . "\n" . $p['holder'];
        return $text;
    }
}
