<?php
/**
 * Store email: a quote was accepted.
 *
 * @var array $quote
 * @var string $total
 * @var WC_Order|null $order
 * @var string $admin_url
 * @var string $email_heading
 * @var string $additional_content
 * @var WC_Email $email
 */
defined('ABSPATH') || exit;

do_action('woocommerce_email_header', $email_heading, $email);
?>
<p><?php echo esc_html(sprintf(/* translators: 1: quote number, 2: total */ __('The customer accepted quote %1$s for %2$s.', 'advanced-quotes-for-woocommerce'), $quote['number'], $total)); ?></p>
<?php if ($order) : ?>
<p><?php echo esc_html(sprintf(/* translators: %s: order number */ __('Order #%s was created and is waiting for payment. WooCommerce will notify you when it is paid.', 'advanced-quotes-for-woocommerce'), $order->get_order_number())); ?></p>
<p><a href="<?php echo esc_url($order->get_edit_order_url()); ?>"><?php esc_html_e('Open the order', 'advanced-quotes-for-woocommerce'); ?></a> · <a href="<?php echo esc_url($admin_url); ?>"><?php esc_html_e('Open the quote', 'advanced-quotes-for-woocommerce'); ?></a></p>
<?php endif; ?>
<?php
if ($additional_content) {
    echo wp_kses_post(wpautop(wptexturize($additional_content)));
}
do_action('woocommerce_email_footer', $email);
