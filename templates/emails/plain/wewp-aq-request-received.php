<?php
/**
 * Customer email (plain text): the quote request arrived.
 *
 * @var array $quote
 * @var array|null $request
 * @var array $answers
 * @var string $email_heading
 * @var string $additional_content
 */
defined('ABSPATH') || exit;

echo '= '.esc_html(wp_strip_all_tags($email_heading))." =\n\n";
echo esc_html(sprintf(/* translators: %s: quote number */ __('We received your quote request. Your reference is %s.', 'advanced-quotes-for-woocommerce'), $quote['number']))."\n\n";
echo esc_html__('We will check the items, set your prices and email you the quote. You can then accept it online and pay.', 'advanced-quotes-for-woocommerce')."\n\n";
foreach ((array) ($request['items'] ?? []) as $wewp_aq_item) {
    echo esc_html((string) $wewp_aq_item['quantity']).' × '.esc_html((string) $wewp_aq_item['name'])."\n";
}
if (! empty($answers)) {
    echo "\n";
}
foreach ((array) ($answers ?? []) as $wewp_aq_answer) {
    echo esc_html($wewp_aq_answer['label']).': '.esc_html($wewp_aq_answer['text'])."\n";
}
if (! empty($request['message'])) {
    echo "\n".esc_html((string) $request['message'])."\n";
}
echo "\n";
if ($additional_content) {
    echo esc_html(wp_strip_all_tags(wptexturize($additional_content)))."\n\n";
}
echo wp_kses_post(apply_filters('woocommerce_email_footer_text', get_option('woocommerce_email_footer_text')));
