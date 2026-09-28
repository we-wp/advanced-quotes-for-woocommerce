<?php

namespace WeWP\AdvancedQuotes;

use DateTimeImmutable;
use RuntimeException;

/**
 * Builds the immutable document data for a sent revision. Templates render only this data.
 */
final class Snapshot
{
    public const SCHEMA = 1;

    /**
     * @param array<string, string> $templateLabels Extra words declared by the chosen template.
     */
    public static function build(array $quote, int $revision, array $draft, array $calc, bool $preview = false, array $templateLabels = []): array
    {
        $settings = Settings::get();
        $customer = self::customer(is_array($draft['customer'] ?? null) ? $draft['customer'] : []);
        if ($customer['email'] === '' || ! is_email($customer['email'])) {
            throw new RuntimeException(__('Enter a valid customer email address.', 'advanced-quotes-for-woocommerce'));
        }
        if ($customer['name'] === '' && $customer['company'] === '') {
            throw new RuntimeException(__('Enter the customer name or company.', 'advanced-quotes-for-woocommerce'));
        }
        $validUntil = self::validUntil($draft, (int) $settings['validity']);
        $format = self::format($calc['currency']);
        $template = sanitize_key((string) ($draft['template'] ?? '')) ?: $settings['template'];
        $accent = sanitize_hex_color((string) ($draft['accent'] ?? '')) ?: $settings['accent'];

        return [
            'schema' => self::SCHEMA,
            'preview' => $preview,
            'quote_id' => (int) $quote['id'],
            'number' => (string) $quote['number'],
            'revision' => $revision,
            'issued_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'issued_date' => wp_date((string) get_option('date_format')),
            'valid_until' => $validUntil,
            'valid_until_date' => wp_date((string) get_option('date_format'), (new DateTimeImmutable($validUntil.' 12:00:00', wp_timezone()))->getTimestamp()),
            'title' => $settings['title'],
            'reference' => mb_substr(sanitize_text_field((string) ($draft['reference'] ?? '')), 0, 120),
            'intro' => mb_substr(sanitize_textarea_field((string) ($draft['intro'] ?? '')), 0, 4000),
            'terms' => mb_substr(sanitize_textarea_field((string) ($draft['terms'] ?? '')), 0, 4000),
            'footer' => $settings['footer'],
            'seller' => [
                'name' => $settings['business'],
                'address' => $settings['address'],
                'tax_id' => $settings['tax_id'],
                'email' => $settings['email'],
                'phone' => $settings['phone'],
                'website' => $settings['website'],
                'logo' => Settings::logoDataUri((int) $settings['logo_id']),
            ],
            'customer' => $customer,
            'customer_id' => (int) ($draft['customer_id'] ?? 0),
            'currency' => $calc['currency'],
            'format' => $format,
            'tax' => $calc['tax'],
            'lines' => $calc['lines'],
            'shipping' => $calc['shipping'],
            'tax_rows' => $calc['tax_rows'],
            'totals' => $calc['totals'],
            'totals_rows' => self::totalsRows($calc, $format),
            'labels' => self::labels() + array_map('strval', $templateLabels),
            'template' => ['id' => $template, 'accent' => $accent, 'paper' => $settings['paper']],
            'store' => ['name' => get_bloginfo('name'), 'url' => home_url('/')],
            'locale' => determine_locale(),
        ];
    }

    /**
     * Customer block with a formatted postal address.
     */
    public static function customer(array $c): array
    {
        $clean = [];
        foreach (['first_name', 'last_name', 'company', 'phone', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'tax_id'] as $key) {
            $clean[$key] = mb_substr(sanitize_text_field((string) ($c[$key] ?? '')), 0, 200);
        }
        $clean['email'] = strtolower(sanitize_email((string) ($c['email'] ?? '')));
        $address = WC()->countries->get_formatted_address([
            'address_1' => $clean['address_1'],
            'address_2' => $clean['address_2'],
            'city' => $clean['city'],
            'state' => $clean['state'],
            'postcode' => $clean['postcode'],
            'country' => $clean['country'],
        ], "\n");

        return $clean + [
            'name' => trim($clean['first_name'].' '.$clean['last_name']),
            'address' => trim(html_entity_decode(wp_strip_all_tags(str_replace(['<br/>', '<br>'], "\n", (string) $address)), ENT_QUOTES, 'UTF-8')),
        ];
    }

    public static function validUntil(array $draft, int $days): string
    {
        $today = current_time('Y-m-d');
        $value = (string) ($draft['valid_until'] ?? '');
        if ($value !== '') {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, wp_timezone());
            if (! $date || $date->format('Y-m-d') !== $value) {
                throw new RuntimeException(__('Enter the validity date as YYYY-MM-DD.', 'advanced-quotes-for-woocommerce'));
            }
            if ($value < $today) {
                throw new RuntimeException(__('The validity date cannot be in the past.', 'advanced-quotes-for-woocommerce'));
            }

            return $value;
        }

        return (new DateTimeImmutable($today, wp_timezone()))->modify('+'.max(1, $days).' days')->format('Y-m-d');
    }

    /**
     * Price format frozen with the document, so later store changes do not alter it.
     */
    public static function format(string $currency): array
    {
        return [
            'decimals' => wc_get_price_decimals(),
            'decimal_separator' => wc_get_price_decimal_separator(),
            'thousand_separator' => wc_get_price_thousand_separator(),
            'symbol' => html_entity_decode(get_woocommerce_currency_symbol($currency), ENT_QUOTES, 'UTF-8'),
            'position' => (string) get_option('woocommerce_currency_pos', 'left'),
        ];
    }

    public static function money(string $amount, array $format): string
    {
        $value = (float) $amount;
        $number = number_format(abs($value), (int) $format['decimals'], (string) $format['decimal_separator'], (string) $format['thousand_separator']);
        $symbol = (string) $format['symbol'];
        $text = match ($format['position']) {
            'right' => $number.$symbol,
            'left_space' => $symbol."\u{00A0}".$number,
            'right_space' => $number."\u{00A0}".$symbol,
            default => $symbol.$number,
        };

        return ($value < 0 && round($value, (int) $format['decimals']) != 0.0 ? "\u{2212}" : '').$text;
    }

    /**
     * Totals as labelled rows. Tax rows follow the total when prices are shown with tax.
     */
    public static function totalsRows(array $calc, array $format): array
    {
        $t = $calc['totals'];
        $incl = ($calc['tax']['display'] ?? 'excl') === 'incl';
        $rows = [];
        $rows[] = ['key' => 'subtotal', 'label' => __('Subtotal', 'advanced-quotes-for-woocommerce'), 'amount' => self::money(Money::fixed((float) $t['subtotal'] + ($incl ? (float) $t['subtotal_tax'] : 0.0)), $format)];
        if ((float) $t['discount'] > 0) {
            $rows[] = ['key' => 'discount', 'label' => __('Discount', 'advanced-quotes-for-woocommerce'), 'amount' => self::money('-'.Money::fixed((float) $t['discount'] + ($incl ? (float) $t['discount_tax'] : 0.0)), $format)];
        }
        if ($calc['shipping']) {
            $rows[] = ['key' => 'shipping', 'label' => (string) $calc['shipping']['label'], 'amount' => self::money(Money::fixed((float) $t['shipping'] + ($incl ? (float) $t['shipping_tax'] : 0.0)), $format)];
        }
        $taxRows = [];
        foreach ($calc['tax_rows'] as $tax) {
            $label = trim($tax['label'].' '.$tax['percent']);
            $taxRows[] = [
                'key' => 'tax',
                /* translators: %s: tax label and rate, for example "VAT 21%" */
                'label' => $incl ? sprintf(__('Includes %s', 'advanced-quotes-for-woocommerce'), $label) : $label,
                'amount' => self::money($tax['amount'], $format),
                'included' => $incl,
            ];
        }
        if (! $incl) {
            array_push($rows, ...$taxRows);
        }
        $rows[] = ['key' => 'total', 'label' => __('Total', 'advanced-quotes-for-woocommerce'), 'amount' => self::money($t['total'], $format)];
        if ($incl) {
            array_push($rows, ...$taxRows);
        }

        return $rows;
    }

    public static function labels(): array
    {
        return [
            'number' => __('Quote number', 'advanced-quotes-for-woocommerce'),
            'date' => __('Date', 'advanced-quotes-for-woocommerce'),
            'valid_until' => __('Valid until', 'advanced-quotes-for-woocommerce'),
            'reference' => __('Your reference', 'advanced-quotes-for-woocommerce'),
            'revision' => __('Revision', 'advanced-quotes-for-woocommerce'),
            'prepared_for' => __('Prepared for', 'advanced-quotes-for-woocommerce'),
            'from' => __('From', 'advanced-quotes-for-woocommerce'),
            'item' => __('Item', 'advanced-quotes-for-woocommerce'),
            'sku' => __('SKU', 'advanced-quotes-for-woocommerce'),
            'quantity' => __('Qty', 'advanced-quotes-for-woocommerce'),
            'unit_price' => __('Unit price', 'advanced-quotes-for-woocommerce'),
            'discount' => __('Discount', 'advanced-quotes-for-woocommerce'),
            'amount' => __('Amount', 'advanced-quotes-for-woocommerce'),
            'terms' => __('Terms', 'advanced-quotes-for-woocommerce'),
            'tax_id' => __('Tax number', 'advanced-quotes-for-woocommerce'),
            'email' => __('Email', 'advanced-quotes-for-woocommerce'),
            'phone' => __('Phone', 'advanced-quotes-for-woocommerce'),
            'page' => __('Page', 'advanced-quotes-for-woocommerce'),
            'of' => __('of', 'advanced-quotes-for-woocommerce'),
            'total' => __('Total', 'advanced-quotes-for-woocommerce'),
            'prices_excl' => __('Prices exclude tax.', 'advanced-quotes-for-woocommerce'),
            'prices_incl' => __('Prices include tax.', 'advanced-quotes-for-woocommerce'),
            'accept' => __('Accept this quote online to place your order.', 'advanced-quotes-for-woocommerce'),
            'preview' => __('Preview — not sent', 'advanced-quotes-for-woocommerce'),
        ];
    }
}
