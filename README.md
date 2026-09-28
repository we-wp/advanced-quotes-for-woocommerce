# Advanced Quotes for WooCommerce

Let customers request quotes. Set prices in your admin, send numbered PDF quotes, and take payment when the customer accepts.

[Product page](https://we-wp.com/plugins/advanced-quotes-for-woocommerce) · [Report an issue](https://github.com/we-wp/advanced-quotes-for-woocommerce/issues)

## Install

1. Open the [v0.1.0 release](https://github.com/we-wp/advanced-quotes-for-woocommerce/releases/tag/v0.1.0) and download `advanced-quotes-for-woocommerce-0.1.0.zip`. GitHub's "Source code" archives are not the installer.
2. In WordPress, open **Plugins → Add New Plugin → Upload Plugin**. Select the ZIP, install it, then activate it.
3. Open **WooCommerce → Settings → Quotes**. Add your business details, then open **WooCommerce → Quotes** to create your first quote.

## How it works

1. A customer selects **Request a quote** on a product or in the cart, then sends the request.
2. You open it in **WooCommerce → Quotes**, or create a quote in the quote generator.
3. You set prices, discounts, shipping and terms, then send the quote. The customer gets a private link and the PDF.
4. The customer accepts online. The plugin creates a pending WooCommerce order at the quoted prices, holds its stock, and opens the WooCommerce payment page. If an item is out of stock, no order is created.

## What this release does

- Storefront request button on product pages and the cart, and a request page (`[wewp_quote_request]`).
- Admin quote generator with registered or guest customers, products and variations, custom items, per-line discounts, shipping, notes, a validity date and live totals.
- Totals from WooCommerce tax rates and order rounding. Acceptance recreates the lines with their stored taxes and refuses the order if WooCommerce reaches another total.
- Sequential numbers from a locked counter, with a configurable prefix.
- Immutable, hashed revisions. Each revision keeps its PDF.
- Online quote page with Accept and pay, Decline and PDF download, plus **My Account → Quotes**.
- Five WooCommerce emails: request received, new request, quote (with PDF), accepted and declined.
- The Essential template. Premium templates are planned for Advanced Quotes Pro, which is not available to buy.

No telemetry, remote rendering or update client is included. You write and approve all terms.

## Requirements and recovery

WordPress 7.0+, WooCommerce 11.1+, PHP 8.3+ with DOM, GD and mbstring, and InnoDB tables. Quotes support up to 200 lines. Activate separately on each Multisite site.

Deactivation and deletion keep all quote tables and the counter. Back up the database before you move or restore a store.

## Build and verify

`composer.lock` pins the PDF dependencies; `tools/composer.lock` pins PHP-Scoper.

```sh
python3 tools/build.py
php tests/render.php --packaged
composer audit --locked
```

`tests/integration.php` and `tests/concurrency.py` run only against the disposable database `wewp_quotes_test_20260927` with invented data. Never change that guard to run against a real store. See [QA.md](QA.md) for the latest local evidence.

Maintainer: UAB BusinessPress. GPL-3.0-or-later. WooCommerce and Woo are trademarks of Automattic Inc.; this independent plugin is not endorsed by Automattic.
