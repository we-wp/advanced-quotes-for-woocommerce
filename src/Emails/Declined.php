<?php

namespace WeWP\AdvancedQuotes\Emails;

/**
 * To the store: a customer declined a quote.
 */
final class Declined extends AdminEmail
{
    private string $reason = '';

    public function __construct()
    {
        $this->id = 'wewp_aq_quote_declined';
        $this->title = __('Quote declined', 'advanced-quotes-for-woocommerce');
        $this->description = __('Sent to the store when a customer declines a quote.', 'advanced-quotes-for-woocommerce');
        $this->template_html = 'emails/wewp-aq-declined.php';
        $this->template_plain = 'emails/plain/wewp-aq-declined.php';
        add_action('wewp_aq_quote_declined_notification', [$this, 'trigger'], 10, 2);
        parent::__construct();
    }

    public function get_default_subject(): string
    {
        return __('[{site_title}]: Quote {quote_number} was declined', 'advanced-quotes-for-woocommerce');
    }

    public function get_default_heading(): string
    {
        return __('Quote declined', 'advanced-quotes-for-woocommerce');
    }

    protected function extraArgs(): array
    {
        return ['reason' => $this->reason];
    }

    public function trigger(int $quoteId, string $reason = ''): void
    {
        $this->reason = $reason;
        if ($this->is_enabled() && $this->load($quoteId)) {
            $this->deliver((string) $this->get_recipient());
        }
    }
}
