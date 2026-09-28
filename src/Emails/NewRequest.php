<?php

namespace WeWP\AdvancedQuotes\Emails;

/**
 * To the store: a customer asked for a quote.
 */
final class NewRequest extends AdminEmail
{
    public function __construct()
    {
        $this->id = 'wewp_aq_new_request';
        $this->title = __('New quote request', 'advanced-quotes-for-woocommerce');
        $this->description = __('Sent to the store when a customer submits a quote request.', 'advanced-quotes-for-woocommerce');
        $this->template_html = 'emails/wewp-aq-new-request.php';
        $this->template_plain = 'emails/plain/wewp-aq-new-request.php';
        add_action('wewp_aq_request_created_notification', [$this, 'trigger']);
        parent::__construct();
    }

    public function get_default_subject(): string
    {
        return __('[{site_title}]: New quote request {quote_number} from {customer_name}', 'advanced-quotes-for-woocommerce');
    }

    public function get_default_heading(): string
    {
        return __('New quote request', 'advanced-quotes-for-woocommerce');
    }

    public function trigger(int $quoteId): void
    {
        if ($this->is_enabled() && $this->load($quoteId)) {
            $this->deliver((string) $this->get_recipient());
        }
    }
}
