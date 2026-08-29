# MVP Technical Spec

Scope: the v1 minimum set from [FEATURES.md](./FEATURES.md). See [PROBLEM.md](./PROBLEM.md) for the underlying problem statement, revised positioning, and evidence.

> Revised 2026-08-29 (first pass) after an independent technical review found a meta-key bug, a double-email-sending fix action, two false-positive detection rules, and a scaling problem in the original per-order-polling design — all corrected below. **Revised again 2026-08-29 (second pass)** after a follow-up review found that the first-pass bulk list-and-diff redesign, while directionally correct, didn't survive contact with two real flows: the Checkout Session path (which is the actual root cause of this project's flagship evidence issue) writes no order-identifying metadata onto the PaymentIntent at all, and the list endpoint returns charge data as bare IDs unless explicitly expanded. Both passes' corrections are kept inline, since the reasoning matters as much as the fix.

## The central design correction: bulk list-and-diff, not per-order polling (first pass) — refined with a Checkout Session cross-reference (second pass)

The original draft's "Pass 2" queried local orders by `_transaction_id` (which stores a charge ID, not a PaymentIntent ID, and is circularly only set by the webhook that already failed) and called `PaymentIntent::retrieve()` per order — wrong on two counts and didn't scale (10⁴-10⁵ calls/day on a mid-size store, every hour, forever, since the candidate set never shrinks).

**First-pass correction:** invert it — pull a bulk, paginated list of Stripe's own PaymentIntents and diff against local orders, rather than looking up Stripe once per local order.

**Second-pass refinement, because the first-pass version still had a hole:** a bulk PaymentIntent list alone cannot resolve every order, because **one entire class of checkout flow puts no order-identifying data on the PaymentIntent at all.**

### The Checkout Session gap (found in the second review, not present in the first-pass correction)

The Adaptive Pricing / Optimized Checkout Session flow — confirmed to be the actual root cause of the original #5691 report (see PROBLEM.md) — creates a PaymentIntent whose metadata is limited to `site_url`, `payment_type`, and `checkout_type`. **No `order_id`, `order_key`, or `signature`** — because the session is built from the cart before a WooCommerce order exists yet. A PaymentIntent list-and-diff that expects order-identifying metadata on every PaymentIntent will misidentify every one of these as an orphaned charge — a false positive on the very flow this project's headline evidence is about.

**Correction:** Pass A runs two list calls, not one, and cross-references them:
1. List **PaymentIntents** created in the window (as before).
2. List **Checkout Sessions** created in the same window (`GET /v1/checkout/sessions`, expanding the linked PaymentIntent reference) — this is the only place a session-flow payment's link back to a purchase context can be found at all, since the relationship only runs Session → PaymentIntent, never the other way.
3. Build a local map of `checkout_session_id → local_order_id` from orders carrying `_stripe_checkout_session_id` meta, and cross-reference: for a PaymentIntent with no resolvable order-identifying metadata, check whether it appears as the linked intent on a Checkout Session whose ID matches a local order's stored session ID.

This adds one more scope requirement (`Checkout Sessions:Read`, see below) but is the only way to avoid false-orphaning an entire real, evidenced flow.

### Coverage gaps to design around

- **Subscription objects themselves don't get `_stripe_intent_id`** — `save_intent_to_order()` returns early only when `is_subscription()` is true, and that's specifically true for a `WC_Subscription` object, not for a subscription's parent order or its renewal orders. **Correction (second review):** the first-pass draft overstated this as "blind to every subscription order" — parent and renewal orders (the actual payable orders a merchant cares about reconciling) do get the meta normally. The real gap is narrower: the subscription record itself isn't a payable order this reconciler should be diffing in the first place, so this isn't a coverage gap that needs a workaround.
- **Checkout Session flow** uses `_stripe_checkout_session_id`, a separate ID space — handled via the cross-reference above, not by reading `_stripe_intent_id` harder.
- Legacy (pre-UPE) source-based orders and some `order-pay`-endpoint orders may have neither field populated — treat as `needs_review`, not silently skipped or immediately flagged.

## Two detection passes, with corrected cadence

1. **Pass A — bulk list-and-diff (PaymentIntents + Checkout Sessions cross-reference).** The primary mechanism. Handles the narrowed wrongly-cancelled-paid check, the stuck-pending check (with its full exclusion list — see FEATURES.md #7, including the `charge.disputed` exclusion added in the second review), and orphaned-charge detection.
   **Corrected cadence (second review) — the first-pass draft's single "re-pull the 30-day window every run" design would re-fetch the same month's data 24×/day forever, and never catch a state change on an object outside the newly-created window.** Two cadences instead:
   - **Incremental** (hourly default): fetch objects created since the last incremental run's high-water mark. Cheap, catches new checkout activity promptly.
   - **Full resweep** (daily default): re-pull the full reconciliation window. Needed because a dispute opening, a refund posting, or a cancellation happening *after* a PaymentIntent's creation timestamp would never surface via the incremental pass alone, since it filters on `created`, not `updated`.
2. **Pass B — `delivery_success=false` Events poll.** Diagnostic only — feeds the webhook health-check (FEATURES.md #11), not orphan detection (a webhook the gateway couldn't map to an order still gets acked 200, so this filter returns nothing for that case).

## Plugin identity

- Slug: `woo-stripe-reconcile` (placeholder name)
- Hard dependency: WooCommerce active AND `woocommerce-gateway-stripe` active — check on activation, self-deactivate with an admin notice if either is missing.
- Declare HPOS compatibility explicitly (`FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true)`). Query orders via `wc_get_orders()`/`WC_Order_Query` and core lookup helpers (e.g. `wc_get_order_id_by_order_key()`) — no direct `$wpdb` queries against order or meta tables. **Correction (second review):** the first-pass draft cited the gateway plugin as an example of violating this ("HPOS-fatal"); on inspection its raw-query path only runs on legacy (non-HPOS) storage as an intentional branch — it isn't broken. The recommendation to avoid raw queries in our own code stands regardless, just not on that basis.
- **Version baseline, clarified (second review):** WooCommerce core (`trunk`) requires WordPress 7.0; the gateway plugin itself requires WordPress 6.8 and PHP 7.4. Since the target population is gateway-plugin users specifically (not necessarily running the very latest WooCommerce core), adopt the **gateway's own baseline — WP 6.8, PHP 7.4** — as this plugin's `Requires at least`, rather than WooCommerce core's stricter number.

## Data model

Two custom tables (created via `dbDelta` on activation):

**`{prefix}wsr_event_ledger`** — dedup ledger. **Scope corrected (second review):** covers the hook listeners and Pass B only — PaymentIntents (Pass A's subject) carry no event ID for this table to key on. See the drift-log uniqueness constraint below for Pass A's idempotency mechanism instead.
| column | notes |
|---|---|
| event_id | Stripe event ID, unique key |
| status | `pending` / `processing` / `processed` |
| created_at, processed_at | |

Pruned on each scheduled run to Stripe's 30-day Events retention window.

**`{prefix}wsr_drift_log`** — drift record and fix audit log combined.
| column | notes |
|---|---|
| id | |
| order_id | local WC order ID (nullable — an unresolved `needs_review` orphan candidate may have no order to attach to yet) |
| stripe_object_id | PaymentIntent ID or Checkout Session ID |
| drift_type | `stuck_pending` / `wrongly_cancelled_paid` / `orphaned_charge` / `needs_review` (v1); `duplicate_order` / `refund_mismatch` reserved for v1.1 |
| severity | `high` / `critical` |
| local_status_at_detection, stripe_status_at_detection | |
| status | `open` / `fixed` / `dismissed` |
| detected_at, resolved_at, resolved_by | `resolved_by` = `system` (self-healed) or a WP user ID (manual fix) |
| details | JSON blob: amounts, currency, raw PI status, dispute flag, any extra context |

**Added, second review:** a **unique constraint on `(stripe_object_id, drift_type)` where `status = 'open'`** — this is Pass A's idempotency mechanism, since it has no event-ID ledger to rely on. A re-detected open drift updates `detected_at` on the existing row rather than inserting a duplicate.

A `schema_version` option, checked on plugin load, with a migration path for future changes to these two tables.

## File structure

```
woo-stripe-reconcile/
  woo-stripe-reconcile.php          # bootstrap: header, activation/deactivation hooks, dependency check
  includes/
    class-wsr-activator.php         # dbDelta table creation + schema-version migration, default options
    class-wsr-stripe-client.php     # thin read-only wrapper — raw wp_remote_get(), no bundled SDK (see below)
    class-wsr-event-ledger.php      # dedup ledger (hooks + Pass B only) + retention pruning
    class-wsr-hook-listener.php     # wc_gateway_stripe_process_webhook_payment_error (filtered: exclude dispute type),
                                     # wc_gateway_stripe_process_payment_charge (detection accelerator only),
                                     # wc_stripe_paid_order_cancellation_prevented (telemetry),
                                     # woocommerce_payment_complete (auto-resolve open drift — see Fix actions)
    class-wsr-reconciler.php        # Pass A (incremental + daily resweep, PaymentIntent+CheckoutSession cross-reference)
                                     # + Pass B (undelivered-events diagnostic)
    class-wsr-scheduler.php         # ActionScheduler recurring registration (two cadences) + dispatch
    class-wsr-fixer.php             # payment_complete() + lock-check (read-only accessor) + Stripe-meta completion
                                     # + post-call state re-verification before marking fixed
    class-wsr-audit-log.php         # wraps drift_log's resolved/status fields; implements WP Privacy export/erase hooks
    class-wsr-admin-dashboard.php   # WP_List_Table-based drift screen (categories matching what v1 actually produces),
                                     # webhook health status
    class-wsr-settings.php          # API key entry/validation (4 scopes, see below), environment badge, coverage indicator
  assets/                           # admin CSS/JS for the dashboard
```

## Core flow

### Setup
1. Activation checks WooCommerce + gateway plugin are active.
2. `dbDelta` creates the two tables (with the uniqueness constraint on `wsr_drift_log`); set `schema_version` option.
3. Settings screen: merchant pastes a **restricted, read-only** Stripe key. **Scope list, corrected and expanded (second review):** `PaymentIntents:Read`, `Charges:Read` (core), `Checkout Sessions:Read` (gates the cross-reference above — missing from the first-pass draft entirely), `Events:Read` (gates Pass B), `Webhook Endpoints:Read` (gates the webhook health-check, FEATURES.md #11 — also missing from the first-pass draft). **Before writing the onboarding copy that tells merchants which toggles to enable, verify against a sandbox restricted key which of these Stripe's permission model actually exposes as discrete scopes** — confirmed for PaymentIntents/Charges, not yet confirmed for the other three.
4. Support a `WSR_STRIPE_RESTRICTED_KEY` wp-config constant as the secure-storage option, falling back to a `wp_options` entry.
5. Environment detection: `wp_get_environment_type()` plus a fallback, since it defaults to `'production'` when unset. **Prefix correction (second review):** compare the **restricted**-key prefix (`rk_live_`/`rk_test_`) against the site's environment signal — not `pk_live_`/`pk_test_` (publishable-key prefixes), which was an error in the prior revision of this document.
6. Run the webhook health check (FEATURES.md #11) on setup and periodically thereafter.

### Real-time signal (accelerator/telemetry, not a resolver)
- `wc_gateway_stripe_process_webhook_payment_error`, filtered to exclude dispute-created invocations → record a drift candidate, schedule a single ActionScheduler action ~2 minutes out to verify against Stripe directly.
- `wc_gateway_stripe_process_payment_charge` → **used only to flag a candidate as "likely resolving," never to mark a drift record `fixed` or `resolved`** (corrected, second review — this hook fires before the charge is actually processed/committed, so treating it as confirmation would mark unfixed orders as resolved).
- `woocommerce_payment_complete` → **the correct auto-resolve trigger** (corrected, second review, replacing the previous draft's use of the process-charge hook for this purpose) — fires only after WooCommerce core has actually committed the payment-complete transition, so a drift record can be safely marked `resolved_by: system` here.
- `wc_stripe_paid_order_cancellation_prevented` → log as telemetry only (once-per-order, per the gateway's own guard) — valuable for the coverage dashboard, not a resolver for the wrongly-cancelled-paid check (see FEATURES.md #3 for why that framing was wrong in the prior revision).

### Scheduled reconciliation (ActionScheduler, two cadences)

**Pass A — incremental (hourly):**
List PaymentIntents created since the last incremental high-water mark, paginated, with `expand[]=data.latest_charge` (**added, second review** — without this, the list response returns the charge as a bare ID string, and every check reading `charge.captured`, `charge.outcome.type`, or `charge.disputed` would silently evaluate against undefined data). List Checkout Sessions created in the same window, expanding the linked PaymentIntent. Batch-load in-window local orders into an in-memory map up front (keyed by order key / signature-derived order ID / checkout session ID) — **do not look up orders one at a time per PaymentIntent**, which would reintroduce the original per-order-call scaling problem from the other direction.

For each PaymentIntent, resolve to a local order via, in order: `metadata.signature` (parse leading order ID) → `metadata.order_key` → Checkout Session cross-reference → in-memory map by `_stripe_intent_id`. If resolved, run the state-comparison checks (stuck-pending, wrongly-cancelled-paid) with their full exclusion lists (see FEATURES.md #6/#7, including the `charge.disputed` hard exclusion). If not resolved: check `metadata.site_url` via **host comparison, not strict string equality** (normalizing www/non-www, http/https) against the site's own host; a mismatch means it belongs to another store/app sharing the Stripe account and is skipped; a match, or metadata absent entirely, is written as `needs_review` and escalated to `orphaned_charge` only if still unresolved on a second consecutive run.

**Pass A — full resweep (daily):**
Same logic, full 30-day window, to catch state changes (a dispute opened, a refund posted, a late cancellation) on PaymentIntents outside the incremental window's `created` filter.

**Pass B — undelivered-events diagnostic:**
Unchanged from the first-pass revision — `delivery_success=false`, feeds the webhook health-check status, not orphan detection.

### Fix action — corrected primitive, now with three additional API-level fixes

The fixer calls `$order->payment_complete( $charge_id )` — **not** `update_status()` plus a manual re-fire, which would double-send WooCommerce's own emails (see FEATURES.md for the full first-pass correction). Three further corrections from the second review, all "the right primitive, called incorrectly" in the prior revision:

1. **Lock check, corrected.** `WC_Stripe_Order_Helper::lock_order_payment()` **acquires** a lock (returning whether one was already held) — it does not check one. Calling it as a pre-condition check would silently take a 5-minute lock the fixer never releases, blocking the gateway's own webhook processing. **Use the read-only accessors** (`is_order_payment_locked()` / `get_order_existing_payment_lock()`) to check, and skip/defer the fix if locked.
2. **Post-call verification, added.** `payment_complete()`'s return value is `true` on a no-op path as well as genuine success — it cannot be used as a success signal on its own. Re-read the order's actual status and `date_paid` after the call, and only write `fixed` to `wsr_drift_log` if the state genuinely changed as expected.
3. **Stripe-meta completion, added.** `payment_complete()` alone doesn't set `_stripe_charge_captured` or clear `_stripe_payment_awaiting_action` — both of which the gateway's own normal processing path sets, and both of which other gateway logic (refunds, cancellation checks) reads. Leaving them unset would reproduce the exact failure mode described in this project's own cited evidence, [#5699](https://github.com/woocommerce/woocommerce-gateway-stripe/issues/5699). The fixer must also write `_stripe_charge_captured = true`, set `_stripe_intent_id` if absent, and clear `_stripe_payment_awaiting_action`.

Fix-applied tracking uses one `_wsr_fix_applied` meta key (JSON list of already-fixed `stripe_object_id`s) — unbounded per-fix dynamic keys were the problem with the original draft, not queryability (a JSON blob is actually less queryable; `wsr_drift_log` remains the authoritative queryable history regardless).

**Never calls a Stripe write endpoint.** Refund/void/charge actions remain permanently out of scope per the read-only trust model in PROBLEM.md.

### Admin dashboard
`WooCommerce → Stripe Reconciliation`, `WP_List_Table`-based:
- Columns: Order #, Drift Type, Severity, Detected At, Local status vs Stripe status, Actions (Fix / Dismiss / View in Stripe Dashboard).
- **Category list corrected (second review):** stuck-pending / wrongly-cancelled-paid / orphaned-charge / needs-review — matching what v1 actually produces. `refund-mismatch` is v1.1 scope and shouldn't appear until that check ships.
- "Last successful run" / coverage indicator as a **primary dashboard element**, showing both cadences (incremental and full-resweep) separately, since a stalled daily resweep is a different failure than a stalled hourly incremental.
- Webhook health status (from Pass B) shown alongside.
- Settings tab: API key, scheduler frequency for both cadences, alert email.

## Stripe integration specifics

No bundled SDK — confirmed against the reference implementation itself, which uses raw `wp_remote_post()`/`wp_remote_get()`, not `stripe-php` (avoids the global-PHP-namespace collision risk of two plugins bundling different SDK versions). All v1 calls are read-only:
- `GET /v1/payment_intents` (list, paginated, `expand[]=data.latest_charge`) — **no server-side status or metadata filter exists on this endpoint** (confirmed against Stripe's API reference), so all filtering happens locally after fetch.
- `GET /v1/checkout/sessions` (list, paginated, expanding the linked PaymentIntent) — new in this revision, for the Checkout Session cross-reference.
- `GET /v1/events` (Pass B diagnostic, `delivery_success=false`).
- `GET /v1/webhook_endpoints` (webhook health-check, FEATURES.md #11).

Restricted key scopes needed for v1 (expanded, second review): `PaymentIntents:Read`, `Charges:Read`, `Checkout Sessions:Read`, `Events:Read`, `Webhook Endpoints:Read`. Verify all five against a sandbox key before finalizing onboarding copy (see Setup step 3 above).

No endpoint exists to force webhook redelivery — the fixer always acts on a direct list/read, never waits on redelivery.

## Compliance & standards

Unchanged from the second revision — both review passes confirmed the PCI, Stripe-terms, WordPress.org, and GDPR sections as accurate on inspection. No further corrections this pass.

## Explicitly deferred (per FEATURES.md, not re-litigated here)

Duplicate-order flagging, refund/capture drift check, idempotency-collision flag, dispute-cascade detection, Radar annotations, Slack alerting, any multi-site/hosted variant, full Stripe Connect/multi-currency amount comparison (order *matching* for Checkout Session flows is in v1 per the correction above — only the amount-comparison logic is deferred for currency-mismatched orders specifically).

## Suggested build order

1. Plugin skeleton, activation hook, table creation (with the drift-log uniqueness constraint) + schema versioning, WooCommerce/gateway dependency check.
2. Stripe HTTP client wrapper (`wp_remote_get()`-based, no SDK) + settings page. **Sandbox-verify all five restricted-key scopes** (not just one) before finalizing onboarding copy.
3. **Pass A, built as the full corrected design from the start** — bulk list-and-diff with `expand[]=data.latest_charge`, the Checkout Session cross-reference, host-normalized site scoping, and the full exclusion lists (including `charge.disputed`) — as a manual "Run now" button, testable without scheduler/hook wiring. This is now a materially bigger first milestone than the original draft's per-order loop would have been, but it's the version that actually survives contact with a real store; building the simpler wrong version first would mean rewriting the core matching logic, not just wrapping it in a scheduler later.
4. Wrap step 3 in the two ActionScheduler cadences (incremental hourly, full resweep daily); add Pass B.
5. Hook listeners, with their corrected roles (accelerator/telemetry, not resolvers) — `woocommerce_payment_complete` specifically for auto-resolution.
6. Admin dashboard UI, with the coverage indicator (both cadences) and webhook health status as primary elements from the start.
7. Fix action — `payment_complete()` plus the three corrected companion steps (lock-check via read-only accessor, post-call re-verification, Stripe-meta completion) — plus audit log.
8. Email alerting.
