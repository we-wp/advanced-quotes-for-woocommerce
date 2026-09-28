<?php

namespace WeWP\AdvancedQuotes;

use WC_Order;

/**
 * Keeps quote status in step with the WooCommerce order created on acceptance.
 */
final class Orders
{
    public const SESSION = 'wewp_aq_orders';

    public function __construct(private Store $store) {}

    public function boot(): void
    {
        add_action('woocommerce_order_status_changed', [$this, 'statusChanged'], 10, 4);
        add_filter('woocommerce_order_email_verification_required', [$this, 'verification'], 10, 3);
        add_action('woocommerce_admin_order_data_after_order_details', [$this, 'adminLink']);
    }

    public function statusChanged(int $orderId, string $from, string $to, mixed $order = null): void
    {
        $order = $order instanceof WC_Order ? $order : wc_get_order($orderId);
        if (! $order instanceof WC_Order) {
            return;
        }
        $quoteId = (int) $order->get_meta('_wewp_aq_quote_id');
        if (! $quoteId) {
            return;
        }
        if ($order->is_paid() && ! in_array($from, wc_get_is_paid_statuses(), true)) {
            // Payment completion always moves the order into a paid status, so this runs once per payment.
            $this->markPaid($order);
        } elseif (in_array($to, ['cancelled', 'failed', 'refunded'], true)) {
            $this->store->event($quoteId, 'order_'.$to, '#'.$order->get_order_number(), 0);
        }
    }

    private function markPaid(WC_Order $order): void
    {
        $quoteId = (int) $order->get_meta('_wewp_aq_quote_id');
        $quote = $quoteId ? $this->store->find($quoteId) : null;
        if (! $quote) {
            return;
        }
        if ($quote['order_id'] !== $order->get_id()) {
            // The customer paid an order from an earlier revision. Tell the store; do not change the current quote.
            $this->store->event($quoteId, 'order_paid_superseded', '#'.$order->get_order_number(), 0);

            return;
        }
        if ($this->store->transition($quoteId, ['accepted'], 'paid', 'paid', 0, '#'.$order->get_order_number())) {
            do_action('wewp_aq_quote_paid', $quoteId, $order->get_id());
        }
    }

    /**
     * A guest who opened the private quote link already proved access. Skip WooCommerce's email check for that order.
     */
    public function verification(bool $required, mixed $order, string $context = ''): bool
    {
        if (! $required || ! $order instanceof WC_Order || ! $order->get_meta('_wewp_aq_quote_id') || ! WC()->session) {
            return $required;
        }
        $allowed = array_map('intval', (array) WC()->session->get(self::SESSION, []));

        return in_array($order->get_id(), $allowed, true) ? false : $required;
    }

    public static function rememberForSession(int $orderId): void
    {
        if (! WC()->session) {
            return;
        }
        if (! WC()->session->has_session()) {
            WC()->session->set_customer_session_cookie(true);
        }
        $orders = array_map('intval', (array) WC()->session->get(self::SESSION, []));
        $orders[] = $orderId;
        WC()->session->set(self::SESSION, array_slice(array_values(array_unique($orders)), -20));
    }

    public function adminLink(WC_Order $order): void
    {
        $quoteId = (int) $order->get_meta('_wewp_aq_quote_id');
        if (! $quoteId || ! current_user_can('manage_wewp_quotes')) {
            return;
        }
        $url = admin_url('admin.php?page=wewp-quotes&action=edit&quote='.$quoteId);
        /* translators: 1: quote number, 2: revision number */
        $text = sprintf(__('Created from quote %1$s, revision %2$d', 'advanced-quotes-for-woocommerce'), (string) $order->get_meta('_wewp_aq_quote_number'), (int) $order->get_meta('_wewp_aq_quote_revision'));
        echo '<p class="form-field form-field-wide"><a href="'.esc_url($url).'">'.esc_html($text).'</a></p>';
    }
}
