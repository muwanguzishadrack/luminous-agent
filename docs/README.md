# Luminous Commerce — WhatsApp CRM

Reference documentation for a multi-tenant WhatsApp CRM built on the **WhatsApp Cloud API** as a
**Meta Tech Provider**, with **Meta Business Agent** for AI, and **ioTec Pay** for Uganda mobile
money / card / bank payments.

> **Status: pre-implementation.** Nothing in `app/`, `resources/`, or `database/` exists yet.
> These documents are the agreed plan and the reference we implement against.

## How to use these docs

1. Read `00-product-brief.md` for scope and non-goals.
2. Read `01-architecture.md` and `02-data-model.md` before writing any code.
3. Set your machine up with `03-local-development.md`.
4. When implementing a module, open its file in `modules/` — each has data model deltas,
   endpoints, jobs, UI surface, and acceptance criteria.
5. `reference/` holds **external API facts only** — verified against live docs/specs.
   If reality disagrees with `reference/`, reality wins: fix the doc in the same PR.

## Index

### Foundations
| Doc | Contents |
|---|---|
| [00-product-brief.md](00-product-brief.md) | What we are building, who for, scope, non-goals |
| [01-architecture.md](01-architecture.md) | System shape, processes, queues, public surfaces |
| [02-data-model.md](02-data-model.md) | Complete schema, every table and column |
| [03-local-development.md](03-local-development.md) | Herd + Docker + Cloudflare Tunnel setup |
| [04-conventions.md](04-conventions.md) | Laravel / React 19 / TS / Inertia 3 / shadcn conventions |
| [05-security-multitenancy.md](05-security-multitenancy.md) | Team isolation, token vault, webhook auth |
| [06-testing-strategy.md](06-testing-strategy.md) | Test layers, fakes, contract tests |

### External API reference (verified)
| Doc | Contents |
|---|---|
| [reference/whatsapp-cloud-api.md](reference/whatsapp-cloud-api.md) | Endpoints, message types, media, errors |
| [reference/whatsapp-webhooks.md](reference/whatsapp-webhooks.md) | Every webhook field we subscribe to |
| [reference/meta-business-agent.md](reference/meta-business-agent.md) | MBA APIs, thread control, connectors |
| [reference/iotec-pay.md](reference/iotec-pay.md) | ioTec Pay — full spec from live OpenAPI |
| [reference/iotec-pay-openapi.json](reference/iotec-pay-openapi.json) | Captured ioTec OpenAPI document (moves to `tests/Fixtures/iotec/` in Phase 0 for the contract test) |
| [reference/pricing-and-limits.md](reference/pricing-and-limits.md) | Pricing, rate limits, messaging tiers, key dates |

### Modules
| Doc | Module |
|---|---|
| [modules/m0-onboarding.md](modules/m0-onboarding.md) | M0 — Onboarding & platform |
| [modules/m1-team-inbox.md](modules/m1-team-inbox.md) | M1 — Team inbox |
| [modules/m2-contacts-consent.md](modules/m2-contacts-consent.md) | M2 — Contacts, consent & segmentation |
| [modules/m3-templates.md](modules/m3-templates.md) | M3 — Template management |
| [modules/m4-campaigns.md](modules/m4-campaigns.md) | M4 — Campaigns & broadcast |
| [modules/m5-meta-business-agent.md](modules/m5-meta-business-agent.md) | M5 — Meta Business Agent |
| [modules/m6-commerce-payments.md](modules/m6-commerce-payments.md) | M6 — Commerce & ioTec payments |
| [modules/m7-ctwa-ads.md](modules/m7-ctwa-ads.md) | M7 — Ads that Click to WhatsApp |
| [modules/m8-analytics-billing.md](modules/m8-analytics-billing.md) | M8 — Analytics, billing & health |

### Planning
| Doc | Contents |
|---|---|
| [90-roadmap.md](90-roadmap.md) | Phased build order with exit criteria |
| [91-prerequisites.md](91-prerequisites.md) | **What is needed from you before implementation** |
| [92-decisions.md](92-decisions.md) | Decision log (ADRs) |

## Confirmed stack

| Layer | Choice | Version verified 2026-08-01 |
|---|---|---|
| PHP | Herd | 8.4.23 |
| Framework | Laravel | 13.23.0 |
| Server-side adapter | `inertiajs/inertia-laravel` | 3.2.1 |
| Client | React | 19.2.8 |
| Inertia client | `@inertiajs/react` | 3.6.1 |
| Language | TypeScript | 7.0.2 |
| Styling | Tailwind CSS | 4.3.3 |
| Components | shadcn/ui | latest CLI |
| Bundler | Vite | 8.2.0 |
| Local web server | Laravel Herd | — |
| Local infra | Docker Compose (Postgres, Redis, Meilisearch, MinIO, Mailpit) | Docker 29.6.1 |
| Public tunnel | cloudflared named tunnel | 2026.3.0 |

## Non-negotiables

1. **Never drop a webhook.** Persist raw, ack fast, process async, dedupe on `wamid`. See
   [modules/m1-team-inbox.md](modules/m1-team-inbox.md) and `01-architecture.md`.
2. **Team isolation is a security boundary, not a filter.** See `05-security-multitenancy.md`.
3. **Consent is enforced at send time**, not only at audience-build time.
4. **Money is append-only.** Payments and usage meters are event-sourced; never mutate in place.
5. **Every fact about an external API lives in `reference/` with a source link.** No memory-based
   API claims in code comments or docs.
