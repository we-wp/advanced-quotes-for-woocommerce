<?php

namespace WeWP\AdvancedQuotes;

/**
 * Private customer links. The token is an HMAC of the quote's secret with the site's auth salt,
 * so a database copy alone does not reveal working links. Rotating the secret revokes old links.
 */
final class Access
{
    public const QUERY = 'wewp-quote';

    public static function token(array $quote): string
    {
        return substr(hash_hmac('sha256', 'wewp-aq-access|'.(int) $quote['id'].'|'.$quote['access_secret'], wp_salt('auth')), 0, 40);
    }

    public static function url(array $quote): string
    {
        return add_query_arg([self::QUERY => (int) $quote['id'], 'key' => self::token($quote)], home_url('/'));
    }

    public static function verify(array $quote, string $key): bool
    {
        return $key !== '' && hash_equals(self::token($quote), $key);
    }

    /**
     * Whether the current visitor may act for the customer: a valid key or the owning account.
     */
    public static function isCustomer(array $quote, string $key): bool
    {
        if (self::verify($quote, $key)) {
            return true;
        }
        $user = get_current_user_id();

        return $user > 0 && (int) $quote['customer_id'] === $user;
    }

    /**
     * Whether the current visitor may view the quote page. Store staff may view but not accept.
     */
    public static function canView(array $quote, string $key): bool
    {
        return self::isCustomer($quote, $key) || current_user_can('manage_wewp_quotes');
    }

    public static function rotate(int $quoteId): void
    {
        global $wpdb;
        $store = new Store;
        $wpdb->update($store->table('quotes'), ['access_secret' => bin2hex(random_bytes(16)), 'updated_at' => gmdate('Y-m-d H:i:s')], ['id' => $quoteId]);
        $store->event($quoteId, 'link_reset');
    }
}
