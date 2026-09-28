<?php

// Run only through WP-CLI in the named disposable synthetic store:
//   wp eval-file tests/integration.php
// It creates and deletes synthetic products, quotes and orders, and changes tax settings temporarily.
if (! defined('WP_CLI') || ! WP_CLI || DB_NAME !== 'wewp_quotes_test_20260927') {
    throw new RuntimeException('Synthetic test database required.');
}

use WeWP\AdvancedQuotes\Access;
use WeWP\AdvancedQuotes\Calculator;
use WeWP\AdvancedQuotes\Money;
use WeWP\AdvancedQuotes\Plugin;
use WeWP\AdvancedQuotes\Privacy;
use WeWP\AdvancedQuotes\Settings;

$GLOBALS['aq_checks'] = 0;
function check(bool $condition, string $label): void
{
    if (! $condition) {
        throw new RuntimeException('FAIL: '.$label);
    }
    $GLOBALS['aq_checks']++;
}
function rejected(callable $action, string $label, string $contains = ''): void
{
    try {
        $action();
    } catch (RuntimeException $e) {
        check($contains === '' || str_contains($e->getMessage(), $contains), $label.' (message: '.$e->getMessage().')');

        return;
    }
    check(false, $label.' (no exception)');
}
function aq_product(string $sku, string $price, string $class = ''): WC_Product_Simple
{
    $existing = wc_get_product_id_by_sku($sku);
    if ($existing) {
        wp_delete_post($existing, true);
    }
    $p = new WC_Product_Simple;
    $p->set_name('Fixture '.$sku);
    $p->set_sku($sku);
    $p->set_regular_price($price);
    $p->set_tax_class($class);
    $p->set_status('publish');
    $p->save();

    return $p;
}
function aq_draft(array $lines, string $country = 'LT', array $shipping = ['label' => 'Delivery', 'cost' => '12.34']): array
{
    $draft = Plugin::instance()->quotes->blankDraft();
    $draft['customer'] = array_merge($draft['customer'], ['first_name' => 'Fixture', 'last_name' => 'Buyer', 'company' => 'Fixture UAB', 'email' => 'fixture-buyer@example.test', 'country' => $country, 'city' => 'Vilnius', 'postcode' => '01100', 'address_1' => 'Fixture g. 1']);
    $draft['lines'] = $lines;
    $draft['shipping'] = $shipping;

    return $draft;
}
function aq_line(int $productId, int $qty, string $price, string $discount = '', string $name = '', string $class = ''): array
{
    return ['key' => 'l'.wp_generate_password(6, false, false), 'product_id' => $productId, 'variation_id' => 0, 'name' => $name, 'description' => '', 'quantity' => $qty, 'unit_price' => $price, 'discount' => $discount, 'tax_class' => $class];
}
function aq_reset_tax_cache(): void
{
    WC_Cache_Helper::invalidate_cache_group('taxes');
    wp_cache_flush();
}

$plugin = Plugin::instance();
$store = $plugin->store;
$quotes = $plugin->quotes;
$accept = $plugin->acceptance();
$original = ['incl' => get_option('woocommerce_prices_include_tax'), 'round' => get_option('woocommerce_tax_round_at_subtotal'), 'based' => get_option('woocommerce_tax_based_on'), 'ship' => get_option('woocommerce_shipping_tax_class')];

$standard = aq_product('AQ-FIX-STD', '19.99');
$reduced = aq_product('AQ-FIX-RED', '7.35', 'reduced-rate');
$zero = aq_product('AQ-FIX-ZERO', '100', 'zero-rate');
$none = aq_product('AQ-FIX-NONE', '50');
$none->set_tax_status('none');
$none->save();

// 1. Totals match WooCommerce's own order calculation in every tax mode.
$matrix = [
    'excl, per line, billing LT' => ['no', 'no', 'billing', 'inherit', 'LT'],
    'excl, at subtotal, billing LT' => ['no', 'yes', 'billing', 'inherit', 'LT'],
    'incl, per line, billing LT' => ['yes', 'no', 'billing', 'inherit', 'LT'],
    'incl, at subtotal, billing DE' => ['yes', 'yes', 'billing', 'inherit', 'DE'],
    'excl, per line, base address' => ['no', 'no', 'base', '', 'PL'],
    'excl, reduced shipping class, billing PL' => ['no', 'no', 'billing', 'reduced-rate', 'PL'],
];
foreach ($matrix as $label => [$incl, $round, $based, $shipClass, $country]) {
    update_option('woocommerce_prices_include_tax', $incl);
    update_option('woocommerce_tax_round_at_subtotal', $round);
    update_option('woocommerce_tax_based_on', $based);
    update_option('woocommerce_shipping_tax_class', $shipClass);
    aq_reset_tax_cache();
    $draft = aq_draft([
        aq_line($standard->get_id(), 3, '19.99', '12.5'),
        aq_line($reduced->get_id(), 7, '7.35'),
        aq_line($zero->get_id(), 1, '100'),
        aq_line($none->get_id(), 2, '50', '3'),
        aq_line(0, 3, '0.333', '', 'Custom standard', ''),
        aq_line(0, 1, '45.10', '', 'Custom reduced', 'reduced-rate'),
        aq_line(0, 1, '20', '', 'Custom untaxed', '0'),
    ], $country);
    $calc = (new Calculator)->calculate($draft);

    // Reference: a native order with the same net lines, totalled by WooCommerce itself.
    $ref = wc_create_order(['status' => 'pending']);
    foreach (['country' => $country, 'city' => 'Vilnius', 'postcode' => '01100', 'address_1' => 'Fixture g. 1'] as $f => $v) {
        $ref->{'set_billing_'.$f}($v);
        $ref->{'set_shipping_'.$f}($v);
    }
    foreach ($calc['lines'] as $line) {
        $item = new WC_Order_Item_Product;
        if ($line['type'] === 'product') {
            $item->set_product(wc_get_product($line['product_id']));
        } else {
            $item->set_name($line['name']);
            $item->set_tax_class($line['tax_class']);
        }
        $item->set_quantity($line['quantity']);
        $item->set_subtotal($line['subtotal']);
        $item->set_total($line['total']);
        $ref->add_item($item);
    }
    $ship = new WC_Order_Item_Shipping;
    $ship->set_method_title('Delivery');
    $ship->set_total('12.34');
    $ref->add_item($ship);
    $ref->calculate_totals(true);
    check(Money::units(Money::fixed((float) $ref->get_total())) === Money::units($calc['totals']['total']), "total matches WooCommerce: $label ({$ref->get_total()} vs {$calc['totals']['total']})");
    check(Money::units(Money::fixed((float) $ref->get_total_tax())) === Money::units($calc['totals']['tax']), "tax matches WooCommerce: $label");
    check(Money::units(Money::fixed((float) $ref->get_discount_total())) === Money::units($calc['totals']['discount']), "discount matches WooCommerce: $label");
    $ref->delete(true);

    // The accepted order must carry the quoted total exactly.
    $quote = $quotes->createDraft($draft);
    $quotes->send($quote, false);
    $order = $accept->accept($quote['id'], 1, 0, 'store');
    $sent = $store->revision($quote['id'], 1);
    check(Money::units(Money::fixed((float) $order->get_total())) === Money::units($sent['data']['totals']['total']), "accepted order equals quote: $label");
    check(count($order->get_items()) === 7 && count($order->get_items('shipping')) === 1, "order items recreated: $label");
    check($order->get_created_via() === 'advanced-quotes' && (int) $order->get_meta('_wewp_aq_quote_id') === $quote['id'], "order linked to quote: $label");
}
update_option('woocommerce_prices_include_tax', 'yes');
update_option('woocommerce_tax_round_at_subtotal', 'no');
update_option('woocommerce_tax_based_on', 'billing');
update_option('woocommerce_shipping_tax_class', 'inherit');
aq_reset_tax_cache();
$gross = aq_product('AQ-FIX-GROSS', '121');
$calc = (new Calculator)->calculate(aq_draft([aq_line($gross->get_id(), 1, '121')], 'LT', ['label' => '', 'cost' => '']));
check($calc['totals']['total'] === '121.00' && $calc['totals']['tax'] === '21.00', 'tax-inclusive price keeps the gross price for a base-country customer');
$calc = (new Calculator)->calculate(aq_draft([aq_line($gross->get_id(), 1, '121')], 'DE', ['label' => '', 'cost' => '']));
check($calc['totals']['total'] === '119.00', 'tax-inclusive price is re-taxed for another country by default ('.$calc['totals']['total'].')');
foreach ($original as $key => $value) {
    update_option(['incl' => 'woocommerce_prices_include_tax', 'round' => 'woocommerce_tax_round_at_subtotal', 'based' => 'woocommerce_tax_based_on', 'ship' => 'woocommerce_shipping_tax_class'][$key], $value);
}
aq_reset_tax_cache();

// 2. Input validation.
rejected(fn () => (new Calculator)->calculate(aq_draft([])), 'empty quote refused', 'at least one item');
rejected(fn () => (new Calculator)->calculate(aq_draft([aq_line($standard->get_id(), 0, '10')])), 'zero quantity refused', 'quantity');
rejected(fn () => (new Calculator)->calculate(aq_draft([aq_line($standard->get_id(), 1, '-5')])), 'negative price refused');
rejected(fn () => (new Calculator)->calculate(aq_draft([aq_line($standard->get_id(), 1, '10', '101')])), 'discount over 100% refused', 'Discount');
rejected(fn () => (new Calculator)->calculate(aq_draft([aq_line(999999, 1, '10')])), 'missing product refused', 'no longer exists');
rejected(fn () => (new Calculator)->calculate(aq_draft([aq_line(0, 1, '10', '', '')])), 'unnamed custom item refused', 'name');
$variable = wc_get_product(wc_get_product_id_by_sku('DESK-OAK'));
rejected(fn () => (new Calculator)->calculate(aq_draft([aq_line($variable->get_id(), 1, '10')])), 'variable parent refused', 'variation');
$bad = aq_draft([aq_line($standard->get_id(), 1, '10')]);
$bad['customer']['email'] = 'not-an-email';
$badQuote = $quotes->createDraft($bad);
rejected(fn () => $quotes->send($badQuote, false), 'invalid customer email refused at send', 'email');
check($store->find($badQuote['id'])['revision'] === 0, 'failed send leaves no revision');
$past = aq_draft([aq_line($standard->get_id(), 1, '10')]);
$past['valid_until'] = '2020-01-01';
$pastQuote = $quotes->createDraft($past);
rejected(fn () => $quotes->send($pastQuote, false), 'past validity date refused', 'past');

// 3. Numbers are sequential, never reused, and follow the prefix at creation time.
$a = $quotes->createDraft(aq_draft([aq_line($standard->get_id(), 1, '10')]));
$b = $quotes->createDraft(aq_draft([aq_line($standard->get_id(), 1, '10')]));
check((int) substr($b['number'], -6) === (int) substr($a['number'], -6) + 1, 'sequential numbers');
Settings::save(['prefix' => 'QT/']);
$c = $quotes->createDraft(aq_draft([aq_line($standard->get_id(), 1, '10')]));
check(str_starts_with($c['number'], 'QT/') && (int) substr($c['number'], -6) === (int) substr($b['number'], -6) + 1, 'new prefix continues the counter');
Settings::save(['prefix' => 'Q-']);
$store->install();
$d = $quotes->createDraft(aq_draft([aq_line($standard->get_id(), 1, '10')]));
check((int) substr($d['number'], -6) === (int) substr($c['number'], -6) + 1, 'reinstall keeps the counter');

// 4. Sent revisions are immutable; tampering is detected.
$quote = $quotes->createDraft(aq_draft([aq_line($standard->get_id(), 2, '25')]));
$r1 = $quotes->send($quote, false);
check($r1['revision'] === 1 && str_starts_with((string) $r1['pdf'] ?: $store->revision($quote['id'], 1)['pdf'], '%PDF-'), 'first revision stored with PDF');
$draft = $store->find($quote['id'])['draft'];
$draft['lines'][0]['unit_price'] = '30';
$quotes->save($store->find($quote['id']), $draft);
$again = $store->revision($quote['id'], 1);
check($again['snapshot'] === $r1['snapshot'] && $again['snapshot_hash'] === $r1['snapshot_hash'], 'editing the draft does not change the sent revision');
$r2 = $quotes->send($store->find($quote['id']), false);
check($r2['revision'] === 2 && $r2['data']['lines'][0]['unit_price'] === '30', 'second revision carries the change');
global $wpdb;
$wpdb->update($store->table('revisions'), ['snapshot' => str_replace('"30"', '"1"', $r2['snapshot'])], ['id' => $r2['id']]);
rejected(fn () => $store->revision($quote['id'], 2), 'tampered snapshot detected', 'integrity');
$wpdb->update($store->table('revisions'), ['snapshot' => $r2['snapshot']], ['id' => $r2['id']]);
$wpdb->update($store->table('revisions'), ['pdf' => '%PDF-tampered'], ['id' => $r2['id']]);
rejected(fn () => $store->pdf($store->revision($quote['id'], 2), $plugin->renderer()), 'tampered PDF detected', 'integrity');

// 5. Acceptance rules.
rejected(fn () => $accept->accept($quote['id'], 1, 0), 'superseded revision cannot be accepted', 'updated');
$fresh = $quotes->createDraft(aq_draft([aq_line($standard->get_id(), 1, '40')]));
$quotes->send($fresh, false);
$first = $accept->accept($fresh['id'], 1, 0);
$second = $accept->accept($fresh['id'], 1, 0);
check($first->get_id() === $second->get_id(), 'repeated acceptance returns the same order');
check($first->get_status() === 'pending' && $first->needs_payment(), 'accepted order waits for payment');
rejected(fn () => $quotes->send($store->find($fresh['id']), false), 'accepted quote cannot be re-sent while its order is open', 'no longer');
$first->update_status('cancelled');
$quotes->send($store->find($fresh['id']), false);
check($store->find($fresh['id'])['status'] === 'sent' && $store->find($fresh['id'])['revision'] === 2, 'cancelled order allows a new revision');
$third = $accept->accept($fresh['id'], 2, 0);
check($third->get_id() !== $first->get_id(), 'new revision creates a new order');
$third->payment_complete();
check($store->find($fresh['id'])['status'] === 'paid', 'payment marks the quote paid');

$expired = $quotes->createDraft(aq_draft([aq_line($standard->get_id(), 1, '10')]));
$quotes->send($expired, false);
$wpdb->update($store->table('quotes'), ['expires_on' => gmdate('Y-m-d', strtotime('-2 days'))], ['id' => $expired['id']]);
rejected(fn () => $accept->accept($expired['id'], 1, 0), 'expired quote refused', 'expired');

$declined = $quotes->createDraft(aq_draft([aq_line($standard->get_id(), 1, '10')]));
$quotes->send($declined, false);
rejected(fn () => $accept->decline($declined['id'], 5, 'x'), 'decline of another revision refused');
$accept->decline($declined['id'], 1, "Too expensive <script>alert(1)</script>");
check($store->find($declined['id'])['status'] === 'declined', 'decline recorded');
rejected(fn () => $accept->accept($declined['id'], 1, 0), 'declined quote cannot be accepted', 'declined');
$events = $store->events($declined['id']);
check(! str_contains($events[0]['detail'], '<script>'), 'decline reason sanitised');

$withdrawn = $quotes->createDraft(aq_draft([aq_line($standard->get_id(), 1, '10')]));
$quotes->send($withdrawn, false);
check($quotes->cancel($store->find($withdrawn['id'])), 'withdraw sent quote');
rejected(fn () => $accept->accept($withdrawn['id'], 1, 0), 'withdrawn quote cannot be accepted', 'withdrew');

// A total mismatch deletes the order and leaves the quote acceptable.
$mismatch = $quotes->createDraft(aq_draft([aq_line($standard->get_id(), 1, '10')]));
$sent = $quotes->send($mismatch, false);
$data = $sent['data'];
$data['totals']['total'] = '999.99';
$json = wp_json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$wpdb->update($store->table('revisions'), ['snapshot' => $json, 'snapshot_hash' => hash('sha256', $json)], ['id' => $sent['id']]);
$before = count(wc_get_orders(['limit' => -1, 'return' => 'ids', 'status' => array_keys(wc_get_order_statuses())]));
rejected(fn () => $accept->accept($mismatch['id'], 1, 0), 'order refused when WooCommerce reaches another total', 'did not match');
check(count(wc_get_orders(['limit' => -1, 'return' => 'ids', 'status' => array_keys(wc_get_order_statuses())])) === $before, 'mismatched order deleted');
check($store->find($mismatch['id'])['status'] === 'sent', 'quote returns to sent after a refused order');

// 6. Private links.
$row = $store->find($fresh['id']);
$token = Access::token($row);
$tampered = substr($token, 0, -1).($token[-1] === '0' ? '1' : '0');
check(Access::verify($row, $token) && ! Access::verify($row, $tampered) && ! Access::verify($row, ''), 'link key verification');
Access::rotate($row['id']);
check(! Access::verify($store->find($row['id']), $token), 'new link revokes the old one');
wp_set_current_user(0);
check(! Access::canView($store->find($row['id']), 'wrong'), 'guest without key cannot view');
check(! current_user_can('manage_wewp_quotes'), 'guest lacks the quotes capability');
$admin = get_user_by('login', 'quotes_admin');
wp_set_current_user($admin->ID);
check(current_user_can('manage_wewp_quotes'), 'administrator has the quotes capability');

// 7. Storefront request.
$request = $quotes->createRequest(['first_name' => 'Guest', 'last_name' => 'Fixture', 'email' => 'guest-fixture@example.test', 'country' => 'LT'], [['product_id' => $standard->get_id(), 'variation_id' => 0, 'quantity' => 3]], 'Please include delivery.', 0);
check($request['status'] === 'requested' && $request['draft']['lines'][0]['unit_price'] === '19.99' && $request['request']['message'] === 'Please include delivery.', 'request stored with catalogue price and message');

// 8. Privacy.
$privacy = new Privacy($store);
$export = $privacy->export('guest-fixture@example.test');
check(count($export['data']) === 1, 'privacy export finds the request');
$privacy->erase('guest-fixture@example.test');
check($store->find($request['id']) === null, 'privacy erase deletes a quote without an order');
$erase = $privacy->erase('fixture-buyer@example.test');
check($erase['items_retained'] === true && $store->find($fresh['id']) !== null, 'privacy erase keeps quotes that became orders');


// 9. Review regressions.
// 9a. Saving a draft of a sent quote keeps the sent customer, email and total.
$sentQuote = $quotes->createDraft(aq_draft([aq_line($standard->get_id(), 1, '10')]));
$quotes->send($sentQuote, false);
$before = $store->find($sentQuote['id']);
$edited = $before['draft'];
$edited['customer']['email'] = 'someone-else@example.test';
$edited['customer_id'] = (int) get_user_by('login', 'zivile')->ID;
$edited['lines'][0]['unit_price'] = '999';
$quotes->save($before, $edited);
$after = $store->find($sentQuote['id']);
check($after['email'] === $before['email'] && $after['customer_id'] === $before['customer_id'] && $after['total'] === $before['total'], 'draft edits leave the sent customer, email and total unchanged');
check($after['draft']['customer']['email'] === 'someone-else@example.test', 'draft edits are kept in the draft');
// 9b. Sending to another recipient creates a new private link.
$oldKey = Access::token($after);
$quotes->send($after, false);
$resent = $store->find($sentQuote['id']);
check(! Access::verify($resent, $oldKey) && $resent['email'] === 'someone-else@example.test', 'new recipient revokes the old link');
check($store->revision($sentQuote['id'], 2)['data']['customer_id'] === $edited['customer_id'], 'snapshot stores the linked customer');
$sameKey = Access::token($resent);
$quotes->send($resent, false);
check(Access::verify($store->find($sentQuote['id']), $sameKey), 'same recipient keeps the link');
// 9c. The order customer comes from the accepted snapshot.
$order = $accept->accept($sentQuote['id'], 3, 0);
check($order->get_customer_id() === $edited['customer_id'], 'order belongs to the snapshot customer');
// 9d. A failed order keeps the quote closed; paying a superseded order is reported.
$order->update_status('failed');
check(! $quotes->canReviseAccepted($store->find($sentQuote['id'])), 'failed order blocks a new revision');
$order->update_status('cancelled');
$quotes->send($store->find($sentQuote['id']), false);
$order->set_status('pending');
$order->save();
$order->payment_complete();
$events = array_column($store->events($sentQuote['id']), 'kind');
check(in_array('order_paid_superseded', $events, true) && $store->find($sentQuote['id'])['status'] === 'sent', 'payment on a superseded order is reported, quote unchanged');
// 9e. Interrupted acceptance: the attached order is recovered, not duplicated.
$crash = $quotes->createDraft(aq_draft([aq_line($standard->get_id(), 1, '15')]));
$quotes->send($crash, false);
$first = $accept->accept($crash['id'], 1, 0);
$wpdb->update($store->table('quotes'), ['status' => 'accepting', 'lock_token' => 'stale', 'locked_at' => gmdate('Y-m-d H:i:s', time() - 600)], ['id' => $crash['id']]);
$again = $accept->accept($crash['id'], 1, 0);
check($again->get_id() === $first->get_id() && $store->find($crash['id'])['status'] === 'accepted', 'stale acceptance recovers its existing order');
// 9f. Stock is held on acceptance; unavailable stock refuses the order.
$limited = aq_product('AQ-FIX-STOCK', '20');
$limited->set_manage_stock(true);
$limited->set_stock_quantity(2);
$limited->set_backorders('no');
$limited->save();
$stock = $quotes->createDraft(aq_draft([aq_line($limited->get_id(), 5, '20')]));
$quotes->send($stock, false);
$count = count(wc_get_orders(['limit' => -1, 'return' => 'ids', 'status' => array_keys(wc_get_order_statuses())]));
rejected(fn () => $accept->accept($stock['id'], 1, 0), 'unavailable stock refuses acceptance', 'stock');
check(count(wc_get_orders(['limit' => -1, 'return' => 'ids', 'status' => array_keys(wc_get_order_statuses())])) === $count && $store->find($stock['id'])['status'] === 'sent', 'no order kept when stock is short');
// 9g. "Any" variation attributes reach the order.
$attr = new WC_Product_Attribute();
$attr->set_name('Size');
$attr->set_options(['S', 'M', 'L']);
$attr->set_visible(true);
$attr->set_variation(true);
$shirt = new WC_Product_Variable();
$shirt->set_name('Fixture shirt');
$shirt->set_attributes([$attr]);
$shirt->set_status('publish');
$shirtId = $shirt->save();
$anyVariation = new WC_Product_Variation();
$anyVariation->set_parent_id($shirtId);
$anyVariation->set_attributes(['size' => '']);
$anyVariation->set_regular_price('12');
$anyVariation->set_status('publish');
$anyVariation->save();
$line = aq_line($shirtId, 2, '12');
$line['variation_id'] = $anyVariation->get_id();
$line['attributes'] = ['attribute_size' => 'M'];
$shirtQuote = $quotes->createDraft(aq_draft([$line]));
$sentShirt = $quotes->send($shirtQuote, false);
check(str_contains($sentShirt['data']['lines'][0]['meta'], 'M'), 'chosen size shown on the quote');
$shirtOrder = $accept->accept($shirtQuote['id'], 1, 0);
$shirtItem = current($shirtOrder->get_items());
check($shirtItem->get_meta('size') === 'M', 'chosen size stored on the order item');
// 9h. Large logos are downscaled for snapshots.
$big = imagecreatetruecolor(3000, 1000);
imagefill($big, 0, 0, imagecolorallocate($big, 20, 120, 110));
$file = get_temp_dir().'aq-big-logo.png';
imagepng($big, $file);
require_once ABSPATH.'wp-admin/includes/media.php';
require_once ABSPATH.'wp-admin/includes/file.php';
require_once ABSPATH.'wp-admin/includes/image.php';
$logoId = media_handle_sideload(['name' => 'aq-big-logo.png', 'tmp_name' => $file], 0);
$uri = Settings::logoDataUri((int) $logoId);
$decoded = getimagesizefromstring(base64_decode(substr($uri, strpos($uri, ',') + 1)));
check($uri !== '' && strlen($uri) < 600000 && $decoded[0] <= 900 && $decoded[1] <= 300, 'large logo downscaled to fit');
wp_delete_attachment((int) $logoId, true);
// 9i. Privacy export leaves out the staff note and includes sent revisions.
$noted = aq_draft([aq_line($standard->get_id(), 1, '10')]);
$noted['customer']['email'] = 'privacy-fixture@example.test';
$noted['note'] = 'Staff only: margin is thin';
$notedQuote = $quotes->createDraft($noted);
$quotes->send($notedQuote, false);
$export = (new Privacy($store))->export('privacy-fixture@example.test');
$flat = wp_json_encode($export);
check(! str_contains($flat, 'margin is thin') && str_contains($flat, 'Sent revision 1'), 'privacy export excludes the staff note and includes revisions');

echo 'PASS '.$GLOBALS['aq_checks']." integration checks\n";
