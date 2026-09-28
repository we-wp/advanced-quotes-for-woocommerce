<?php

namespace WeWP\AdvancedQuotes\Storefront;

use WeWP\AdvancedQuotes\Access;
use WeWP\AdvancedQuotes\Admin\ListTable;
use WeWP\AdvancedQuotes\Plugin;
use WeWP\AdvancedQuotes\Snapshot;

/**
 * My Account → Quotes for registered customers.
 */
final class Account
{
    public const ENDPOINT = 'quotes';

    public function __construct(private Plugin $plugin) {}

    public static function endpoint(): void
    {
        add_rewrite_endpoint(self::ENDPOINT, EP_ROOT | EP_PAGES);
    }

    public function boot(): void
    {
        add_filter('woocommerce_get_query_vars', static function (array $vars): array {
            $vars[self::ENDPOINT] = self::ENDPOINT;

            return $vars;
        });
        add_action('init', static function (): void {
            if (get_option('wewp_aq_flush_rules')) {
                delete_option('wewp_aq_flush_rules');
                flush_rewrite_rules();
            }
        }, 99);
        add_filter('woocommerce_account_menu_items', [$this, 'menu']);
        add_action('woocommerce_account_'.self::ENDPOINT.'_endpoint', [$this, 'content']);
        add_filter('woocommerce_endpoint_'.self::ENDPOINT.'_title', static fn () => __('Quotes', 'advanced-quotes-for-woocommerce'));
    }

    public function menu(array $items): array
    {
        $out = [];
        foreach ($items as $key => $label) {
            $out[$key] = $label;
            if ($key === 'orders') {
                $out[self::ENDPOINT] = __('Quotes', 'advanced-quotes-for-woocommerce');
            }
        }
        if (! isset($out[self::ENDPOINT])) {
            $out[self::ENDPOINT] = __('Quotes', 'advanced-quotes-for-woocommerce');
        }

        return $out;
    }

    public function content(): void
    {
        $rows = $this->plugin->store->forCustomer(get_current_user_id());
        if (! $rows) {
            echo '<div class="woocommerce-info">'.esc_html__('You have no quotes yet. Use the request button on a product to ask for a quote.', 'advanced-quotes-for-woocommerce').'</div>';

            return;
        }
        $labels = ListTable::statuses();
        echo '<table class="woocommerce-orders-table shop_table shop_table_responsive my_account_orders account-quotes-table"><thead><tr>';
        foreach ([__('Quote', 'advanced-quotes-for-woocommerce'), __('Date', 'advanced-quotes-for-woocommerce'), __('Status', 'advanced-quotes-for-woocommerce'), __('Total', 'advanced-quotes-for-woocommerce'), ''] as $heading) {
            echo '<th scope="col"><span class="nobr">'.esc_html($heading).'</span></th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($rows as $row) {
            $quote = $this->plugin->store->find((int) $row['id']);
            if (! $quote) {
                continue;
            }
            $status = ListTable::effectiveStatus($row);
            $label = $status === 'sent' ? __('Awaiting your reply', 'advanced-quotes-for-woocommerce') : ($status === 'requested' ? __('Being prepared', 'advanced-quotes-for-woocommerce') : ($labels[$status] ?? $status));
            $total = (int) $row['revision'] > 0 ? Snapshot::money((string) $row['total'], Snapshot::format((string) ($row['currency'] ?: get_woocommerce_currency()))) : '—';
            echo '<tr><td data-title="'.esc_attr__('Quote', 'advanced-quotes-for-woocommerce').'">'.esc_html($row['number']).'</td>';
            echo '<td data-title="'.esc_attr__('Date', 'advanced-quotes-for-woocommerce').'"><time datetime="'.esc_attr(gmdate('c', strtotime($row['created_at'].' UTC'))).'">'.esc_html(wp_date((string) get_option('date_format'), strtotime($row['created_at'].' UTC'))).'</time></td>';
            echo '<td data-title="'.esc_attr__('Status', 'advanced-quotes-for-woocommerce').'">'.esc_html($label).'</td>';
            echo '<td data-title="'.esc_attr__('Total', 'advanced-quotes-for-woocommerce').'">'.esc_html($total).'</td><td>';
            if ((int) $row['revision'] > 0) {
                echo '<a class="woocommerce-button button view" href="'.esc_url(Access::url($quote)).'">'.esc_html__('View', 'advanced-quotes-for-woocommerce').'</a>';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table>';
    }
}
