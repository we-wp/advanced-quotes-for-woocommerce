<?php

namespace WeWP\AdvancedQuotes\Emails;

/**
 * Base for emails sent to the store. Recipients default to the site admin address.
 */
abstract class AdminEmail extends Base
{
    public function __construct()
    {
        $this->customer_email = false;
        parent::__construct();
        $this->recipient = $this->get_option('recipient', get_option('admin_email'));
    }

    public function init_form_fields(): void
    {
        parent::init_form_fields();
        $this->form_fields = array_merge(['enabled' => $this->form_fields['enabled'], 'recipient' => [
            'title' => __('Recipient(s)', 'advanced-quotes-for-woocommerce'),
            'type' => 'text',
            /* translators: %s: admin email address */
            'description' => sprintf(__('Enter recipients (comma separated). Defaults to %s.', 'advanced-quotes-for-woocommerce'), '<code>'.esc_html(get_option('admin_email')).'</code>'),
            'placeholder' => '',
            'default' => '',
            'desc_tip' => true,
        ]], $this->form_fields);
    }
}
