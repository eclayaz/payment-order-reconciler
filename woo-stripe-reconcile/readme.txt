=== WooCommerce Stripe Reconcile ===
Contributors: eclayaz
Tags: woocommerce, stripe, reconciliation, orders, payments
Requires at least: 6.8
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.0
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
* Reviews — to avoid acting on an order whose charge is currently held for manual review by Stripe Radar.

No data is sent to any server other than Stripe's own API and your own WordPress site. See Stripe's Privacy Policy: https://stripe.com/privacy and Terms of Service: https://stripe.com/legal/consumer

== Frequently Asked Questions ==

= Does this plugin work with WooPayments? =

No. It's specifically for stores using the third-party "WooCommerce Stripe Payment Gateway" plugin, which doesn't have equivalent built-in reconciliation.

= Can this plugin refund or charge anything? =

No. It only ever reads from Stripe and writes to your own WooCommerce order status. The Stripe API key it uses should be a restricted, read-only key with no write access at all.

== Changelog ==

= 1.0.0 =
* Pass A: bulk PaymentIntent list-and-diff against local orders, detecting stuck-pending orders, wrongly-cancelled paid orders, and orphaned charges with no matching order.
* Pass B: undelivered-webhook diagnostic, and a webhook-endpoint health check.
* Real-time auto-resolve via WooCommerce/gateway hooks, in addition to the daily scheduled pass.
* Admin dashboard with Open/Fixed/Dismissed views and one-click Fix/Dismiss actions, each re-verifying live Stripe state immediately before acting.
* Settings screen: restricted-key entry with scope validation, alert email, coverage status.
* Full PHPUnit test suite; independently reviewed across four rounds (spec x3, code x1) before release.
