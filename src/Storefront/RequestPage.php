<?php

namespace WeWP\AdvancedQuotes\Storefront;

use RuntimeException;
use Throwable;
use WC_Product;
use WeWP\AdvancedQuotes\Plugin;
use WeWP\AdvancedQuotes\RequestFields;
use WeWP\AdvancedQuotes\Settings;

/**
 * Storefront quote requests: the product and cart buttons, the quote list kept in the WooCommerce session,
 * and the request page rendered by the [wewp_quote_request] shortcode.
 */
final class RequestPage
{
    public const SHORTCODE = 'wewp_quote_request';

    public const SESSION = 'wewp_aq_list';

    public const MAX_ITEMS = 50;

    /** Fields that failed the last submission, so the form can mark them. */
    private array $invalid = [];

    public function __construct(private Plugin $plugin) {}

    public static function ensurePage(): void
    {
        $id = (int) get_option('wewp_aq_request_page', 0);
        if ($id && get_post_status($id)) {
            return;
        }
        $id = wp_insert_post([
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_title' => __('Request a quote', 'advanced-quotes-for-woocommerce'),
            'post_name' => 'request-a-quote',
            'post_content' => '<!-- wp:shortcode -->['.self::SHORTCODE.']<!-- /wp:shortcode -->',
            'comment_status' => 'closed',
        ]);
        if ($id && ! is_wp_error($id)) {
            update_option('wewp_aq_request_page', (int) $id, false);
            Settings::save(['request_page' => (int) $id]);
        }
    }

    public function boot(): void
    {
        add_shortcode(self::SHORTCODE, [$this, 'shortcode']);
        add_action('wp_loaded', [$this, 'handle'], 5);
        add_action('template_redirect', [$this, 'noCache']);
        add_action('wp_enqueue_scripts', [$this, 'assets']);
        add_action('woocommerce_after_add_to_cart_form', [$this, 'productButton']);
        add_action('woocommerce_proceed_to_checkout', [$this, 'cartButton'], 30);
        add_filter('render_block', [$this, 'blocks'], 10, 2);
    }

    /**
     * The request page shows session-specific content, so page caches must not store it.
     */
    public function noCache(): void
    {
        $page = (int) Settings::value('request_page');
        if ($page && is_page($page)) {
            nocache_headers();
            if (! defined('DONOTCACHEPAGE')) {
                // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Shared constant read by page-caching plugins.
                define('DONOTCACHEPAGE', true);
            }
        }
    }

    /**
     * Keep only "any" attributes of a variation, with values the parent product offers.
     *
     * @return array<string, string>
     */
    private static function chosenAttributes(WC_Product $product, mixed $input): array
    {
        if (! $product->is_type('variation')) {
            return [];
        }
        $open = array_filter($product->get_variation_attributes(), static fn ($value) => $value === '');
        $parent = wc_get_product($product->get_parent_id());
        $options = [];
        if ($parent instanceof \WC_Product_Variable) {
            foreach ($parent->get_variation_attributes() as $name => $values) {
                $options['attribute_'.sanitize_title($name)] = array_map('strval', (array) $values);
            }
        }
        $chosen = [];
        foreach (\WeWP\AdvancedQuotes\Quotes::attributes($input) as $key => $value) {
            if (array_key_exists($key, $open) && in_array($value, $options[$key] ?? [], true)) {
                $chosen[$key] = $value;
            }
        }
        if (count($chosen) !== count($open)) {
            throw new RuntimeException(__('Choose the product options before you request a quote.', 'advanced-quotes-for-woocommerce'));
        }

        return $chosen;
    }

    private static function itemKey(WC_Product $product, array $attributes): string
    {
        return ($product->get_parent_id() ?: $product->get_id()).':'.($product->is_type('variation') ? $product->get_id() : 0).($attributes ? ':'.substr(md5((string) wp_json_encode($attributes)), 0, 8) : '');
    }

    public static function pageUrl(): string
    {
        $id = (int) Settings::value('request_page');

        return $id && get_post_status($id) === 'publish' ? (string) get_permalink($id) : home_url('/');
    }

    public function assets(): void
    {
        if (! is_product() && ! is_cart() && ! is_page((int) Settings::value('request_page'))) {
            return;
        }
        $dir = dirname(WEWP_AQ_FILE).'/assets/';
        wp_enqueue_style('wewp-aq-store', Plugin::url('assets/css/storefront.css'), [], WEWP_AQ_VERSION.'-'.filemtime($dir.'css/storefront.css'));
        wp_enqueue_script('wewp-aq-store', Plugin::url('assets/js/storefront.js'), [], WEWP_AQ_VERSION.'-'.filemtime($dir.'js/storefront.js'), true);
        wp_localize_script('wewp-aq-store', 'wewpAqStore', ['chooseOptions' => __('Choose the product options before you request a quote.', 'advanced-quotes-for-woocommerce')]);
    }

    /**
     * @return array<string, array{product_id:int,variation_id:int,quantity:int}>
     */
    public static function items(): array
    {
        if (! WC()->session) {
            return [];
        }
        $items = WC()->session->get(self::SESSION, []);

        return is_array($items) ? $items : [];
    }

    private static function saveItems(array $items): void
    {
        if (! WC()->session) {
            return;
        }
        if (! WC()->session->has_session()) {
            WC()->session->set_customer_session_cookie(true);
        }
        WC()->session->set(self::SESSION, array_slice($items, 0, self::MAX_ITEMS, true));
    }

    private static function product(int $productId, int $variationId): WC_Product
    {
        $product = wc_get_product($variationId ?: $productId);
        if (! $product instanceof WC_Product || $product->get_status() !== 'publish' && ! ($product->is_type('variation') && get_post_status($product->get_parent_id()) === 'publish')) {
            throw new RuntimeException(__('This product is not available.', 'advanced-quotes-for-woocommerce'));
        }
        if ($product->is_type('variable')) {
            throw new RuntimeException(__('Choose the product options before you request a quote.', 'advanced-quotes-for-woocommerce'));
        }
        if ($variationId && $product->get_parent_id() !== $productId) {
            throw new RuntimeException(__('This product is not available.', 'advanced-quotes-for-woocommerce'));
        }

        return $product;
    }

    /**
     * Handles list changes and request submission before WooCommerce's own form handlers run.
     */
    public function handle(): void
    {
        if (! isset($_POST['wewp_aq_request_action']) || is_admin() || ! WC()->session) {
            return;
        }
        $op = sanitize_key(wp_unslash($_POST['wewp_aq_request_action']));
        if (! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wewp_aq'] ?? '')), 'wewp_aq_list')) {
            wc_add_notice(__('Your session expired. Try again.', 'advanced-quotes-for-woocommerce'), 'error');

            return;
        }
        $items = self::items();
        $redirect = self::pageUrl();
        try {
            switch ($op) {
                case 'add':
                    $product = self::product(absint($_POST['product_id'] ?? 0), absint($_POST['variation_id'] ?? 0));
                    $quantity = max(1, min(999999, absint(wp_unslash($_POST['quantity'] ?? 1))));
                    $attributes = self::chosenAttributes($product, isset($_POST['attributes']) && is_array($_POST['attributes']) ? map_deep(wp_unslash($_POST['attributes']), 'sanitize_text_field') : []);
                    $key = self::itemKey($product, $attributes);
                    if (! isset($items[$key]) && count($items) >= self::MAX_ITEMS) {
                        /* translators: %d: maximum number of items */
                        throw new RuntimeException(sprintf(__('A quote request can include up to %d different items.', 'advanced-quotes-for-woocommerce'), self::MAX_ITEMS));
                    }
                    $items[$key] = ['product_id' => $product->get_parent_id() ?: $product->get_id(), 'variation_id' => $product->is_type('variation') ? $product->get_id() : 0, 'quantity' => min(999999, ($items[$key]['quantity'] ?? 0) + $quantity), 'attributes' => $attributes];
                    self::saveItems($items);
                    /* translators: %s: product name */
                    wc_add_notice(esc_html(sprintf(__('%s was added to your quote request.', 'advanced-quotes-for-woocommerce'), $product->get_name())).' <a href="'.esc_url(self::pageUrl()).'" class="button wc-forward">'.esc_html__('View quote request', 'advanced-quotes-for-woocommerce').'</a>');
                    $redirect = wp_get_referer() ?: get_permalink($product->get_parent_id() ?: $product->get_id());
                    break;
                case 'update':
                    $quantities = isset($_POST['quantity']) && is_array($_POST['quantity']) ? map_deep(wp_unslash($_POST['quantity']), 'absint') : [];
                    foreach ($quantities as $key => $quantity) {
                        $key = sanitize_text_field((string) $key);
                        if (isset($items[$key])) {
                            $quantity = (int) $quantity;
                            if ($quantity < 1) {
                                unset($items[$key]);
                            } else {
                                $items[$key]['quantity'] = min(999999, $quantity);
                            }
                        }
                    }
                    $remove = sanitize_text_field(wp_unslash($_POST['remove'] ?? ''));
                    if ($remove !== '') {
                        unset($items[$remove]);
                    }
                    self::saveItems($items);
                    wc_add_notice(__('Your quote request was updated.', 'advanced-quotes-for-woocommerce'));
                    break;
                case 'cart':
                    foreach (WC()->cart ? WC()->cart->get_cart() : [] as $line) {
                        $product = wc_get_product((int) ($line['variation_id'] ?: $line['product_id']));
                        if (! $product instanceof WC_Product) {
                            continue;
                        }
                        try {
                            $attributes = self::chosenAttributes($product, (array) ($line['variation'] ?? []));
                        } catch (RuntimeException) {
                            $attributes = [];
                        }
                        $key = self::itemKey($product, $attributes);
                        if (! isset($items[$key]) && count($items) >= self::MAX_ITEMS) {
                            break;
                        }
                        $items[$key] = ['product_id' => (int) $line['product_id'], 'variation_id' => (int) $line['variation_id'], 'quantity' => min(999999, ($items[$key]['quantity'] ?? 0) + (int) $line['quantity']), 'attributes' => $attributes];
                    }
                    self::saveItems($items);
                    wc_add_notice(__('Your cart items were copied into a quote request. Your cart was not changed.', 'advanced-quotes-for-woocommerce'));
                    break;
                case 'submit':
                    $quote = $this->submit($items);
                    self::saveItems([]);
                    WC()->session->set('wewp_aq_submitted', ['number' => $quote['number'], 'email' => $quote['email']]);
                    break;
                default:
                    return;
            }
        } catch (RuntimeException $e) {
            // Form errors arrive one per line; each becomes its own notice.
            foreach (explode("\n", $e->getMessage()) as $message) {
                wc_add_notice(esc_html($message), 'error');
            }
            if ($op === 'submit') {
                WC()->session->set('wewp_aq_form', $this->posted());
                WC()->session->set('wewp_aq_form_invalid', $this->invalid);

                return;
            }
        } catch (Throwable $e) {
            wc_add_notice(__('Your request could not be saved. Try again in a moment.', 'advanced-quotes-for-woocommerce'), 'error');

            return;
        }
        wp_safe_redirect($redirect);
        exit;
    }

    private function posted(): array
    {
        $fields = [];
        foreach (['first_name', 'last_name', 'company', 'email', 'phone', 'tax_id', 'country', 'address_1', 'city', 'postcode', 'message'] as $field) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Called from handle() after nonce verification.
            $fields[$field] = $field === 'message' ? sanitize_textarea_field(wp_unslash($_POST[$field] ?? '')) : sanitize_text_field(wp_unslash($_POST[$field] ?? ''));
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Called from handle() after nonce verification; each value is cleaned below.
        $extra = isset($_POST['wewp_aq_extra']) && is_array($_POST['wewp_aq_extra']) ? wp_unslash($_POST['wewp_aq_extra']) : [];
        $fields['extra'] = [];
        foreach (array_slice($extra, 0, RequestFields::MAX_FIELDS * 2, true) as $id => $value) {
            $fields['extra'][sanitize_key((string) $id)] = is_array($value)
                ? array_map(static fn ($item): string => mb_substr(sanitize_text_field(is_scalar($item) ? (string) $item : ''), 0, 120), array_slice(array_values($value), 0, RequestFields::MAX_CHOICES))
                : mb_substr(sanitize_textarea_field(is_scalar($value) ? (string) $value : ''), 0, 2000);
        }

        return $fields;
    }

    private function submit(array $items): array
    {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- Called from handle() after nonce verification.
        if (! $items) {
            throw new RuntimeException(__('Your quote request is empty.', 'advanced-quotes-for-woocommerce'));
        }
        if (trim(sanitize_text_field(wp_unslash($_POST['wewp_aq_website'] ?? ''))) !== '') {
            throw new RuntimeException(__('Your request could not be sent.', 'advanced-quotes-for-woocommerce'));
        }
        $started = sanitize_text_field(wp_unslash($_POST['wewp_aq_started'] ?? ''));
        [$time, $sig] = array_pad(explode('.', $started, 2), 2, '');
        $age = time() - (int) $time;
        if (! hash_equals(self::formToken($time), $started) || $age < 3 || $age > 2 * HOUR_IN_SECONDS) {
            throw new RuntimeException(__('Please review your details and send the request again.', 'advanced-quotes-for-woocommerce'));
        }
        $fields = $this->posted();
        // phpcs:enable
        $extra = $fields['extra'];
        unset($fields['extra']);
        $settings = Settings::get();
        // Errors follow the order of the form.
        $errors = [];
        foreach (['first_name' => __('first name', 'advanced-quotes-for-woocommerce'), 'last_name' => __('last name', 'advanced-quotes-for-woocommerce')] as $field => $label) {
            if ($fields[$field] === '') {
                /* translators: %s: field name */
                $errors[$field] = sprintf(__('Enter your %s.', 'advanced-quotes-for-woocommerce'), $label);
            }
        }
        if (! is_email($fields['email'])) {
            $errors['email'] = __('Enter a valid email address.', 'advanced-quotes-for-woocommerce');
        }
        $errors += self::contactErrors($fields, $settings, ['phone', 'company', 'tax_id']);
        if ($fields['country'] === '') {
            /* translators: %s: field name */
            $errors['country'] = sprintf(__('Enter your %s.', 'advanced-quotes-for-woocommerce'), __('country', 'advanced-quotes-for-woocommerce'));
        } elseif (! array_key_exists($fields['country'], WC()->countries->get_allowed_countries())) {
            $errors['country'] = __('Choose a country we sell to.', 'advanced-quotes-for-woocommerce');
        }
        $errors += self::contactErrors($fields, $settings, ['address']);
        $extra = RequestFields::collect(RequestFields::active(), $extra);
        // Extra field ids get a prefix so they never collide with contact field names.
        foreach ($extra['invalid'] as $index => $id) {
            $errors['extra:'.$id] = $extra['errors'][$index];
        }
        $errors += self::contactErrors($fields, $settings, ['message']);
        if ($errors) {
            $this->invalid = array_keys($errors);
            throw new RuntimeException(implode("\n", $errors));
        }
        // Limits use the connection address, which a client cannot set, plus per-recipient and store-wide caps.
        $address = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
        $limits = [
            'wewp_aq_rate_'.md5($address.'|'.wp_salt('nonce')) => 5,
            'wewp_aq_rcpt_'.md5(strtolower($fields['email']).'|'.wp_salt('nonce')) => 3,
            'wewp_aq_rate_all' => (int) apply_filters('wewp_aq_request_hourly_limit', 50),
        ];
        foreach ($limits as $key => $max) {
            if ((int) get_transient($key) >= $max) {
                throw new RuntimeException(__('The store received many requests in a short time. Wait an hour, or contact the store directly.', 'advanced-quotes-for-woocommerce'));
            }
        }
        foreach (array_keys($limits) as $key) {
            set_transient($key, (int) get_transient($key) + 1, HOUR_IN_SECONDS);
        }
        $list = [];
        foreach ($items as $item) {
            try {
                self::product((int) $item['product_id'], (int) $item['variation_id']);
                $list[] = $item;
            } catch (RuntimeException) {
                continue;
            }
        }
        $message = mb_substr($fields['message'], 0, 2000);
        unset($fields['message']);
        $fields['email'] = strtolower($fields['email']);

        return $this->plugin->quotes->createRequest($fields, $list, $message, get_current_user_id(), $extra['answers']);
    }

    /**
     * Apply the merchant's choice for optional contact fields: clear hidden ones and report missing required ones.
     *
     * @param list<string> $groups
     * @return array<string, string> Field => error message.
     */
    private static function contactErrors(array &$fields, array $settings, array $groups): array
    {
        $messages = [
            'phone' => ['phone' => __('Enter your phone number.', 'advanced-quotes-for-woocommerce')],
            'company' => ['company' => __('Enter your company name.', 'advanced-quotes-for-woocommerce')],
            'tax_id' => ['tax_id' => __('Enter your tax number.', 'advanced-quotes-for-woocommerce')],
            'address' => ['address_1' => __('Enter your street address.', 'advanced-quotes-for-woocommerce'), 'postcode' => __('Enter your postcode.', 'advanced-quotes-for-woocommerce'), 'city' => __('Enter your city.', 'advanced-quotes-for-woocommerce')],
            'message' => ['message' => __('Enter a message.', 'advanced-quotes-for-woocommerce')],
        ];
        $errors = [];
        foreach ($groups as $group) {
            $mode = $settings['field_'.$group] ?? 'optional';
            foreach ($messages[$group] as $field => $message) {
                if ($mode === 'hidden') {
                    $fields[$field] = '';
                } elseif ($mode === 'required' && trim($fields[$field]) === '' && ($field !== 'postcode' || self::postcodeNeeded($fields['country']))) {
                    $errors[$field] = $message;
                }
            }
        }

        return $errors;
    }

    /**
     * Whether WooCommerce asks for a postcode in this country.
     */
    private static function postcodeNeeded(string $country): bool
    {
        $locale = WC()->countries->get_country_locale()[$country]['postcode'] ?? [];

        return empty($locale['hidden']) && ($locale['required'] ?? true);
    }

    public function productButton(): void
    {
        global $product;
        if (Settings::value('button') === 'yes' && $product instanceof WC_Product) {
            echo $this->buttonHtml($product); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in buttonHtml().
        }
    }

    private function buttonHtml(WC_Product $product): string
    {
        if (! $product->is_type(['simple', 'variable'])) {
            return '';
        }

        return '<form class="wewp-aq-add" method="post" data-variable="'.($product->is_type('variable') ? '1' : '0').'">'
            .wp_nonce_field('wewp_aq_list', '_wewp_aq', false, false)
            .'<input type="hidden" name="wewp_aq_request_action" value="add"><input type="hidden" name="product_id" value="'.esc_attr((string) $product->get_id()).'"><input type="hidden" name="variation_id" value=""><input type="hidden" name="quantity" value="1">'
            .'<button type="submit" class="button wp-element-button wewp-aq-button">'.esc_html(Settings::value('button_label')).'</button>'
            .'<p class="wewp-aq-add-error" role="alert" hidden></p></form>';
    }

    public function cartButton(): void
    {
        if (Settings::value('cart_button') !== 'yes') {
            return;
        }
        echo $this->cartHtml(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in cartHtml().
    }

    private function cartHtml(): string
    {
        if (! WC()->cart || WC()->cart->is_empty()) {
            return '';
        }

        return '<form class="wewp-aq-cart" method="post" action="'.esc_url(self::pageUrl()).'">'.wp_nonce_field('wewp_aq_list', '_wewp_aq', false, false)
            .'<input type="hidden" name="wewp_aq_request_action" value="cart"><button type="submit" class="button wp-element-button wewp-aq-button">'.esc_html__('Request a quote for these items', 'advanced-quotes-for-woocommerce').'</button></form>';
    }

    /**
     * Block themes: append the buttons after the product add-to-cart block and the cart checkout button.
     */
    public function blocks(string $content, array $block): string
    {
        $name = $block['blockName'] ?? '';
        $settings = Settings::get();
        if ($settings['button'] === 'yes' && in_array($name, ['woocommerce/add-to-cart-form', 'woocommerce/add-to-cart-with-options'], true)) {
            $product = wc_get_product(get_the_ID());

            return $product instanceof WC_Product && ! str_contains($content, 'wewp-aq-add') ? $content.$this->buttonHtml($product) : $content;
        }
        if ($settings['cart_button'] === 'yes' && $name === 'woocommerce/proceed-to-checkout-block') {
            return $content.$this->cartHtml();
        }

        return $content;
    }

    public function shortcode(): string
    {
        if (! WC()->session) {
            return '';
        }
        ob_start();
        echo '<div class="wewp-aq-request woocommerce">';
        wc_print_notices();
        $submitted = WC()->session->get('wewp_aq_submitted');
        if (is_array($submitted)) {
            WC()->session->set('wewp_aq_submitted', null);
            echo '<div class="wewp-aq-thanks" role="status"><h2>'.esc_html__('Thank you. Your request was sent.', 'advanced-quotes-for-woocommerce').'</h2>';
            /* translators: 1: quote number, 2: email address */
            echo '<p>'.esc_html(sprintf(__('Your reference is %1$s. We will prepare your prices and email the quote to %2$s.', 'advanced-quotes-for-woocommerce'), $submitted['number'], $submitted['email'])).'</p>';
            echo '<p><a class="button wp-element-button" href="'.esc_url(wc_get_page_permalink('shop')).'">'.esc_html__('Continue shopping', 'advanced-quotes-for-woocommerce').'</a></p></div></div>';

            return (string) ob_get_clean();
        }
        $items = self::items();
        if (! $items) {
            echo '<div class="wewp-aq-empty"><p>'.esc_html__('Your quote request is empty. Open a product and select the request button, or request a quote for your cart.', 'advanced-quotes-for-woocommerce').'</p>';
            echo '<p><a class="button wp-element-button" href="'.esc_url(wc_get_page_permalink('shop')).'">'.esc_html__('Browse products', 'advanced-quotes-for-woocommerce').'</a></p></div></div>';

            return (string) ob_get_clean();
        }
        $this->listHtml($items);
        $this->formHtml();
        echo '</div>';

        return (string) ob_get_clean();
    }

    private function listHtml(array $items): void
    {
        echo '<form class="wewp-aq-list" method="post">'.wp_nonce_field('wewp_aq_list', '_wewp_aq', false, false).'<input type="hidden" name="wewp_aq_request_action" value="update">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Core nonce field markup.
        echo '<table class="shop_table shop_table_responsive"><thead><tr><th class="product-name" scope="col">'.esc_html__('Product', 'advanced-quotes-for-woocommerce').'</th><th class="product-quantity" scope="col">'.esc_html__('Quantity', 'advanced-quotes-for-woocommerce').'</th><th class="product-remove" scope="col"><span class="screen-reader-text">'.esc_html__('Remove', 'advanced-quotes-for-woocommerce').'</span></th></tr></thead><tbody>';
        foreach ($items as $key => $item) {
            $product = wc_get_product($item['variation_id'] ?: $item['product_id']);
            if (! $product instanceof WC_Product) {
                continue;
            }
            $label = $product->is_type('variation') ? \WeWP\AdvancedQuotes\Calculator::variationText($product, (array) ($item['attributes'] ?? [])) : '';
            echo '<tr><td class="product-name" data-title="'.esc_attr__('Product', 'advanced-quotes-for-woocommerce').'"><span class="wewp-aq-thumb">'.wp_kses_post($product->get_image('woocommerce_gallery_thumbnail')).'</span><a href="'.esc_url($product->get_permalink()).'">'.esc_html($product->get_name()).'</a>'.($label !== '' ? '<span class="wewp-aq-variation">'.esc_html($label).'</span>' : '').'</td>';
            echo '<td class="product-quantity" data-title="'.esc_attr__('Quantity', 'advanced-quotes-for-woocommerce').'"><label class="screen-reader-text" for="wewp-aq-q-'.esc_attr(md5($key)).'">'.esc_html__('Quantity', 'advanced-quotes-for-woocommerce').'</label><input id="wewp-aq-q-'.esc_attr(md5($key)).'" class="input-text qty text" type="number" min="0" max="999999" step="1" name="quantity['.esc_attr($key).']" value="'.esc_attr((string) $item['quantity']).'"></td>';
            /* translators: %s: product name */
            echo '<td class="product-remove"><button type="submit" class="wewp-aq-remove" name="remove" value="'.esc_attr($key).'" aria-label="'.esc_attr(sprintf(__('Remove %s from the request', 'advanced-quotes-for-woocommerce'), $product->get_name())).'">'.esc_html__('Remove', 'advanced-quotes-for-woocommerce').'</button></td></tr>';
        }
        echo '</tbody></table><p><button type="submit" class="button wp-element-button wewp-aq-secondary">'.esc_html__('Update quantities', 'advanced-quotes-for-woocommerce').'</button></p></form>';
    }

    private function formHtml(): void
    {
        $saved = (array) (WC()->session->get('wewp_aq_form') ?: []);
        $invalid = (array) (WC()->session->get('wewp_aq_form_invalid') ?: []);
        WC()->session->set('wewp_aq_form', null);
        WC()->session->set('wewp_aq_form_invalid', null);
        $settings = Settings::get();
        $customer = WC()->customer;
        $value = static function (string $field) use ($saved, $customer): string {
            if (isset($saved[$field])) {
                return (string) $saved[$field];
            }
            if (! $customer || ! is_user_logged_in()) {
                return $field === 'country' ? (string) WC()->countries->get_base_country() : '';
            }
            $getter = 'get_billing_'.$field;

            return method_exists($customer, $getter) ? (string) $customer->{$getter}() : '';
        };
        $time = (string) time();
        $token = self::formToken($time);
        echo '<form class="wewp-aq-form checkout" method="post" novalidate><h2>'.esc_html__('Your details', 'advanced-quotes-for-woocommerce').'</h2>';
        echo '<p class="wewp-aq-intro">'.esc_html__('We use these details to prepare your prices and email the quote. You can accept it online and pay on our checkout.', 'advanced-quotes-for-woocommerce').'</p>';
        echo wp_nonce_field('wewp_aq_list', '_wewp_aq', false, false); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Core nonce field markup.
        echo '<input type="hidden" name="wewp_aq_request_action" value="submit"><input type="hidden" name="wewp_aq_started" value="'.esc_attr($token).'">';
        echo '<p class="wewp-aq-hp" aria-hidden="true"><label for="wewp-aq-website">'.esc_html__('Leave this field empty', 'advanced-quotes-for-woocommerce').'</label><input id="wewp-aq-website" type="text" name="wewp_aq_website" tabindex="-1" autocomplete="off"></p>';
        $fields = [
            'first_name' => [__('First name', 'advanced-quotes-for-woocommerce'), 'given-name', 'required'],
            'last_name' => [__('Last name', 'advanced-quotes-for-woocommerce'), 'family-name', 'required'],
            'email' => [__('Email', 'advanced-quotes-for-woocommerce'), 'email', 'required'],
            'phone' => [__('Phone', 'advanced-quotes-for-woocommerce'), 'tel', $settings['field_phone']],
            'company' => [__('Company', 'advanced-quotes-for-woocommerce'), 'organization', $settings['field_company']],
            'tax_id' => [__('Tax number', 'advanced-quotes-for-woocommerce'), 'off', $settings['field_tax_id']],
        ];
        // Visible fields fill two columns in order.
        $column = 0;
        foreach ($fields as $key => [$label, $autocomplete, $mode]) {
            if ($mode !== 'hidden') {
                $this->field($key, $label, $autocomplete, $mode === 'required', $column++ % 2 ? 'form-row-last' : 'form-row-first', $value($key), in_array($key, $invalid, true));
            }
        }
        echo '<p class="form-row form-row-wide"><label for="wewp-aq-f-country">'.esc_html__('Country', 'advanced-quotes-for-woocommerce').' '.self::marker(true).'</label><select id="wewp-aq-f-country" name="country" autocomplete="country" required'.(in_array('country', $invalid, true) ? ' aria-invalid="true"' : '').'>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- marker() escapes its text.
        foreach (WC()->countries->get_allowed_countries() as $code => $name) {
            echo '<option value="'.esc_attr($code).'"'.selected($value('country'), $code, false).'>'.esc_html(html_entity_decode($name, ENT_QUOTES, 'UTF-8')).'</option>';
        }
        echo '</select></p>';
        if ($settings['field_address'] !== 'hidden') {
            $required = $settings['field_address'] === 'required';
            $this->field('address_1', __('Street address', 'advanced-quotes-for-woocommerce'), 'address-line1', $required, 'form-row-wide', $value('address_1'), in_array('address_1', $invalid, true));
            $this->field('postcode', __('Postcode', 'advanced-quotes-for-woocommerce'), 'postal-code', $required, 'form-row-first', $value('postcode'), in_array('postcode', $invalid, true));
            $this->field('city', __('City', 'advanced-quotes-for-woocommerce'), 'address-level2', $required, 'form-row-last', $value('city'), in_array('city', $invalid, true));
        }
        $extra = RequestFields::active();
        if ($extra) {
            echo '<h3 class="wewp-aq-subhead">'.esc_html__('About your request', 'advanced-quotes-for-woocommerce').'</h3>';
            foreach ($extra as $field) {
                $this->extraField($field, $saved['extra'][$field['id']] ?? '', in_array('extra:'.$field['id'], $invalid, true));
            }
        }
        if ($settings['field_message'] !== 'hidden') {
            $required = $settings['field_message'] === 'required';
            $placeholder = $extra ? __('Anything else we should know', 'advanced-quotes-for-woocommerce') : __('Delivery date, quantities or other details', 'advanced-quotes-for-woocommerce');
            echo '<p class="form-row form-row-wide"><label for="wewp-aq-f-message">'.esc_html__('Message', 'advanced-quotes-for-woocommerce').' '.self::marker($required).'</label><textarea id="wewp-aq-f-message" class="input-text" name="message" rows="4" maxlength="2000" placeholder="'.esc_attr($placeholder).'"'.($required ? ' required aria-required="true"' : '').(in_array('message', $invalid, true) ? ' aria-invalid="true"' : '').'>'.esc_textarea($saved['message'] ?? '').'</textarea></p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- marker() escapes its text.
        }
        $privacy = get_privacy_policy_url();
        echo '<p class="wewp-aq-privacy">'.esc_html__('We store your details with the quote to answer your request.', 'advanced-quotes-for-woocommerce').($privacy ? ' <a href="'.esc_url($privacy).'">'.esc_html__('Privacy policy', 'advanced-quotes-for-woocommerce').'</a>' : '').'</p>';
        echo '<p><button type="submit" class="button alt wp-element-button wewp-aq-submit">'.esc_html__('Send quote request', 'advanced-quotes-for-woocommerce').'</button></p></form>';
    }

    /**
     * Form token: issue time signed together with the visitor's WooCommerce session.
     */
    private static function formToken(string $time): string
    {
        $session = WC()->session ? (string) WC()->session->get_customer_id() : '';

        return $time.'.'.wp_hash('wewp_aq_started|'.$time.'|'.$session);
    }

    private static function marker(bool $required): string
    {
        return $required
            ? '<abbr class="required" title="'.esc_attr__('required', 'advanced-quotes-for-woocommerce').'">*</abbr>'
            : '<span class="optional">('.esc_html__('optional', 'advanced-quotes-for-woocommerce').')</span>';
    }

    private function field(string $key, string $label, string $autocomplete, bool $required, string $class, string $value, bool $invalid = false): void
    {
        $type = $key === 'email' ? 'email' : ($key === 'phone' ? 'tel' : 'text');
        echo '<p class="form-row '.esc_attr($class).'"><label for="wewp-aq-f-'.esc_attr($key).'">'.esc_html($label).' '.self::marker($required).'</label>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- marker() escapes its text.
        echo '<input id="wewp-aq-f-'.esc_attr($key).'" class="input-text" type="'.esc_attr($type).'" name="'.esc_attr($key).'" value="'.esc_attr($value).'" autocomplete="'.esc_attr($autocomplete).'"'.($required ? ' required aria-required="true"' : '').($invalid ? ' aria-invalid="true"' : '').'></p>';
    }

    /**
     * One extra field from Settings → Quotes → Request form.
     *
     * @param array{id:string,label:string,type:string,required:bool,help:string,choices:list<string>} $field
     */
    private function extraField(array $field, mixed $value, bool $invalid): void
    {
        $id = 'wewp-aq-x-'.$field['id'];
        $name = 'wewp_aq_extra['.$field['id'].']';
        $help = $field['help'] !== '' ? '<span class="wewp-aq-help" id="'.esc_attr($id.'-help').'">'.esc_html($field['help']).'</span>' : '';
        $state = ($field['help'] !== '' ? ' aria-describedby="'.esc_attr($id.'-help').'"' : '').($invalid ? ' aria-invalid="true"' : '');
        $required = $field['required'] ? ' required aria-required="true"' : '';
        $class = 'form-row form-row-wide wewp-aq-extra wewp-aq-extra-'.$field['type'];
        $text = is_scalar($value) ? (string) $value : '';
        // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- $help, $state, $required and marker() are escaped above.
        switch ($field['type']) {
            case 'radio':
            case 'checkboxes':
                $picked = array_map('strval', array_filter((array) $value, 'is_scalar'));
                $multiple = $field['type'] === 'checkboxes';
                echo '<fieldset class="'.esc_attr($class).'"'.($field['help'] !== '' ? ' aria-describedby="'.esc_attr($id.'-help').'"' : '').'><legend>'.esc_html($field['label']).' '.self::marker($field['required']).'</legend>'.$help;
                foreach ($field['choices'] as $index => $choice) {
                    echo '<label class="wewp-aq-option" for="'.esc_attr($id.'-'.$index).'"><input id="'.esc_attr($id.'-'.$index).'" type="'.($multiple ? 'checkbox' : 'radio').'" name="'.esc_attr($name.($multiple ? '[]' : '')).'" value="'.esc_attr($choice).'"'.checked(in_array($choice, $picked, true), true, false).($multiple ? '' : $required).($invalid ? ' aria-invalid="true"' : '').'> <span>'.esc_html($choice).'</span></label>';
                }
                echo '</fieldset>';
                break;
            case 'checkbox':
                echo '<p class="'.esc_attr($class).'"><label class="wewp-aq-option" for="'.esc_attr($id).'"><input id="'.esc_attr($id).'" type="checkbox" name="'.esc_attr($name).'" value="yes"'.checked($text, 'yes', false).$required.$state.'> <span>'.esc_html($field['label']).($field['required'] ? ' '.self::marker(true) : '').'</span></label>'.$help.'</p>';
                break;
            case 'select':
                echo '<p class="'.esc_attr($class).'"><label for="'.esc_attr($id).'">'.esc_html($field['label']).' '.self::marker($field['required']).'</label>'.$help.'<select id="'.esc_attr($id).'" name="'.esc_attr($name).'"'.$required.$state.'><option value="">'.esc_html__('Choose an option', 'advanced-quotes-for-woocommerce').'</option>';
                foreach ($field['choices'] as $choice) {
                    echo '<option value="'.esc_attr($choice).'"'.selected($text, $choice, false).'>'.esc_html($choice).'</option>';
                }
                echo '</select></p>';
                break;
            case 'textarea':
                echo '<p class="'.esc_attr($class).'"><label for="'.esc_attr($id).'">'.esc_html($field['label']).' '.self::marker($field['required']).'</label>'.$help.'<textarea id="'.esc_attr($id).'" class="input-text" name="'.esc_attr($name).'" rows="4" maxlength="2000"'.$required.$state.'>'.esc_textarea($text).'</textarea></p>';
                break;
            default:
                $attributes = match ($field['type']) {
                    'number' => 'type="number" step="any" inputmode="decimal"',
                    'date' => 'type="date"',
                    default => 'type="text" maxlength="200"',
                };
                echo '<p class="'.esc_attr($class).'"><label for="'.esc_attr($id).'">'.esc_html($field['label']).' '.self::marker($field['required']).'</label>'.$help.'<input id="'.esc_attr($id).'" class="input-text" '.$attributes.' name="'.esc_attr($name).'" value="'.esc_attr($text).'"'.$required.$state.'></p>';
        }
        // phpcs:enable
    }
}
