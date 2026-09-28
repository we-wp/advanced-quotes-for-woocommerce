# Verification — Free 0.1.0

Verified locally on 2026-09-28 with an invented store, products and people only. No production data was used. The package evidence below refers to the released installer.

## Environment

PHP 8.3.32, MySQL 26.7.0 (Homebrew, InnoDB), WordPress 7.1, WooCommerce 11.1.0, Twenty Twenty-Five block theme. Disposable database `wewp_quotes_test_20260927`. Taxes enabled with Lithuanian 21%, 9% and 0% rates plus German, Latvian and Polish standard rates. Emails were captured to files by a test-only mu-plugin instead of being sent.

## Automated checks

- `php tests/render.php` — money validation, escaping of hostile customer and product text, refusal of remote logos, invalid accent fallback, contrast maths, stored template labels, and A4, Letter and 120-line multipage PDFs. With `--extra=<autoload.php>`, the same checks cover add-on templates.
- Page-break sweep: the templates were rendered with 6 to 24 item lines. No page starts with the totals block without at least one item line. The 4-line fixture and a 5-line sample quote fit on one A4 page in Essential.
- `wp eval-file tests/integration.php` — 97 checks. Totals match WooCommerce's own `calculate_totals()` in six tax configurations: prices excluding and including tax, rounding per line and at subtotal, billing and shop-base tax location, inherited and fixed shipping tax classes, discounts, custom items and untaxed items. Each accepted order equals its quote total. Also covered: input validation, sequential numbering across a prefix change and reinstall, immutable revisions, tamper detection for snapshots and PDFs, superseded, expired, declined and withdrawn quotes, repeated acceptance, re-sending after a cancelled order, payment marking the quote paid, refused orders when WooCommerce reaches another total, private link keys and revocation, capabilities, storefront requests and privacy export and erasure. Checks added after the code review cover: a new link when the recipient changes (and the same link for the same recipient), orders owned by the customer stored in the snapshot, no new revision while a failed order exists, payment on a superseded order reported without changing the quote, recovery of a stale acceptance, no order kept when stock is short, a chosen "any" attribute stored on the order item, and a privacy export without the staff note.
- `python3 tests/concurrency.py` — eight separate PHP processes accept one quote at the same time; one order is created and all eight requests receive it.
- A zero-total quote completes its order on acceptance and becomes paid.

## Browser checks

Guest storefront request (variation choice enforced, quantities, request form, confirmation) → admin pricing with a custom item, discount and shipping → sent revision with PDF attachment → guest acceptance → WooCommerce pay-for-order page with the exact quoted total → cash on delivery → quote paid. Also checked: customer search and product search in the generator, live totals, settings save, cart-to-quote in the block cart, My Account → Quotes, the customer page on desktop (1440 px) and phone (375 px).

After the code-review fixes (2026-09-28): guest Accept and Decline through the customer page's own form, with Accept leading to the pay page at the exact total and cash on delivery marking the quote paid. A decline reason was recorded. A variable product with an "any" size attribute was added to a request with its chosen size. Store staff who open a customer link see the staff view without Accept and Decline. WooCommerce reports the plugin compatible with HPOS and with cart and checkout blocks.

## Package

`python3 tools/build.py` produces `dist/advanced-quotes-for-woocommerce-0.1.0.zip` (5,020,870 bytes, 703 entries, SHA-256 `8c6dfbff58dd9f0ac0e79302fe64eb642289244240993b47f27745a79ebd6f0b` for the v0.1.0 release). Two builds produce identical bytes. `php tests/render.php --packaged` passes against the scoped package.

The exact ZIP was extracted into the test store in place of the source. The 97 integration checks and the concurrency test passed against it, with only the prefixed `WeWPQuotesVendor` Dompdf loaded. With Advanced Invoices 0.1.0 also active, both plugins rendered PDFs in one request from their separately prefixed Dompdf copies.

## Static checks

WordPress Plugin Check 2.1.0 on the exact ZIP reports expected items only: exception strings flagged as unescaped output (messages are escaped where they are printed), direct queries on the plugin's own tables, WooCommerce's own hook names in email templates, one placeholder count that the sniff cannot read in the list search query, and the deliberate `Update URI` header. PHPStan level 5 with WordPress and WooCommerce stubs reports stub-typing artefacts only (missing WordPress constants, WooCommerce stub types and the stub `WC_VERSION`).

## Fonts

The plugin bundles no custom fonts.
- PDFs use DejaVu Sans, which ships with Dompdf. Add-on templates may also use DejaVu Sans Mono.
- The PDFs embed only those faces. This was checked by reading the font names in each PDF.
- `fonts/` holds only the metric caches from `tools/fonts.php`. Dompdf added no files there during the render, integration and exact-ZIP runs.
- The online quote page uses the theme's body font through `wp_get_global_styles()` and `wp_print_font_faces()`. With Twenty Twenty-Five this is Manrope, loaded from the theme. When the theme sets no body font, the page uses the system font.
- There is no horizontal overflow at 1440 px or 390 px.

## Design review

An independent design review of the templates and the online quote page found 8 issues. A second review confirmed all 8 as fixed and found one new issue in a template's fills. That issue was fixed and checked locally.

## Limits

No claim covers every theme, payment gateway, multi-currency plugin, host or database version. The integration and concurrency tests also passed with HPOS off (posts storage with compatibility sync). Multisite and classic themes were not part of this matrix. Terms written by the merchant are not checked for legal requirements.
