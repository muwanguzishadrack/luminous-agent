# 90 — Roadmap

Four phases. Each ends with a demoable, testable state. Nothing in a later phase is a prerequisite
for an earlier one.

---

## Phase 0 — Skeleton (no credentials required)

Buildable today with zero external accounts.

| # | Deliverable |
|---|---|
| 0.1 | Laravel 13 + Inertia 3 + React 19 + TS 7 + Tailwind 4 + shadcn/ui scaffold, Herd-served |
| 0.2 | `docker/compose.dev.yml` running Postgres 17, Redis 8, Meilisearch, MinIO, Mailpit |
| 0.3 | Full migration set for all 56 tables (`02-data-model.md`), running clean from empty |
| 0.4 | Models, enums, DTOs, policies; `BelongsToTenant` + `TenantScope` + Postgres RLS |
| 0.5 | Auth, tenant/user/role plumbing, invitations |
| 0.6 | `GraphClient` and `IotecClient` with **fakes**, bound in tests |
| 0.7 | `DemoTenantSeeder` — 2 numbers, 5 users, 2k contacts, 30 conversations across all 4 ownership states, 12 templates, 3 campaigns, 40 orders, payments in every ioTec status, CTWA referrals |
| 0.8 | App shell: navigation, layouts, theme tokens, empty states |
| 0.9 | CI: Pint, PHPStan L8, Pest, `tsc --noEmit`, ESLint, build |
| 0.10 | Typescript type generation from PHP Data objects |

**Exit criteria:** migrations run clean; demo seeder produces a browsable UI; tenant-isolation test
suite green including the Octane leak test; CI green.

---

## Phase 1 — Sellable core

| Module | Scope |
|---|---|
| M0 | Embedded Signup v4, token vault, phone registration, webhook subscription, asset sync, payment-readiness gate, tenant lifecycle, RBAC, audit log, **webhook ingest reliability** |
| M1 | Full inbox: tri-source ingest, ownership state machine, CSW/FEP timers, all message types, media pipeline, assignment, labels, notes, canned replies, presence, search, realtime |
| M2 | Contacts, identifiers, **consent ledger + consent_states + send guard**, keyword handling, segments, import/export/merge, customer context object |
| M3 | Template CRUD, category cost warning, library, variable mapping, status/quality/category webhooks, multi-language groups |
| M4 | Campaign builder, audience snapshot, suppression, **rate-limited sender**, scheduling, budget caps, pre-flight, per-recipient reporting |
| M8 (partial) | Health monitoring only — quality, tier, alerts, watchdogs |

**Exit criteria** — all of these, measured:

1. A sandbox business completes onboarding unattended in under 10 minutes (M0 AC1).
2. 500 webhook deliveries/sec for 60s: zero drops, zero duplicates, p95 ack < 50ms (M1 AC1).
3. Replaying `webhook_deliveries` reproduces identical domain state (M1 AC2).
4. 10,000-recipient campaign: zero `131056`, zero duplicates, resumes after worker kill (M4 AC1–2).
5. Native opt-out blocks the next marketing send, proven at the Action level (M2 AC1).
6. Every message type renders without error in a browser test (M1 AC6).
7. RED quality auto-pauses campaigns (M8 AC6).

---

## Phase 2 — Meta Business Agent

The differentiator. **Ship the customer-context Connector with the agent, never after.**

| Scope |
|---|
| Eligibility gating; ToS dependency surfaced as blocked-on-client |
| BISU token as a second credential type |
| Agent onboarding, settings, skills, allowlist canary |
| Knowledge management: business info, FAQs (bulk import), websites (SSRF-safe crawl), files |
| **Read connector** `customer-context` — allowlisted, cached, p95 < 800ms, fail-soft |
| **Write connectors** — log_intent, create_ticket, check_stock, create_order, request_payment, book_slot, escalate |
| Connector token management, call log with latency |
| Thread control: take over / hand back, guarded, audited |
| Agent Event triggers, rate-limited and individually switchable |
| Agent Test + Agent Eval; containment rate and cost per resolution |
| Token spend meter (estimate-labelled) and per-tenant hard cap |
| **Empirical answers to U1–U6** recorded in `92-decisions.md` |

**Exit criteria:** M5 AC1–9. In particular: the allowlist canary works, `customer-context` never
returns an undeclared field, and U1–U6 are answered rather than assumed.

---

## Phase 3 — Money

| Module | Scope |
|---|---|
| M6 | Catalog sync, product messages, orders, ioTec collections (MoMo + card), disbursements incl. maker–checker states, **mandatory re-fetch on callback**, **mandatory poller**, fees, daily reconciliation, in-conversation payment UX |
| M8 | Dual-meter cost engine, rate cards, daily reconcile, **Oct 1 impact projector**, margin billing + wallet, AI vs human economics, operational reports, all analytics snapshots |

**Exit criteria:** M6 AC1–11 and M8 AC1–9. Specifically:

1. `SentToVendor` never marks an order paid.
2. A callback claiming `Success` while the API says `Pending` does not credit the order.
3. Duplicated callbacks credit exactly once.
4. `usage_meters` reconciles to `pricing_analytics` within 1%.
5. Zero wallet balance blocks campaign starts but never blocks inbound processing.
6. The Oct 1 projector produces a real per-tenant delta.

> **Timing note:** the Oct 1, 2026 pricing change lands during or just after this phase. The projector
> should ship as early in Phase 3 as possible — ideally by mid-September, once Meta publishes the
> rates (due by Sept 1).

---

## Phase 4 — Growth & polish

| Scope |
|---|
| M7: CTWA referral capture, 72h free-window tracking, Conversions API, QR/tracked links, welcome-sequence coordination, closed-loop ROAS |
| M4 remainder: MM API routing, A/B testing, click tracking, drip sequences |
| M0 remainder: **Coexistence** (contacts + history import, handset mirroring), white-label, public API + outbound webhooks |
| M8 remainder: scheduled digests, exports |

**Coexistence is deliberately last** despite being the strongest wedge, because the 24-hour one-shot
sync is unforgiving and requires the ingest pipeline to be proven under real load first.

**Exit criteria:** M7 AC1–7; Coexistence imports contacts and history and mirrors handset messages
within 5s (M0 AC3); declined history sharing completes gracefully (M0 AC4).

---

## Deliberately deferred

| Item | Revisit when |
|---|---|
| WhatsApp Calling API / SIP | After Phase 4; large scope, clear upsell |
| Groups API | On customer demand |
| Fallback LLM agent for non-MBA verticals | **Not needed** — pipeline confirmed all within MBA's verticals (D-018). Reopen only if the mix changes. |
| Multi-Partner Solution with a Solution Partner | If clients balk at attaching their own payment method |
| Marketing API ingestion for ad spend | After manual ROAS proves useful |
| Instagram / Messenger channels | Not in this product |

---

## Cross-phase invariants

Every phase must preserve these. A PR that breaks one does not merge.

1. No dropped webhooks.
2. No cross-tenant read, at any layer.
3. Consent enforced inside the send Action.
4. `usage_meters`, `consents`, `payment_events`, `message_events`, `wallet_entries` are append-only.
5. Every external-API fact in code is backed by a line in `reference/`.
6. No dashboard calls the Graph API in a request path.
