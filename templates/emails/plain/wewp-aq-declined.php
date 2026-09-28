<?php
/**
 * Store email (plain text): a quote was declined.
 *
 * @var array $quote
 * @var string $reason
 * @var string $admin_url
 * @var string $email_heading
 * @var string $additional_content
 */
defined('ABSPATH') || exit;

echo '= '.esc_html(wp_strip_all_tags($email_heading))." =\n\n";
echo esc_html(sprintf(/* translators: 1: quote number, 2: customer email */ __('Quote %1$s was declined by %2$s.', 'advanced-quotes-for-woocommerce'), $quote['number'], $quote['email']))."\n\n";
echo ($reason !== '' ? esc_html($reason) : esc_html__('The customer did not give a reason.', 'advanced-quotes-for-woocommerce'))."\n\n";
echo esc_url_raw($admin_url)."\n\n";
if ($additional_content) {
    echo esc_html(wp_strip_all_tags(wptexturize($additional_content)))."\n\n";
}
echo wp_kses_post(apply_filters('woocommerce_email_footer_text', get_option('woocommerce_email_footer_text')));
