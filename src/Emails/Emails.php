<?php

namespace WeWP\AdvancedQuotes\Emails;

use WeWP\AdvancedQuotes\Plugin;

/**
 * Registers the quote emails with WooCommerce. Each one can be edited or turned off in
 * WooCommerce → Settings → Emails. WooCommerce sends them through its normal mailer.
 */
final class Emails
{
    public const ACTIONS = ['wewp_aq_request_created', 'wewp_aq_quote_sent', 'wewp_aq_quote_accepted', 'wewp_aq_quote_declined'];

    private static ?Plugin $plugin = null;

    public function __construct(Plugin $plugin)
    {
        self::$plugin = $plugin;
    }

    public static function plugin(): Plugin
    {
        return self::$plugin ?? Plugin::instance();
    }

    public function boot(): void
    {
        add_filter('woocommerce_email_actions', static fn (array $actions): array => array_merge($actions, self::ACTIONS));
        add_filter('woocommerce_email_classes', static function (array $emails): array {
            $emails['WEWP_AQ_Request_Received'] = new RequestReceived;
            $emails['WEWP_AQ_New_Request'] = new NewRequest;
            $emails['WEWP_AQ_Quote'] = new QuoteEmail;
            $emails['WEWP_AQ_Accepted'] = new Accepted;
            $emails['WEWP_AQ_Declined'] = new Declined;

            return $emails;
        });
    }
}
