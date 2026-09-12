# MVP Technical Spec

Scope: the v1 minimum set from [FEATURES.md](./FEATURES.md). See [PROBLEM.md](./PROBLEM.md) for the problem statement and the corrected dispute/review-handling principle.

> Revised 2026-08-29, three times. This revision (third pass) fixes: an idempotency mechanism that cannot be built on MySQL, a full Checkout Session list scan replaced with a targeted per-charge lookup, a two-cadence scheduler cut to one daily pass to remove a race condition, a lock-check call that was still a PHP fatal, two drift types wired into the dashboard with no defined fix behavior, and a fix action that could re-enter its own auto-resolve listener and misattribute authorship in the audit log. Per the third review's own recommendation, this is meant to be the last full rewrite before code — remaining verification is a narrow "trace one row end-to-end" exercise (see the end of this document), not another independent review pass.

## The central mechanism: a single daily bulk list-and-diff, with a targeted lookup for the unresolved tail

Per-order polling (checking one local order against Stripe at a time) doesn't scale and uses the wrong meta key to begin with (see prior revisions for why). The corrected mechanism, refined twice more since:

1. **List PaymentIntents** created in the last 30 days, paginated, expanding `data.latest_charge` (charge fields come back as bare ID strings otherwise) **and, within the same expand depth budget, `data.latest_charge.dispute` and `data.latest_charge.review`** — needed for the live dispute/review-status checks in FEATURES.md #6/#7, not just capture/refund state.
2. **Batch-load in-window local orders into an in-memory map up front**, keyed by every identifier a PaymentIntent might resolve through: order key, signature-derived order ID, checkout session ID, **and `_stripe_intent_id`** (the fourth key was missing from an earlier revision's batch-load despite being used in the resolution chain — fixed here). Never look up orders one at a time per PaymentIntent.
3. **Resolve each PaymentIntent to a local order**, in order: `metadata.signature` (parse leading order ID — see the corrected hash format below, needed only if independently re-deriving/verifying the signature, not for the join itself, which just needs the leading segment) → `metadata.order_key` → in-memory map by `_stripe_intent_id` → **targeted Checkout Session lookup** (below) for the tail that resolves via none of the above.
4. **Checkout Session lookup, corrected to be targeted, not a full list scan.** The gateway backfills order-identifying metadata onto Adaptive-Pricing-flow PaymentIntents itself, ~2 minutes after a successful session (via its own deferred job) — so most of these resolve via step 3 like any other PaymentIntent once they're at least a few minutes old. **Only PaymentIntents older than that backfill window that still fail every metadata join need a session lookup at all.** For those: `GET /v1/checkout/sessions?payment_intent={id}` (Stripe's list endpoint supports filtering directly by PaymentIntent ID), then cross-reference the returned session ID against local `_stripe_checkout_session_id` order meta. This replaces a full-window Checkout Session list (O(every cart, healthy or not) — the larger of the two calls by a wide margin on any real store) with a lookup sized to the actual unresolved drift population.
5. **Site scoping** for anything still unresolved: `metadata.site_url`, compared by **normalized host** (www/non-www, http/https), not strict string equality — a strict-equality check would silently drop legitimate payments across any past domain change. A mismatch means the charge belongs to another store/app sharing the Stripe account (skip). A match, or metadata absent entirely, becomes `needs_review`.
6. **Run the state-comparison checks** (stuck-pending, wrongly-cancelled-paid, with their corrected exclusion lists including the live dispute/review handling) on everything resolved to a local order in step 3.

## Cadence — corrected to a single daily pass

The prior revision specified an hourly incremental pass (by `created` high-water mark) plus a daily full resweep, reasoning that the incremental pass alone would miss state changes (a dispute opening, a refund posting) on PaymentIntents outside its creation-time window. That reasoning was right, but running both concurrently, with nothing arbitrating them, meant they could race and double-process the same objects. **Corrected: v1 ships one daily ActionScheduler recurring job, running the full 30-day window every time.** This is simpler, removes the race entirely, and is an acceptable v1 latency for a bug affecting ~0.4-0.5% of orders (see PROBLEM.md) — an hourly pass is a plausible v1.1 addition once real usage data justifies the added complexity. A "run in progress" guard (an ActionScheduler check plus a short-lived transient claim) still prevents the daily job from overlapping a manually-triggered "Run now" click.

## Pass B — unchanged in role, given an explicit place in the schedule

`delivery_success=false` Events poll, diagnostic only, feeding the webhook health-check. **Runs alongside Pass A in the same single daily job**, not on its own separate schedule — there's no reason for it to run more or less often than the reconciliation pass it supports.

## The webhook health-check (FEATURES.md #11) — given an explicit owner and place, which it was missing

`GET /v1/webhook_endpoints`, comparing the configured endpoint URL against the site's current production URL. **Runs as its own step within the same single daily job**, not folded into Pass B (a prior revision's "webhook health comes from Pass B" was a mislabel — Pass B is the `delivery_success=false` diagnostic, a different signal). This check directly catches the stale-staging-domain root cause that's this project's own best piece of evidence (see PROBLEM.md) — worth building early, not treating as an afterthought.

## Plugin identity

- Slug: `payment-order-reconciler` (final — was `woo-stripe-reconcile` during development, then `stripe-order-reconciler` at WP.org rollout phase 1, which fixed leading with the WooCommerce trademark but introduced leading with the Stripe trademark instead — WordPress.org's own naming guidance disallows both equally. Corrected 2026-09-12 so neither trademark leads.) Public name: **Payment Order Reconciler for WooCommerce and Stripe**. Hard dependency on WooCommerce + gateway plugin active. HPOS compatibility declared via `FeaturesUtil::declare_compatibility`. Baseline: WordPress 6.8, PHP 7.4 (the gateway's own requirement, since the target population is gateway users specifically).
- Query orders via `wc_get_orders()`/`WC_Order_Query`/core lookup helpers only, never raw `$wpdb` — this recommendation stands on its own merits (a prior revision incorrectly cited the gateway plugin itself as violating this; on inspection its raw-query path is an intentional, correct legacy-storage branch, not a bug to avoid copying for that reason).

## Data model

**`{prefix}wsr_event_ledger`** — dedup ledger for the hook listeners and Pass B only (PaymentIntents have no event ID for this to key on).
| column | notes |
|---|---|
| event_id | Stripe event ID, unique key |
| status | `pending` / `processing` / `processed` |
| created_at, processed_at | pruned to Stripe's 30-day retention |

**`{prefix}wsr_drift_log`** — corrected to be buildable on MySQL and to support dismissal persistence and escalation, neither of which the prior schema actually supported.
| column | notes |
|---|---|
| id | |
| order_id | nullable — `orphaned_charge`/`needs_review` rows have none |
| stripe_object_id | PaymentIntent ID or Checkout Session ID |
| drift_type | `stuck_pending` / `wrongly_cancelled_paid` / `orphaned_charge` / `needs_review` (v1); `duplicate_order` / `refund_mismatch` reserved for v1.1 |
| severity | `info` / `high` / `critical` — **`info` added this revision**, for currently-disputed or currently-under-review matches (detected, not auto-fixable) |
| local_status_at_detection, stripe_status_at_detection | |
| status | `open` / `dismissed` / `fixed` |
| **open_key** | **new, this revision.** Generated: `CONCAT(stripe_object_id, ':', drift_type)` when `status` is `open` or `dismissed`, `NULL` when `fixed`. Carries a plain `UNIQUE KEY`. *Why this exists:* the prior revision specified a `WHERE status = 'open'` partial unique index — **MySQL/MariaDB don't support partial/filtered unique indexes at all**, so that constraint was unbuildable on the platform this plugin actually runs on. A generated column plus a plain unique index (which MySQL correctly treats as satisfied by any number of `NULL`s) reproduces the intended semantics and is real, portable SQL. It also fixes a second gap the prior schema had: a `dismissed` row's uniqueness previously lapsed the moment status changed, so the next scan would silently re-insert a drift the admin had already dismissed — including `dismissed` alongside `open` in the generated key means a dismissal actually persists. |
| **first_detected_at** | **new, this revision** — needed because the "update `detected_at` on re-detection" behavior meant the original detection time was being overwritten, leaving no way to answer "how long has this been open" or drive escalation. |
| detected_at | now specifically "last seen," not "first seen" |
| **detection_count** | **new, this revision** — incremented on each re-detection of an already-open row. Drives `needs_review → orphaned_charge` escalation at `detection_count >= 2`, which — with the single daily cadence above — cleanly means "still unresolved the next day." **Escalation mutates the existing row's `drift_type` in place** (`UPDATE ... SET drift_type = 'orphaned_charge'`, letting the generated `open_key` recompute) — it never inserts a second row for the same object. Confirmed via the end-to-end trace below: inserting a new row here would let the same charge appear twice on the dashboard under two different categories simultaneously. |
| resolved_at, resolved_by | `system` (self-healed, correctly attributed — see the fixer's re-entrancy guard below) or a WP user ID |
| details | JSON: amounts, currency, raw PI status, dispute/review status snapshot, any extra context |

A `schema_version` option with a migration path for future changes to these tables.

## File structure

```
payment-order-reconciler/
  payment-order-reconciler.php
  includes/
    class-wsr-activator.php         # dbDelta (incl. generated open_key column + unique index), schema versioning
    class-wsr-stripe-client.php     # raw wp_remote_get() wrapper, no bundled SDK
    class-wsr-event-ledger.php      # hooks + Pass B dedup only, retention pruning
    class-wsr-hook-listener.php     # wc_gateway_stripe_process_webhook_payment_error (dispute-filtered),
                                     # wc_gateway_stripe_process_payment_charge (accelerator only),
                                     # wc_stripe_paid_order_cancellation_prevented (telemetry only),
                                     # woocommerce_payment_complete (auto-resolve — re-entrancy-guard-aware)
    class-wsr-reconciler.php        # Pass A (single daily, PI list + targeted session lookup for unresolved tail)
                                     # + Pass B (undelivered-events diagnostic) + webhook health-check, all in one job
    class-wsr-scheduler.php         # ActionScheduler: ONE daily recurring registration + "run in progress" guard
    class-wsr-fixer.php             # payment_complete() + read-only lock check + Stripe-meta completion via
                                     # WC_Stripe_Order_Helper instance setters + post-call re-verification +
                                     # re-entrancy guard around the auto-resolve listener
    class-wsr-audit-log.php         # drift_log wrapper; WP Privacy export/erase hooks
    class-wsr-admin-dashboard.php   # drift categories matching v1 output; Dismiss-only rows for orphan/needs-review
    class-wsr-settings.php          # API key entry (6 scopes, see below), environment badge, coverage indicator
  assets/
```

## Core flow

### Setup
1-2. Unchanged: activation dependency check; `dbDelta` table creation including the `open_key` generated column and its unique index; `schema_version` set.
3. Settings screen, restricted key. **Scope list, corrected to seven** (a prior revision corrected it to six, adding `Disputes:Read`; this revision adds a seventh, `Reviews:Read`, found missing the same way — a real Pass A run, not `check_scopes()`, is what actually caught it): `PaymentIntents:Read`, `Charges:Read`, `Checkout Sessions:Read`, `Events:Read`, `Webhook Endpoints:Read`, `Disputes:Read`, and **`Reviews:Read`** — needed because Pass A's own PaymentIntents list call expands `data.latest_charge.review` for the live Radar-review downgrade check, and Stripe gates that expand behind this separate permission (`review_read`), distinct from `Disputes:Read`. Found 2026-08-30: a live sandbox key scoped to exactly the prior six "confirmed" scopes failed Pass A's very first page fetch outright, because `WSR_Stripe_Client::check_scopes()` never actually tested for this one — the six-scope confirmation two revisions ago was only as complete as what it checked. `check_scopes()` now includes `Reviews:Read`; all seven confirmed to exist as discrete Stripe restricted-key resources.
4-6. Unchanged: `WSR_STRIPE_RESTRICTED_KEY` constant or encrypted option; environment detection via `wp_get_environment_type()` plus a restricted-key-prefix (`rk_live_`/`rk_test_` — corrected from an earlier, wrong `pk_`-prefix version) fallback check; run the webhook health-check on setup.

### Real-time signal (accelerator/telemetry only, never a resolver)
- `wc_gateway_stripe_process_webhook_payment_error` (dispute-filtered) → schedule a short-delay verification, don't act instantly.
- `wc_gateway_stripe_process_payment_charge` → flag as "likely resolving" only; too early in the request lifecycle to trust as confirmation.
- `wc_stripe_paid_order_cancellation_prevented` → telemetry only, once-per-order.
- `woocommerce_payment_complete` → the auto-resolve trigger, **now re-entrancy-guard-aware**: if the fixer set its short-lived flag before calling `payment_complete()` itself, this listener skips writing `resolved_by: system`, deferring to the fixer's own authoritative write. Without this guard, every manual admin fix would get double-written and misattributed as a system self-heal, since the fixer's own call to `payment_complete()` fires this exact hook synchronously.

### Scheduled reconciliation — one daily job, three steps
1. **Pass A**: the bulk list-and-diff mechanism described above (PaymentIntent list → in-memory order map → metadata joins → targeted session lookup for the unresolved tail → site-scoped `needs_review`/orphan classification → state-comparison checks with live dispute/review handling).
2. **Pass B**: `delivery_success=false` Events poll, diagnostic only.
3. **Webhook health-check**: `GET /v1/webhook_endpoints` vs. current site URL.

All three run under one "run in progress" guard so a manual "Run now" click and the scheduled trigger can't overlap.

### Fix action — `payment_complete()` plus four corrected companion steps

1. **Lock check, call syntax corrected this revision.** `WC_Stripe_Order_Helper::get_instance()->is_order_payment_locked( $order )` / `->get_order_existing_payment_lock( $order )` — these are **instance methods**, not static ones; the bare static-call form specified in the second revision is a PHP fatal. Skip/defer the fix if locked.
2. **Stripe-meta completion, write path corrected this revision.** `payment_complete()` doesn't set `_stripe_charge_captured` or clear `_stripe_payment_awaiting_action`. Their backing meta constants are private to the gateway's order-helper class — route through that class's own instance setter/clearer methods (confirm exact method names against the installed gateway version at implementation time; don't hardcode the private constants' literal string values from documentation alone).
3. **Post-call verification** (unchanged from the second revision): re-read order status/`date_paid` after calling `payment_complete()` before writing `fixed` — its return value alone (`true` on a no-op path too) can't be trusted.
4. **Re-entrancy guard, new this revision.** Set a short-lived flag immediately before calling `payment_complete()`; the `woocommerce_payment_complete` auto-resolve listener (above) checks and skips while it's set, so the fixer's own write — with the correct `resolved_by` (the admin's user ID, not `system`) — is the one that lands.

Fix-applied tracking via one `_wsr_fix_applied` meta key (JSON list), for idempotency only — `wsr_drift_log` remains the authoritative, queryable history.

**No Fix action exists for `orphaned_charge` or `needs_review`** — there's no local order to act on. These are dismissible, informational rows only in v1 (see FEATURES.md).

**Never calls a Stripe write endpoint.**

### Admin dashboard
- Categories matching actual v1 output: stuck-pending / wrongly-cancelled-paid / orphaned-charge / needs-review.
- `info`-severity rows (currently disputed/under-review matches) shown distinctly, no Fix button.
- Orphan/needs-review rows: Dismiss only.
- "Last successful run" as a primary element (one line now, not two cadences to show).
- Webhook health status, from its own daily check.

## Stripe integration specifics

No bundled SDK — raw `wp_remote_get()`, matching the reference implementation. Calls needed for v1:
- `GET /v1/payment_intents` (list, paginated, `expand[]=data.latest_charge`, `expand[]=data.latest_charge.dispute`, `expand[]=data.latest_charge.review`) — no server-side status/metadata filter exists; all filtering happens locally.
- `GET /v1/checkout/sessions?payment_intent={id}` (targeted, only for the unresolved tail — not a full list).
- `GET /v1/events` (Pass B diagnostic).
- `GET /v1/webhook_endpoints` (health-check).

Restricted key scopes: `PaymentIntents:Read`, `Charges:Read`, `Checkout Sessions:Read`, `Events:Read`, `Webhook Endpoints:Read`, `Disputes:Read`, `Reviews:Read` — seven, confirmed to exist.

## Compliance & standards

Unchanged across all three review passes — confirmed accurate each time. No further corrections.

## Explicitly deferred (v1.1+)

Duplicate-order flagging, refund/capture drift check, idempotency-collision flag, dispute-cascade detection, Radar annotations (beyond the live-status check already in v1), Slack alerting, any multi-site/hosted variant, full Connect/multi-currency amount comparison, hourly incremental scheduling, a manual "link charge to order" action for orphan/needs-review rows.

## Suggested build order

1. Plugin skeleton, activation (tables incl. `open_key` + unique index, schema versioning), dependency check.
2. Stripe HTTP client wrapper, settings page, sandbox-verify all six scopes.
3. **Pass A**, built as the corrected design directly: bulk list with the three expands, in-memory order map keyed by all four identifiers, the three-step join chain, targeted (not full-list) session lookup for the unresolved tail, host-normalized site scoping, live dispute/review-aware state checks — as a manual "Run now" button, testable without scheduler wiring.
4. Wrap step 3 in the single daily ActionScheduler job; add Pass B and the webhook health-check as the other two steps in that same job.
5. Hook listeners in their corrected accelerator/telemetry/re-entrancy-aware-resolver roles.
6. Admin dashboard: correct categories, `info` severity, Dismiss-only rows for orphan/needs-review, coverage indicator.
7. Fix action: `payment_complete()` plus all four corrected companion steps, audit log.
8. Email alerting.

## Before writing code: a narrow verification exercise, not a fourth review pass

The third review's own recommendation, followed here rather than deferred: for each of the four drift types, trace one row end-to-end through **detect → dedup/escalate → display → fix-or-not → audit**.

- **`stuck_pending` / `wrongly_cancelled_paid`:** Pass A resolves the order, checks status + exclusions (live dispute/review status included) → inserts or updates via `open_key`, `detection_count` incremented on repeat detection → dashboard shows it at `high`/`critical` severity, or `info` with no Fix button if a dispute/review is currently open → admin clicks Fix → lock check (read-only accessor) → `payment_complete()` + meta completion, re-entrancy flag set → listener skips auto-resolve → post-call verification → `wsr_drift_log` updated to `fixed`, `resolved_by` = the admin's user ID. Every stage has defined behavior. Holds together.
- **`orphaned_charge` / `needs_review`:** Pass A fails to resolve an order, site-scope check passes → inserted as `needs_review` → re-detected next day, `detection_count` reaches 2 → **existing row's `drift_type` mutated to `orphaned_charge` in place** (see the data model table above — this was the one genuine ambiguity this trace surfaced; resolved by mutating in place, not inserting a second row, so the same charge never appears twice) → dashboard shows it, Dismiss-only, no Fix button → admin dismisses → `status = 'dismissed'`, `open_key` still populated (dismissed rows stay in the unique-key set) → next day's run re-detects the same charge, hits the `open_key` collision, sees the existing row is `dismissed`, and skips the update — the dismissal persists rather than silently reappearing. Holds together once the mutate-in-place rule above is explicit, which it now is.

This is the ~30-minute desk-check the third review recommended in place of a fourth independent pass — it surfaced one real ambiguity (mutate vs. insert on escalation), now resolved above. No further review pass is planned before implementation begins.
