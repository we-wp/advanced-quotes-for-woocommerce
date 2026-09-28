<?php

namespace WeWP\AdvancedQuotes;

use RuntimeException;
use Throwable;
use WC_Order;
use WC_Order_Item_Product;
use WC_Order_Item_Shipping;
use WC_Product;
use WC_Tax;

/**
 * Turns an accepted revision into a pending WooCommerce order at the quoted amounts.
 */
final class Acceptance
{
    public function __construct(private Store $store) {}

    /**
     * Accept one sent revision. Repeating the request returns the order that was already created.
     */
    public function accept(int $quoteId, int $revision, int $actorId, string $actor = 'customer'): WC_Order
    {
        $quote = $this->store->find($quoteId);
        if (! $quote) {
            throw new RuntimeException(__('This quote no longer exists.', 'advanced-quotes-for-woocommerce'));
        }
        $existing = $this->existingOrder($quote);
        if ($existing) {
            return $existing;
        }
        if ($quote['status'] === 'accepting') {
            $quote = $this->awaitOther($quoteId);
            $existing = $this->existingOrder($quote);
            if ($existing) {
                return $existing;
            }
        }
        if ($quote['status'] !== 'sent') {
            throw new RuntimeException(self::statusMessage($quote));
        }
        if ($quote['revision'] !== $revision) {
            throw new RuntimeException(__('This quote was updated. Review the latest version before you accept it.', 'advanced-quotes-for-woocommerce'));
        }
        $today = current_time('Y-m-d');
        if ($quote['expires_on'] && $quote['expires_on'] < $today) {
            throw new RuntimeException(__('This quote has expired. Ask the store for a new quote.', 'advanced-quotes-for-woocommerce'));
        }
        $sent = $this->store->revision($quoteId, $revision);
        if (! $sent) {
            throw new RuntimeException(__('This quote revision is unavailable.', 'advanced-quotes-for-woocommerce'));
        }
        $token = $this->store->beginAcceptance($quoteId, $revision, $today);
        if ($token === null) {
            // Another request, such as a double click, is accepting this quote. Wait for its order.
            $existing = $this->existingOrder($this->awaitOther($quoteId));
            if ($existing) {
                return $existing;
            }
            throw new RuntimeException(__('This quote changed while you were viewing it. Refresh the page and try again.', 'advanced-quotes-for-woocommerce'));
        }
        try {
            $order = $this->createOrder($quote, $sent['data'], $token);
        } catch (Throwable $e) {
            $this->store->abortAcceptance($quoteId, $token);
            throw $e;
        }
        try {
            /* translators: 1: order number, 2: who accepted: customer or store */
            $this->store->completeAcceptance($quoteId, $token, $order->get_id(), $actorId, sprintf(__('Order #%1$s created (%2$s).', 'advanced-quotes-for-woocommerce'), $order->get_order_number(), $actor === 'store' ? __('accepted by the store', 'advanced-quotes-for-woocommerce') : __('accepted by the customer', 'advanced-quotes-for-woocommerce')));
        } catch (Throwable $e) {
            $order->delete(true);
            throw $e;
        }
        do_action('wewp_aq_quote_accepted', $quoteId, $order->get_id(), $actor);
        if (! $order->needs_payment() && (float) $order->get_total() <= 0) {
            // Nothing to pay, as WooCommerce checkout does for free orders.
            $order->payment_complete();
        }

        return $order;
    }

    /**
     * Wait up to five seconds for a concurrent acceptance to finish. After two minutes a stalled acceptance
     * is completed with its order when that order exists, otherwise released so the customer can try again.
     */
    private function awaitOther(int $quoteId): array
    {
        for ($i = 0; $i < 20; $i++) {
            $quote = $this->store->find($quoteId);
            if (! $quote || $quote['status'] !== 'accepting') {
                return $quote ?? throw new RuntimeException(__('This quote no longer exists.', 'advanced-quotes-for-woocommerce'));
            }
            usleep(250000);
        }
        $order = $quote['order_id'] ? wc_get_order($quote['order_id']) : null;
        if ($order instanceof WC_Order && ! $order->has_status(['cancelled', 'trash']) && $this->store->recoverAcceptance($quoteId, $order->get_id())) {
            return $this->store->find($quoteId);
        }
        if ($this->store->releaseStaleAcceptance($quoteId)) {
            if ($order instanceof WC_Order) {
                $order->delete(true);
            }

            return $this->store->find($quoteId);
        }
        throw new RuntimeException(__('This quote is being accepted now. Refresh the page in a moment.', 'advanced-quotes-for-woocommerce'));
    }

    public function decline(int $quoteId, int $revision, string $reason): void
    {
        $quote = $this->store->find($quoteId);
        if (! $quote || $quote['status'] !== 'sent') {
            throw new RuntimeException($quote ? self::statusMessage($quote) : __('This quote no longer exists.', 'advanced-quotes-for-woocommerce'));
        }
        $reason = mb_substr(trim(sanitize_textarea_field($reason)), 0, 500);
        if (! $this->store->transition($quoteId, ['sent'], 'declined', 'declined', get_current_user_id(), $reason, $revision)) {
            throw new RuntimeException(__('This quote changed while you were viewing it. Refresh the page and try again.', 'advanced-quotes-for-woocommerce'));
        }
        do_action('wewp_aq_quote_declined', $quoteId, $reason);
    }

    public static function statusMessage(array $quote): string
    {
        return match ($quote['status']) {
            'accepted', 'paid', 'accepting' => __('This quote was already accepted.', 'advanced-quotes-for-woocommerce'),
            'declined' => __('This quote was declined. Contact the store if you changed your mind.', 'advanced-quotes-for-woocommerce'),
            'cancelled' => __('The store withdrew this quote.', 'advanced-quotes-for-woocommerce'),
            default => __('This quote is not ready yet. The store will email you when it is.', 'advanced-quotes-for-woocommerce'),
        };
    }

    private function existingOrder(array $quote): ?WC_Order
    {
        if (! in_array($quote['status'], ['accepted', 'paid'], true) || ! $quote['order_id']) {
            return null;
        }
        $order = wc_get_order($quote['order_id']);

        return $order instanceof WC_Order ? $order : null;
    }

    /**
     * Recreate the snapshot's items with their stored taxes, then let WooCommerce total them.
     * The order is deleted again when WooCommerce reaches a different total.
     */
    private function createOrder(array $quote, array $s, string $token): WC_Order
    {
        $customerId = (int) ($s['customer_id'] ?? $quote['customer_id']);
        $order = wc_create_order(['status' => 'pending', 'customer_id' => $customerId, 'created_via' => 'advanced-quotes']);
        if (is_wp_error($order)) {
            throw new RuntimeException($order->get_error_message());
        }
        $this->store->attachOrder((int) $quote['id'], $token, $order->get_id());
        try {
            $c = $s['customer'];
            $order->set_currency($s['currency']);
            $order->set_prices_include_tax((bool) $s['tax']['prices_include_tax']);
            foreach (['first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country'] as $field) {
                $order->{'set_billing_'.$field}((string) ($c[$field] ?? ''));
                $order->{'set_shipping_'.$field}((string) ($c[$field] ?? ''));
            }
            $order->set_billing_email((string) $c['email']);
            $order->set_billing_phone((string) ($c['phone'] ?? ''));
            $slugs = WC_Tax::get_tax_class_slugs();
            foreach ($s['lines'] as $line) {
                $item = new WC_Order_Item_Product;
                $product = $line['type'] === 'product' ? wc_get_product($line['variation_id'] ?: $line['product_id']) : null;
                if ($product instanceof WC_Product) {
                    $item->set_product($product);
                    if ($product->is_type('variation')) {
                        // Attributes the variation leaves open ("any") carry the customer's choice from the request.
                        $chosen = array_intersect_key((array) ($line['attributes'] ?? []), array_filter($product->get_variation_attributes(), static fn ($value) => $value === ''));
                        $item->set_variation(array_merge($product->get_variation_attributes(), $chosen));
                    }
                }
                $class = (string) $line['tax_class'];
                $item->set_name((string) $line['name']);
                $item->set_tax_class(in_array($class, array_merge(['', '0'], $slugs), true) ? $class : '');
                $item->set_quantity((int) $line['quantity']);
                $item->set_subtotal((string) $line['subtotal']);
                $item->set_total((string) $line['total']);
                $item->set_taxes($line['taxable'] ? $line['taxes'] : false);
                if (($line['description'] ?? '') !== '') {
                    $item->add_meta_data(__('Note', 'advanced-quotes-for-woocommerce'), (string) $line['description'], true);
                }
                $order->add_item($item);
            }
            if ($s['shipping']) {
                $shipping = new WC_Order_Item_Shipping;
                $shipping->set_method_title((string) $s['shipping']['label']);
                $shipping->set_method_id('wewp_aq_shipping');
                $shipping->set_total((string) $s['shipping']['total']);
                $shipping->set_taxes($s['shipping']['taxes']);
                $order->add_item($shipping);
            }
            $order->update_meta_data('_wewp_aq_quote_id', (int) $quote['id']);
            $order->update_meta_data('_wewp_aq_quote_number', (string) $quote['number']);
            $order->update_meta_data('_wewp_aq_quote_revision', (int) $s['revision']);
            $order->save();
            $order->update_taxes();
            $order->calculate_totals(false);
            if (Money::units(Money::fixed((float) $order->get_total())) !== Money::units((string) $s['totals']['total'])) {
                throw new RuntimeException(__('The order total did not match the quote, so no order was kept. Ask the store to send the quote again.', 'advanced-quotes-for-woocommerce'));
            }
            // Hold stock as WooCommerce checkout does, so paying later cannot oversell. Backorder products are skipped.
            if (function_exists('wc_reserve_stock_for_order')) {
                try {
                    wc_reserve_stock_for_order($order);
                } catch (Throwable) {
                    throw new RuntimeException(__('Some items in this quote are no longer in stock. Contact the store for an updated quote.', 'advanced-quotes-for-woocommerce'));
                }
            }
            /* translators: 1: quote number, 2: revision number */
            $order->add_order_note(sprintf(__('Created from accepted quote %1$s, revision %2$d.', 'advanced-quotes-for-woocommerce'), $quote['number'], (int) $s['revision']));

            return $order;
        } catch (Throwable $e) {
            if (function_exists('wc_release_stock_for_order')) {
                wc_release_stock_for_order($order);
            }
            $order->delete(true);
            throw $e;
        }
    }
}
