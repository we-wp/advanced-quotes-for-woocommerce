<?php
/**
 * Customer email (plain text): the quote.
 *
 * @var array $quote
 * @var array $snapshot
 * @var string $total
 * @var string $quote_url
 * @var string $email_heading
 * @var string $additional_content
 */
defined('ABSPATH') || exit;

echo '= '.esc_html(wp_strip_all_tags($email_heading))." =\n\n";
echo esc_html(sprintf(/* translators: 1: quote number, 2: date */ __('Your quote %1$s is ready. It is valid until %2$s.', 'advanced-quotes-for-woocommerce'), $quote['number'], $snapshot['valid_until_date']))."\n\n";
echo esc_html__('Total', 'advanced-quotes-for-woocommerce').': '.esc_html($total)."\n\n";
echo esc_html__('Review and accept the quote:', 'advanced-quotes-for-woocommerce')."\n".esc_url_raw($quote_url)."\n\n";
echo esc_html__('This link is private. Anyone with it can accept the quote. Share it only with people who approve the purchase.', 'advanced-quotes-for-woocommerce')."\n\n";
if ($additional_content) {
    echo esc_html(wp_strip_all_tags(wptexturize($additional_content)))."\n\n";
}
echo wp_kses_post(apply_filters('woocommerce_email_footer_text', get_option('woocommerce_email_footer_text')));
