<?php

namespace WeWP\AdvancedQuotes;

use WeWP\AdvancedQuotes\Admin\Admin;
use WeWP\AdvancedQuotes\Emails\Emails;
use WeWP\AdvancedQuotes\Storefront\Account;
use WeWP\AdvancedQuotes\Storefront\QuotePage;
use WeWP\AdvancedQuotes\Storefront\RequestPage;
use WeWP\AdvancedQuotes\Templates\Essential;
use WeWP\AdvancedQuotes\Templates\Registry;

final class Plugin
{
    public const VERSION = '0.1.0';

    public const SCHEMA = '1';

    private static ?self $instance = null;

    private ?Registry $templates = null;

    public readonly Store $store;

    public readonly Quotes $quotes;

    private function __construct()
    {
        $this->store = new Store;
        $this->quotes = new Quotes($this);
    }

    public static function instance(): self
    {
        return self::$instance ??= new self;
    }

    public static function activate(bool $network = false): void
    {
        if ($network) {
            wp_die(esc_html__('Activate Advanced Quotes separately on each store. Network activation is not supported.', 'advanced-quotes-for-woocommerce'));
        }
        foreach (['dom', 'gd', 'mbstring'] as $extension) {
            if (! extension_loaded($extension)) {
                wp_die(esc_html__('Advanced Quotes needs the PHP DOM, GD and mbstring extensions.', 'advanced-quotes-for-woocommerce'));
            }
        }
        (new Store)->install();
        RequestPage::ensurePage();
        Account::endpoint();
        flush_rewrite_rules();
        update_option('wewp_aq_flush_rules', 1, false);
    }

    public static function deactivate(): void
    {
        flush_rewrite_rules();
    }

    public static function boot(): void
    {
        if (! class_exists('WooCommerce') || ! defined('WC_VERSION') || version_compare(WC_VERSION, '11.1', '<')) {
            add_action('admin_notices', static function (): void {
                echo '<div class="notice notice-error"><p>'.esc_html__('Advanced Quotes needs WooCommerce 11.1 or newer. Your saved quotes stay in the database.', 'advanced-quotes-for-woocommerce').'</p></div>';
            });

            return;
        }
        $plugin = self::instance();
        if (get_option('wewp_aq_schema') !== self::SCHEMA) {
            $plugin->store->install();
        }
        (new Orders($plugin->store))->boot();
        (new Emails($plugin))->boot();
        (new Privacy($plugin->store))->boot();
        (new RequestPage($plugin))->boot();
        (new QuotePage($plugin))->boot();
        (new Account($plugin))->boot();
        if (is_admin()) {
            (new Admin($plugin))->boot();
        }
    }

    /**
     * Template registry, built on first use so add-ons loaded later can register.
     */
    public function templates(): Registry
    {
        if (! $this->templates) {
            $registry = new Registry;
            $registry->register(new Essential);
            do_action(Registry::HOOK, $registry);
            $registry->seal();
            $this->templates = $registry;
        }

        return $this->templates;
    }

    public function renderer(): Renderer
    {
        return new Renderer($this->templates());
    }

    public function acceptance(): Acceptance
    {
        return new Acceptance($this->store);
    }

    public static function url(string $path): string
    {
        return plugins_url($path, WEWP_AQ_FILE);
    }
}
