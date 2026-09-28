# Advanced Quotes for WooCommerce

[![Latest release](https://img.shields.io/github/v/release/we-wp/advanced-quotes-for-woocommerce?label=release)](https://github.com/we-wp/advanced-quotes-for-woocommerce/releases/latest)
[![Quality](https://github.com/we-wp/advanced-quotes-for-woocommerce/actions/workflows/quality.yml/badge.svg?branch=main)](https://github.com/we-wp/advanced-quotes-for-woocommerce/actions/workflows/quality.yml)
[![License: GPL-3.0-or-later](https://img.shields.io/badge/license-GPL--3.0--or--later-315bea.svg)](LICENSE)

Let customers ask for a price, send them a quote, and get paid when they accept.

**[Download the latest version](https://github.com/we-wp/advanced-quotes-for-woocommerce/releases/latest)** · [Plugin page](https://we-wp.com/plugins/advanced-quotes-for-woocommerce)

![The quote page your customer sees, with the total and an Accept and pay button](docs/screenshots/quote-page.png)

## How it works

**1. A customer asks for a price.** A Request a quote button sits next to Add to cart, and in the cart.

![Product page with a Request a quote button](docs/screenshots/request-a-quote.png)

**2. You set the prices.** Open the request, adjust quantities and prices, add a discount or shipping, and send it. Totals follow your WooCommerce tax settings.

![Quote generator in the WordPress admin](docs/screenshots/quote-generator.png)

**3. They accept and pay.** Your customer gets a PDF and a private link. Accept and pay creates the order at your prices and opens checkout.

<p>
  <img src="docs/screenshots/quote-on-phone.png" alt="The quote on a phone" width="300">
  &nbsp;
  <img src="docs/screenshots/quote-pdf.png" alt="The PDF quote" width="420">
</p>

## What you get

- A Request a quote button on product pages and in the cart
- Your own questions on the request form, such as a deadline, budget or measurements
- A quote generator with discounts, custom items and shipping
- PDF quotes with your logo and colour
- A page where customers accept, decline or download their quote
- An email at every step
- No limit on quotes

## Install

1. Download `advanced-quotes-for-woocommerce-0.2.0.zip` from the [latest release](https://github.com/we-wp/advanced-quotes-for-woocommerce/releases/latest). The Source code files won't install.
2. In WordPress, go to **Plugins → Add New Plugin → Upload Plugin**, then install and activate it.
3. Open **WooCommerce → Settings → Quotes** to add your business details and logo.

You need WordPress 7.0+, WooCommerce 11.1+ and PHP 8.3+.

## Help

Questions or problems? [Open an issue](https://github.com/we-wp/advanced-quotes-for-woocommerce/issues). Found a security problem? [Report it privately](https://github.com/we-wp/advanced-quotes-for-woocommerce/security/advisories/new).

## For developers

`python3 tools/build.py` builds the installer. [CONTRIBUTING.md](CONTRIBUTING.md) explains how to test a change, and [QA.md](QA.md) lists what was tested.

---

Made by [we-wp](https://we-wp.com) · UAB BusinessPress · GPL-3.0-or-later. WooCommerce is a trademark of Automattic Inc. This plugin is not endorsed by Automattic.
