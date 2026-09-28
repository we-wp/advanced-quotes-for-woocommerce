<?php
/**
 * Customer email: the quote request arrived.
 *
 * @var array $quote
 * @var array|null $request
 * @var string $email_heading
 * @var string $additional_content
 * @var WC_Email $email
 */
defined('ABSPATH') || exit;

do_action('woocommerce_email_header', $email_heading, $email);
?>
<p><?php echo esc_html(sprintf(/* translators: %s: quote number */ __('We received your quote request. Your reference is %s.', 'advanced-quotes-for-woocommerce'), $quote['number'])); ?></p>
<p><?php esc_html_e('We will check the items, set your prices and email you the quote. You can then accept it online and pay.', 'advanced-quotes-for-woocommerce'); ?></p>
<?php if (! empty($request['items'])) : ?>
<table cellspacing="0" cellpadding="6" border="1" style="width:100%;border-collapse:collapse;margin:0 0 20px;">
	<thead><tr><th scope="col" style="text-align:left;"><?php esc_html_e('Product', 'advanced-quotes-for-woocommerce'); ?></th><th scope="col" style="text-align:right;"><?php esc_html_e('Quantity', 'advanced-quotes-for-woocommerce'); ?></th></tr></thead>
	<tbody>
	<?php foreach ($request['items'] as $wewp_aq_item) : ?>
		<tr><td><?php echo esc_html((string) $wewp_aq_item['name']); ?></td><td style="text-align:right;"><?php echo esc_html((string) $wewp_aq_item['quantity']); ?></td></tr>
	<?php endforeach; ?>
	</tbody>
</table>
<?php endif; ?>
<?php
if ($additional_content) {
    echo wp_kses_post(wpautop(wptexturize($additional_content)));
}
do_action('woocommerce_email_footer', $email);
