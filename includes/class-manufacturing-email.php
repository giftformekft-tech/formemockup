<?php
if (!defined('ABSPATH')) {
    exit;
}

class MG_Manufacturing_Email extends WC_Email {

    public function __construct() {
        $this->id             = 'mg_manufacturing';
        $this->customer_email = true;
        $this->title          = 'Gyártás alatt értesítő (vevőnek)';
        $this->description    = 'Ezt az emailt kapja a vevő, amikor a rendelése "Gyártás alatt" státuszra vált.';
        $this->subject        = 'Rendelésedet gyártjuk! – {site_title} (#{order_number})';
        $this->heading        = 'Rendelésedet gyártjuk!';

        $this->template_html  = '';
        $this->template_plain = '';

        add_action('woocommerce_order_status_manufacturing', array($this, 'trigger'), 10, 2);

        parent::__construct();
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

        if (!$this->is_enabled() || !$this->get_recipient()) {
            return;
        }

        $this->send($this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments());
    }

    public function get_subject() {
        return $this->format_string(parent::get_subject());
    }

    public function get_heading() {
        return $this->format_string(parent::get_heading());
    }

    public function get_content_html() {
        $order   = $this->object;
        $heading = $this->get_heading();

        ob_start();
        do_action('woocommerce_email_header', $heading, $this);
        ?>
        <p>Kedves <?php echo esc_html($order->get_billing_first_name()); ?>!</p>
        <p>Örömmel értesítünk, hogy <strong>#<?php echo esc_html($order->get_order_number()); ?></strong> számú rendelésedet megkaptuk és a gyártás megkezdődött.</p>
        <p>Amint elkészül, azonnal értesítünk a szállítás megindításáról.</p>
        <?php do_action('woocommerce_email_order_details', $order, false, false, $this); ?>
        <?php do_action('woocommerce_email_footer', $this); ?>
        <?php
        return ob_get_clean();
    }

    public function get_content_plain() {
        $order = $this->object;
        $text  = 'Kedves ' . $order->get_billing_first_name() . '!' . "\n\n";
        $text .= 'Rendelésedet (#' . $order->get_order_number() . ') megkaptuk, a gyártás megkezdődött.' . "\n\n";
        $text .= 'Amint elkészül, értesítünk a szállítás megindításáról.' . "\n\n";
        $text .= 'Üdvözlettel,' . "\n" . get_bloginfo('name');
        return $text;
    }
}
