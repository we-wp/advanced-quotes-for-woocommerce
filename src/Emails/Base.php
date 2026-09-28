<?php

namespace WeWP\AdvancedQuotes\Emails;

use WC_Email;
use WeWP\AdvancedQuotes\Access;
use WeWP\AdvancedQuotes\RequestFields;
use WeWP\AdvancedQuotes\Snapshot;

/**
 * Shared behaviour for quote emails: loading the quote, placeholders and templates.
 */
abstract class Base extends WC_Email
{
    protected array $quote = [];

    protected ?array $revision = null;

    public function __construct()
    {
        $this->template_base = dirname(WEWP_AQ_FILE).'/templates/';
        $this->placeholders = array_merge($this->placeholders, ['{quote_number}' => '', '{customer_name}' => '']);
        parent::__construct();
    }

    /**
     * Load the quote and fill placeholders. Returns false when the quote is missing.
     */
    protected function load(int $quoteId, ?int $revision = null): bool
    {
        $quote = Emails::plugin()->store->find($quoteId);
        if (! $quote) {
            return false;
        }
        $this->quote = $quote;
        $this->revision = $quote['revision'] > 0 ? Emails::plugin()->store->revision($quoteId, $revision) : null;
        $this->placeholders['{quote_number}'] = $quote['number'];
        $this->placeholders['{customer_name}'] = $quote['customer_name'] !== '' ? $quote['customer_name'] : $quote['company'];

        return true;
    }

    protected function templateArgs(bool $plain): array
    {
        $snapshot = $this->revision['data'] ?? null;

        return [
            'quote' => $this->quote,
            'snapshot' => $snapshot,
            'request' => $this->quote['request'] ?? null,
            'answers' => RequestFields::answers($this->quote['request'] ?? null),
            'total' => $snapshot ? Snapshot::money($snapshot['totals']['total'], $snapshot['format']) : '',
            'quote_url' => $this->quote && $this->quote['revision'] > 0 ? Access::url($this->quote) : '',
            'admin_url' => admin_url('admin.php?page=wewp-quotes&action=edit&quote='.(int) ($this->quote['id'] ?? 0)),
            'email_heading' => $this->get_heading(),
            'additional_content' => $this->get_additional_content(),
            'sent_to_admin' => ! $this->is_customer_email(),
            'plain_text' => $plain,
            'email' => $this,
        ] + $this->extraArgs();
    }

    protected function extraArgs(): array
    {
        return [];
    }

    public function get_content_html(): string
    {
        return wc_get_template_html($this->template_html, $this->templateArgs(false), '', $this->template_base);
    }

    public function get_content_plain(): string
    {
        return wc_get_template_html($this->template_plain, $this->templateArgs(true), '', $this->template_base);
    }

    protected function deliver(string $recipient): bool
    {
        $this->setup_locale();
        $sent = $recipient !== '' && $this->send($recipient, $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments());
        $this->restore_locale();
        if ($recipient !== '' && ! $sent && $this->quote) {
            Emails::plugin()->store->event((int) $this->quote['id'], 'email_failed', $this->get_title(), 0);
        }

        return $sent;
    }
}
