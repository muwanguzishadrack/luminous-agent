# M8 — Analytics, Billing & Health

**Goal:** every tenant knows what messaging costs, what it earns, and whether their number is healthy
— and we bill correctly on two different meters.

Tables: `usage_meters`, `wallet_entries`, `analytics_snapshots`, `health_events`.

---

## 1. Meta analytics ingestion

```
GET /{waba-id}?fields=<field>.<filters>
```

| Field | Granularity | Lookback | Pull cadence |
|---|---|---|---|
| `analytics` | HALF_HOUR / DAY / MONTH | 1 year | hourly |
| `conversation_analytics` | HALF_HOUR / DAILY / MONTHLY | 1 year | hourly |
| `pricing_analytics` | HALF_HOUR / DAILY / MONTHLY | 1 year | hourly |
| `template_analytics` | DAILY | **90 days** | daily |
| `template_group_analytics` | DAILY | **90 days** | daily |

`pricing_analytics` dimensions we use: `COUNTRY`, `PHONE`, `PRICING_CATEGORY`, `PRICING_TYPE`, `TIER`.
`metric_types`: `COST`, `VOLUME`.

**Because the lookback is capped at 1 year (90 days for templates), we snapshot daily into
`analytics_snapshots` and never rely on Meta for historical reporting.** Snapshots are keyed on
`(waba, field, granularity, start, end, dimensions_hash)` so re-pulls are idempotent.

Handle a missing `COST` gracefully — Meta omits it for WABAs on a Solution Partner credit line. As a
Tech Provider our tenants pay Meta directly so it should be present, but never assume.

---

## 2. The dual-meter cost engine

This is the core of the module. **Two different units of account:**

| Meter | Unit | Source |
|---|---|---|
| `template_message` | messages | `pricing.category` on status webhooks, reconciled against `pricing_analytics` |
| `service_message` | messages | `pricing.category = "service"` (chargeable from **Oct 1, 2026**) |
| `mba_tokens` | **tokens** | Meta's MBA analytics — **see the gap below** |
| `payment_fee` | currency | ioTec `totalTransactionCharge` (M6) |
| `platform_seat` | seats | our own subscription |

Two-stage cost resolution:

1. **Immediate (webhook):** on a `sent`/`delivered` status, write a `usage_meters` row with
   `pricing_category`, recipient `country`, and our best-known rate. Marked `source = 'webhook'`.
2. **Reconcile (daily):** compare against `pricing_analytics` for the same day and correct. Marked
   `source = 'pricing_analytics'`. Corrections are **new rows**, never updates — `usage_meters` is
   append-only.

Meta's own rate card lives in the versioned `rate_cards` table (`02-data-model.md` §11) keyed by
`(effective_from, region, category, tier)`. Rates change only on Jan 1 / Apr 1 / Jul 1 / Oct 1.

### ⚠️ The MBA token gap

Meta stated MBA **analytics and webhook payload details** would be published *before charging began on
August 1, 2026*, and as of writing they were still listed as forthcoming.

Until confirmed:
- Estimate tokens as `mba_message_count × config('pricing.mba.est_tokens_per_message')` (22,500).
- Cost = `tokens / 1_000_000 × 2.00 USD`, converted to the tenant currency.
- **Label every MBA figure in the UI as an estimate**, with a tooltip explaining why.
- A task in `91-prerequisites.md` re-checks the docs; switch to real data the moment it exists and
  backfill.

---

## 3. The October 1, 2026 impact projector

A dedicated, tenant-facing screen. From Oct 1:

- **all** non-template messages become chargeable, including human agent replies that are free today
- **service messages** are priced at utility/authentication market rates, with **no volume tiers**
- **utility templates inside the CSW stop being free**

For each tenant we compute, from the last 30 days of actual traffic:

| Line | Today | From Oct 1 |
|---|---|---|
| Marketing templates | charged | charged (unchanged) |
| Utility templates outside CSW | charged | charged (unchanged) |
| Utility templates inside CSW | **free** | **charged** |
| Human agent replies (service) | **free** | **charged, no volume tiers** |
| MBA replies | charged per token | charged per token (unchanged) |
| **Total** | X | **Y** |

Then: *"Your bill would rise by UGX Z (N%). Here is where."* Plus recommendations — shift resolvable
questions to MBA, tighten FAQs, reduce redundant utility sends.

Meta publishes the Oct 1 rates by **Sept 1, 2026**; until then the projector uses current
utility/authentication rates as a proxy and says so.

Shipping this **before** October is the difference between a client being warned and a client being
surprised.

---

## 4. Margin billing

| Component | Detail |
|---|---|
| Wallet | `wallet_entries` append-only; balance = `SUM(amount_minor)`, checkpoint cached |
| Markup | Per-plan percentage or fixed uplift on Meta cost, stored per `usage_meters` row so historical invoices are reproducible |
| Invoicing | Monthly: platform subscription + marked-up messaging + MBA tokens + payment fees |
| Alerts | 50% / 80% / 100% of wallet balance; email + in-app |
| Hard stop | At zero balance, refuse campaign starts and (configurable) agent replies. **Never block inbound receipt.** |
| Currency | Tenant billing currency; FX rate snapshotted per invoice, never recomputed |

Rule: **never block inbound message processing for a billing reason.** Losing a customer's message
because a client's wallet ran dry is unacceptable and would breach the "never drop a webhook" invariant.

---

## 5. AI vs human economics

The report that justifies the subscription.

| Metric | Definition |
|---|---|
| **Containment rate** | conversations resolved entirely with `state = ai` ÷ AI-handled conversations |
| Escalation rate | AI → human transitions ÷ AI-handled |
| **Cost per resolution — AI** | MBA token cost ÷ contained conversations |
| **Cost per resolution — human** | (agent minutes × loaded rate) + service-message cost ÷ human-resolved |
| Deflection value | human cost per resolution × contained conversations |
| **Expensive intents** | intents ranked by token spend → "add these 5 as FAQs and save UGX N/month" |

That last row turns a cost report into a product recommendation and is directly actionable.

---

## 6. Operational reporting

| Report | Contents |
|---|---|
| Agent performance | FRT, resolution time, volume, CSAT (via template survey), by agent and team |
| SLA compliance | Breaches by view and time of day |
| Campaign ROI | Delivered → read → clicked → replied → ordered → revenue, net of send cost (M4/M7) |
| Closed-loop ROAS | The M7 §6 table |
| Commerce | Orders, conversion rate, AOV, payment success rate by channel (MTN vs Airtel vs card), fee load |
| Payment funnel | Requested → SentToVendor → Success/Failed, with drop-off by vendor |
| Inbox volume | By hour and day, for staffing decisions |

All reports are exportable (CSV) and schedulable (email digest).

---

## 7. Health & quality monitoring

`health_events`, fed by webhooks:

| Webhook | Severity | Action |
|---|---|---|
| `phone_number_quality_update` → YELLOW | warning | Notify; show on dashboard |
| `phone_number_quality_update` → RED | **critical** | Auto-pause running campaigns (M4); notify owner |
| Messaging tier change | info/warning | Update `phone_numbers.messaging_limit_tier`; recompute campaign ETAs |
| `business_capability_update` | info | Update limits |
| `account_review_update` | varies | Surface decision |
| `account_alerts` | varies | Surface verbatim |
| `payment_configuration_update` | warning | Payment-readiness banner (M0) |
| `template_*_update` | varies | M3 |
| Error `368` (policy block) | **critical** | Halt all sends on that number immediately |

Plus our own watchdogs:

| Watchdog | Trigger |
|---|---|
| Graph rate-limit headroom | Usage > 70% of the hourly WABA budget → back off and warn |
| Webhook backlog | `webhook_deliveries` pending > 1,000 or oldest > 60s → page |
| DLQ non-empty | Any failed delivery → alert |
| Send failure rate | > 5% over 15 minutes on a number |
| Payment stale rate | Payments unresolved > 24h |
| Credential breaker | Any `meta_credentials` with `failure_count ≥ 3` |
| ioTec callback silence | No callback received in 24h while collections were created → check portal config |

The Meta DevTools API-usage surface (rate limits, call volume, deprecations) is also worth polling to
watch our **app-level** headroom across all tenants, not just per-WABA.

---

## 8. UI surface

| Route | Screen |
|---|---|
| `/analytics` | Overview: volume, cost, revenue, containment, quality — the daily-driver dashboard |
| `/analytics/costs` | Dual-meter breakdown by category, country, number, template |
| `/analytics/projector` | The Oct 1 impact projector (§3) |
| `/analytics/ai` | AI vs human economics (§5) |
| `/analytics/agents` | Agent and SLA performance |
| `/analytics/campaigns` | Campaign ROI |
| `/analytics/commerce` | Orders, payments, fees, funnel |
| `/analytics/health` | Health timeline, unacknowledged events, watchdog status |
| `/settings/billing` | Wallet, invoices, plan, markup (admin-visible), alerts |

Dashboards read `analytics_snapshots` and `usage_meters`, **never** the Graph API live. A dashboard
that hammers Meta will consume the tenant's own rate-limit budget and slow their sends.

---

## 9. Edge cases

| Case | Handling |
|---|---|
| `pricing_analytics` disagrees with our webhook estimate | Append a correcting row; show both in an audit view |
| Analytics older than the lookback | Serve from snapshots; label the source |
| Meta omits `COST` | Fall back to rate-card computation; flag as computed |
| MBA analytics ship mid-flight | Backfill via append: a `basis = correction` row reversing each estimate plus a `basis = actual` row — never re-mark rows in place (D-012) |
| Rate card changes on a quarter boundary | Rows keep the rate effective at `occurred_on` — never retroactively repriced |
| Tenant changes billing currency | New FX snapshot; historical invoices unchanged |
| Wallet goes negative from a late reconciliation | Allowed; flagged for collection. Never rewrite history. |
| Clock skew between Meta's day and ours | All day boundaries use the **WABA timezone**, stored on `waba_accounts` |
| Very large export | Queued, emailed as a signed link, expires in 24h |

---

## 10. Acceptance criteria

1. Snapshots run daily for all five analytics fields and are idempotent on re-run.
2. `usage_meters` totals for a day reconcile to `pricing_analytics` within 1% after the daily job.
3. A cost correction appears as a new row; no `usage_meters` row is ever updated or deleted.
4. MBA figures are visibly labelled as estimates and switch to actuals when real data exists.
5. The Oct 1 projector produces a per-tenant delta from real 30-day traffic and states its assumptions.
6. A RED quality event pauses running campaigns within one webhook cycle and raises a critical
   `health_events` row.
7. Zero wallet balance blocks campaign starts but **does not** block inbound webhook processing —
   asserted by a test.
8. Wallet balance from `SUM(amount_minor)` matches the cached checkpoint across 10,000 seeded entries.
9. Every dashboard renders from local tables with no Graph API call in the request path — asserted by
   a test that fails if `GraphClient` is invoked during a dashboard request.
