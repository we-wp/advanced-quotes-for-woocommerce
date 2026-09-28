<?php

namespace WeWP\AdvancedQuotes\Emails;

use Throwable;

/**
 * To the customer: the quote, with a private link and the PDF attached.
 */
final class QuoteEmail extends Base
{
    private string $attachment = '';

    public function __construct()
    {
        $this->id = 'wewp_aq_quote';
        $this->customer_email = true;
        $this->title = __('Quote', 'advanced-quotes-for-woocommerce');
        $this->description = __('Sent to the customer when you send a quote or a new revision. It links to the online quote where the customer can accept and pay.', 'advanced-quotes-for-woocommerce');
        $this->template_html = 'emails/wewp-aq-quote.php';
        $this->template_plain = 'emails/plain/wewp-aq-quote.php';
        add_action('wewp_aq_quote_sent_notification', [$this, 'trigger'], 10, 2);
        parent::__construct();
    }

    public function get_default_subject(): string
    {
        return __('Your quote {quote_number} from {site_title}', 'advanced-quotes-for-woocommerce');
    }

    public function get_default_heading(): string
    {
        return __('Your quote is ready', 'advanced-quotes-for-woocommerce');
    }

    public function init_form_fields(): void
    {
        parent::init_form_fields();
        $this->form_fields['attach_pdf'] = [
            'title' => __('Attach PDF', 'advanced-quotes-for-woocommerce'),
            'type' => 'checkbox',
            'label' => __('Attach the quote PDF to this email', 'advanced-quotes-for-woocommerce'),
            'default' => 'yes',
        ];
    }

    public function get_attachments(): array
    {
        $attachments = parent::get_attachments();
        if ($this->attachment !== '') {
            $attachments[] = $this->attachment;
        }

        return $attachments;
    }

    public function trigger(int $quoteId, int $revision = 0): void
    {
        if (! $this->is_enabled() || ! $this->load($quoteId, $revision ?: null) || ! $this->revision) {
            return;
        }
        $this->attachment = '';
        $dir = '';
        try {
            if ($this->get_option('attach_pdf', 'yes') === 'yes') {
                try {
                    $pdf = Emails::plugin()->store->pdf($this->revision, Emails::plugin()->renderer());
                    // A private directory and file, because the system temporary directory may be shared with other accounts.
                    $dir = trailingslashit(get_temp_dir()).'wewp-aq-'.wp_generate_password(16, false, false);
                    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Needs owner-only permissions.
                    if (mkdir($dir, 0700)) {
                        $file = $dir.'/'.sanitize_file_name($this->quote['number'].'.pdf');
                        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Temporary attachment removed after sending.
                        if (file_put_contents($file, $pdf) !== false && chmod($file, 0600)) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod
                            $this->attachment = $file;
                        }
                    }
                } catch (Throwable) {
                    $this->attachment = '';
                }
            }
            $this->deliver((string) $this->revision['data']['customer']['email']);
        } finally {
            if ($this->attachment !== '') {
                wp_delete_file($this->attachment);
            }
            if ($dir !== '' && is_dir($dir)) {
                @rmdir($dir); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Best-effort cleanup of the empty temporary directory.
            }
            $this->attachment = '';
        }
    }
}
