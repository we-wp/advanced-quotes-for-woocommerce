<?php

namespace WeWP\AdvancedQuotes\Admin;

use WC_Product;
use WC_Tax;
use WeWP\AdvancedQuotes\Access;
use WeWP\AdvancedQuotes\Plugin;
use WeWP\AdvancedQuotes\RequestFields;
use WeWP\AdvancedQuotes\Settings;
use WeWP\AdvancedQuotes\Snapshot;
use WeWP\AdvancedQuotes\Store;

/**
 * The quote generator: one screen to create, price, preview, send and follow a quote.
 */
final class Editor
{
    public function __construct(private Plugin $plugin) {}

    public function render(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen routing.
        $id = absint($_GET['quote'] ?? 0);
        $quote = $id ? $this->plugin->store->find($id) : null;
        if ($id && ! $quote) {
            wp_die(esc_html__('This quote no longer exists.', 'advanced-quotes-for-woocommerce'), '', ['response' => 404, 'back_link' => true]);
        }
        $quotes = $this->plugin->quotes;
        $draft = $quote ? array_replace_recursive($quotes->blankDraft(), $quote['draft']) : $quotes->blankDraft();
        $editable = ! $quote || $quotes->isEditable($quote);
        $status = $quote ? ListTable::effectiveStatus($quote) : 'new';

        echo '<div class="wrap wewp-aq-editor">';
        echo '<h1 class="wp-heading-inline">'.($quote ? esc_html($quote['number']) : esc_html__('New quote', 'advanced-quotes-for-woocommerce')).'</h1>';
        if ($quote) {
            echo ' '.ListTable::badge($status); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in badge().
        }
        echo ' <a href="'.esc_url(Admin::url()).'" class="page-title-action">'.esc_html__('All quotes', 'advanced-quotes-for-woocommerce').'</a><hr class="wp-header-end">';

        if ($quote && $quote['request']) {
            $this->requestPanel($quote);
        }

        echo '<form id="wewp-aq-form" class="wewp-aq-form" method="post" action="'.esc_url(admin_url('admin-post.php')).'" novalidate>';
        wp_nonce_field('wewp_aq_save');
        echo '<input type="hidden" name="action" value="wewp_aq_save"><input type="hidden" name="quote_id" value="'.esc_attr((string) ($quote['id'] ?? 0)).'">';
        echo '<div class="wewp-aq-layout"><div class="wewp-aq-main"><fieldset class="wewp-aq-fields"'.($editable ? '' : ' disabled').'>';
        if (! $editable) {
            echo '<div class="notice notice-info inline"><p>'.esc_html($this->lockedMessage($quote)).'</p></div>';
        }
        $this->customerPanel($draft);
        $this->itemsPanel($draft);
        $this->messagePanel($draft);
        echo '</fieldset></div><aside class="wewp-aq-side">';
        $this->actionsPanel($quote, $draft, $status, $editable);
        $this->templatePanel($draft, $editable);
        $this->notePanel($draft, $editable);
        if ($quote) {
            $this->historyPanel($quote);
        }
        echo '</aside></div></form>';
        echo '<template id="wewp-aq-line-template">'.$this->lineRow(['key' => '__key__', 'product_id' => 0, 'variation_id' => 0, 'name' => '', 'description' => '', 'quantity' => 1, 'unit_price' => '', 'discount' => '', 'tax_class' => ''], null).'</template>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- lineRow() escapes every value.
        echo '</div>';
    }

    private function lockedMessage(array $quote): string
    {
        return match ($quote['status']) {
            'paid' => __('This quote is paid. It can no longer be changed. Duplicate it to start a new quote.', 'advanced-quotes-for-woocommerce'),
            'accepted', 'accepting' => __('The customer accepted this quote. Cancel the order first if the quote must change.', 'advanced-quotes-for-woocommerce'),
            'cancelled' => __('This quote was withdrawn. Duplicate it to start a new quote.', 'advanced-quotes-for-woocommerce'),
            default => __('This quote can no longer be changed.', 'advanced-quotes-for-woocommerce'),
        };
    }

    private function requestPanel(array $quote): void
    {
        $request = $quote['request'];
        echo '<section class="wewp-aq-request" aria-labelledby="wewp-aq-request-h"><h2 id="wewp-aq-request-h">'.esc_html__('Customer request', 'advanced-quotes-for-woocommerce').'</h2>';
        $time = strtotime(($request['submitted_at'] ?? $quote['created_at']).' UTC');
        /* translators: %s: date and time */
        echo '<p class="wewp-aq-muted">'.esc_html(sprintf(__('Submitted %s. The items below were copied into the quote at current prices.', 'advanced-quotes-for-woocommerce'), wp_date(get_option('date_format').' '.get_option('time_format'), $time))).'</p><ul>';
        foreach ((array) ($request['items'] ?? []) as $item) {
            echo '<li><strong>'.esc_html((string) $item['quantity']).' × </strong>'.esc_html((string) $item['name']).($item['sku'] !== '' ? ' <span class="wewp-aq-muted">'.esc_html((string) $item['sku']).'</span>' : '').'</li>';
        }
        echo '</ul>';
        $answers = RequestFields::answers($request);
        if ($answers) {
            echo '<dl class="wewp-aq-answers">';
            foreach ($answers as $answer) {
                echo '<div><dt>'.esc_html($answer['label']).'</dt><dd>'.nl2br(esc_html($answer['text'])).'</dd></div>';
            }
            echo '</dl>';
        }
        if (trim((string) ($request['message'] ?? '')) !== '') {
            echo '<blockquote>'.nl2br(esc_html((string) $request['message'])).'</blockquote>';
        }
        echo '</section>';
    }

    private function customerPanel(array $draft): void
    {
        $c = $draft['customer'];
        $userId = (int) ($draft['customer_id'] ?? 0);
        $user = $userId ? get_userdata($userId) : false;
        echo '<section class="wewp-aq-panel" aria-labelledby="wewp-aq-customer-h"><h2 id="wewp-aq-customer-h">'.esc_html__('Customer', 'advanced-quotes-for-woocommerce').'</h2>';
        echo '<div class="wewp-aq-find"><label for="wewp-aq-customer-id">'.esc_html__('Registered customer', 'advanced-quotes-for-woocommerce').'</label>';
        echo '<select id="wewp-aq-customer-id" class="wc-customer-search" name="customer_id" data-placeholder="'.esc_attr__('Guest — or search by name or email', 'advanced-quotes-for-woocommerce').'" data-allow_clear="true">';
        if ($user) {
            echo '<option value="'.esc_attr((string) $userId).'" selected>'.esc_html($user->display_name.' ('.$user->user_email.')').'</option>';
        }
        echo '</select><p class="description">'.esc_html__('Link a registered customer to show the quote in their account and attach the order to it. Choosing one fills the fields below.', 'advanced-quotes-for-woocommerce').'</p></div>';
        $fields = [
            'first_name' => [__('First name', 'advanced-quotes-for-woocommerce'), 'given-name'],
            'last_name' => [__('Last name', 'advanced-quotes-for-woocommerce'), 'family-name'],
            'company' => [__('Company', 'advanced-quotes-for-woocommerce'), 'organization'],
            'tax_id' => [__('Tax number', 'advanced-quotes-for-woocommerce'), 'off'],
            'email' => [__('Email', 'advanced-quotes-for-woocommerce'), 'email'],
            'phone' => [__('Phone', 'advanced-quotes-for-woocommerce'), 'tel'],
            'address_1' => [__('Address line 1', 'advanced-quotes-for-woocommerce'), 'address-line1'],
            'address_2' => [__('Address line 2', 'advanced-quotes-for-woocommerce'), 'address-line2'],
            'city' => [__('City', 'advanced-quotes-for-woocommerce'), 'address-level2'],
            'postcode' => [__('Postcode', 'advanced-quotes-for-woocommerce'), 'postal-code'],
            'state' => [__('State or county', 'advanced-quotes-for-woocommerce'), 'address-level1'],
        ];
        echo '<div class="wewp-aq-grid">';
        foreach ($fields as $key => [$label, $autocomplete]) {
            $required = $key === 'email';
            echo '<p class="wewp-aq-field wewp-aq-f-'.esc_attr($key).'"><label for="wewp-aq-c-'.esc_attr($key).'">'.esc_html($label).($required ? ' <span class="required" aria-hidden="true">*</span>' : '').'</label>';
            echo '<input id="wewp-aq-c-'.esc_attr($key).'" type="'.($key === 'email' ? 'email' : ($key === 'phone' ? 'tel' : 'text')).'" name="customer['.esc_attr($key).']" value="'.esc_attr((string) ($c[$key] ?? '')).'" autocomplete="'.esc_attr($autocomplete).'"'.($required ? ' required aria-required="true"' : '').'></p>';
        }
        echo '<p class="wewp-aq-field wewp-aq-f-country"><label for="wewp-aq-c-country">'.esc_html__('Country', 'advanced-quotes-for-woocommerce').'</label><select id="wewp-aq-c-country" name="customer[country]" class="wc-enhanced-select">';
        foreach (WC()->countries->get_countries() as $code => $name) {
            echo '<option value="'.esc_attr($code).'"'.selected((string) ($c['country'] ?? ''), $code, false).'>'.esc_html(html_entity_decode($name, ENT_QUOTES, 'UTF-8')).'</option>';
        }
        echo '</select></p></div>';
        echo '<p class="description">'.esc_html__('Taxes follow your WooCommerce tax settings and this address.', 'advanced-quotes-for-woocommerce').'</p></section>';
    }

    private function itemsPanel(array $draft): void
    {
        $inclusive = wc_prices_include_tax() && wc_tax_enabled();
        echo '<section class="wewp-aq-panel" aria-labelledby="wewp-aq-items-h"><h2 id="wewp-aq-items-h">'.esc_html__('Items', 'advanced-quotes-for-woocommerce').'</h2>';
        echo '<table class="wewp-aq-lines"><thead><tr><th scope="col" class="col-item">'.esc_html__('Item', 'advanced-quotes-for-woocommerce').'</th><th scope="col" class="col-qty">'.esc_html__('Qty', 'advanced-quotes-for-woocommerce').'</th><th scope="col" class="col-price">'.esc_html($inclusive ? __('Unit price incl. tax', 'advanced-quotes-for-woocommerce') : __('Unit price excl. tax', 'advanced-quotes-for-woocommerce')).'</th><th scope="col" class="col-disc">'.esc_html__('Discount', 'advanced-quotes-for-woocommerce').'</th><th scope="col" class="col-amount">'.esc_html__('Amount', 'advanced-quotes-for-woocommerce').'</th><th scope="col" class="col-remove"><span class="screen-reader-text">'.esc_html__('Remove', 'advanced-quotes-for-woocommerce').'</span></th></tr></thead><tbody id="wewp-aq-lines">';
        foreach ($draft['lines'] as $line) {
            $product = ($line['variation_id'] || $line['product_id']) ? wc_get_product($line['variation_id'] ?: $line['product_id']) : null;
            echo $this->lineRow($line, $product instanceof WC_Product ? $product : null, (bool) ($line['product_id'] || $line['variation_id'])); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- lineRow() escapes every value.
        }
        echo '</tbody></table>';
        echo '<p class="wewp-aq-empty-lines"'.($draft['lines'] ? ' hidden' : '').'>'.esc_html__('No items yet. Add a product from your catalogue or a custom item, such as a service.', 'advanced-quotes-for-woocommerce').'</p>';
        echo '<div class="wewp-aq-add"><label class="screen-reader-text" for="wewp-aq-product">'.esc_html__('Add a product', 'advanced-quotes-for-woocommerce').'</label>';
        echo '<select id="wewp-aq-product" class="wc-product-search" data-action="woocommerce_json_search_products_and_variations" data-exclude_type="variable" data-placeholder="'.esc_attr__('Add a product: search by name or SKU', 'advanced-quotes-for-woocommerce').'" data-allow_clear="true"></select>';
        echo '<button type="button" class="button" id="wewp-aq-add-custom"><span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span> '.esc_html__('Add custom item', 'advanced-quotes-for-woocommerce').'</button></div>';
        echo '<div class="wewp-aq-bottom"><div class="wewp-aq-shipping"><h3>'.esc_html__('Shipping', 'advanced-quotes-for-woocommerce').'</h3>';
        echo '<p class="wewp-aq-field"><label for="wewp-aq-ship-label">'.esc_html__('Label', 'advanced-quotes-for-woocommerce').'</label><input id="wewp-aq-ship-label" type="text" name="shipping[label]" value="'.esc_attr((string) $draft['shipping']['label']).'" placeholder="'.esc_attr__('Delivery', 'advanced-quotes-for-woocommerce').'"></p>';
        echo '<p class="wewp-aq-field"><label for="wewp-aq-ship-cost">'.esc_html__('Cost excl. tax', 'advanced-quotes-for-woocommerce').'</label><input id="wewp-aq-ship-cost" type="text" inputmode="decimal" class="wc_input_price" name="shipping[cost]" value="'.esc_attr((string) $draft['shipping']['cost']).'" placeholder="'.esc_attr__('No shipping', 'advanced-quotes-for-woocommerce').'"></p></div>';
        echo '<div class="wewp-aq-summary"><div class="wewp-aq-calc-error" role="alert" hidden></div><table class="wewp-aq-totals" aria-live="polite" aria-busy="false"><tbody><tr><th scope="row">'.esc_html__('Total', 'advanced-quotes-for-woocommerce').'</th><td>—</td></tr></tbody></table></div></div>';
        echo '</section>';
    }

    /**
     * One editable item row. Product rows show catalogue facts; custom rows take a name and tax class.
     */
    public function lineRow(array $line, ?WC_Product $product, bool $missing = false): string
    {
        $key = sanitize_key((string) $line['key']) ?: '__key__';
        $name = fn (string $field) => 'lines['.$key.']['.$field.']';
        $isProduct = $product !== null || $missing;
        $cell = '<input type="hidden" name="'.esc_attr($name('key')).'" value="'.esc_attr($key).'">'
            .'<input type="hidden" class="wewp-aq-pid" name="'.esc_attr($name('product_id')).'" value="'.esc_attr((string) $line['product_id']).'">'
            .'<input type="hidden" class="wewp-aq-vid" name="'.esc_attr($name('variation_id')).'" value="'.esc_attr((string) $line['variation_id']).'">';
        $chosen = \WeWP\AdvancedQuotes\Quotes::attributes($line['attributes'] ?? []);
        foreach ($chosen as $attribute => $value) {
            $cell .= '<input type="hidden" name="'.esc_attr($name('attributes').'['.$attribute.']').'" value="'.esc_attr($value).'">';
        }
        if ($product) {
            $facts = array_filter([$product->is_type('variation') ? \WeWP\AdvancedQuotes\Calculator::variationText($product, $chosen) : '', $product->get_sku() ? __('SKU', 'advanced-quotes-for-woocommerce').' '.$product->get_sku() : '']);
            $cell .= '<a class="wewp-aq-line-name" href="'.esc_url((string) get_edit_post_link($product->get_parent_id() ?: $product->get_id())).'" target="_blank" rel="noopener">'.esc_html($product->get_name()).'</a>';
            $cell .= '<span class="wewp-aq-line-facts">'.esc_html(implode(' · ', $facts)).'</span>';
            $cell .= '<span class="wewp-aq-line-facts">'.self::stockText($product).' · '.esc_html__('Catalogue price', 'advanced-quotes-for-woocommerce').' '.esc_html(self::plainPrice((string) $product->get_price('edit'))).'</span>';
        } elseif ($missing) {
            $cell .= '<span class="wewp-aq-line-name is-missing">'.esc_html__('Product no longer available', 'advanced-quotes-for-woocommerce').'</span><span class="wewp-aq-line-facts">'.esc_html__('Remove this item or replace it with a custom item before sending.', 'advanced-quotes-for-woocommerce').'</span>';
        } else {
            $cell .= '<label class="screen-reader-text" for="wewp-aq-n-'.esc_attr($key).'">'.esc_html__('Item name', 'advanced-quotes-for-woocommerce').'</label><input id="wewp-aq-n-'.esc_attr($key).'" class="wewp-aq-custom-name" type="text" name="'.esc_attr($name('name')).'" value="'.esc_attr((string) $line['name']).'" placeholder="'.esc_attr__('Custom item, such as installation', 'advanced-quotes-for-woocommerce').'">';
            $cell .= '<label class="wewp-aq-tax"><span>'.esc_html__('Tax', 'advanced-quotes-for-woocommerce').'</span><select name="'.esc_attr($name('tax_class')).'">'.self::taxOptions((string) $line['tax_class']).'</select></label>';
        }
        $description = (string) $line['description'];
        $cell .= '<details class="wewp-aq-note"'.($description !== '' ? ' open' : '').'><summary>'.esc_html__('Note for the customer', 'advanced-quotes-for-woocommerce').'</summary><label class="screen-reader-text" for="wewp-aq-d-'.esc_attr($key).'">'.esc_html__('Note for the customer', 'advanced-quotes-for-woocommerce').'</label><textarea id="wewp-aq-d-'.esc_attr($key).'" rows="2" name="'.esc_attr($name('description')).'" maxlength="1000">'.esc_textarea($description).'</textarea></details>';

        return '<tr class="wewp-aq-line'.($isProduct ? ' is-product' : ' is-custom').'" data-key="'.esc_attr($key).'">'
            .'<td class="col-item">'.$cell.'</td>'
            .'<td class="col-qty"><input type="number" min="1" max="999999" step="1" name="'.esc_attr($name('quantity')).'" value="'.esc_attr((string) max(1, (int) $line['quantity'])).'" aria-label="'.esc_attr__('Quantity', 'advanced-quotes-for-woocommerce').'"></td>'
            .'<td class="col-price"><input type="text" inputmode="decimal" class="wc_input_price" name="'.esc_attr($name('unit_price')).'" value="'.esc_attr((string) $line['unit_price']).'" placeholder="0" aria-label="'.esc_attr__('Unit price', 'advanced-quotes-for-woocommerce').'"></td>'
            .'<td class="col-disc"><span class="wewp-aq-pct"><input type="text" inputmode="decimal" name="'.esc_attr($name('discount')).'" value="'.esc_attr((string) $line['discount']).'" placeholder="0" aria-label="'.esc_attr__('Discount percentage', 'advanced-quotes-for-woocommerce').'"><span aria-hidden="true">%</span></span></td>'
            .'<td class="col-amount"><output class="wewp-aq-amount">—</output></td>'
            .'<td class="col-remove"><button type="button" class="button-link wewp-aq-remove" aria-label="'.esc_attr__('Remove item', 'advanced-quotes-for-woocommerce').'"><span class="dashicons dashicons-trash" aria-hidden="true"></span></button></td></tr>';
    }

    public static function stockText(WC_Product $product): string
    {
        if (! $product->managing_stock()) {
            return esc_html($product->is_in_stock() ? __('In stock', 'advanced-quotes-for-woocommerce') : __('Out of stock', 'advanced-quotes-for-woocommerce'));
        }
        /* translators: %s: stock quantity */
        $text = sprintf(__('%s in stock', 'advanced-quotes-for-woocommerce'), wc_stock_amount((int) $product->get_stock_quantity()));

        return (int) $product->get_stock_quantity() > 0 ? esc_html($text) : '<span class="is-warning">'.esc_html($product->backorders_allowed() ? __('On backorder', 'advanced-quotes-for-woocommerce') : __('Out of stock', 'advanced-quotes-for-woocommerce')).'</span>';
    }

    public static function plainPrice(string $amount): string
    {
        return $amount === '' ? '—' : Snapshot::money($amount, Snapshot::format(get_woocommerce_currency()));
    }

    public static function taxOptions(string $current): string
    {
        $options = ['' => __('Standard rate', 'advanced-quotes-for-woocommerce')];
        foreach (WC_Tax::get_tax_classes() as $name) {
            $options[sanitize_title($name)] = $name;
        }
        $options['0'] = __('No tax', 'advanced-quotes-for-woocommerce');
        $html = '';
        foreach ($options as $value => $label) {
            $html .= '<option value="'.esc_attr((string) $value).'"'.selected($current, (string) $value, false).'>'.esc_html($label).'</option>';
        }

        return $html;
    }

    private function messagePanel(array $draft): void
    {
        echo '<section class="wewp-aq-panel" aria-labelledby="wewp-aq-message-h"><h2 id="wewp-aq-message-h">'.esc_html__('Message and terms', 'advanced-quotes-for-woocommerce').'</h2>';
        echo '<p class="wewp-aq-field"><label for="wewp-aq-reference">'.esc_html__('Customer reference', 'advanced-quotes-for-woocommerce').'</label><input id="wewp-aq-reference" type="text" name="reference" maxlength="120" value="'.esc_attr((string) $draft['reference']).'" placeholder="'.esc_attr__('For example, a purchase order number', 'advanced-quotes-for-woocommerce').'"></p>';
        echo '<p class="wewp-aq-field"><label for="wewp-aq-intro">'.esc_html__('Introduction', 'advanced-quotes-for-woocommerce').'</label><textarea id="wewp-aq-intro" name="intro" rows="3" maxlength="4000">'.esc_textarea((string) $draft['intro']).'</textarea></p>';
        echo '<p class="wewp-aq-field"><label for="wewp-aq-terms">'.esc_html__('Terms', 'advanced-quotes-for-woocommerce').'</label><textarea id="wewp-aq-terms" name="terms" rows="4" maxlength="4000">'.esc_textarea((string) $draft['terms']).'</textarea><span class="description">'.esc_html__('You write and approve this text. The plugin does not check it for legal requirements.', 'advanced-quotes-for-woocommerce').'</span></p>';
        echo '</section>';
    }

    private function actionsPanel(?array $quote, array $draft, string $status, bool $editable): void
    {
        $days = (int) Settings::value('validity');
        echo '<section class="wewp-aq-panel wewp-aq-actions" aria-labelledby="wewp-aq-actions-h"><h2 id="wewp-aq-actions-h">'.esc_html__('Quote', 'advanced-quotes-for-woocommerce').'</h2>';
        echo '<p class="wewp-aq-state">'.esc_html($this->stateText($quote, $status)).'</p>';
        echo '<fieldset class="wewp-aq-fields"'.($editable ? '' : ' disabled').'>';
        /* translators: %d: number of days */
        echo '<p class="wewp-aq-field"><label for="wewp-aq-valid">'.esc_html__('Valid until', 'advanced-quotes-for-woocommerce').'</label><input id="wewp-aq-valid" type="date" name="valid_until" min="'.esc_attr(current_time('Y-m-d')).'" value="'.esc_attr((string) $draft['valid_until']).'"><span class="description">'.esc_html(sprintf(__('Leave empty for %d days after sending.', 'advanced-quotes-for-woocommerce'), $days)).'</span></p>';
        echo '<p class="wewp-aq-field"><label for="wewp-aq-display">'.esc_html__('Show line prices', 'advanced-quotes-for-woocommerce').'</label><select id="wewp-aq-display" name="display"><option value="excl"'.selected($draft['display'], 'excl', false).'>'.esc_html__('Excluding tax', 'advanced-quotes-for-woocommerce').'</option><option value="incl"'.selected($draft['display'], 'incl', false).'>'.esc_html__('Including tax', 'advanced-quotes-for-woocommerce').'</option></select></p>';
        echo '</fieldset>';
        if ($editable) {
            $next = $quote ? (int) $quote['revision'] + 1 : 1;
            /* translators: %d: revision number */
            $sendLabel = $next > 1 ? sprintf(__('Send revision %d', 'advanced-quotes-for-woocommerce'), $next) : __('Send quote', 'advanced-quotes-for-woocommerce');
            echo '<div class="wewp-aq-buttons">';
            echo '<button type="submit" class="button button-primary button-large wewp-aq-send" name="operation" value="send">'.esc_html($sendLabel).'</button>';
            echo '<button type="submit" class="button" name="operation" value="save">'.esc_html__('Save draft', 'advanced-quotes-for-woocommerce').'</button>';
            echo '<button type="submit" class="button" name="operation" value="preview" formtarget="_blank">'.esc_html__('Preview PDF', 'advanced-quotes-for-woocommerce').'</button>';
            echo '</div><p class="description wewp-aq-send-hint">'.esc_html__('Sending records this version permanently, emails the customer a private link and the PDF, and lets them accept and pay online.', 'advanced-quotes-for-woocommerce').'</p>';
        }
        if ($quote && (int) $quote['revision'] > 0) {
            $url = Access::url($quote);
            echo '<div class="wewp-aq-link"><label for="wewp-aq-customer-link">'.esc_html__('Customer link', 'advanced-quotes-for-woocommerce').'</label><span class="wewp-aq-copy"><input id="wewp-aq-customer-link" type="text" readonly value="'.esc_attr($url).'"><button type="button" class="button wewp-aq-copy-btn" data-copy="wewp-aq-customer-link">'.esc_html__('Copy', 'advanced-quotes-for-woocommerce').'</button></span>';
            echo '<span class="description">'.esc_html__('Anyone with this link can view and accept the quote. Share it only with the customer.', 'advanced-quotes-for-woocommerce').'</span></div>';
            echo '<ul class="wewp-aq-secondary">';
            /* translators: %d: revision number */
            echo '<li><a href="'.esc_url(Actions::downloadUrl($quote['id'])).'">'.esc_html(sprintf(__('Download PDF (revision %d)', 'advanced-quotes-for-woocommerce'), (int) $quote['revision'])).'</a></li>';
            echo '<li><a href="'.esc_url($url).'" target="_blank" rel="noopener">'.esc_html__('Open customer page', 'advanced-quotes-for-woocommerce').'</a></li>';
            if ($status === 'sent') {
                echo '<li><button type="submit" class="button-link wewp-aq-confirm" data-confirm="accept" name="operation" value="accept">'.esc_html__('Accept for the customer', 'advanced-quotes-for-woocommerce').'</button></li>';
            }
            echo '<li><button type="submit" class="button-link wewp-aq-confirm" data-confirm="reset" name="operation" value="reset_link">'.esc_html__('Create a new customer link', 'advanced-quotes-for-woocommerce').'</button></li>';
            echo '</ul>';
        }
        if ($quote && in_array($quote['status'], ['accepted', 'paid'], true) && $quote['order_id']) {
            $order = wc_get_order($quote['order_id']);
            if ($order) {
                /* translators: 1: order number, 2: order status */
                echo '<p class="wewp-aq-order"><a class="button" href="'.esc_url($order->get_edit_order_url()).'">'.esc_html(sprintf(__('Order #%1$s · %2$s', 'advanced-quotes-for-woocommerce'), $order->get_order_number(), wc_get_order_status_name($order->get_status()))).'</a></p>';
            }
        }
        if ($quote) {
            echo '<p class="wewp-aq-danger"><button type="submit" class="button-link" name="operation" value="duplicate">'.esc_html__('Duplicate', 'advanced-quotes-for-woocommerce').'</button>';
            if (in_array($quote['status'], ['requested', 'draft', 'sent', 'declined'], true)) {
                echo ' · <button type="submit" class="button-link button-link-delete wewp-aq-confirm" data-confirm="cancel" name="operation" value="cancel">'.esc_html__('Withdraw quote', 'advanced-quotes-for-woocommerce').'</button>';
            }
            echo '</p>';
        }
        echo '</section>';
    }

    private function stateText(?array $quote, string $status): string
    {
        if (! $quote) {
            return __('Not saved yet. Add a customer and items, then send the quote.', 'advanced-quotes-for-woocommerce');
        }
        $date = static fn (?string $day): string => $day ? wp_date((string) get_option('date_format'), strtotime($day.' 12:00:00')) : '';

        /* translators: 1: email address, 2: date */
        $sent = __('Sent to %1$s. Valid until %2$s.', 'advanced-quotes-for-woocommerce');
        /* translators: %s: date */
        $expired = __('Expired on %s. Send a new revision to extend it.', 'advanced-quotes-for-woocommerce');

        return match ($status) {
            'requested' => __('New customer request. Check the items, set your prices, then send the quote.', 'advanced-quotes-for-woocommerce'),
            'draft' => __('Draft. The customer has not received anything yet.', 'advanced-quotes-for-woocommerce'),
            'sent' => sprintf($sent, $quote['email'], $date($quote['expires_on'])),
            'expired' => sprintf($expired, $date($quote['expires_on'])),
            'accepted' => __('Accepted. The order waits for payment.', 'advanced-quotes-for-woocommerce'),
            'paid' => __('Accepted and paid.', 'advanced-quotes-for-woocommerce'),
            'declined' => __('Declined by the customer. You can send a new revision.', 'advanced-quotes-for-woocommerce'),
            'cancelled' => __('Withdrawn. The customer can no longer accept it.', 'advanced-quotes-for-woocommerce'),
            default => '',
        };
    }

    private function templatePanel(array $draft, bool $editable): void
    {
        $templates = $this->plugin->templates()->all();
        echo '<section class="wewp-aq-panel" aria-labelledby="wewp-aq-template-h"><h2 id="wewp-aq-template-h">'.esc_html__('Design', 'advanced-quotes-for-woocommerce').'</h2><fieldset class="wewp-aq-fields"'.($editable ? '' : ' disabled').'><legend class="screen-reader-text">'.esc_html__('Template', 'advanced-quotes-for-woocommerce').'</legend><div class="wewp-aq-templates">';
        foreach ($templates as $id => $template) {
            $thumb = $template->thumbnail();
            echo '<label class="wewp-aq-template"><input type="radio" name="template" value="'.esc_attr($id).'"'.checked($draft['template'], $id, false).'>';
            echo '<span class="wewp-aq-template-card">'.($thumb !== '' ? '<img src="'.esc_url($thumb).'" alt="" loading="lazy" width="96" height="136">' : '<span class="wewp-aq-template-blank" aria-hidden="true"></span>');
            echo '<span class="wewp-aq-template-name">'.esc_html($template->name()).($template->edition() === 'pro' ? ' <span class="wewp-aq-tag">Pro</span>' : '').'</span></span></label>';
        }
        echo '</div>';
        if (count($templates) === 1) {
            echo '<p class="description">'.esc_html__('Essential is included. More templates are planned for Advanced Quotes Pro, which is not available yet.', 'advanced-quotes-for-woocommerce').'</p>';
        }
        echo '<p class="wewp-aq-field wewp-aq-accent"><label for="wewp-aq-accent">'.esc_html__('Accent colour', 'advanced-quotes-for-woocommerce').'</label><input id="wewp-aq-accent" type="color" name="accent" value="'.esc_attr((string) $draft['accent']).'"></p>';
        echo '</fieldset></section>';
    }

    private function notePanel(array $draft, bool $editable): void
    {
        echo '<section class="wewp-aq-panel" aria-labelledby="wewp-aq-note-h"><h2 id="wewp-aq-note-h">'.esc_html__('Internal note', 'advanced-quotes-for-woocommerce').'</h2><fieldset class="wewp-aq-fields"'.($editable ? '' : ' disabled').'>';
        echo '<label class="screen-reader-text" for="wewp-aq-note">'.esc_html__('Internal note', 'advanced-quotes-for-woocommerce').'</label><textarea id="wewp-aq-note" name="note" rows="3" maxlength="4000">'.esc_textarea((string) $draft['note']).'</textarea><p class="description">'.esc_html__('Only store staff see this note.', 'advanced-quotes-for-woocommerce').'</p></fieldset></section>';
    }

    private function historyPanel(array $quote): void
    {
        $labels = [
            'requested' => __('Customer requested a quote', 'advanced-quotes-for-woocommerce'),
            'created' => __('Quote created', 'advanced-quotes-for-woocommerce'),
            'sent' => __('Revision sent', 'advanced-quotes-for-woocommerce'),
            'viewed' => __('Customer opened the quote', 'advanced-quotes-for-woocommerce'),
            'accepted' => __('Quote accepted', 'advanced-quotes-for-woocommerce'),
            'paid' => __('Order paid', 'advanced-quotes-for-woocommerce'),
            'declined' => __('Customer declined', 'advanced-quotes-for-woocommerce'),
            'cancelled' => __('Quote withdrawn', 'advanced-quotes-for-woocommerce'),
            'link_reset' => __('New customer link created', 'advanced-quotes-for-woocommerce'),
            'email_failed' => __('Email could not be sent', 'advanced-quotes-for-woocommerce'),
            'pdf_failed' => __('PDF could not be created', 'advanced-quotes-for-woocommerce'),
            'order_cancelled' => __('Order cancelled', 'advanced-quotes-for-woocommerce'),
            'order_failed' => __('Order payment failed', 'advanced-quotes-for-woocommerce'),
            'order_refunded' => __('Order refunded', 'advanced-quotes-for-woocommerce'),
            'order_paid_superseded' => __('An order from an earlier revision was paid. Check it before you continue.', 'advanced-quotes-for-woocommerce'),
        ];
        echo '<section class="wewp-aq-panel" aria-labelledby="wewp-aq-history-h"><h2 id="wewp-aq-history-h">'.esc_html__('History', 'advanced-quotes-for-woocommerce').'</h2>';
        $revisions = $this->plugin->store->revisions($quote['id']);
        if ($revisions) {
            echo '<ul class="wewp-aq-revisions">';
            foreach ($revisions as $rev) {
                /* translators: %d: revision number */
                echo '<li><a href="'.esc_url(Actions::downloadUrl($quote['id'], $rev['revision'])).'">'.esc_html(sprintf(__('Revision %d', 'advanced-quotes-for-woocommerce'), $rev['revision'])).'</a> <span class="wewp-aq-muted">'.esc_html(Snapshot::money($rev['total'], Snapshot::format((string) ($quote['currency'] ?: get_woocommerce_currency())))).'</span></li>';
            }
            echo '</ul>';
        }
        echo '<ol class="wewp-aq-events">';
        foreach ($this->plugin->store->events($quote['id']) as $event) {
            $time = strtotime($event['happened_at'].' UTC');
            $text = $labels[$event['kind']] ?? $event['kind'];
            if ($event['kind'] === 'sent') {
                /* translators: 1: revision number, 2: email address */
                $text = sprintf(__('Revision %1$d sent to %2$s', 'advanced-quotes-for-woocommerce'), (int) $event['revision'], $event['detail']);
            }
            echo '<li><span class="wewp-aq-event">'.esc_html($text).'</span>';
            if ($event['detail'] !== '' && $event['kind'] !== 'sent') {
                echo '<span class="wewp-aq-event-detail">'.esc_html($event['detail']).'</span>';
            }
            echo '<time datetime="'.esc_attr(gmdate('c', $time)).'">'.esc_html(wp_date(get_option('date_format').' '.get_option('time_format'), $time)).'</time></li>';
        }
        echo '</ol></section>';
    }
}
