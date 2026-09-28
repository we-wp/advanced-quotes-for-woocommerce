<?php

namespace WeWP\AdvancedQuotes;

use RuntimeException;
use Throwable;
use WC_Order;
use WC_Product;

/**
 * Quote workflows shared by the admin generator, the storefront and the customer page.
 */
final class Quotes
{
    public const CUSTOMER_FIELDS = ['first_name', 'last_name', 'company', 'email', 'phone', 'tax_id', 'country', 'state', 'address_1', 'address_2', 'city', 'postcode'];

    public function __construct(private Plugin $plugin) {}

    public function blankDraft(): array
    {
        $settings = Settings::get();
        $customer = array_fill_keys(self::CUSTOMER_FIELDS, '');
        $customer['country'] = WC()->countries ? WC()->countries->get_base_country() : '';

        return [
            'customer_id' => 0,
            'customer' => $customer,
            'lines' => [],
            'shipping' => ['label' => '', 'cost' => ''],
            'reference' => '',
            'intro' => $settings['intro'],
            'terms' => $settings['terms'],
            'note' => '',
            'valid_until' => '',
            'template' => $settings['template'],
            'accent' => $settings['accent'],
            'display' => $settings['display'],
        ];
    }

    /**
     * Normalise a draft from untrusted input. Prices stay strings; the calculator validates them.
     */
    public function sanitizeDraft(array $input, array $base): array
    {
        $draft = array_merge($this->blankDraft(), $base);
        $customer = is_array($input['customer'] ?? null) ? $input['customer'] : [];
        foreach (self::CUSTOMER_FIELDS as $field) {
            $value = (string) ($customer[$field] ?? '');
            $draft['customer'][$field] = $field === 'email' ? strtolower(sanitize_email($value)) : mb_substr(sanitize_text_field($value), 0, 200);
        }
        $draft['customer']['country'] = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $draft['customer']['country']), 0, 2));
        $draft['customer_id'] = absint($input['customer_id'] ?? 0);
        if ($draft['customer_id'] && ! get_userdata($draft['customer_id'])) {
            $draft['customer_id'] = 0;
        }
        $lines = [];
        $keys = [];
        foreach (array_slice(array_values(is_array($input['lines'] ?? null) ? $input['lines'] : []), 0, Calculator::MAX_LINES) as $line) {
            if (! is_array($line)) {
                continue;
            }
            $key = sanitize_key((string) ($line['key'] ?? ''));
            if ($key === '' || $key === '__key__' || isset($keys[$key])) {
                $key = 'l'.strtolower(wp_generate_password(8, false, false));
            }
            $keys[$key] = true;
            $lines[] = [
                'key' => $key,
                'product_id' => absint($line['product_id'] ?? 0),
                'variation_id' => absint($line['variation_id'] ?? 0),
                'name' => mb_substr(sanitize_text_field((string) ($line['name'] ?? '')), 0, 200),
                'description' => mb_substr(sanitize_textarea_field((string) ($line['description'] ?? '')), 0, 1000),
                'quantity' => max(0, min(999999, (int) ($line['quantity'] ?? 1))),
                'unit_price' => mb_substr(sanitize_text_field((string) ($line['unit_price'] ?? '')), 0, 20),
                'discount' => mb_substr(sanitize_text_field((string) ($line['discount'] ?? '')), 0, 8),
                'tax_class' => sanitize_title((string) ($line['tax_class'] ?? '')),
                'attributes' => self::attributes($line['attributes'] ?? []),
            ];
        }
        $draft['lines'] = $lines;
        $shipping = is_array($input['shipping'] ?? null) ? $input['shipping'] : [];
        $draft['shipping'] = [
            'label' => mb_substr(sanitize_text_field((string) ($shipping['label'] ?? '')), 0, 200),
            'cost' => mb_substr(sanitize_text_field((string) ($shipping['cost'] ?? '')), 0, 20),
        ];
        foreach (['reference' => 120, 'valid_until' => 10] as $key => $max) {
            $draft[$key] = mb_substr(sanitize_text_field((string) ($input[$key] ?? '')), 0, $max);
        }
        foreach (['intro', 'terms', 'note'] as $key) {
            $draft[$key] = mb_substr(sanitize_textarea_field((string) ($input[$key] ?? '')), 0, 4000);
        }
        $template = sanitize_key((string) ($input['template'] ?? ''));
        $draft['template'] = $this->plugin->templates()->get($template) ? $template : 'essential';
        $draft['accent'] = sanitize_hex_color((string) ($input['accent'] ?? '')) ?: Settings::value('accent');
        $draft['display'] = ($input['display'] ?? '') === 'incl' ? 'incl' : 'excl';

        return $draft;
    }

    /**
     * Variation attributes chosen for "any" values, as attribute_{slug} => value.
     *
     * @return array<string, string>
     */
    public static function attributes(mixed $input): array
    {
        $out = [];
        foreach (is_array($input) ? $input : [] as $key => $value) {
            $key = (string) $key;
            if (! str_starts_with($key, 'attribute_') || ! is_scalar($value)) {
                continue;
            }
            $slug = 'attribute_'.sanitize_title(substr($key, 10));
            $value = mb_substr(sanitize_text_field((string) $value), 0, 200);
            if ($slug !== 'attribute_' && $value !== '') {
                $out[$slug] = $value;
            }
        }

        return array_slice($out, 0, 20, true);
    }

    private function fields(array $draft, string $total): array
    {
        $c = $draft['customer'];

        return [
            'customer_id' => (int) ($draft['customer_id'] ?? 0),
            'email' => (string) $c['email'],
            'customer_name' => trim($c['first_name'].' '.$c['last_name']),
            'company' => (string) $c['company'],
            'currency' => get_woocommerce_currency(),
            'total' => $total,
        ];
    }

    private function totalOf(array $draft, string $fallback = '0'): string
    {
        try {
            return (new Calculator)->calculate($draft)['totals']['total'];
        } catch (Throwable) {
            return $fallback;
        }
    }

    public function createDraft(array $draft): array
    {
        return $this->plugin->store->create(['status' => 'draft', 'source' => 'admin'] + $this->fields($draft, $this->totalOf($draft)), $draft, null, Settings::value('prefix'));
    }

    /**
     * Create a quote from a storefront request. Lines start at the current catalogue price.
     *
     * @param list<array{product_id:int,variation_id:int,quantity:int,attributes?:array<string,string>}> $items
     */
    public function createRequest(array $customer, array $items, string $message, int $userId): array
    {
        $draft = $this->blankDraft();
        $draft['customer'] = array_merge($draft['customer'], array_intersect_key($customer, $draft['customer']));
        $draft['customer_id'] = $userId;
        $requested = [];
        foreach ($items as $item) {
            $product = wc_get_product($item['variation_id'] ?: $item['product_id']);
            if (! $product instanceof WC_Product) {
                continue;
            }
            $draft['lines'][] = [
                'key' => 'l'.wp_generate_password(6, false, false),
                'product_id' => $product->get_parent_id() ?: $product->get_id(),
                'variation_id' => $product->is_type('variation') ? $product->get_id() : 0,
                'name' => '',
                'description' => '',
                'quantity' => (int) $item['quantity'],
                'unit_price' => (string) $product->get_price('edit'),
                'discount' => '',
                'tax_class' => '',
                'attributes' => self::attributes($item['attributes'] ?? []),
            ];
            $chosen = self::attributes($item['attributes'] ?? []);
            $requested[] = ['product_id' => $product->get_parent_id() ?: $product->get_id(), 'variation_id' => $product->is_type('variation') ? $product->get_id() : 0, 'name' => $product->get_name().($chosen ? ' ('.implode(', ', $chosen).')' : ''), 'sku' => $product->get_sku(), 'quantity' => (int) $item['quantity'], 'price' => (string) $product->get_price('edit'), 'attributes' => $chosen];
        }
        if (! $draft['lines']) {
            throw new RuntimeException(__('Your quote list is empty.', 'advanced-quotes-for-woocommerce'));
        }
        $request = ['items' => $requested, 'message' => $message, 'submitted_at' => gmdate('Y-m-d H:i:s')];
        $quote = $this->plugin->store->create(['status' => 'requested', 'source' => 'storefront', 'actor_id' => $userId] + $this->fields($draft, '0'), $draft, $request, Settings::value('prefix'));
        do_action('wewp_aq_request_created', $quote['id']);

        return $quote;
    }

    /**
     * Whether an accepted quote may be revised: only when its order was cancelled or deleted.
     * A failed order can still be paid, so it keeps the quote closed.
     */
    public function canReviseAccepted(array $quote): bool
    {
        if ($quote['status'] !== 'accepted') {
            return false;
        }
        $order = $quote['order_id'] ? wc_get_order($quote['order_id']) : null;

        return ! $order instanceof WC_Order || $order->has_status(['cancelled', 'trash']);
    }

    public function isEditable(array $quote): bool
    {
        return in_array($quote['status'], Store::EDITABLE, true) || $this->canReviseAccepted($quote);
    }

    public function save(array $quote, array $draft): void
    {
        $this->plugin->store->saveDraft($quote['id'], $draft, $this->fields($draft, $this->totalOf($draft, (string) $quote['total'])), $this->canReviseAccepted($quote));
    }

    /**
     * Send the saved draft as the next revision, keep its PDF, and email the customer.
     */
    public function send(array $quote, bool $email = true): array
    {
        $calculator = new Calculator;
        $templates = $this->plugin->templates();
        $revision = $this->plugin->store->send($quote['id'], static function (array $locked, int $revision) use ($calculator, $templates): array {
            $labels = $templates->resolve((string) ($locked['draft']['template'] ?? ''))->labels();

            return Snapshot::build($locked, $revision, $locked['draft'], $calculator->calculate($locked['draft']), false, $labels);
        }, $this->canReviseAccepted($quote));
        try {
            // Keep the PDF made with the template code that was active when the quote was sent.
            $this->plugin->store->pdf($revision, $this->plugin->renderer());
        } catch (Throwable $e) {
            $this->plugin->store->event($quote['id'], 'pdf_failed', mb_substr($e->getMessage(), 0, 200));
        }
        if ($email) {
            do_action('wewp_aq_quote_sent', $quote['id'], $revision['revision']);
        }

        return $revision;
    }

    public function previewPdf(array $quote, array $draft): string
    {
        $labels = $this->plugin->templates()->resolve((string) ($draft['template'] ?? ''))->labels();
        $snapshot = Snapshot::build($quote, (int) $quote['revision'] + 1, $draft, (new Calculator)->calculate($draft), true, $labels);

        return $this->plugin->renderer()->pdf($snapshot);
    }

    public function duplicate(array $quote): array
    {
        $draft = $quote['draft'];
        $draft['valid_until'] = '';
        foreach ($draft['lines'] as &$line) {
            $line['key'] = 'l'.wp_generate_password(6, false, false);
        }

        return $this->createDraft($draft);
    }

    public function cancel(array $quote): bool
    {
        return $this->plugin->store->transition($quote['id'], ['requested', 'draft', 'sent', 'declined'], 'cancelled', 'cancelled', get_current_user_id());
    }

    /** Latest sent revision with its PDF bytes. */
    public function latestPdf(array $quote, ?int $revision = null): array
    {
        $sent = $this->plugin->store->revision($quote['id'], $revision);
        if (! $sent) {
            throw new RuntimeException(__('This quote has not been sent yet.', 'advanced-quotes-for-woocommerce'));
        }

        return [$sent, $this->plugin->store->pdf($sent, $this->plugin->renderer())];
    }
}
