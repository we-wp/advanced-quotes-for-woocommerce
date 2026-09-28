<?php
/**
 * Store email: a quote was declined.
 *
 * @var array $quote
 * @var string $reason
 * @var string $admin_url
 * @var string $email_heading
 * @var string $additional_content
 * @var WC_Email $email
 */
defined('ABSPATH') || exit;

do_action('woocommerce_email_header', $email_heading, $email);
?>
<p><?php echo esc_html(sprintf(/* translators: 1: quote number, 2: customer email */ __('Quote %1$s was declined by %2$s.', 'advanced-quotes-for-woocommerce'), $quote['number'], $quote['email'])); ?></p>
<?php if ($reason !== '') : ?>
<blockquote style="margin:0 0 20px;padding:12px 16px;border-left:3px solid #dcdcde;"><?php echo nl2br(esc_html($reason)); ?></blockquote>
<?php else : ?>
<p><?php esc_html_e('The customer did not give a reason.', 'advanced-quotes-for-woocommerce'); ?></p>
<?php endif; ?>
<p><a href="<?php echo esc_url($admin_url); ?>"><?php esc_html_e('Open the quote to send a new revision', 'advanced-quotes-for-woocommerce'); ?></a></p>
<?php
if ($additional_content) {
    echo wp_kses_post(wpautop(wptexturize($additional_content)));
}
do_action('woocommerce_email_footer', $email);
