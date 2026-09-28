<?php

namespace WeWP\AdvancedQuotes\Emails;

/**
 * To the store: a customer accepted a quote and an order was created.
 */
final class Accepted extends AdminEmail
{
    private int $orderId = 0;

    public function __construct()
    {
        $this->id = 'wewp_aq_quote_accepted';
        $this->title = __('Quote accepted', 'advanced-quotes-for-woocommerce');
        $this->description = __('Sent to the store when a quote is accepted and its pending order is created.', 'advanced-quotes-for-woocommerce');
        $this->template_html = 'emails/wewp-aq-accepted.php';
        $this->template_plain = 'emails/plain/wewp-aq-accepted.php';
        add_action('wewp_aq_quote_accepted_notification', [$this, 'trigger'], 10, 3);
        parent::__construct();
    }

    public function get_default_subject(): string
    {
        return __('[{site_title}]: Quote {quote_number} was accepted', 'advanced-quotes-for-woocommerce');
    }

    public function get_default_heading(): string
    {
        return __('Quote accepted', 'advanced-quotes-for-woocommerce');
    }

    protected function extraArgs(): array
    {
        $order = $this->orderId ? wc_get_order($this->orderId) : null;

        return ['order' => $order ?: null];
    }

    public function trigger(int $quoteId, int $orderId = 0, string $actor = 'customer'): void
    {
        $this->orderId = $orderId;
        if ($actor === 'customer' && $this->is_enabled() && $this->load($quoteId)) {
            $this->deliver((string) $this->get_recipient());
        }
    }
}
