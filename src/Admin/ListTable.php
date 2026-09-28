<?php

namespace WeWP\AdvancedQuotes\Admin;

use WeWP\AdvancedQuotes\Plugin;
use WeWP\AdvancedQuotes\Snapshot;
use WP_List_Table;

if (! defined('ABSPATH')) {
    exit;
}

if (! class_exists(WP_List_Table::class)) {
    require_once ABSPATH.'wp-admin/includes/class-wp-list-table.php';
}

final class ListTable extends WP_List_Table
{
    public function __construct(private Plugin $plugin)
    {
        parent::__construct(['singular' => 'quote', 'plural' => 'quotes', 'ajax' => false]);
    }

    public static function statuses(): array
    {
        return [
            'requested' => __('Requested', 'advanced-quotes-for-woocommerce'),
            'draft' => __('Draft', 'advanced-quotes-for-woocommerce'),
            'sent' => __('Sent', 'advanced-quotes-for-woocommerce'),
            'accepted' => __('Accepted', 'advanced-quotes-for-woocommerce'),
            'paid' => __('Paid', 'advanced-quotes-for-woocommerce'),
            'expired' => __('Expired', 'advanced-quotes-for-woocommerce'),
            'declined' => __('Declined', 'advanced-quotes-for-woocommerce'),
            'cancelled' => __('Withdrawn', 'advanced-quotes-for-woocommerce'),
        ];
    }

    /** Status shown to people: sent quotes past their date read as expired. */
    public static function effectiveStatus(array $row): string
    {
        if ($row['status'] === 'sent' && ! empty($row['expires_on']) && $row['expires_on'] < current_time('Y-m-d')) {
            return 'expired';
        }

        return $row['status'] === 'accepting' ? 'accepted' : (string) $row['status'];
    }

    public static function badge(string $status): string
    {
        $labels = self::statuses();

        return '<mark class="wewp-aq-status is-'.esc_attr($status).'"><span>'.esc_html($labels[$status] ?? $status).'</span></mark>';
    }

    public function get_columns(): array
    {
        return [
            'number' => __('Quote', 'advanced-quotes-for-woocommerce'),
            'customer_name' => __('Customer', 'advanced-quotes-for-woocommerce'),
            'status' => __('Status', 'advanced-quotes-for-woocommerce'),
            'total' => __('Total', 'advanced-quotes-for-woocommerce'),
            'expires_on' => __('Valid until', 'advanced-quotes-for-woocommerce'),
            'updated_at' => __('Updated', 'advanced-quotes-for-woocommerce'),
        ];
    }

    protected function get_sortable_columns(): array
    {
        return ['number' => ['number', false], 'customer_name' => ['customer_name', false], 'expires_on' => ['expires_on', false], 'updated_at' => ['updated_at', true]];
    }

    protected function get_views(): array
    {
        $counts = $this->plugin->store->counts();
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter.
        $current = sanitize_key(wp_unslash($_GET['status'] ?? ''));
        $views = ['all' => '<a href="'.esc_url(Admin::url()).'"'.($current === '' ? ' class="current" aria-current="page"' : '').'>'.esc_html__('All', 'advanced-quotes-for-woocommerce').' <span class="count">('.number_format_i18n($counts['all'] ?? 0).')</span></a>'];
        foreach (self::statuses() as $status => $label) {
            if (empty($counts[$status])) {
                continue;
            }
            $views[$status] = '<a href="'.esc_url(Admin::url(['status' => $status])).'"'.($current === $status ? ' class="current" aria-current="page"' : '').'>'.esc_html($label).' <span class="count">('.number_format_i18n($counts[$status]).')</span></a>';
        }

        return $views;
    }

    public function prepare_items(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only list parameters.
        $status = sanitize_key(wp_unslash($_GET['status'] ?? ''));
        $term = sanitize_text_field(wp_unslash($_GET['s'] ?? ''));
        $orderBy = sanitize_key(wp_unslash($_GET['orderby'] ?? 'updated_at'));
        $order = sanitize_key(wp_unslash($_GET['order'] ?? 'desc'));
        // phpcs:enable
        $perPage = 20;
        [$rows, $total] = $this->plugin->store->search($status, $term, $this->get_pagenum(), $perPage, $orderBy, $order);
        $this->items = $rows;
        $this->_column_headers = [$this->get_columns(), [], $this->get_sortable_columns(), 'number'];
        $this->set_pagination_args(['total_items' => $total, 'per_page' => $perPage]);
    }

    public function no_items(): void
    {
        esc_html_e('No quotes match this view.', 'advanced-quotes-for-woocommerce');
    }

    protected function column_number(array $row): string
    {
        $url = Admin::editUrl((int) $row['id']);
        $out = '<a class="row-title" href="'.esc_url($url).'">'.esc_html($row['number']).'</a>';
        if ((int) $row['revision'] > 1) {
            /* translators: %d: revision number */
            $out .= ' <span class="wewp-aq-rev">'.esc_html(sprintf(__('Revision %d', 'advanced-quotes-for-woocommerce'), (int) $row['revision'])).'</span>';
        }
        $actions = ['edit' => '<a href="'.esc_url($url).'">'.esc_html__('Open', 'advanced-quotes-for-woocommerce').'</a>'];
        if ((int) $row['revision'] > 0) {
            $actions['pdf'] = '<a href="'.esc_url(Actions::downloadUrl((int) $row['id'])).'">'.esc_html__('Download PDF', 'advanced-quotes-for-woocommerce').'</a>';
        }
        $actions['duplicate'] = '<a href="'.esc_url(Actions::rowUrl((int) $row['id'], 'duplicate')).'">'.esc_html__('Duplicate', 'advanced-quotes-for-woocommerce').'</a>';

        return $out.$this->row_actions($actions);
    }

    protected function column_customer_name(array $row): string
    {
        $name = trim((string) $row['customer_name']);
        $company = trim((string) $row['company']);
        $primary = $company !== '' ? $company : ($name !== '' ? $name : __('Unnamed customer', 'advanced-quotes-for-woocommerce'));
        $secondary = $company !== '' && $name !== '' ? $name.' · '.$row['email'] : $row['email'];

        return '<strong>'.esc_html($primary).'</strong><br><span class="wewp-aq-muted">'.esc_html((string) $secondary).'</span>'.($row['source'] === 'storefront' ? ' <span class="wewp-aq-tag">'.esc_html__('Customer request', 'advanced-quotes-for-woocommerce').'</span>' : '');
    }

    protected function column_status(array $row): string
    {
        $status = self::effectiveStatus($row);
        $out = self::badge($status);
        if (in_array($status, ['accepted', 'paid'], true) && (int) $row['order_id'] > 0) {
            $order = wc_get_order((int) $row['order_id']);
            if ($order) {
                /* translators: %s: order number */
                $out .= '<br><a href="'.esc_url($order->get_edit_order_url()).'">'.esc_html(sprintf(__('Order #%s', 'advanced-quotes-for-woocommerce'), $order->get_order_number())).'</a>';
            }
        }

        return $out;
    }

    protected function column_total(array $row): string
    {
        if ((float) $row['total'] <= 0 && $row['status'] === 'requested') {
            return '<span class="wewp-aq-muted">'.esc_html__('Not priced yet', 'advanced-quotes-for-woocommerce').'</span>';
        }

        return esc_html(Snapshot::money((string) $row['total'], Snapshot::format((string) ($row['currency'] ?: get_woocommerce_currency()))));
    }

    protected function column_expires_on(array $row): string
    {
        if (empty($row['expires_on']) || (int) $row['revision'] === 0) {
            return '<span class="wewp-aq-muted">—</span>';
        }

        return esc_html(wp_date((string) get_option('date_format'), strtotime($row['expires_on'].' 12:00:00')));
    }

    protected function column_updated_at(array $row): string
    {
        $time = strtotime($row['updated_at'].' UTC');

        /* translators: %s: human-readable time difference */
        return '<time datetime="'.esc_attr(gmdate('c', $time)).'" title="'.esc_attr(wp_date((string) get_option('date_format').' '.get_option('time_format'), $time)).'">'.esc_html(sprintf(__('%s ago', 'advanced-quotes-for-woocommerce'), human_time_diff($time))).'</time>';
    }
}
