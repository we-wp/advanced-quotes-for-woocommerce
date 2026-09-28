<?php

namespace WeWP\AdvancedQuotes;

/**
 * WordPress personal data export and erasure for quotes matched by customer email.
 */
final class Privacy
{
    public function __construct(private Store $store) {}

    public function boot(): void
    {
        add_filter('wp_privacy_personal_data_exporters', function (array $exporters): array {
            $exporters['wewp-quotes'] = ['exporter_friendly_name' => __('Quotes', 'advanced-quotes-for-woocommerce'), 'callback' => [$this, 'export']];

            return $exporters;
        });
        add_filter('wp_privacy_personal_data_erasers', function (array $erasers): array {
            $erasers['wewp-quotes'] = ['eraser_friendly_name' => __('Quotes', 'advanced-quotes-for-woocommerce'), 'callback' => [$this, 'erase']];

            return $erasers;
        });
        add_action('admin_init', static function (): void {
            if (function_exists('wp_add_privacy_policy_content')) {
                wp_add_privacy_policy_content(__('Advanced Quotes', 'advanced-quotes-for-woocommerce'), wp_kses_post(wpautop(__('When you request a quote, the store keeps your name, company, contact details, address, requested items, message and any other answers you give on the request form to prepare the quote. Sent quotes and their PDF files are kept as business records. The quote plugin sends no data to third parties.', 'advanced-quotes-for-woocommerce'))));
            }
        });
    }

    public function export(string $email, int $page = 1): array
    {
        $rows = $this->store->forEmail($email, $page);
        $data = [];
        foreach ($rows as $row) {
            $draft = json_decode((string) $row['draft'], true) ?: [];
            unset($draft['note']); // Staff-only note, not customer data.
            $items = [
                ['name' => __('Quote number', 'advanced-quotes-for-woocommerce'), 'value' => $row['number']],
                ['name' => __('Status', 'advanced-quotes-for-woocommerce'), 'value' => $row['status']],
                ['name' => __('Quote details', 'advanced-quotes-for-woocommerce'), 'value' => (string) wp_json_encode($draft, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)],
                ['name' => __('Original request', 'advanced-quotes-for-woocommerce'), 'value' => (string) ($row['request'] ?? '')],
            ];
            foreach ($this->store->snapshots((int) $row['id']) as $index => $snapshot) {
                /* translators: %d: revision number */
                $items[] = ['name' => sprintf(__('Sent revision %d', 'advanced-quotes-for-woocommerce'), $index + 1), 'value' => $snapshot];
            }
            $data[] = ['group_id' => 'wewp-quotes', 'group_label' => __('Quotes', 'advanced-quotes-for-woocommerce'), 'item_id' => 'wewp-quote-'.$row['id'], 'data' => $items];
        }

        return ['data' => $data, 'done' => count($rows) < 50];
    }

    /**
     * Delete quotes that never became an order. Quotes linked to an order stay with that order's records.
     */
    public function erase(string $email, int $page = 1): array
    {
        $removed = false;
        $retained = false;
        $messages = [];
        foreach ($this->store->forEmail($email, 1, 100) as $row) {
            if ((int) $row['order_id'] > 0 || in_array($row['status'], ['accepted', 'accepting', 'paid'], true)) {
                $retained = true;
                continue;
            }
            $this->store->delete((int) $row['id']);
            $removed = true;
        }
        if ($retained) {
            $messages[] = __('Quotes that became orders were kept with the order records. Review them with the related orders.', 'advanced-quotes-for-woocommerce');
        }

        return ['items_removed' => $removed, 'items_retained' => $retained, 'messages' => $messages, 'done' => true];
    }
}
