"""Eight separate PHP processes accept the same sent quote at once. Exactly one order may exist afterwards.

Usage: python3 tests/concurrency.py <path-to-store-wp-wrapper>
The wrapper runs WP-CLI against the disposable synthetic database only.
"""
import concurrent.futures
import subprocess
import sys

wp = sys.argv[1]
guard = "if (DB_NAME !== 'wewp_quotes_test_20260927') { throw new RuntimeException('Wrong database'); }"
setup = guard + """
$p = WeWP\\AdvancedQuotes\\Plugin::instance();
$d = $p->quotes->blankDraft();
$d['customer'] = array_merge($d['customer'], ['first_name' => 'Race', 'last_name' => 'Fixture', 'email' => 'race-fixture@example.test', 'country' => 'LT']);
$d['lines'] = [['key' => 'l1', 'product_id' => 0, 'variation_id' => 0, 'name' => 'Race item', 'description' => '', 'quantity' => 2, 'unit_price' => '49.95', 'discount' => '', 'tax_class' => '']];
$q = $p->quotes->createDraft($d);
$p->quotes->send($q, false);
echo $q['id'];
"""
quote_id = subprocess.run([wp, 'eval', setup], capture_output=True, text=True).stdout.strip().splitlines()[-1]


def accept(_):
    code = guard + f"""
try {{
    $o = WeWP\\AdvancedQuotes\\Plugin::instance()->acceptance()->accept({quote_id}, 1, 0);
    echo 'ORDER ' . $o->get_id();
}} catch (RuntimeException $e) {{
    echo 'BUSY ' . $e->getMessage();
}}
"""
    out = subprocess.run([wp, 'eval', code], capture_output=True, text=True).stdout.strip().splitlines()
    return out[-1] if out else 'EMPTY'


with concurrent.futures.ThreadPoolExecutor(max_workers=8) as pool:
    results = list(pool.map(accept, range(8)))

orders = {r for r in results if r.startswith('ORDER ')}
busy = [r for r in results if r.startswith('BUSY ')]
assert len(orders) == 1, results
assert len(orders) + len(busy) >= 1 and all(r.startswith(('ORDER ', 'BUSY ')) for r in results), results
check = guard + f"""
$q = WeWP\\AdvancedQuotes\\Plugin::instance()->store->find({quote_id});
$ids = wc_get_orders(['limit' => -1, 'return' => 'ids', 'meta_key' => '_wewp_aq_quote_id', 'meta_value' => {quote_id}, 'status' => array_keys(wc_get_order_statuses())]);
echo $q['status'] . ' ' . $q['order_id'] . ' ' . count($ids);
"""
state = subprocess.run([wp, 'eval', check], capture_output=True, text=True).stdout.strip().splitlines()[-1].split()
assert state[0] == 'accepted' and state[2] == '1' and 'ORDER ' + state[1] in orders, (state, results)
print(f'PASS: eight concurrent acceptances produced one order (#{state[1]}); {len(busy)} requests were told to retry')
