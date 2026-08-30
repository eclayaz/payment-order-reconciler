# WooCommerce Stripe Reconcile

A self-hosted WordPress plugin that reconciles Stripe payment state against WooCommerce order state — for stores using the third-party **WooCommerce Stripe Payment Gateway** plugin (not WooPayments).

It catches the three ways that gateway's webhook handling can silently drift from reality:

- **Stuck pending** — the customer paid, Stripe shows `succeeded`, but the order is still sitting in `Pending`/`On hold` because a webhook never arrived or failed to process.
- **Wrongly cancelled / failed** — the order got auto-cancelled (or marked `Failed`) by WooCommerce's own cleanup job even though Stripe shows the charge was captured.
- **Orphaned charges** — Stripe has a succeeded PaymentIntent with no matching local order at all.

You find out from a dashboard, not from an angry customer.

## Why this exists

Built from problem-first research into a real, evidenced gap in the standard Stripe gateway plugin's webhook reliability — not a hypothetical. See [`PROBLEM.md`](PROBLEM.md) for the full evidence base.

## Trust model (read this before installing)

This is the part that isn't negotiable:

- **Read-only, restricted Stripe API key only.** The plugin never has permission to charge, refund, capture, or move money in any way. It can only ever `GET` data.
- **Self-hosted.** No hosted service, no third-party server. The only two parties that ever see your data are Stripe's own API and your own WordPress site.
- **The only mutation this plugin ever makes** is calling WooCommerce's own `$order->payment_complete()` on a *local* order, when you explicitly click Fix (or select it in a bulk action) — and only after re-verifying the current Stripe state live, immediately before acting, never off a cached snapshot.

If a restricted key with write access to anything ever seems necessary, that's a sign something is designed wrong — not a reason to loosen this.

## How it works

- **Pass A** (daily, plus manual "Run now"): lists every Stripe PaymentIntent from the last 30 days in bulk, resolves each to a local order (via metadata, order key, or intent ID — never a per-order API call), and runs two state-comparison checks with exclusion lists derived from real gateway behavior (a fresh mid-3DS window, live dispute/Radar-review status, refund state, etc.).
- **Pass B**: a diagnostic-only check for undelivered webhook events and whether your webhook endpoint is even reachable — surfaced on the dashboard, never itself a source of drift.
- **Real-time listener**: WooCommerce/gateway hooks auto-resolve a drift row the moment an order legitimately completes, without waiting for the next scheduled pass.
- **The dashboard** (WooCommerce → Stripe Reconciliation): Open / Fixed / Dismissed views, with one-click Fix/Dismiss per row, or select multiple rows and bulk Fix/Dismiss for a backlog. Every Fix — single or bulk — re-fetches the PaymentIntent from Stripe and re-runs the same detection check immediately before acting, so it can never act on stale data.

## Requirements

- WordPress 6.8+, PHP 7.4+
- WooCommerce 8.2+
- The [WooCommerce Stripe Payment Gateway](https://wordpress.org/plugins/woocommerce-gateway-stripe/) plugin, active and configured
- A Stripe account (test or live)

## Installation

This isn't published on WordPress.org (it's a private project) — install it manually:

1. Copy (or clone) the `woo-stripe-reconcile/` directory into your site's `wp-content/plugins/`.
2. Activate WooCommerce and the WooCommerce Stripe Payment Gateway first — the plugin refuses to activate (and deactivates itself if either is later turned off) without both.
3. Activate **WooCommerce Stripe Reconcile**.

## Setup

1. In Stripe: **Developers → API keys → Create restricted key**. Set exactly these seven resources to **Read**, everything else to **None**:
   - PaymentIntents
   - Charges
   - Checkout Sessions
   - Events
   - Webhook Endpoints
   - Disputes
   - Reviews
2. In WordPress: **WooCommerce → Stripe Reconcile**, paste the key, save. The settings screen validates all seven scopes and shows which (if any) failed.
3. Alternatively, define `WSR_STRIPE_RESTRICTED_KEY` in `wp-config.php` to lock the key via a file constant instead of storing it as an option — the settings screen will show it as locked and refuse to accept a pasted key over it.
4. The key is encrypted at rest either way (AES-256-GCM, derived from the site's own auth salt) if stored as an option — see `WSR_Encryption`'s class comment for the exact threat model this does and doesn't cover.
5. The first scheduled run happens an hour after activation (so you have time to configure the key first); use "Run reconciliation now" on the settings screen to check sooner.

## Using the dashboard

**WooCommerce → Stripe Reconciliation:**

- **Open** — currently-detected drift, actionable.
- **Fixed** — resolved, either by you clicking Fix, a bulk Fix, or the plugin noticing on its own that the order became healthy again.
- **Dismissed** — you decided this one doesn't need action; it won't reappear unless it genuinely recurs later.

Each row shows the local status → Stripe status, severity, and a direct link to the PaymentIntent in the Stripe dashboard. A currently-disputed or under-Radar-review charge is downgraded to `info` severity with no Fix button — by design, no automated action is taken while that's unresolved.

**Bulk actions**: select multiple rows on the Open view and choose Fix or Dismiss from the dropdown. Each selected row goes through the identical live re-verification a single row's own Fix/Dismiss link uses.

## Architecture

| Class | Responsibility |
|---|---|
| `WSR_Reconciler` | Pass A (detection) + Pass B (webhook-health diagnostic) |
| `WSR_Fixer` | The only destructive action; live re-verification before every fix |
| `WSR_Stripe_Client` | Thin `wp_remote_get()` wrapper for the Stripe API + scope validation |
| `WSR_Scheduler` | Daily ActionScheduler job, atomic run lock |
| `WSR_Hook_Listener` | Real-time auto-resolve via WooCommerce/gateway hooks |
| `WSR_Admin_Dashboard` / `WSR_Drift_List_Table` | The dashboard UI, including bulk actions |
| `WSR_Settings` | API key entry, scope validation, coverage status |
| `WSR_Encryption` | Encryption-at-rest for the stored API key |
| `WSR_Event_Ledger` | Webhook-event dedup for the real-time listener |
| `WSR_Email_Alerts` | One email per run when new high/critical drift appears |
| `WSR_Audit_Log` | WP Privacy exporter/eraser hooks |
| `WSR_Activator` | Table creation/schema versioning on activation |

The full design reasoning — including three rounds of independent spec review and the evidence behind every detection rule — lives in [`PROBLEM.md`](PROBLEM.md), [`FEATURES.md`](FEATURES.md), and [`TECHNICAL_SPEC.md`](TECHNICAL_SPEC.md).

## Development

A Docker-based dev/test environment via [`@wordpress/env`](https://www.npmjs.com/package/@wordpress/env):

```bash
npm install
npm run env:start      # boots WordPress + WooCommerce + the Stripe gateway + this plugin
```

Site: `http://localhost:8888` — Admin: `http://localhost:8888/wp-admin` (`admin` / `password`). You'll still need to paste real Stripe **test-mode** keys into WooCommerce → Settings → Payments → Stripe yourself — never commit real or test secret keys to this repo.

```bash
npm run env:stop
npm run env:destroy    # wipes the environment entirely
npm run env:cli -- <command>   # run any wp-cli command against the dev site
```

### Running the test suite

```bash
npx wp-env run tests-cli -- bash -c "cd wp-content/plugins/woo-stripe-reconcile && vendor/bin/phpunit"
```

145 tests across detection logic, the fixer's live-recheck flow, the scheduler's lock, encryption, and every documented bug fix from the independent review round. `tests/TestCase.php` has the shared fixtures (`make_order()`, `base_pi()`, etc.) if you're adding more.

A pre-PHPUnit, plain eval-file smoke test also still exists at `dev-tests/manual-test-pass-a-logic.php`, runnable via `wp eval-file` against the `cli` container — kept as a real-environment sanity check independent of the isolated PHPUnit test database.

## License

GPLv2 or later — see [`woo-stripe-reconcile/readme.txt`](woo-stripe-reconcile/readme.txt) (the WordPress.org-format plugin readme, used for the in-plugin external-service disclosure and changelog).
