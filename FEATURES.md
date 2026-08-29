# Feature List — derived from evidence

See [PROBLEM.md](./PROBLEM.md) for the problem statement, including the corrected dispute/review-handling principle this document depends on.

> Revised 2026-08-29, three times. Third-pass corrections (this revision) fix: a dispute exclusion that was permanent when it needed to be live-checked, a hash format that would never validate, a lock-check call that was still a PHP fatal, two drift types with no defined fix behavior, an idempotency mechanism that can't be built on MySQL, and a fix action that could re-enter its own auto-resolve listener and misattribute who fixed what in the audit log. It also applies two scope cuts recommended by the third review: a full Checkout Session list scan is replaced with a targeted per-charge lookup, and the two-cadence scheduler is cut to one daily pass.

## Detection layer

1. **Real-time hook listener** on `wc_gateway_stripe_process_webhook_payment_error` — fires at 7 call sites, confirmed in source. Fires on dispute-created events too (filter it out — not a failure). Every call site requires a resolved `$order`, so **this hook can never signal an orphaned charge** — that's Pass A's job.

2. **Real-time hook listener** on `wc_gateway_stripe_process_payment_charge` (non-deprecated replacement for `wc_gateway_stripe_process_webhook_payment`, which is deprecated since 9.7.0) — **used only to flag a candidate as "likely resolving," never to mark anything resolved.** *Correction (second pass, restated precisely per the third review's fact-check):* this hook fires very early in `process_response()` — preceded only by a logging call and an order-collision check that can itself throw — genuinely before the charge is committed, so it cannot be trusted as a resolution signal.

3. **Real-time hook listener** on `wc_stripe_paid_order_cancellation_prevented` (gateway 10.8.0+). **This hook has exactly one legitimate use in this plugin: telemetry for the coverage/health dashboard.** *Correction (third pass):* the second-pass draft listed a second use — "a real-time signal for stores below 10.8.0" — and then correctly noted in the same sentence that the hook can't answer that question either, since it doesn't exist on stores below 10.8.0. That's not a second use, it's the same sentence negating itself; removed. This hook fires once per order, ever (gateway-side guard), and only inside the branch where `date_paid` is already set — it cannot signal the residual `date_paid`-empty case that check #6 exists for.

4. **Scheduled reconciliation job on ActionScheduler — a single daily pass, not two cadences.** *Scope cut, third review:* the prior revision specified an hourly incremental pass (by `created` high-water mark) plus a daily full resweep, to catch both new activity and later state changes (a dispute opened, a refund posted) on older PaymentIntents. The third review found the two cadences could run concurrently with nothing arbitrating them, corrupting the dedup mechanism below. **v1 ships one daily pass over the full 30-day window.** For a bug occurring on ~0.4-0.5% of orders (see PROBLEM.md), next-day detection is an acceptable v1 latency; an hourly pass is a plausible v1.1 addition once real usage data says otherwise. A single "run in progress" guard (an ActionScheduler check plus a short-lived claim) still applies, since a manual "Run now" click could otherwise overlap the scheduled run.

5. **Idempotency and dismissal-persistence, corrected to be MySQL-buildable.** The prior draft specified two things that don't hold up: an event-ID ledger (fine for hooks/Pass B, but PaymentIntents have no event ID for Pass A to key on) and a `WHERE status = 'open'` partial unique index on `wsr_drift_log` — **partial/filtered unique indexes don't exist in MySQL/MariaDB at all**, which is what a WordPress install actually runs on.
   **Corrected mechanism:** add a generated `open_key` column on `wsr_drift_log` — `CONCAT(stripe_object_id, ':', drift_type)` when `status` is `open` **or** `dismissed`, `NULL` otherwise — with a plain `UNIQUE KEY` on it. MySQL unique indexes ignore `NULL`s, which gives exactly the right semantics: an open or dismissed row blocks re-insertion of the same drift (dismissals persist — see #8 below, corrected from a real gap the third review found), while a `fixed` row's `NULL` key allows a genuinely new future occurrence of the same object/type to be logged. On a would-be duplicate insert, update `detected_at` and increment `detection_count` on the existing row instead (skip the update if the existing row is `dismissed` — a dismissal must not be silently resurrected).
   The event-ID ledger remains, scoped to the hook listeners and the Pass B diagnostic only.

## Reconciliation checks

6. **Wrongly-cancelled-paid-order detector** — targets the residual case where `date_paid` was never set (see PROBLEM.md's "What changed upstream").
   *Check logic:* PaymentIntent status `succeeded`, underlying charge `captured === true`, `amount_refunded < amount`.
   *Dispute handling, corrected per PROBLEM.md's live-check principle:* expand `data.latest_charge.dispute` on every detection run (never cache disputed status). If a dispute is currently open, detect but set `severity: info` and disable the Fix action. **If the dispute was lost, exclude this order from this check entirely** — a lost dispute is, per the gateway's own logic, a legitimate reason for `failed` status, not evidence of wrongful cancellation, and this exclusion is safe to be permanent because it reflects the dispute's final state rather than a cached snapshot. If won or no dispute, no special handling.
   *Evidence:* [#5268](https://github.com/woocommerce/woocommerce-gateway-stripe/issues/5268) (fixed 10.8.0, historical), the SSSV report on #5691 (2026-08-14, confirmed *not* the Checkout Session flow — see PROBLEM.md).

7. **Stuck-pending detector** — local status ∈ {pending, on-hold}, PaymentIntent `succeeded`. Corrected, simplified exclusion list:
   - `_stripe_payment_awaiting_action` meta set **and** order modified within the last 24 hours (mirrors the gateway's own time-bounded treatment of this meta — excluding on presence alone, with no time bound, would create a permanent blind spot for a 3DS flow that completed but whose meta was never cleared).
   - **Currently open dispute** (live-checked, same principle as #6) → detect at `severity: info`, no Fix action. **Currently open Radar review** (expand `data.latest_charge.review`, check live status, not the static `outcome.type` flag which never changes after the fact) → same treatment. *Correction (third review):* the prior draft used `charge.outcome.type === 'manual_review'` as a permanent exclusion — that field records the decision at charge time and doesn't update when a human approves the review, which would have permanently hidden an approved-and-released Radar order from detection. Checking the review's live status instead avoids that.
   - **Removed as redundant or unnecessary (third review):**
     - `_stripe_status_final` meta — confirmed to be set only on dispute-close, making it a strict subset of the dispute handling above once that's implemented correctly. Dropped as its own exclusion.
     - "On-hold by design under authorize-and-capture, uncaptured" — this check's own precondition (`PI.status == succeeded`) already excludes it: Stripe's PaymentIntent state machine reports `requires_capture`, not `succeeded`, for an authorized-but-uncaptured intent. The base condition already handles this; no separate exclusion needed.
     - "Awaiting an async payment method (ACH microdeposits, Multibanco, Boleto)" — these are backed by a `SetupIntent` object (`seti_` prefix), not a PaymentIntent, and Pass A lists PaymentIntents only. This is a **documented v1 coverage boundary** (these flows are structurally invisible to Pass A, not something requiring an exclusion rule), not a rule to implement. No `SetupIntents:Read` scope is needed for v1 as a result.
   *Evidence:* [#5691](https://github.com/woocommerce/woocommerce-gateway-stripe/issues/5691), [#5326](https://github.com/woocommerce/woocommerce-gateway-stripe/issues/5326).

8. **Orphaned-charge / needs-review detector — join-key strategy and mechanism both corrected in the third review.**
   *Join-key order of preference:*
   1. **`metadata.signature`** — format `order_id:md5(order_id-order_key-customer_id-stripe_amount)`. **Corrected (third review):** the fourth hashed component is the order total converted to **Stripe's minor-unit integer amount** (via the gateway's own amount-conversion helper), not the raw decimal order total as an earlier draft stated — an implementer following the earlier wording would hash the wrong string and never get a match. The leading segment (before the colon) is the internal order ID directly, no lookup needed.
   2. **`metadata.order_key`** (UPE path) as fallback.
   3. **Checkout Session cross-reference** for the tail that has neither.
   *The Checkout Session mechanism, corrected to a targeted lookup instead of a full list scan:* the second-pass draft proposed listing **all** Checkout Sessions in the window as a second bulk call — the third review found the gateway actually **backfills** `signature`/`order_key`-equivalent metadata onto Adaptive-Pricing-flow PaymentIntents itself, roughly 2 minutes after a successful session, via its own deferred job. So the population genuinely needing session cross-reference is **only the unresolved tail after that backfill window**, not "every Adaptive Pricing PaymentIntent" as previously stated — and Stripe's Checkout Sessions list endpoint supports filtering directly **by PaymentIntent ID**. **Corrected mechanism:** for each PaymentIntent that fails both metadata joins above, wait until it's at least a few minutes old (past the gateway's own backfill window) before doing anything, then issue a **targeted** `GET /v1/checkout/sessions?payment_intent={id}` lookup and cross-reference the returned session ID against local `_stripe_checkout_session_id` order meta. This is O(unresolved drift), not O(every cart), and needs no in-memory full-session-list handling at all.
   *Site scoping:* `metadata.site_url`, host-normalized (not strict string equality) to tolerate domain/www/scheme changes. Metadata-absent PaymentIntents are `needs_review`, not silently dropped or immediately flagged orphaned.
   *Escalation, corrected to use real counters instead of an ambiguous "second consecutive run":* `wsr_drift_log` tracks `first_detected_at`, `detected_at` (last-seen), and `detection_count`. A `needs_review` row escalates to `orphaned_charge` once `detection_count >= 2` — with a single daily cadence (see #4's scope cut), this cleanly means "still unresolved the next day," removing the ambiguity a two-cadence design would have introduced.
   *Fix action: none in v1 — see "Explicitly NOT reconciliation-job features" below.* Detection-only, dismissible.
   *Evidence:* the "stuck/missing order" issue family, the "23 missing orders" blog post, and the Adaptive-Pricing-specific root cause on #5691 itself.

9. **Refund/capture drift detector** (v1.1). [#2799](https://github.com/woocommerce/woocommerce-gateway-stripe/issues/2799) (open), [#5140](https://github.com/woocommerce/woocommerce-gateway-stripe/issues/5140) (fixed, released 10.9.0), [#5699](https://github.com/woocommerce/woocommerce-gateway-stripe/issues/5699) (fixed, **not yet released** — sits in an unreleased 11.0.0-dev changelog block only).

10. **Idempotency-collision flag** (v1.5). [#4915](https://github.com/woocommerce/woocommerce-gateway-stripe/issues/4915), real but rare/unquantified.

11. **Webhook setup/health check — given an explicit owner and cadence (third review found it had neither).** `GET /v1/webhook_endpoints`, comparing the configured endpoint URL against the site's current production URL — directly catches the stale-staging-domain root cause documented in PROBLEM.md. **Runs as part of the same single daily scheduled pass as Pass A** (not folded into Pass B, which is a different diagnostic — the prior revision's "webhook health comes from Pass B" was a mislabel). Needs a `Webhook Endpoints:Read` restricted-key scope.

## Explicitly NOT reconciliation-job features

- **No Fix action for `orphaned_charge` or `needs_review` in v1** (third review). These drift types have no local order to call a fix primitive on, and the second-pass draft specified a dashboard Fix button for them with no defined behavior behind it. v1 ships these as informational, dismissible rows only. A manual "link this charge to an order" action is a plausible v2 feature.
- **Dispute-cascading-to-wrong-order** ([#3556](https://github.com/woocommerce/woocommerce-gateway-stripe/issues/3556), closed 2025-06-17 — historical evidence, v2 candidate).
- **Stripe Radar false-blocks** — checkout-time prevention bugs, not detectable after the fact.
- **Test-mode/live-mode key mismatches** — folded into the webhook health-check (#11).
- **Currency/multi-currency amount comparison** — deferred; single-currency, non-Connect stores are the supported v1 configuration for *amount* checks specifically. Order *matching* for Checkout Session flows (which is itself the multi-currency-adjacent flow) is in v1 per #8 above — these are separable: match the order first, skip amount-based checks when presentment currency differs from the store default.
- **Hourly incremental scheduling** — cut to v1.1, see #4.

## Fix actions — corrected primitive, with three additional API-level fixes and one re-entrancy fix from the third review

**History:** first pass fixed a design that would have double-sent emails (`update_status()` + manual re-fire → `payment_complete()`). Second pass found and fixed three further bugs in how the correct primitive was being called. Third pass found one more, in how the fix interacts with the plugin's own auto-resolve listener.

`$order->payment_complete( $charge_id )` remains the core primitive — works from `cancelled`/`pending`/`on-hold`/`failed`, sets `date_paid` (re-arming the gateway's own 10.8.0 guard) and transaction ID, fires `woocommerce_payment_complete`, lets core handle stock/email exactly once. Companion steps, corrected:

- **Lock check — call syntax corrected (third review).** `is_order_payment_locked()` / `get_order_existing_payment_lock()` are **instance methods on a singleton**, not static methods — `WC_Stripe_Order_Helper::get_instance()->is_order_payment_locked( $order )`, not a bare static call (which is a PHP fatal). Skip/defer the fix if locked.
- **Stripe-meta completion — write path corrected (third review).** `payment_complete()` alone doesn't set `_stripe_charge_captured` or clear `_stripe_payment_awaiting_action`. The relevant meta constants are private to the gateway's order-helper class, so route through that class's own instance setter/clearer methods rather than hardcoding the literal meta key strings — confirm the exact method names against the installed gateway version at implementation time rather than assuming names from documentation.
- **Post-call verification, unchanged from second pass:** `payment_complete()`'s return value can't be trusted as success on its own (`true` on a no-op path too) — re-read order status/`date_paid` after the call before writing `fixed`.
- **New in the third review: a re-entrancy guard around the whole fix.** The auto-resolve listener (hooked to `woocommerce_payment_complete`, see Reliability section) and the fixer's own call to `payment_complete()` both react to the same hook — calling `payment_complete()` from the fixer fires that hook synchronously, which would let the auto-resolve listener mark the row `resolved_by: system` a moment before the fixer itself writes `resolved_by: <the admin's user ID>`. Since the audit log's whole purpose is recording who actually did what, this ordering bug would misattribute a manual fix as a system self-heal. **Fix:** the fixer sets a short-lived flag immediately before calling `payment_complete()`; the auto-resolve listener checks and skips when that flag is set, deferring the authoritative write to the fixer itself.

Fix-applied tracking: one `_wsr_fix_applied` meta key (JSON list of fixed `stripe_object_id`s) — unbounded per-fix dynamic keys were the actual problem with the very first draft, not queryability (a JSON blob is less queryable than discrete keys; `wsr_drift_log` remains the authoritative queryable history regardless).

Never calls a Stripe write endpoint.

## Reliability/safety features

- ActionScheduler for the single daily job, plus a "run in progress" guard (a manual "Run now" click and the scheduled run must not overlap).
- No direct `$wpdb` queries against order/meta tables — `wc_get_orders()`, `WC_Order_Query`, core lookup helpers.
- Environment detection: `wp_get_environment_type()` plus a fallback comparing the **restricted**-key prefix (`rk_live_`/`rk_test_` — corrected from an earlier `pk_`-prefix error) against the site's environment signal.
- "Last successful run" indicator as a primary dashboard element.
- **Restricted-key scopes needed for v1, resolved (third review confirmed all five exist as discrete Stripe restricted-key resources, closing what was previously an open verification item):** `PaymentIntents:Read`, `Charges:Read`, `Checkout Sessions:Read`, `Events:Read`, `Webhook Endpoints:Read`, plus **`Disputes:Read`** (needed for the corrected live dispute-status check in #6/#7, added in this revision — confirmed to exist as a discrete scope alongside the other five). A 15-minute sandbox-key confirmation is still worth doing before finalizing onboarding copy, but this is no longer an open design risk.

## Admin UX (dashboard)

- **Drift dashboard**, categories matching what v1 actually produces: stuck-pending / wrongly-cancelled-paid / orphaned-charge / needs-review. `refund-mismatch` stays out until v1.1 ships it.
- **Severity now includes `info`** (alongside `high`/`critical`) — for currently-disputed or currently-under-review matches that are detected but not auto-fixable.
- Orphaned-charge/needs-review rows show **Dismiss** only, no **Fix** (see Fix actions above).
- Webhook health status shown prominently, from its own daily check (#11), not conflated with Pass B.
- Email/Slack alert on new high-severity drift (paid tier).
- Audit log of every fix, cross-checked against re-read order state, correctly attributed to system-vs-admin per the re-entrancy fix above.

## Summary: what v1 must include, minimum

Detection: hook listeners #1-#3 (accelerator/telemetry roles only) + the single daily Pass A run (#4) + the `open_key`-based dedup/dismissal mechanism (#5).
Checks: orphaned-charge/needs-review (#8, with the targeted session lookup and detection-count-based escalation) and the narrowed wrongly-cancelled-paid check (#6), both with live-checked dispute/review handling. Stuck-pending (#7) ships with its full, simplified exclusion list from day one.
Webhook health-check (#11) ships alongside, with its own place in the daily job.
Fix: `payment_complete()` plus the corrected lock-check, Stripe-meta completion, post-call verification, and re-entrancy guard — available only for stuck-pending/wrongly-cancelled-paid, never for orphaned-charge/needs-review.
Everything else (refund-drift, idempotency-collision flag, dispute-cascade, hourly cadence) remains real and evidenced but sequenced into v1.1+.
