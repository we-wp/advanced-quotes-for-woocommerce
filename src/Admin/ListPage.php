<?php

namespace WeWP\AdvancedQuotes\Admin;

use WeWP\AdvancedQuotes\Plugin;

/**
 * WooCommerce → Quotes list screen.
 */
final class ListPage
{
    public function __construct(private Plugin $plugin) {}

    public function render(): void
    {
        $table = new ListTable($this->plugin);
        $table->prepare_items();
        $counts = $this->plugin->store->counts();
        echo '<div class="wrap wewp-aq-list"><h1 class="wp-heading-inline">'.esc_html__('Quotes', 'advanced-quotes-for-woocommerce').'</h1>';
        echo ' <a href="'.esc_url(Admin::url(['action' => 'new'])).'" class="page-title-action">'.esc_html__('Add quote', 'advanced-quotes-for-woocommerce').'</a>';
        echo ' <a href="'.esc_url(admin_url('admin.php?page=wc-settings&tab=wewp_quotes')).'" class="page-title-action">'.esc_html__('Settings', 'advanced-quotes-for-woocommerce').'</a><hr class="wp-header-end">';
        if (($counts['all'] ?? 0) === 0) {
            $this->emptyState();
            echo '</div>';

            return;
        }
        $table->views();
        echo '<form method="get"><input type="hidden" name="page" value="'.esc_attr(Admin::SLUG).'">';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter.
        $status = sanitize_key(wp_unslash($_GET['status'] ?? ''));
        if ($status !== '') {
            echo '<input type="hidden" name="status" value="'.esc_attr($status).'">';
        }
        $table->search_box(__('Search quotes', 'advanced-quotes-for-woocommerce'), 'wewp-aq-search');
        $table->display();
        echo '</form></div>';
    }

    private function emptyState(): void
    {
        $page = (int) \WeWP\AdvancedQuotes\Settings::value('request_page');
        echo '<div class="wewp-aq-empty">';
        echo '<h2>'.esc_html__('Your first quote starts here', 'advanced-quotes-for-woocommerce').'</h2>';
        echo '<ol>';
        echo '<li>'.esc_html__('Customers select “Request a quote” on a product or in the cart, then send their request.', 'advanced-quotes-for-woocommerce').'</li>';
        echo '<li>'.esc_html__('You set the prices here and send a numbered quote by email.', 'advanced-quotes-for-woocommerce').'</li>';
        echo '<li>'.esc_html__('The customer accepts online and pays on the WooCommerce payment page.', 'advanced-quotes-for-woocommerce').'</li>';
        echo '</ol><p>';
        echo '<a class="button button-primary" href="'.esc_url(Admin::url(['action' => 'new'])).'">'.esc_html__('Create a quote', 'advanced-quotes-for-woocommerce').'</a> ';
        echo '<a class="button" href="'.esc_url(admin_url('admin.php?page=wc-settings&tab=wewp_quotes')).'">'.esc_html__('Add your business details', 'advanced-quotes-for-woocommerce').'</a>';
        if ($page && get_post_status($page) === 'publish') {
            echo ' <a class="button" href="'.esc_url((string) get_permalink($page)).'">'.esc_html__('View the request page', 'advanced-quotes-for-woocommerce').'</a>';
        }
        echo '</p></div>';
    }
}
