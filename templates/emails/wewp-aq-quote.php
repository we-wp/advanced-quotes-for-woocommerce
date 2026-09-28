<?php
/**
 * Customer email: the quote. Override by copying to yourtheme/woocommerce/emails/wewp-aq-quote.php.
 *
 * @var array $quote
 * @var array $snapshot
 * @var string $total
 * @var string $quote_url
 * @var string $email_heading
 * @var string $additional_content
 * @var WC_Email $email
 */
defined('ABSPATH') || exit;

do_action('woocommerce_email_header', $email_heading, $email);
$wewp_aq_name = trim((string) ($snapshot['customer']['first_name'] ?? ''));
?>
<p><?php echo $wewp_aq_name !== '' ? esc_html(sprintf(/* translators: %s: customer first name */ __('Hello %s,', 'advanced-quotes-for-woocommerce'), $wewp_aq_name)) : esc_html__('Hello,', 'advanced-quotes-for-woocommerce'); ?></p>
<p><?php echo esc_html(sprintf(/* translators: 1: quote number, 2: date */ __('Your quote %1$s is ready. It is valid until %2$s.', 'advanced-quotes-for-woocommerce'), $quote['number'], $snapshot['valid_until_date'])); ?></p>
<table cellspacing="0" cellpadding="6" border="1" style="width:100%;border-collapse:collapse;margin:0 0 20px;" role="presentation">
	<tr><th scope="row" style="text-align:left;"><?php esc_html_e('Quote', 'advanced-quotes-for-woocommerce'); ?></th><td style="text-align:right;"><?php echo esc_html($quote['number']); ?></td></tr>
	<tr><th scope="row" style="text-align:left;"><?php esc_html_e('Total', 'advanced-quotes-for-woocommerce'); ?></th><td style="text-align:right;"><strong><?php echo esc_html($total); ?></strong></td></tr>
</table>
<p><?php esc_html_e('Open the quote to review it. If it is right for you, accept it online and pay on the next page.', 'advanced-quotes-for-woocommerce'); ?></p>
<p style="margin:24px 0;"><a href="<?php echo esc_url($quote_url); ?>" style="display:inline-block;padding:12px 20px;background:<?php echo esc_attr((string) get_option('woocommerce_email_base_color', '#720eec')); ?>;color:#ffffff;text-decoration:none;font-weight:600;border-radius:3px;"><?php esc_html_e('Review and accept the quote', 'advanced-quotes-for-woocommerce'); ?></a></p>
<p style="font-size:13px;"><?php esc_html_e('This link is private. Anyone with it can accept the quote. Share it only with people who approve the purchase.', 'advanced-quotes-for-woocommerce'); ?></p>
<?php
if ($additional_content) {
    echo wp_kses_post(wpautop(wptexturize($additional_content)));
}
do_action('woocommerce_email_footer', $email);
