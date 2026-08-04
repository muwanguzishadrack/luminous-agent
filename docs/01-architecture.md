# 01 — Architecture

## Shape

A **single Laravel 13 monolith** serving an Inertia 3 + React 19 SPA, plus queue workers.
No separate API service, no microservices. Team isolation is a column, not a database.

A **team** is one customer workspace: one team per user, one WABA and one phone number per team
(`92-decisions.md` D-020). There is no team switcher, so every authenticated request has exactly one
possible team context, resolved from the user's single membership.

```
                    ┌──────────────────────────────────────────────┐
  Browser  ────────▶│  Laravel 13  (Herd locally / FrankenPHP prod)│
  (Inertia/React)   │  ┌────────────┬───────────────┬───────────┐  │
                    │  │ Web (Inertia)│ Public HTTP │ Console   │  │
                    │  └────────────┴───────────────┴───────────┘  │
                    └───────┬──────────────────┬───────────────────┘
                            │                  │
                    ┌───────▼───────┐  ┌───────▼────────┐
                    │ Postgres 17   │  │ Redis 8        │
                    │ (system of    │  │ queues, cache, │
                    │  record)      │  │ locks, rate    │
                    └───────────────┘  │ limiters       │
                    ┌───────────────┐  └───────┬────────┘
                    │ Meilisearch   │          │
                    │ (msg search)  │  ┌───────▼────────┐
                    └───────────────┘  │ Horizon workers│
                    ┌───────────────┐  │ (8 queues)     │
                    │ S3 / MinIO    │  └────────────────┘
                    │ (media)       │
                    └───────────────┘

  INBOUND from the internet  ──▶ Cloudflare Tunnel ──▶ Herd ──▶ /webhooks/*  /connectors/*
     • Meta WhatsApp webhooks
     • ioTec Pay callbacks
     • Meta Business Agent connector calls  ← Meta's AI calls US
     • Embedded Signup OAuth redirect
```

## The four public HTTP surfaces

These are the only routes reachable from the internet without a session. Each has its own
authentication scheme. They live in `routes/public.php`, registered without the `web` middleware
group (no session, no CSRF).

| Surface | Route | Who calls it | Auth |
|---|---|---|---|
| WhatsApp webhooks | `GET|POST /webhooks/meta[/{app}]` — canonical URL is `/webhooks/meta`; the optional app-id segment must match ours | Meta | `X-Hub-Signature-256` HMAC-SHA256 with app secret; `hub.verify_token` on GET |
| ioTec Pay callbacks | `POST /webhooks/iotec/{kind}` | ioTec | Static security header configured per-wallet in the ioTec portal, **plus** mandatory re-fetch of transaction status before trusting |
| MBA connectors | `GET|POST /connectors/v1/{team}/...` | Meta Business Agent | Per-team bearer token, rotatable, scoped to read-only or write tools |
| Embedded Signup callback | `POST /onboarding/exchange` | Our own browser JS | Session + signed nonce (this one IS in the `web` group) |

> **Critical:** the MBA connector surface means Meta's AI makes live calls into our application on
> the customer's behalf. It must be fast (target p95 < 800ms), read-mostly, team-scoped, and
> rate-limited. Treat it as a public product API, not an internal endpoint.

## Request/response boundaries

| Concern | Where it lives |
|---|---|
| HTTP → validated input | `app/Http/Requests/*` (FormRequest) |
| Business operation | `app/Actions/*` — one class, one public `handle()`, no HTTP awareness |
| External API call | `app/Services/Meta/*`, `app/Services/Iotec/*` — thin typed clients, no business logic |
| Long-running / retryable work | `app/Jobs/*` |
| Read model for the UI | `app/Http/Resources/*` → typed props consumed by React |
| Domain events | `app/Events/*` + listeners; used for meters, audit, search indexing |

Controllers do three things only: authorize, delegate to an Action, return an Inertia response.

## Queues

Redis-backed, managed by Horizon. Queue names and intent:

| Queue | Priority | Contents | Failure policy |
|---|---|---|---|
| `webhooks` | highest | Decode + persist inbound webhook payloads | Retry 5× exponential, then DLQ table + alert. Never lose. |
| `inbound` | high | Turn persisted webhook rows into contacts/conversations/messages | Retry 5×, idempotent on `wamid` |
| `sends` | high | Single outbound message sends (inbox replies) | Retry 3×; surface failure in UI immediately |
| `campaigns` | normal | Rate-limited bulk sends | Retry with backoff honouring `131056`; per-recipient status |
| `media` | normal | Download/upload media, thumbnails, virus scan | Retry 3× |
| `sync` | normal | Template sync, MBA knowledge sync, catalog sync, coexistence import | Retry 3× |
| `payments` | high | ioTec status polling + reconciliation | Retry with backoff; never double-credit |
| `analytics` | low | Pull Meta analytics, roll up meters | Retry 3×, safe to re-run |

Run locally with `php artisan horizon`. Herd does **not** run queue workers.

## The webhook ingest pipeline

This is the most important piece of the system. Detailed spec in
[modules/m1-team-inbox.md](modules/m1-team-inbox.md); the shape is:

```
POST /webhooks/meta
  1. Verify X-Hub-Signature-256 against raw body        → 401 on mismatch
  2. INSERT INTO webhook_deliveries (raw payload, sha256 of body)
       - unique index on (source, body_sha256) → duplicate delivery is a no-op
  3. return 200 immediately                             ← target < 50ms
  4. dispatch ProcessWebhookDelivery on `webhooks` queue
       → fan out per entry/change into per-field handlers on `inbound`
       → every message write is UPSERT on wamid
```

Rules:
- **Never repoint the app-level callback URL for development.** The Meta app's webhook config is
  global (and already points at production, `https://www.app.luminouscrm.com/…`). Local dev uses
  the **WABA-level callback override** (`webhook_configuration` via `subscribed_apps`) to route a
  test WABA's traffic to the Cloudflare tunnel while production WABAs keep the app-level URL.
- **Ack before processing.** Meta retries aggressively; a slow 200 causes duplicate storms.
- **Never trust ordering.** A `statuses` event can arrive before the `messages` event it refers to.
  Message rows are created on first sight from either direction.
- **Everything is replayable.** `webhook_deliveries` retains raw payloads for 30 days so any
  processing bug can be fixed and replayed.

## Outbound send path

```
Action: SendMessage
  1. Authorize (team, phone number, user)
  2. Guard: consent (M2) → CSW state (M1) → template category (M3) → budget (M8)
  3. Acquire rate-limit tokens:
       per-number throughput bucket (80 msgs/sec STANDARD · 1,000 HIGH · 20 Coexistence)
       per-recipient pair gate      (1 msg / 6s to the same wa_id)
       (the 5000/hr per-WABA limit governs Business Management calls only, not sends)
  4. Persist message row status=queued with a local ULID
  5. POST /{phone_number_id}/messages
  6. Store returned wamid; status=sent
  7. Record usage meter entry (M8)
```

Steps 3–6 are also the campaign path — campaigns just feed the same Action from a queue with a
different rate-limiter scope.

## Thread ownership state machine (M1 + M5)

Every conversation has exactly one owner. This is the core state that MBA forces on us.

```
        ┌──────────────────── pass_thread_control ────────────────────┐
        ▼                                                            │
  ┌──────────┐   agent clicks "Take over"    ┌──────────┐   assign   ┌──────────┐
  │    AI    │ ────────────────────────────▶ │  QUEUED  │ ─────────▶ │  HUMAN   │
  │ (MBA has │      (take_thread_control)     │ (ours,   │            │ (ours,   │
  │ control) │                                │ unowned) │            │ assigned)│
  └──────────┘                                └──────────┘            └──────────┘
        ▲                                                                   │
        └────────── "Hand back to AI" → Thread Control `pass` ──────────────┘

  Inbound message arrives on:  `standby` webhook when state = AI
                               `messages` webhook when state = QUEUED or HUMAN
  Any outbound send by us implicitly takes control → must be gated by explicit UI intent.
```

`messaging_handovers` webhook is the authoritative source of truth for the current owner. Our local
state is a cache of it and must reconcile on every handover event.

## Configuration surfaces (`config/`)

| File | Contents |
|---|---|
| `config/meta.php` | Graph API version (pinned), app id/secret, ES config id, webhook verify token, permission list |
| `config/mba.php` | Connector base URL, connector token TTL, supported verticals list, token price for estimates |
| `config/iotec.php` | Token URL, API base URL, client id/secret, wallet ids per currency, callback header name/value, min amount |
| `config/teams.php` | Team resolution strategy, impersonation rules |
| `config/limits.php` | All rate limits and tiers in one place, so they are testable and tunable |

**Pin the Graph API version** in config (`v26.0` at time of writing — see `92-decisions.md` D-017)
and upgrade deliberately. Never call an unversioned Graph URL.

## Environments

| Environment | App served by | Public URL | Infra |
|---|---|---|---|
| Local dev | Herd | Cloudflare named tunnel (stable hostname) | Docker Compose infra-only |
| Production parity check | Docker (FrankenPHP + Octane) | same tunnel, different port | Same Docker Compose + app container |
| Production | TBD | real domain | managed Postgres/Redis/S3 |

See `03-local-development.md`.
