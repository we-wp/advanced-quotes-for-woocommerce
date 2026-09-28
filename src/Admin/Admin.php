<?php

namespace WeWP\AdvancedQuotes\Admin;

use WeWP\AdvancedQuotes\Plugin;

/**
 * Admin wiring: menu, screens, actions, AJAX and the WooCommerce settings tab.
 */
final class Admin
{
    public const CAP = 'manage_wewp_quotes';

    public const SLUG = 'wewp-quotes';

    public function __construct(private Plugin $plugin) {}

    public function boot(): void
    {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_enqueue_scripts', [$this, 'assets']);
        $actions = new Actions($this->plugin);
        add_action('admin_post_wewp_aq_save', [$actions, 'save']);
        add_action('admin_post_wewp_aq_download', [$actions, 'download']);
        add_action('admin_post_wewp_aq_row', [$actions, 'row']);
        $ajax = new Ajax($this->plugin);
        add_action('wp_ajax_wewp_aq_calculate', [$ajax, 'calculate']);
        add_action('wp_ajax_wewp_aq_product', [$ajax, 'product']);
        add_action('wp_ajax_wewp_aq_customer', [$ajax, 'customer']);
        add_filter('woocommerce_get_settings_pages', static function (array $pages): array {
            $pages[] = new SettingsTab;

            return $pages;
        });
        add_filter('plugin_action_links_'.plugin_basename(WEWP_AQ_FILE), static function (array $links): array {
            array_unshift($links, '<a href="'.esc_url(admin_url('admin.php?page=wc-settings&tab=wewp_quotes')).'">'.esc_html__('Settings', 'advanced-quotes-for-woocommerce').'</a>');

            return $links;
        });
        add_action('admin_notices', [Notice::class, 'render']);
    }

    public function menu(): void
    {
        $count = $this->plugin->store->counts()['requested'] ?? 0;
        $bubble = $count ? ' <span class="awaiting-mod count-'.(int) $count.'"><span class="pending-count">'.number_format_i18n($count).'</span></span>' : '';
        add_submenu_page('woocommerce', __('Quotes', 'advanced-quotes-for-woocommerce'), __('Quotes', 'advanced-quotes-for-woocommerce').$bubble, self::CAP, self::SLUG, [$this, 'page'], 3);
    }

    public function page(): void
    {
        if (! current_user_can(self::CAP)) {
            wp_die(esc_html__('You cannot manage quotes.', 'advanced-quotes-for-woocommerce'), '', ['response' => 403]);
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen routing.
        $action = sanitize_key(wp_unslash($_GET['action'] ?? ''));
        if (in_array($action, ['new', 'edit'], true)) {
            (new Editor($this->plugin))->render();

            return;
        }
        (new ListPage($this->plugin))->render();
    }

    public function assets(string $hook): void
    {
        if (! str_ends_with($hook, '_page_'.self::SLUG)) {
            return;
        }
        $version = WEWP_AQ_VERSION.'-'.filemtime(dirname(WEWP_AQ_FILE).'/assets/js/admin.js');
        wp_enqueue_style('wewp-aq-admin', Plugin::url('assets/css/admin.css'), ['woocommerce_admin_styles'], $version);
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen routing.
        if (in_array(sanitize_key(wp_unslash($_GET['action'] ?? '')), ['new', 'edit'], true)) {
            wp_enqueue_script('wewp-aq-admin', Plugin::url('assets/js/admin.js'), ['jquery', 'wc-enhanced-select'], $version, true);
            wp_localize_script('wewp-aq-admin', 'wewpAq', [
                'ajax' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('wewp_aq_ajax'),
                'i18n' => [
                    'remove' => __('Remove item', 'advanced-quotes-for-woocommerce'),
                    'calculating' => __('Calculating…', 'advanced-quotes-for-woocommerce'),
                    'copied' => __('Link copied', 'advanced-quotes-for-woocommerce'),
                    'unsaved' => __('You have unsaved changes.', 'advanced-quotes-for-woocommerce'),
                    'chooseVariation' => __('Choose a specific variation.', 'advanced-quotes-for-woocommerce'),
                    /* translators: %s: customer email address */
                    'confirmSend' => __('Send this quote to %s now?', 'advanced-quotes-for-woocommerce'),
                    'confirmAccept' => __('Accept this quote for the customer and create a pending order?', 'advanced-quotes-for-woocommerce'),
                    'confirmCancel' => __('Withdraw this quote? The customer can no longer accept it.', 'advanced-quotes-for-woocommerce'),
                    'confirmReset' => __('Create a new customer link? Links sent earlier will stop working.', 'advanced-quotes-for-woocommerce'),
                ],
            ]);
        }
    }

    public static function url(array $args = []): string
    {
        return add_query_arg($args, admin_url('admin.php?page='.self::SLUG));
    }

    public static function editUrl(int $id): string
    {
        return self::url(['action' => 'edit', 'quote' => $id]);
    }
}
