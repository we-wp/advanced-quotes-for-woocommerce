=== Advanced Quotes for WooCommerce ===
Contributors: eimkasp
Tags: woocommerce, quote, request a quote, b2b, pdf
Requires at least: 7.0
Tested up to: 7.1
Requires PHP: 8.3
Stable tag: 0.2.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Let customers request quotes. Set prices in your admin, send numbered PDF quotes, and take payment when the customer accepts.

== Description ==

Advanced Quotes is a WooCommerce plugin by UAB BusinessPress. It turns a price request into a paid WooCommerce order.

1. A customer selects Request a quote on a product or in the cart, then sends the request.
2. You open the request in WooCommerce > Quotes, or create a quote from scratch in the quote generator.
3. You set prices, discounts, shipping and terms, then send the quote. The customer gets an email with a private link and the PDF.
4. The customer accepts online. The plugin creates a pending WooCommerce order at the quoted prices and opens the standard payment page.

Free includes:

* A Request a quote button on product pages and the cart, and a request page.
* A request form you can shape: make phone, company, tax number, address and message required, optional or hidden, and add up to 20 fields of your own, such as a deadline, a budget or measurements.
* The admin quote generator: registered or guest customers, catalogue products and variations, custom items such as services, per-line discounts, shipping, notes, validity date and live totals.
* Taxes from your WooCommerce tax rates and rounding settings. The accepted order must reach the same total, or no order is kept.
* Sequential quote numbers with your own prefix. Numbers are never reused.
* Revisions. A sent quote never changes; you send a new revision instead. Every revision keeps its own PDF.
* An online quote page with Accept and pay, Decline and PDF download. It works on phones.
* My Account > Quotes for registered customers.
* Five WooCommerce emails that you can edit or turn off: request received, new request, quote, quote accepted and quote declined.
* The Essential template with your logo, business details and accent colour.

Advanced Quotes Pro is planned. It adds four premium templates. It is not available to buy.

The plugin has no telemetry and makes no remote requests. You write and approve your own terms. The plugin does not check them for legal requirements.

== Installation ==

1. Back up your store. Test with invented data on a staging copy first.
2. Download the installer ZIP from the GitHub release. Source archives are not installable packages.
3. Open Plugins > Add New Plugin > Upload Plugin. Upload and activate the ZIP.
4. Open WooCommerce > Settings > Quotes. Add your business details and logo.
5. Open WooCommerce > Quotes > Add quote to create your first quote, or wait for a customer request.

Activation creates a Request a quote page with the [wewp_quote_request] shortcode.

== Frequently Asked Questions ==

= How does the customer pay? =
Accepting creates a pending order with the quoted items, prices, taxes and shipping. WooCommerce then shows its standard payment page with your enabled payment methods. Accepting holds stock for the pending order, as WooCommerce checkout does. If an item is out of stock, no order is created and the customer sees a message. WooCommerce reduces stock with its normal order rules.

= Can I ask customers for more details? =
Yes. Open WooCommerce > Settings > Quotes > Request form and add fields: text, paragraph, number, date, dropdown, radio buttons, checkboxes or a single checkbox. Each field can be required and can have help text. The answers appear with the request in WooCommerce > Quotes and in the request emails.

= Can I change a quote after I send it? =
Yes. Edit it and send a new revision. The customer link always shows the latest revision. Earlier revisions and their PDFs stay in the quote history.

= What happens when a quote expires? =
The customer can still view it, but cannot accept it. Send a new revision with a later date to reopen it.

= Can guests accept quotes? =
Yes. The private link lets the customer accept without an account. If you link the quote to a registered customer, WooCommerce asks that customer to log in on the payment page.

= Who can see a quote? =
Anyone with the private link, the linked customer account, and store staff with the Manage quotes capability. Create a new customer link to revoke old links.

= Does uninstall erase quotes? =
No. Deactivation and deletion keep quotes, revisions, PDFs and the counter.

= Is Multisite supported? =
Activate the plugin separately on each site. Network activation is refused.

== Changelog ==

= 0.2.0 =
Request form fields: add up to 20 fields of your own to the quote request form, and choose which contact fields are required, optional or hidden. Answers appear with the request and in the request emails. Form errors now show one message per field and mark the fields to correct.

= 0.1.0 =
Initial Free release: storefront requests, admin quote generator, numbered revisions with PDFs, online acceptance with payment, customer account list and five emails.
