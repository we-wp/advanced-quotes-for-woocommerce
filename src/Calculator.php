<?php

namespace WeWP\AdvancedQuotes;

use Automattic\WooCommerce\Utilities\NumberUtil;
use RuntimeException;
use WC_Order;
use WC_Order_Item_Product;
use WC_Order_Item_Shipping;
use WC_Product;
use WC_Tax;

/**
 * Prices a quote with WooCommerce tax rates and the rounding rules of WC_Abstract_Order.
 *
 * Line and shipping taxes come from WooCommerce order items. The totals below repeat the arithmetic of
 * WC_Abstract_Order::update_taxes() and ::calculate_totals( false ). Acceptance recreates the same items
 * with the same taxes and refuses the order if WooCommerce reaches a different total.
 */
final class Calculator
{
    public const MAX_LINES = 200;

    public function calculate(array $draft): array
    {
        $customer = is_array($draft['customer'] ?? null) ? $draft['customer'] : [];
        $order = new WC_Order; // Never saved. It only resolves the tax location.
        foreach (['country', 'state', 'postcode', 'city', 'address_1', 'address_2'] as $field) {
            $value = (string) ($customer[$field] ?? '');
            $order->{'set_billing_'.$field}($value);
            $order->{'set_shipping_'.$field}($value);
        }
        $location = $order->get_taxable_location();
        $taxEnabled = wc_tax_enabled();
        $inclusive = wc_prices_include_tax();
        $decimals = wc_get_price_decimals();
        $display = ($draft['display'] ?? Settings::value('display')) === 'incl' ? 'incl' : 'excl';
        $rawLines = array_values(is_array($draft['lines'] ?? null) ? $draft['lines'] : []);
        if (! $rawLines) {
            throw new RuntimeException(__('Add at least one item to the quote.', 'advanced-quotes-for-woocommerce'));
        }
        if (count($rawLines) > self::MAX_LINES) {
            /* translators: %d: maximum number of lines */
            throw new RuntimeException(sprintf(__('A quote can have up to %d items.', 'advanced-quotes-for-woocommerce'), self::MAX_LINES));
        }

        $items = [];
        $lines = [];
        foreach ($rawLines as $index => $raw) {
            $position = $index + 1;
            $quantity = (int) ($raw['quantity'] ?? 0);
            if ($quantity < 1 || $quantity > 999999) {
                /* translators: %d: item position in the quote */
                throw new RuntimeException(sprintf(__('Item %d: enter a quantity from 1 to 999999.', 'advanced-quotes-for-woocommerce'), $position));
            }
            /* translators: %d: item position in the quote */
            $unit = Money::price($raw['unit_price'] ?? '0', sprintf(__('Item %d unit price', 'advanced-quotes-for-woocommerce'), $position));
            $discount = Money::percent($raw['discount'] ?? '0');
            $item = new WC_Order_Item_Product;
            $product = null;
            $productId = absint($raw['product_id'] ?? 0);
            $variationId = absint($raw['variation_id'] ?? 0);
            if ($productId || $variationId) {
                $product = wc_get_product($variationId ?: $productId);
                if (! $product instanceof WC_Product || $product->get_status() === 'trash' || ($variationId && $product->get_parent_id() !== $productId && $productId)) {
                    /* translators: %d: item position in the quote */
                    throw new RuntimeException(sprintf(__('Item %d: the product no longer exists. Remove the item or add a custom item.', 'advanced-quotes-for-woocommerce'), $position));
                }
                if ($product->is_type('variable')) {
                    /* translators: %d: item position in the quote */
                    throw new RuntimeException(sprintf(__('Item %d: choose a specific variation of this product.', 'advanced-quotes-for-woocommerce'), $position));
                }
                $item->set_product($product);
                $attributes = [];
                if ($product->is_type('variation')) {
                    $open = array_filter($product->get_variation_attributes(), static fn ($value) => $value === '');
                    $attributes = array_intersect_key(Quotes::attributes($raw['attributes'] ?? []), $open);
                }
                $taxClass = $product->get_tax_class();
                $taxable = $taxEnabled && $product->is_taxable();
                $name = $product->get_name();
            } else {
                $name = trim(sanitize_text_field((string) ($raw['name'] ?? '')));
                if ($name === '') {
                    /* translators: %d: item position in the quote */
                    throw new RuntimeException(sprintf(__('Item %d: enter a name for the custom item.', 'advanced-quotes-for-woocommerce'), $position));
                }
                $attributes = [];
                $taxClass = (string) ($raw['tax_class'] ?? '');
                if ($taxClass !== '0' && $taxClass !== '' && ! in_array($taxClass, WC_Tax::get_tax_class_slugs(), true)) {
                    $taxClass = '';
                }
                $item->set_name(mb_substr($name, 0, 200));
                $item->set_tax_class($taxClass);
                $taxable = $taxEnabled && $taxClass !== '0';
            }
            $listPrice = (float) $unit * $quantity;
            if ($inclusive && $taxable) {
                $net = $product ? (float) wc_get_price_excluding_tax($product, ['qty' => $quantity, 'price' => $unit, 'order' => $order]) : $this->excludeTax($listPrice, $taxClass, $location);
            } else {
                $net = $listPrice;
            }
            $netAfterDiscount = $net * (100 - (float) $discount) / 100;
            $item->set_quantity($quantity);
            $item->set_subtotal((string) wc_format_decimal($net));
            $item->set_total((string) wc_format_decimal($netAfterDiscount));
            if ($taxable) {
                $item->calculate_taxes($location);
            } else {
                $item->set_taxes(false);
            }
            $taxes = $item->get_taxes();
            $subtotalTax = array_sum(array_map('floatval', $taxes['subtotal'] ?? []));
            $totalTax = array_sum(array_map('floatval', $taxes['total'] ?? []));
            $items[] = $item;
            $lines[] = [
                'key' => sanitize_key((string) ($raw['key'] ?? '')) ?: 'l'.$position,
                'type' => $product ? 'product' : 'custom',
                'product_id' => $product ? ($product->get_parent_id() ?: $product->get_id()) : 0,
                'variation_id' => $product && $product->is_type('variation') ? $product->get_id() : 0,
                'sku' => $product ? (string) $product->get_sku() : '',
                'name' => mb_substr($name, 0, 200),
                'meta' => $product && $product->is_type('variation') ? $this->variationText($product, $attributes) : '',
                'attributes' => $attributes,
                'description' => mb_substr(sanitize_textarea_field((string) ($raw['description'] ?? '')), 0, 1000),
                'quantity' => $quantity,
                'unit_price' => $unit,
                'discount' => $discount,
                'tax_class' => $taxClass,
                'taxable' => $taxable,
                'subtotal' => (string) $item->get_subtotal(),
                'total' => (string) $item->get_total(),
                'taxes' => ['total' => array_map('strval', $taxes['total'] ?? []), 'subtotal' => array_map('strval', $taxes['subtotal'] ?? [])],
                'unit_display' => Money::fixed(($display === 'incl' ? $net + $subtotalTax : $net) / $quantity),
                'amount_display' => Money::fixed($display === 'incl' ? $netAfterDiscount + $totalTax : $netAfterDiscount),
            ];
        }

        $shipping = null;
        $shippingItem = null;
        $label = trim(sanitize_text_field((string) ($draft['shipping']['label'] ?? '')));
        $cost = trim((string) ($draft['shipping']['cost'] ?? ''));
        if ($cost !== '') {
            $cost = Money::price($cost, __('Shipping cost', 'advanced-quotes-for-woocommerce'));
            $shippingItem = new WC_Order_Item_Shipping;
            $shippingItem->set_method_title($label !== '' ? mb_substr($label, 0, 200) : __('Shipping', 'advanced-quotes-for-woocommerce'));
            $shippingItem->set_total((string) wc_format_decimal($cost));
            $shippingClass = $this->shippingTaxClass($items);
            if ($taxEnabled && $shippingClass !== false) {
                $shippingItem->calculate_taxes(array_merge($location, ['tax_class' => $shippingClass]));
            } else {
                $shippingItem->set_taxes(false);
            }
            $shippingTaxes = $shippingItem->get_taxes();
            $shipping = [
                'label' => $shippingItem->get_method_title(),
                'total' => (string) $shippingItem->get_total(),
                'taxes' => ['total' => array_map('strval', $shippingTaxes['total'] ?? [])],
            ];
        }

        // WC_Abstract_Order::update_taxes().
        $roundAtSubtotal = get_option('woocommerce_tax_round_at_subtotal') === 'yes';
        $cartTaxes = [];
        foreach ($items as $item) {
            foreach ($item->get_taxes()['total'] as $rateId => $tax) {
                $amount = $roundAtSubtotal ? (float) $tax : (float) wc_round_tax_total((float) $tax, null);
                $cartTaxes[$rateId] = ($cartTaxes[$rateId] ?? 0.0) + $amount;
            }
        }
        $shippingTaxRates = [];
        if ($shippingItem) {
            foreach ($shippingItem->get_taxes()['total'] as $rateId => $tax) {
                $amount = (float) $tax;
                if (! $roundAtSubtotal) {
                    $amount = (float) wc_round_tax_total($amount);
                }
                $shippingTaxRates[$rateId] = ($shippingTaxRates[$rateId] ?? 0.0) + $amount;
            }
        }
        $cartTax = (float) wc_format_decimal(array_sum($cartTaxes), false, true);
        $shippingTax = (float) wc_format_decimal(array_sum($shippingTaxRates), false, true);

        // WC_Abstract_Order::calculate_totals( false ).
        $cartSubtotal = (float) wc_remove_number_precision(WC_Order::get_rounded_items_total(array_map(static fn ($item) => wc_add_number_precision((float) $item->get_subtotal(), false), $items)));
        $cartTotal = (float) wc_remove_number_precision(WC_Order::get_rounded_items_total(array_map(static fn ($item) => wc_add_number_precision((float) $item->get_total(), false), $items)));
        $shippingTotal = $shippingItem ? (float) wc_format_decimal(NumberUtil::round((float) $shippingItem->get_total(), $decimals), false, true) : 0.0;
        $cartSubtotalTax = 0.0;
        $cartTotalTax = 0.0;
        foreach ($items as $item) {
            $cartSubtotalTax += array_sum(array_map('floatval', $item->get_taxes()['subtotal']));
            $cartTotalTax += array_sum(array_map('floatval', $item->get_taxes()['total']));
        }
        $discountTotal = NumberUtil::round($cartSubtotal - $cartTotal, $decimals);
        $discountTax = (float) wc_round_tax_total($cartSubtotalTax - $cartTotalTax);
        $total = NumberUtil::round($cartTotal + $shippingTotal + $cartTax + $shippingTax, $decimals);
        if ($total < 0) {
            throw new RuntimeException(__('The quote total cannot be negative.', 'advanced-quotes-for-woocommerce'));
        }

        $taxRows = [];
        foreach (array_unique(array_merge(array_keys($cartTaxes), array_keys($shippingTaxRates))) as $rateId) {
            $taxRows[] = [
                'rate_id' => (int) $rateId,
                'label' => WC_Tax::get_rate_label((int) $rateId),
                'percent' => WC_Tax::get_rate_percent((int) $rateId),
                'amount' => Money::fixed(($cartTaxes[$rateId] ?? 0.0) + ($shippingTaxRates[$rateId] ?? 0.0)),
            ];
        }

        $totals = [
            'subtotal' => Money::fixed($cartSubtotal),
            'subtotal_tax' => Money::fixed($cartSubtotalTax),
            'discount' => Money::fixed($discountTotal),
            'discount_tax' => Money::fixed($discountTax),
            'shipping' => Money::fixed($shippingTotal),
            'cart_tax' => Money::fixed($cartTax),
            'shipping_tax' => Money::fixed($shippingTax),
            'tax' => Money::fixed($cartTax + $shippingTax),
            'total' => Money::fixed($total),
        ];

        return [
            'currency' => get_woocommerce_currency(),
            'lines' => $lines,
            'shipping' => $shipping,
            'tax_rows' => $taxRows,
            'totals' => $totals,
            'tax' => ['enabled' => $taxEnabled, 'prices_include_tax' => $inclusive, 'round_at_subtotal' => $roundAtSubtotal, 'display' => $display],
        ];
    }

    /**
     * Remove tax from a tax-inclusive custom line, as wc_get_price_excluding_tax() does for products.
     */
    private function excludeTax(float $gross, string $taxClass, array $location): float
    {
        if (apply_filters('woocommerce_adjust_non_base_location_prices', true)) {
            $rates = WC_Tax::get_base_tax_rates($taxClass);
        } else {
            $rates = WC_Tax::find_rates([
                'country' => $location['country'] ?? '',
                'state' => $location['state'] ?? '',
                'postcode' => $location['postcode'] ?? '',
                'city' => $location['city'] ?? '',
                'tax_class' => $taxClass,
            ]);
        }

        return $gross - array_sum(WC_Tax::calc_tax($gross, $rates, true));
    }

    /**
     * Shipping tax class resolution from WC_Abstract_Order::calculate_taxes().
     *
     * @param list<WC_Order_Item_Product> $items
     */
    private function shippingTaxClass(array $items): string|false
    {
        $class = get_option('woocommerce_shipping_tax_class');
        if ($class !== 'inherit') {
            return (string) $class;
        }
        $found = [];
        foreach ($items as $item) {
            if (in_array($item->get_tax_status(), ['taxable', 'shipping'], true)) {
                $found[] = $item->get_tax_class();
            }
        }
        $matches = array_intersect(array_merge([''], WC_Tax::get_tax_class_slugs()), array_unique($found));

        return count($matches) ? (string) current($matches) : false;
    }

    /**
     * Variation details for the document: the variation's own attributes plus the customer's choice for "any" values.
     *
     * @param array<string, string> $chosen
     */
    public static function variationText(WC_Product $variation, array $chosen = []): string
    {
        $parts = [trim(html_entity_decode(wp_strip_all_tags((string) wc_get_formatted_variation($variation, true, true, true)), ENT_QUOTES, 'UTF-8'))];
        foreach ($chosen as $key => $value) {
            $taxonomy = substr($key, 10);
            $term = taxonomy_exists($taxonomy) ? get_term_by('slug', $value, $taxonomy) : false;
            $parts[] = wc_attribute_label($taxonomy, $variation).': '.($term ? $term->name : $value);
        }

        return mb_substr(implode(', ', array_filter($parts)), 0, 300);
    }

    /**
     * Totals for the admin editor preview, without numbers or snapshots.
     */
    public function preview(array $draft): array
    {
        $result = $this->calculate($draft);
        $format = Snapshot::format($result['currency']);

        return [
            'lines' => array_map(static fn (array $line) => ['key' => $line['key'], 'unit' => Snapshot::money($line['unit_display'], $format), 'amount' => Snapshot::money($line['amount_display'], $format)], $result['lines']),
            'rows' => Snapshot::totalsRows($result, $format),
            'total' => $result['totals']['total'],
            'currency' => $result['currency'],
        ];
    }
}
