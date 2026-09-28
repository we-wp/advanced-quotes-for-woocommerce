<?php
/**
 * Store email (plain text): a quote was accepted.
 *
 * @var array $quote
 * @var string $total
 * @var WC_Order|null $order
 * @var string $admin_url
 * @var string $email_heading
 * @var string $additional_content
 */
defined('ABSPATH') || exit;

echo '= '.esc_html(wp_strip_all_tags($email_heading))." =\n\n";
echo esc_html(sprintf(/* translators: 1: quote number, 2: total */ __('The customer accepted quote %1$s for %2$s.', 'advanced-quotes-for-woocommerce'), $quote['number'], $total))."\n\n";
if ($order) {
    echo esc_html(sprintf(/* translators: %s: order number */ __('Order #%s was created and is waiting for payment. WooCommerce will notify you when it is paid.', 'advanced-quotes-for-woocommerce'), $order->get_order_number()))."\n";
    echo esc_url_raw($order->get_edit_order_url())."\n\n";
}
if ($additional_content) {
    echo esc_html(wp_strip_all_tags(wptexturize($additional_content)))."\n\n";
}
echo wp_kses_post(apply_filters('woocommerce_email_footer_text', get_option('woocommerce_email_footer_text')));
