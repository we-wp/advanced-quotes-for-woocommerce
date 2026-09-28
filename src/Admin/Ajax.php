<?php

namespace WeWP\AdvancedQuotes\Admin;

use Throwable;
use WC_Customer;
use WC_Product;
use WeWP\AdvancedQuotes\Calculator;
use WeWP\AdvancedQuotes\Plugin;

/**
 * JSON endpoints for the quote generator. All require the quotes capability and the editor nonce.
 */
final class Ajax
{
    public function __construct(private Plugin $plugin) {}

    private function guard(): void
    {
        if (! current_user_can(Admin::CAP) || ! check_ajax_referer('wewp_aq_ajax', 'nonce', false)) {
            wp_send_json_error(['message' => __('Your session expired. Reload the page.', 'advanced-quotes-for-woocommerce')], 403);
        }
    }

    // phpcs:disable WordPress.Security.NonceVerification.Missing -- guard() verifies the nonce and capability first.
    public function calculate(): void
    {
        $this->guard();
        $draft = $this->plugin->quotes->sanitizeDraft(wp_unslash($_POST), []);
        if (! $draft['lines']) {
            wp_send_json_success(['lines' => [], 'rows' => [], 'total' => '0', 'empty' => true]);
        }
        try {
            wp_send_json_success((new Calculator)->preview($draft));
        } catch (Throwable $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    public function product(): void
    {
        $this->guard();
        $product = wc_get_product(absint($_POST['product'] ?? 0));
        if (! $product instanceof WC_Product || $product->get_status() === 'trash') {
            wp_send_json_error(['message' => __('Product not found.', 'advanced-quotes-for-woocommerce')], 404);
        }
        if ($product->is_type('variable')) {
            wp_send_json_error(['message' => __('Choose a specific variation.', 'advanced-quotes-for-woocommerce')]);
        }
        $line = [
            'key' => 'l'.wp_generate_password(8, false, false),
            'product_id' => $product->get_parent_id() ?: $product->get_id(),
            'variation_id' => $product->is_type('variation') ? $product->get_id() : 0,
            'name' => '',
            'description' => '',
            'quantity' => 1,
            'unit_price' => (string) $product->get_price('edit'),
            'discount' => '',
            'tax_class' => '',
        ];
        wp_send_json_success(['html' => (new Editor($this->plugin))->lineRow($line, $product)]);
    }

    public function customer(): void
    {
        $this->guard();
        $id = absint($_POST['customer'] ?? 0);
        if (! $id || ! get_userdata($id)) {
            wp_send_json_error(['message' => __('Customer not found.', 'advanced-quotes-for-woocommerce')], 404);
        }
        try {
            $customer = new WC_Customer($id);
        } catch (Throwable) {
            wp_send_json_error(['message' => __('Customer not found.', 'advanced-quotes-for-woocommerce')], 404);
        }
        wp_send_json_success([
            'first_name' => $customer->get_billing_first_name() ?: $customer->get_first_name(),
            'last_name' => $customer->get_billing_last_name() ?: $customer->get_last_name(),
            'company' => $customer->get_billing_company(),
            'email' => $customer->get_billing_email() ?: $customer->get_email(),
            'phone' => $customer->get_billing_phone(),
            'address_1' => $customer->get_billing_address_1(),
            'address_2' => $customer->get_billing_address_2(),
            'city' => $customer->get_billing_city(),
            'postcode' => $customer->get_billing_postcode(),
            'state' => $customer->get_billing_state(),
            'country' => $customer->get_billing_country(),
        ]);
    }
    // phpcs:enable
}
