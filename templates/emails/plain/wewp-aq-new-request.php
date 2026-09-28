<?php
/**
 * Store email (plain text): a new quote request.
 *
 * @var array $quote
 * @var array|null $request
 * @var array $answers
 * @var string $admin_url
 * @var string $email_heading
 * @var string $additional_content
 */
defined('ABSPATH') || exit;

$wewp_aq_customer = $quote['draft']['customer'] ?? [];
echo '= '.esc_html(wp_strip_all_tags($email_heading))." =\n\n";
echo esc_html(sprintf(/* translators: 1: customer name and company, 2: email, 3: quote number */ __('%1$s (%2$s) asked for a quote. Reference: %3$s.', 'advanced-quotes-for-woocommerce'), trim(($wewp_aq_customer['first_name'] ?? '').' '.($wewp_aq_customer['last_name'] ?? '')).(! empty($wewp_aq_customer['company']) ? ', '.$wewp_aq_customer['company'] : ''), $quote['email'], $quote['number']))."\n\n";
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
echo "\n".esc_html__('Set prices and send the quote:', 'advanced-quotes-for-woocommerce').' '.esc_url_raw($admin_url)."\n\n";
if ($additional_content) {
    echo esc_html(wp_strip_all_tags(wptexturize($additional_content)))."\n\n";
}
echo wp_kses_post(apply_filters('woocommerce_email_footer_text', get_option('woocommerce_email_footer_text')));
