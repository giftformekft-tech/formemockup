<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class MG_Email_Footer
 * 
 * Appends PDF links (Terms & Conditions, Withdrawal Declaration) to the footer 
 * of specific WooCommerce emails (Customer On-Hold Order).
 */
class MG_Email_Footer {

    public static function init() {
        if (is_admin() && !defined('DOING_AJAX')) {
            return;
        }

        // Hook into email footer
        add_action('woocommerce_email_footer', array(__CLASS__, 'append_pdf_links'), 10, 1);
    }

    /**
     * Append PDF links to the email footer depending on the email type.
     * 
     * @param mixed $email The email object or null.
     */
    public static function append_pdf_links($email = null) {
        // We only target 'customer_on_hold_order'
        if (!$email || !is_object($email) || !isset($email->id)) {
            return;
        }

        if ($email->id !== 'customer_on_hold_order') {
            return;
        }

        // Retrieve URLs from settings
        $terms_url = get_option('mg_terms_pdf_url', '');
        $withdrawal_url = get_option('mg_withdrawal_pdf_url', '');

        if (!$terms_url && !$withdrawal_url) {
            return;
        }

        echo '<div style="margin-top: 20px; padding-top: 20px; border-top: 1px solid #e5e5e5; font-size: 12px; color: #636363; text-align: center;">';
        
        $links = array();
        if ($terms_url) {
            $links[] = '<a href="' . esc_url($terms_url) . '" target="_blank" style="color: #636363; text-decoration: underline;">' . esc_html__('Általános Szerződési Feltételek leöltése (PDF)', 'woocommerce') . '</a>';
        }
        if ($withdrawal_url) {
            $links[] = '<a href="' . esc_url($withdrawal_url) . '" target="_blank" style="color: #636363; text-decoration: underline;">' . esc_html__('Elállási Nyilatkozat letöltése (PDF)', 'woocommerce') . '</a>';
        }

        echo implode(' | ', $links);
        
        echo '</div>';
    }
}
