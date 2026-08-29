# Problem Statement — WooCommerce Stripe Order Reconciliation

Working name: **woo-stripe-reconcile** (placeholder, not final)

> Revised 2026-08-29, three times. First pass: independent review verified/corrected the evidence base against GitHub and Stripe docs. Second pass: a follow-up review found corrections that were individually right but hadn't been traced into consequences elsewhere. Third pass: a follow-up review found the fact base now solid, but found that new mechanisms introduced in the second rewrite didn't compose with each other (a permanent-vs-live-checked dispute exclusion, an unbuildable database constraint, two undefined-behavior drift types, an audit log that could misattribute who fixed what). All three passes' corrections are kept inline.

## One-line problem

WooCommerce stores using the third-party Stripe gateway plugin (`woocommerce-gateway-stripe`) accumulate orders where Stripe's payment record and the store's order status disagree — most durably because of misconfigured or silently-broken webhook endpoints, and in a narrower residual case because an order never gets marked paid at all. The only fix today is a manual, ad hoc check against the Stripe dashboard, re-invented from scratch by whoever hits it.

## Who has this problem

- **Primary persona:** the person who administers a WooCommerce store on the standard Stripe gateway plugin.
- **Population size:** 700,000+ active installs, ~15-17% of live WooCommerce stores.
- **Not in scope:** WooPayments users (different plugin/codebase). Shopify/other platforms.

## Evidence this is real and recurring

**Verified, still-relevant evidence:**

- [woocommerce-gateway-stripe #5691](https://github.com/woocommerce/woocommerce-gateway-stripe/issues/5691) — "Stripe payments succeeding but WooCommerce orders stuck in 'Pending'/cancelled." Open. Originally filed against core as `woocommerce/woocommerce#66175` and transferred — one report, not two.
- A comment on #5691 (2026-07-30): **"we intake 10,000+ orders a month, but only about 40-50 orders a month are running into this... issue"** — a 0.4-0.5% incidence rate, the pricing/urgency anchor for this whole document.
- The same thread's original report (claudchan-alyka) traced its root cause to the **Adaptive Pricing / Optimized Checkout Session flow** specifically. A later comment on the same thread (SSSV, 2026-08-14) explicitly states *"Adaptive Pricing and Optimized Checkout Suite were both off at the time"* for their report — the general `date_paid`-guard-gap case. **#5691 contains two distinct root causes, not one** — both real, evidenced separately in FEATURES.md and TECHNICAL_SPEC.md.
- The same thread also shows a webhook endpoint pointed at a **stale staging domain** — direct evidence webhook misconfiguration, not gateway logic, is a live root cause on real stores.
- [woocommerce-gateway-stripe #5326](https://github.com/woocommerce/woocommerce-gateway-stripe/issues/5326) — Bancontact/APM charge-ID lookup race. Open.
- Independent evidence the workaround keeps getting reinvented: ["How I Traced 23 Missing WooCommerce Orders to a Broken Stripe Webhook"](https://wp-maintenance.pro/blog/woocommerce-stripe-webhook-failures-missing-orders/).

**Removed from the evidence base (didn't hold up under verification):**
- ~~#3154~~ — cited as open; actually closed in 2024 (fixed 3 days after filing).
- ~~#5601/#5670/#2679 as "Radar false-blocks"~~ — a feature request, its merged PR, and an unrelated issue, not bugs.

## What changed upstream

- **[#5268](https://github.com/woocommerce/woocommerce-gateway-stripe/issues/5268)** — the race letting WooCommerce's cleanup job cancel already-paid orders, originally "the worst bug found" — **fixed and shipped in 10.8.0**. The fix keys on `date_paid` being set; a follow-up hook (`wc_stripe_paid_order_cancellation_prevented`) fires when the guard engages.
- The residual failure: the guard only engages when `date_paid` is set. A merchant on 10.8.5 (SSSV, 2026-08-14) still had an order cancelled by the cleanup job because `date_paid` was never set at all — a narrower, different-shaped bug than #5268.
- **Corrected count (fixed in the second review, restated once more here because the stale phrasing survived into this document's own Open Questions section in an earlier draft):** of the originally-evidenced failure classes, **three are fixed and released** (#5268/10.8.0, #5140/10.9.0, #5030), **one has a merged fix not yet released** (#5699, sitting in an unreleased 11.0.0-dev changelog block), and **one more is an open, unmerged PR** (#5758). Not "four fixed, a fifth in flight" — that phrasing overstated shipped progress and should not recur in any summary of this document.

**Net effect on positioning:** re-anchor on "your webhooks are broken and you don't know it" (configuration/infrastructure failures, orphaned charges, stores on old gateway versions) — durable — rather than "the gateway has unresolved race conditions" — an actively closing gap.

## Why current options fail

| Approach | Why it doesn't solve this |
|---|---|
| Fix the webhook config | Prevents *new* drift, does nothing for orders already stuck, and a merchant with a broken endpoint often doesn't know it's broken |
| WooCommerce support forum advice | Manual, one-order-at-a-time |
| WooPayments Reconciliation Reports | Reconciles **balance/payout** movements (accounting reconciliation), not order-state. Nobody has solved order-state reconciliation for any processor, including WooCommerce's own. |
| Webhook delivery infra (Svix, Hookdeck, Convoy) | Solves delivery, not reconciliation |
| Accounting reconciliation tools (Rutter, Codat) | Books vs. bank statements, not order-table vs. Stripe state |
| Bespoke in-house scripts | Everyone rebuilds the same thing from scratch |

## Cost of the problem

~0.4-0.5% of orders on a real 10,000+ order/month store (direct merchant report). Real recurring at-risk revenue every month on a single mid-size store — the pricing-conversation anchor.

## Constraint that shapes the solution: data trust

A read-only restricted Stripe API key, self-hosted on the merchant's own WordPress site, never a hosted third-party service. Stripe's own restricted-keys documentation directly frames this: *"Third-party sharing... Safer: you hand out only the access the third party needs."* One honesty note: `GET /v1/payment_intents` and `GET /v1/charges` — the plugin's actual primary data sources, not just the diagnostic Events endpoint named in an earlier draft — return full object payloads including `billing_details`, `receipt_email`, and card `last4`. "Read-only" is not "minimal." State this plainly in onboarding copy.

## How disputed and under-review orders are handled — corrected in the third review, this is the highest-severity decision in the whole document

**The second-pass draft got this wrong in a way that would have created a permanent blind spot — the exact defect class it was trying to fix.** It excluded any order whose charge had `disputed === true` from detection entirely. But `charge.disputed` is a historical flag that never reverts to `false`, even after a merchant *wins* the dispute and the order is legitimately restored to normal status. An order excluded once under this rule stays excluded forever, even if it genuinely drifts later.

**Corrected principle, and the one to hold onto for any future exclusion rule in this document: evaluate dispute/review state fresh from Stripe on every detection run — never as a cached or permanent flag.**

- **Currently disputed** (dispute status ∈ `needs_response`/`warning_needs_response`/`under_review`/`warning_under_review`): still detected and shown, but at `severity: info` with no automated fix action available. This matches the original intent of this section (informational surfacing, no automated status change) — the second-pass rewrite drifted from this intent into a harder exclusion; FEATURES.md and TECHNICAL_SPEC.md are corrected to match this document's language, not the other way around.
- **Dispute lost**: for the wrongly-cancelled-paid check specifically, this is excluded outright — the gateway's own logic treats a lost dispute as a legitimate reason for `failed` status, so a cancelled/failed order with a lost dispute isn't wrongly cancelled at all. This exclusion is safe to be permanent because it reflects the dispute's genuinely final state, not a cache of a point-in-time flag.
- **Dispute won, or no dispute**: normal detection logic applies with no special-casing.
- The same "evaluate live, never cache" principle applies to Stripe Radar manual-review holds (an approved-and-released review must not stay excluded forever either) — see FEATURES.md for the corrected mechanism.

## Non-goals / explicit scope boundaries for v1

- Not a webhook delivery/retry service, not an accounting/bookkeeping tool, not multi-processor, not for WooPayments users, not a hosted third-party service, not a fix for Radar false-blocks or checkout-time UX bugs.
- **Not an automated action on orphaned charges or ambiguous ("needs review") drift candidates.** The third review found these two drift types had detection logic and a dashboard "Fix" button specified with no defined behavior behind them — there is no local order to call a fix primitive on. **Corrected scope:** v1 surfaces these as read-only informational rows (dismissible, not fixable). A manual "link this charge to an order" action is a plausible v2 feature, not v1.
- Not a tool with more than one scheduled reconciliation cadence in v1. The second-pass draft specified an hourly incremental pass plus a daily full resweep; the third review found the two cadences could race each other with no arbitration and recommended cutting to one. **v1 ships a single daily pass.** An hourly incremental is a plausible v1.1 addition once real usage data shows next-day detection latency (acceptable for a ~0.4-0.5%-of-orders bug) is actually a problem for merchants.
