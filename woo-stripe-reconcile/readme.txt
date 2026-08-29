=== WooCommerce Stripe Reconcile ===
Contributors: eclayaz
Tags: woocommerce, stripe, reconciliation, orders, payments
Requires at least: 6.8
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 0.1.0-dev
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Finds and fixes WooCommerce orders that disagree with what Stripe actually recorded — stuck-pending orders, wrongly-cancelled paid orders, and payments with no matching order.

== Description ==

If your store uses the standard WooCommerce Stripe Payment Gateway plugin (not WooPayments), a broken or misconfigured webhook can leave orders stuck "Pending" even though the customer paid, or — worse — let WooCommerce's own cleanup job cancel an order that was actually paid. This plugin checks Stripe's own records against your orders and flags the mismatches, so you find out from a dashboard instead of from an angry customer.

Runs entirely on your own site. No hosted service, no third party ever sees your data.

= External service disclosure =

This plugin communicates directly with the Stripe API (`api.stripe.com`) using a **read-only, restricted** API key that you provide. It never has permission to charge, refund, or move money. It reads:

* PaymentIntents and Charges — to compare payment status against your order status.
* Checkout Sessions — to match certain payment flows back to the correct order.
* Events — to check whether your webhook endpoint is currently receiving deliveries.
* Webhook Endpoints — to verify your configured endpoint URL is correct and reachable.
* Disputes — to avoid acting on an order that's currently under an active payment dispute.

No data is sent to any server other than Stripe's own API and your own WordPress site. See Stripe's Privacy Policy: https://stripe.com/privacy and Terms of Service: https://stripe.com/legal/consumer

== Frequently Asked Questions ==

= Does this plugin work with WooPayments? =

No. It's specifically for stores using the third-party "WooCommerce Stripe Payment Gateway" plugin, which doesn't have equivalent built-in reconciliation.

= Can this plugin refund or charge anything? =

No. It only ever reads from Stripe and writes to your own WooCommerce order status. The Stripe API key it uses should be a restricted, read-only key with no write access at all.

== Changelog ==

= 0.1.0-dev =
* Initial scaffolding: activation, dependency checks, database tables.
