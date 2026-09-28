<?php
/**
 * Store email: a new quote request.
 *
 * @var array $quote
 * @var array|null $request
 * @var array $answers
 * @var string $admin_url
 * @var string $email_heading
 * @var string $additional_content
 * @var WC_Email $email
 */
defined('ABSPATH') || exit;

do_action('woocommerce_email_header', $email_heading, $email);
$wewp_aq_customer = $quote['draft']['customer'] ?? [];
?>
<p><?php echo esc_html(sprintf(/* translators: 1: customer name and company, 2: email, 3: quote number */ __('%1$s (%2$s) asked for a quote. Reference: %3$s.', 'advanced-quotes-for-woocommerce'), trim(($wewp_aq_customer['first_name'] ?? '').' '.($wewp_aq_customer['last_name'] ?? '')).(! empty($wewp_aq_customer['company']) ? ', '.$wewp_aq_customer['company'] : ''), $quote['email'], $quote['number'])); ?></p>
<?php if (! empty($request['items'])) : ?>
<table cellspacing="0" cellpadding="6" border="1" style="width:100%;border-collapse:collapse;margin:0 0 20px;">
	<thead><tr><th scope="col" style="text-align:left;"><?php esc_html_e('Product', 'advanced-quotes-for-woocommerce'); ?></th><th scope="col" style="text-align:right;"><?php esc_html_e('Quantity', 'advanced-quotes-for-woocommerce'); ?></th></tr></thead>
	<tbody>
	<?php foreach ($request['items'] as $wewp_aq_item) : ?>
		<tr><td><?php echo esc_html((string) $wewp_aq_item['name']); ?><?php echo ! empty($wewp_aq_item['sku']) ? ' <small>('.esc_html((string) $wewp_aq_item['sku']).')</small>' : ''; ?></td><td style="text-align:right;"><?php echo esc_html((string) $wewp_aq_item['quantity']); ?></td></tr>
	<?php endforeach; ?>
	</tbody>
</table>
<?php endif; ?>
<?php if (! empty($answers)) : ?>
<table cellspacing="0" cellpadding="6" border="1" style="width:100%;border-collapse:collapse;margin:0 0 20px;">
	<tbody>
	<?php foreach ($answers as $wewp_aq_answer) : ?>
		<tr><th scope="row" style="text-align:left;width:40%;"><?php echo esc_html($wewp_aq_answer['label']); ?></th><td><?php echo nl2br(esc_html($wewp_aq_answer['text'])); ?></td></tr>
	<?php endforeach; ?>
	</tbody>
</table>
<?php endif; ?>
<?php if (! empty($request['message'])) : ?>
<blockquote style="margin:0 0 20px;padding:12px 16px;border-left:3px solid #dcdcde;"><?php echo nl2br(esc_html((string) $request['message'])); ?></blockquote>
<?php endif; ?>
<p><a href="<?php echo esc_url($admin_url); ?>"><?php esc_html_e('Set prices and send the quote', 'advanced-quotes-for-woocommerce'); ?></a></p>
<?php
if ($additional_content) {
    echo wp_kses_post(wpautop(wptexturize($additional_content)));
}
do_action('woocommerce_email_footer', $email);
