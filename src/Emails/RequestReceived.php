<?php

namespace WeWP\AdvancedQuotes\Emails;

/**
 * To the customer: their request arrived.
 */
final class RequestReceived extends Base
{
    public function __construct()
    {
        $this->id = 'wewp_aq_request_received';
        $this->customer_email = true;
        $this->title = __('Quote request received', 'advanced-quotes-for-woocommerce');
        $this->description = __('Sent to the customer after they submit a quote request.', 'advanced-quotes-for-woocommerce');
        $this->template_html = 'emails/wewp-aq-request-received.php';
        $this->template_plain = 'emails/plain/wewp-aq-request-received.php';
        add_action('wewp_aq_request_created_notification', [$this, 'trigger']);
        parent::__construct();
    }

    public function get_default_subject(): string
    {
        return __('We received your quote request {quote_number}', 'advanced-quotes-for-woocommerce');
    }

    public function get_default_heading(): string
    {
        return __('Thank you for your request', 'advanced-quotes-for-woocommerce');
    }

    public function trigger(int $quoteId): void
    {
        if ($this->is_enabled() && $this->load($quoteId)) {
            $this->deliver((string) $this->quote['email']);
        }
    }
}
