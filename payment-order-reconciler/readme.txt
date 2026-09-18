=== Driftwatch Order Reconciler for Stripe ===
Contributors: eclayaz
Tags: woocommerce, stripe, reconciliation, orders, payments
Requires at least: 6.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Finds WooCommerce orders that disagree with Stripe: stuck-pending, wrongly-cancelled, or orphaned charges. Fix them safely from a dashboard.

== Description ==

If your store uses the standard WooCommerce Stripe Payment Gateway plugin (not WooPayments), a broken or misconfigured webhook can leave orders stuck "Pending" even though the customer paid, or — worse — let WooCommerce's own cleanup job cancel an order that was actually paid. This plugin checks Stripe's own records against your orders and flags the mismatches, so you find out from a dashboard instead of from an angry customer.

Runs entirely on your own site. No hosted service, no third party ever sees your data.

= Key features =

* Detects stuck-pending orders, wrongly-cancelled/failed orders, and orphaned Stripe charges with no matching order.
* One-click Fix per row — re-verifies the current Stripe state live immediately before acting, never off a cached snapshot.
* Bulk Fix/Dismiss for clearing a backlog without clicking through every row individually.
* Real-time auto-resolve via WooCommerce/gateway hooks, plus a daily scheduled scan.
* Webhook-endpoint health check, so you find out your webhook is broken before it causes more drift.
* Read-only, restricted Stripe API key only — this plugin never has permission to charge, refund, or move money.

= External service disclosure =

This plugin communicates directly with the Stripe API (`api.stripe.com`) using a **read-only, restricted** API key that you provide. It never has permission to charge, refund, or move money. It reads:

* PaymentIntents and Charges — to compare payment status against your order status.
* Checkout Sessions — to match certain payment flows back to the correct order.
* Events — to check whether your webhook endpoint is currently receiving deliveries.
* Webhook Endpoints — to verify your configured endpoint URL is correct and reachable.
* Disputes — to avoid acting on an order that's currently under an active payment dispute.
* Reviews — to avoid acting on an order whose charge is currently held for manual review by Stripe Radar.

No data is sent to any server other than Stripe's own API and your own WordPress site. See Stripe's Privacy Policy: https://stripe.com/privacy and Terms of Service: https://stripe.com/legal/consumer

== Installation ==

1. Install and activate WooCommerce and the "WooCommerce Stripe Payment Gateway" plugin first — both are required, and this plugin refuses to stay active without them.
2. Install and activate this plugin (search for it under Plugins > Add New, or upload the zip).
3. In Stripe, go to Developers > API keys > Create restricted key. Set exactly these seven resources to Read, everything else to None: PaymentIntents, Charges, Checkout Sessions, Events, Webhook Endpoints, Disputes, Reviews.
4. In WordPress, go to WooCommerce > Payment Reconciler, paste the key, and save. The settings screen validates all seven scopes and shows which (if any) failed.
5. The first scheduled scan runs an hour after activation — use "Run reconciliation now" on the same screen to check sooner.
6. Results appear on WooCommerce > Payment Reconciliation.

== Frequently Asked Questions ==

= Does this plugin work with WooPayments? =

No. It's specifically for stores using the third-party "WooCommerce Stripe Payment Gateway" plugin, which doesn't have equivalent built-in reconciliation.

= Can this plugin refund or charge anything? =

No. It only ever reads from Stripe and writes to your own WooCommerce order status. The Stripe API key it uses should be a restricted, read-only key with no write access at all.

= Can I undo a Fix? =

Not from this plugin — clicking Fix marks the order paid and completed via WooCommerce's own normal payment-complete flow (the same thing the gateway itself would have done), which emails the customer and can't be reversed from here. It's only offered when the plugin has just re-verified, live against Stripe, that the order really is showing as paid. If you're unsure, use Dismiss instead — that never changes the order at all.

= What if the Stripe API key is wrong or its permissions are missing? =

The settings screen checks all seven required scopes individually and tells you which one(s) failed. A misconfigured key also shows clearly on the coverage status (Settings and the dashboard) as a failed run rather than a false "all clear."

== Screenshots ==

1. The dashboard: open drift, showing what disagrees with Stripe and why, with Fix/Dismiss actions per row (and bulk actions for a backlog).
2. Settings: restricted-key entry with live validation of all seven required scopes.

== Changelog ==

= 1.1.0 =
* Bulk Fix/Dismiss on the Open view — select multiple rows and act on them at once for a large backlog, instead of one click per row. Each row still goes through the same live Stripe re-verification and safety checks a single Fix/Dismiss click uses.
* Found and fixed a restricted-key scope gap: Reviews:Read was required by Pass A's own Radar-review check but never validated by the settings screen's scope checker, so a key set up exactly per the documented instructions could pass every check yet fail outright on every real run.
* Encrypted the Stripe API key at rest (AES-256-GCM); a previously-saved plaintext key is migrated automatically.

= 1.0.0 =
* Pass A: bulk PaymentIntent list-and-diff against local orders, detecting stuck-pending orders, wrongly-cancelled paid orders, and orphaned charges with no matching order.
* Pass B: undelivered-webhook diagnostic, and a webhook-endpoint health check.
* Real-time auto-resolve via WooCommerce/gateway hooks, in addition to the daily scheduled pass.
* Admin dashboard with Open/Fixed/Dismissed views and one-click Fix/Dismiss actions, each re-verifying live Stripe state immediately before acting.
* Settings screen: restricted-key entry with scope validation, alert email, coverage status.
* Full PHPUnit test suite; independently reviewed across four rounds (spec x3, code x1) before release.
