# Problem Statement — WooCommerce Stripe Order Reconciliation

Working name: **woo-stripe-reconcile** (placeholder, not final)

> Revised 2026-08-29 after an independent technical review (see review notes referenced inline) verified the original evidence directly against GitHub, Stripe's docs, and the gateway plugin's own source. Corrections from that review are folded in below rather than kept as a separate errata — anything struck from the original evidence base is explained, not hidden.

## One-line problem

WooCommerce stores using the third-party Stripe gateway plugin (`woocommerce-gateway-stripe`) accumulate orders where Stripe's payment record and the store's order status disagree — most durably because of misconfigured or silently-broken webhook endpoints, and in a narrower residual case because an order never gets marked paid at all and so falls outside the gateway's own newer safety net (see "What changed upstream" below). The only fix today is a manual, ad hoc check against the Stripe dashboard, re-invented from scratch by whoever hits it.

**This is a deliberately narrower claim than the original draft.** The original framing leaned on "the gateway has unresolved race conditions" as the core pitch. Independent verification found the flagship example of that race condition was fixed and shipped upstream three months before this document was first written. The durable part of the problem is webhook infrastructure/configuration failures and a narrower class of payment-never-marked-paid races — not the gateway being broadly race-prone. See "What changed upstream" for why this matters for positioning.

## Who has this problem

- **Primary persona:** the person who administers a WooCommerce store on the standard Stripe gateway plugin — often the store owner themselves on smaller stores, or a maintaining developer/agency on larger ones.
- **Population size signal:** `woocommerce-gateway-stripe` has **700,000+ active installations** (confirmed directly on wordpress.org), against ~4.1-6.2M live WooCommerce stores total — roughly 15-17% of the ecosystem.
- **Not in scope (for now):** WooPayments users — see the corrected note below on why this exclusion is weaker evidence than originally claimed, though the population itself is still out of scope for other reasons (different plugin, different codebase). Shopify/other platforms — different distribution model, different problem shape.

## Evidence this is real and recurring

**Verified, still-relevant evidence:**

- [woocommerce-gateway-stripe #5691](https://github.com/woocommerce/woocommerce-gateway-stripe/issues/5691) — "Stripe payments succeeding but WooCommerce orders stuck in 'Pending'/cancelled — webhook returns 204 but order never updates." **Open.** Note: this was originally filed against WooCommerce core as `woocommerce/woocommerce#66175` and transferred by a maintainer — it is **one report, not two independent ones** as the original draft implied by citing both.
- A comment on #5691 (2026-07-30, from a real merchant) gives the first quantified frequency data we have: **"we intake 10,000+ orders a month, but only about 40-50 orders a month are running into this... issue"** — a **0.4-0.5% incidence rate**. At even a modest AOV this is real recurring at-risk revenue per affected store, and it's a far better pricing anchor than anything in the original draft.
- The same thread shows the reporter's webhook endpoint was pointed at a **stale staging domain** — direct evidence that webhook misconfiguration, not gateway logic, is a live root cause on real stores.
- [woocommerce-gateway-stripe #5326](https://github.com/woocommerce/woocommerce-gateway-stripe/issues/5326) — Bancontact/APM charges use a `py_` prefix instead of `ch_`; when a webhook races the redirect-return handler, the webhook can't find the order by charge ID. Open, active.
- [woocommerce-gateway-stripe #5362](https://github.com/woocommerce/woocommerce-gateway-stripe/issues/5362) — a 3DS-pending intent that later succeeds after checkout completed via a different gateway gets silently dropped; customer charged twice, merchant never told. Closed, but the failure class (silent drop on conflict) remains instructive.
- Independent evidence the workaround keeps getting reinvented: ["How I Traced 23 Missing WooCommerce Orders to a Broken Stripe Webhook"](https://wp-maintenance.pro/blog/woocommerce-stripe-webhook-failures-missing-orders/) and multiple other blog posts converge on the same manual PaymentIntent-status-check-and-correct pattern, with no shared tool automating it.

**Removed from the evidence base (verification found these don't hold up):**

- ~~woocommerce-gateway-stripe #3154~~ — originally cited as "open, unresolved." It was **closed in 2024**, fixed 3 days after filing in release 8.3.1, and was a regression from an unrelated PR (#3078), not a standing structural problem. Dropped as current evidence.
- ~~#5601 / #5670 / #2679 as "Stripe Radar false-blocks"~~ — #5601 was a feature request and #5670 the merged PR implementing a new hook for it (not a bug); #2679 was an unrelated on-hold/processing status flip. Only [#5030](https://github.com/woocommerce/woocommerce-gateway-stripe/issues/5030) (an AVS rule blocking Pay-for-Order payments) actually fit this category, and it's fixed.

## What changed upstream (verified 2026-08-29, must inform positioning)

The independent review found the plugin's fix velocity on this exact problem area has been high in the last four months, which meaningfully changes the durability of parts of the original pitch:

- **[#5268](https://github.com/woocommerce/woocommerce-gateway-stripe/issues/5268)** — the race that let WooCommerce's own unpaid-order cleanup job cancel already-paid orders, originally described as "the worst bug found" — **was closed and shipped in release 10.8.0**, three months before this document was drafted. The fix (PR #5486) makes the cleanup job bail whenever `date_paid` is set on the order, "regardless of the underlying race." A follow-up (#5496, also 10.8.0) adds a merchant-facing order note **and a new hook, `wc_stripe_paid_order_cancellation_prevented`**, fired whenever the guard trips — a purpose-built real-time signal for exactly this condition that didn't exist when the original research passes ran.
- The residual failure is narrower and different in shape: the new guard keys specifically on `date_paid`. A report on #5691 from a merchant running 10.8.5 (after the fix shipped) still had an order cancelled by the cleanup job — because in that flow **the order never got marked paid at all**, so `date_paid` was empty and the guard never engaged. That's the real remaining version of this bug, not the general race originally described.
- [#5140](https://github.com/woocommerce/woocommerce-gateway-stripe/issues/5140) (multi-refund ID overwrite) and [#5699](https://github.com/woocommerce/woocommerce-gateway-stripe/issues/5699) (missing capture flag on async payments) are both also fixed, in 10.9.0 and 10.8.x respectively.
- [#5758](https://github.com/woocommerce/woocommerce-gateway-stripe/issues/5758) is an **open pull request** (not, as originally characterized, "the maintainers' own active tracking issue") that closes three more race gaps, including webhooks that get acked 200 and silently dropped under lock contention. If merged, this further shrinks the race-condition-based failure surface.

**Revised again after a second review pass (2026-08-29):** the #5699 fix count above was wrong. #5699 was closed 2026-08-24, but its fix only appears in the unreleased `11.0.0-dev` changelog block — it has **not** shipped in any released version yet. Corrected count: **three** of the original ten evidenced failure classes are fixed and released (#5268/10.8.0, #5140/10.9.0, and the AVS Radar issue #5030), **one has a merged fix not yet released** (#5699), and **one more is an open, unmerged PR** (#5758). This doesn't change the positioning conclusion, but "four fixed, a fifth in flight" overstated how much has actually shipped.

**Net effect on positioning:** upstream's fix velocity on this exact problem area is real and ongoing. This doesn't kill the product, but it means **the pitch should be re-anchored on "your webhooks are broken and you don't know it" (configuration/infrastructure failures, orphaned charges, stores not yet upgraded past old gateway versions) — which is durable — rather than "the gateway has unresolved race conditions," which upstream is actively closing.**

**Additional correction from the second review:** the #5691 thread that anchors much of this document's evidence actually contains **two distinct root causes**, not one homogeneous failure. The original reporter's case (claudchan-alyka) was traced to the Adaptive Pricing / Optimized Checkout Session flow specifically — a different code path from the general race-condition family, and one with its own metadata gaps (see FEATURES.md #8 and TECHNICAL_SPEC.md's Checkout Session handling). A later comment on the same thread (SSSV, 2026-08-14) explicitly confirms *"Adaptive Pricing and Optimized Checkout Suite were both off at the time"* for their report — i.e. the general `date_paid`-guard-gap case. Both are real; they are not the same bug, and citing the thread as one piece of evidence understated that.

## Why current options fail

| Approach | Why it doesn't solve this |
|---|---|
| Fix the webhook config (URL/SSL/firewall) | Prevents *new* drift going forward, does nothing for orders already stuck, and a merchant with a broken endpoint typically doesn't know it's broken until a customer complains |
| WooCommerce support forum advice | Manual, one-order-at-a-time, no ongoing detection |
| WooPayments Reconciliation Reports | **Corrected framing:** this was originally cited as proof "WooCommerce solved reconciliation, but only for their own processor." Verification found this is inaccurate — WooPayments Reconciliation Reports reconcile **balance/payout movements** (starting balance → charges → fees → refunds → payouts → ending balance), which is accounting reconciliation, the same category this document already excludes as out-of-scope (see below). **Nobody has solved order-state reconciliation for any processor, including WooCommerce's own.** That's a stronger and more accurate claim than the original. |
| Webhook delivery infra (Svix, Hookdeck, Convoy) | Solves *delivery* reliability, not *reconciliation* — doesn't know what "order state" means for a given store |
| Accounting reconciliation tools (Rutter, Codat) | Operate one layer up — books vs. bank statements, not order-table vs. Stripe state |
| Bespoke in-house scripts | Exists, but every store/agency builds and maintains their own from scratch (see evidence above) |

## Cost of the problem

- **Now quantified, not just qualitative:** one real merchant report gives ~0.4-0.5% of orders affected on a 10,000+ order/month store. At a modest AOV that's real recurring at-risk revenue every month on a single mid-size store — use this as the pricing-conversation anchor instead of speculation.
- Support burden: customers who paid but see no confirmation open support tickets.
- Engineering time: every agency/dev hitting this re-derives the same PaymentIntent-status-check logic.

## Constraint that shapes the solution: data trust

Store owners are (rightly) wary of handing a third party read access to payment data. This rules out a hosted SaaS as the default delivery model. It does not rule out the problem — it rules out one *architecture* for solving it.

**Corrected supporting evidence:** the original draft cited **Synder** (a Stripe App Marketplace listing) as precedent that "read-only third-party access to Stripe data" is an accepted pattern. Verification found this precedent doesn't actually transfer: Synder is an *accounting* integration (Stripe → QuickBooks/Xero), the exact category already excluded above, and Stripe Apps authenticate via a different mechanism (an app-install-issued key) than a merchant pasting a restricted key into a plugin. Drop Synder as precedent. The stronger, directly-applicable evidence is Stripe's own restricted-keys documentation, which explicitly frames this exact use case: *"Third-party sharing... Safer: you hand out only the access the third party needs."* That's Stripe endorsing the mechanism itself, which is what actually matters here.

**One honesty note the original draft missed:** `GET /v1/events` and other read endpoints return **full object payloads**, not just status fields — for payment-related objects this can include customer email, billing address, and card brand/last 4 digits. "Read-only" is not the same as "minimal data." This should be stated plainly in the plugin's own onboarding copy rather than left for a careful merchant to discover on their own.

## Non-goals / explicit scope boundaries for v1

- Not a webhook delivery/retry service (that's Svix/Hookdeck's job).
- Not an accounting/bookkeeping reconciliation tool.
- Not multi-payment-processor (PayPal, Adyen) in v1 — Stripe only.
- Not for WooPayments users — different plugin/codebase entirely.
- Not a hosted service that stores or transmits the merchant's financial data to a third-party server by default.
- Not a fix for Stripe Radar false-blocks or checkout-time UX bugs — those are the gateway plugin's own bugs to fix, not reconcilable after the fact.
- **Not a tool that acts on disputed orders.** The second review pass found that an active dispute puts an order on-hold with a still-`succeeded` PaymentIntent — a state indistinguishable from a stuck-pending order under the original detection rules. v1 explicitly excludes any order with an open dispute from every check's fix path (detection can still surface it as informational, but no automated status change happens) — see FEATURES.md's corrected exclusion list. This is the single highest-severity false-positive risk found across two review passes and is treated as a hard exclusion, not a tuning parameter.

## Open questions — findings (2026-08-29, revised after independent review)

1. **Addressable population — large, confirmed, unchanged by the review.** 700,000+ active installs, ~15-17% of live WooCommerce stores.

2. **Distribution model — freemium WP.org/marketplace, $39-199/site/year is the going rate.** Unchanged by the review. **New consideration from the review:** if distributing on WooCommerce.com's marketplace with recurring pricing, their SaaS Billing API must be implemented before launch on that channel specifically.

3. **Bug frequency — now answered, was previously the one open item.** ~0.4-0.5% of orders on a real 10,000+ order/month store, per a direct merchant report on #5691. This resolves what was previously flagged as needing direct outreach — the evidence was already sitting in a GitHub comment on evidence we'd already cited.

4. **Shelf life — durable for the webhook-misconfiguration and orphaned-charge failure classes; shrinking for the race-condition failure classes specifically.** WooPayments' country/currency limitations (38 countries vs. Stripe's 47+) still mean merchants outside its footprint are structurally stuck on the third-party gateway — that part is unchanged. But the review found the gateway's *own bug-fix velocity* on race conditions is high right now (four fixes in four months, a fifth in flight) — meaning the product's positioning needs to lean on the durable failure classes (config/infra, orphaned charges, stores lagging on updates) rather than "the gateway has race conditions," which is an actively closing gap.

**Net read, revised:** the problem is still real and worth building for, but narrower and differently shaped than first drafted. The addressable population and pricing model are solid; the bug-frequency question is answered with real data; the positioning needs to shift toward webhook health/orphaned-charge detection as the durable core, with the narrower "never-marked-paid" race as a secondary, shrinking-but-not-gone check.
