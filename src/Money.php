<?php

namespace WeWP\AdvancedQuotes;

use RuntimeException;

/**
 * Decimal validation and exact comparison. Amounts are compared in micro-units, never as floats.
 */
final class Money
{
    public static function units(string $amount): int
    {
        if (! preg_match('/^(-?)(\d{1,11})(?:\.(\d{1,6}))?$/D', $amount, $m)) {
            throw new RuntimeException(__('Unsupported amount precision or size.', 'advanced-quotes-for-woocommerce'));
        }

        return ($m[1] === '-' ? -1 : 1) * ((int) $m[2] * 1000000 + (int) str_pad($m[3] ?? '', 6, '0'));
    }

    /**
     * Normalise a merchant-entered, non-negative price. Accepts the store decimal separator.
     */
    public static function price(mixed $raw, string $label): string
    {
        $value = trim((string) $raw);
        if ($value === '') {
            return '0';
        }
        $value = (string) wc_format_decimal($value);
        if (! preg_match('/^\d{1,11}(?:\.\d{1,6})?$/D', $value)) {
            /* translators: %s: field label, for example "Unit price" */
            throw new RuntimeException(sprintf(__('%s must be a positive number with up to 6 decimals.', 'advanced-quotes-for-woocommerce'), $label));
        }

        return $value;
    }

    /**
     * Normalise a discount percentage between 0 and 100.
     */
    public static function percent(mixed $raw): string
    {
        $value = trim((string) $raw);
        if ($value === '') {
            return '0';
        }
        $value = (string) wc_format_decimal($value);
        if (! preg_match('/^\d{1,3}(?:\.\d{1,2})?$/D', $value) || (float) $value > 100) {
            throw new RuntimeException(__('Discount must be a percentage from 0 to 100.', 'advanced-quotes-for-woocommerce'));
        }

        return $value;
    }

    /**
     * Fixed-decimal string for display and storage, using the store price decimals.
     */
    public static function fixed(float $value, ?int $decimals = null): string
    {
        $decimals ??= wc_get_price_decimals();
        $rounded = round($value, $decimals);
        if ($rounded == 0.0) {
            $rounded = 0.0;
        }

        return number_format($rounded, $decimals, '.', '');
    }
}
