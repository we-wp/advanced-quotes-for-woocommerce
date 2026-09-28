<?php

// Synthetic quote snapshots for standalone rendering checks. Invented store, people and products only.

function aq_fixture_snapshot(array $overrides = []): array
{
    $lines = [
        ['name' => 'Oak standing desk', 'meta' => 'Width: 160 cm, Finish: Natural oil', 'sku' => 'DESK-OAK-160', 'description' => 'Delivered assembled to the second floor.', 'quantity' => 4, 'unit_display' => '740.00', 'discount' => '10', 'amount_display' => '2664.00'],
        ['name' => 'Task chair', 'meta' => 'Colour: Graphite', 'sku' => 'CHAIR-TASK-G', 'description' => '', 'quantity' => 8, 'unit_display' => '289.00', 'discount' => '0', 'amount_display' => '2312.00'],
        ['name' => 'Acoustic desk screen', 'meta' => '', 'sku' => 'SCREEN-AC-120', 'description' => '', 'quantity' => 4, 'unit_display' => '118.50', 'discount' => '5', 'amount_display' => '450.30'],
        ['name' => 'Installation and cable management', 'meta' => '', 'sku' => '', 'description' => 'One visit, two technicians. Includes packaging removal.', 'quantity' => 1, 'unit_display' => '360.00', 'discount' => '0', 'amount_display' => '360.00'],
    ];
    $snapshot = [
        'schema' => 1,
        'preview' => false,
        'quote_id' => 12,
        'number' => 'Q-000012',
        'revision' => 2,
        'issued_date' => '28 September 2026',
        'valid_until' => '2026-10-28',
        'valid_until_date' => '28 October 2026',
        'title' => 'Quote',
        'reference' => 'PO-2026-0457',
        'intro' => 'Thank you for your request. This quote covers furniture and installation for your new Vilnius office. Prices are fixed until the date shown.',
        'terms' => "Payment is due when you accept the quote. Delivery within 15 working days after payment.\nReturns follow our standard terms at example-store.test/terms.",
        'footer' => 'Registered in Lithuania, company code 300000000.',
        'seller' => ['name' => 'Northwind Workspace', 'address' => "Gedimino pr. 1\n01103 Vilnius\nLithuania", 'tax_id' => 'LT100000000000', 'email' => 'sales@northwind.example', 'phone' => '+370 600 00000', 'website' => 'https://northwind.example/', 'logo' => ''],
        'customer' => ['name' => 'Živilė Example', 'company' => 'Example Studio UAB', 'address' => "Konstitucijos pr. 7\n09308 Vilnius\nLithuania", 'email' => 'zivile@example.test', 'phone' => '+370 611 11111', 'tax_id' => 'LT200000000000'],
        'currency' => 'EUR',
        'format' => ['decimals' => 2, 'decimal_separator' => ',', 'thousand_separator' => ' ', 'symbol' => '€', 'position' => 'right_space'],
        'tax' => ['enabled' => true, 'prices_include_tax' => false, 'round_at_subtotal' => false, 'display' => 'excl'],
        'lines' => $lines,
        'totals' => ['total' => '7123.49'],
        'totals_rows' => [
            ['key' => 'subtotal', 'label' => 'Subtotal', 'amount' => '6 106,00 €'],
            ['key' => 'discount', 'label' => 'Discount', 'amount' => '−319,70 €'],
            ['key' => 'shipping', 'label' => 'Delivery to Vilnius', 'amount' => '80,00 €'],
            ['key' => 'tax', 'label' => 'VAT 21%', 'amount' => '1 257,19 €'],
            ['key' => 'total', 'label' => 'Total', 'amount' => '7 123,49 €'],
        ],
        'labels' => [
            'number' => 'Quote number', 'date' => 'Date', 'valid_until' => 'Valid until', 'reference' => 'Your reference', 'revision' => 'Revision',
            'prepared_for' => 'Prepared for', 'from' => 'From', 'item' => 'Item', 'sku' => 'SKU', 'quantity' => 'Qty', 'unit_price' => 'Unit price',
            'discount' => 'Discount', 'amount' => 'Amount', 'terms' => 'Terms', 'tax_id' => 'Tax number', 'email' => 'Email', 'phone' => 'Phone',
            'page' => 'Page', 'of' => 'of', 'total' => 'Total', 'prices_excl' => 'Prices exclude tax.', 'prices_incl' => 'Prices include tax.',
            'accept' => 'Accept this quote online to place your order.', 'preview' => 'Preview — not sent',
        ],
        'template' => ['id' => 'essential', 'accent' => '#214ee8', 'paper' => 'A4'],
        'store' => ['name' => 'Northwind Workspace', 'url' => 'https://northwind.example/'],
        'locale' => 'en_GB',
    ];

    return array_replace_recursive($snapshot, $overrides);
}
