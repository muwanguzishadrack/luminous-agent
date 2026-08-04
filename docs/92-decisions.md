# 92 — Decision Log

Append-only. Each entry: context, decision, consequences, status. Amend by adding a superseding entry,
never by editing history.

---

## D-001 — Laravel monolith with Inertia, not a separate API + SPA

**Date:** 2026-08-01 · **Status:** accepted

**Context.** The product is a dense internal tool (inbox, tables, builders) for authenticated users,
plus four small public HTTP surfaces.

**Decision.** One Laravel 13 app serving Inertia 3 + React 19. No separate API service for the UI. A
team-facing public API is a thin additional layer, not the primary consumer.

**Consequences.** No client-side data layer to maintain; server is the single source of truth; typed
props generated from PHP. Trade-off: React Native or a third-party UI would need the public API built
first.

---

## D-002 — Single database, `team_id` column, not database-per-team

**Date:** 2026-08-01 · **Status:** accepted

**Context.** Multi-tenant SaaS with potentially thousands of small teams. Cross-team queries are
needed for our own platform analytics and health monitoring.

**Decision.** One Postgres database. `team_id` on every team-scoped table. Three enforcement
layers: Eloquent global scope, **Postgres RLS**, and policies.

**Consequences.** Simple migrations, cheap onboarding, easy platform-wide reporting. The risk is a
missing scope, which RLS is specifically there to catch. Requires the team-isolation test suite in
`06-testing-strategy.md` §2 as a permanent gate.

**Rejected:** `stancl/tenancy` multi-database — operationally heavy for thousands of small teams,
and makes our own cross-team analytics painful.

---

## D-003 — Herd for serving, Docker for backing services, no Sail

**Date:** 2026-08-01 · **Status:** accepted (user decision)

**Decision.** Herd serves the app at `https://luminous-commerce.test`. Docker Compose provides
Postgres, Redis, Meilisearch, MinIO, Mailpit on non-default ports. A separate
`docker/compose.parity.yml` containerises the app for pre-deploy verification.

**Consequences.** Fast local iteration; production-parity is an explicit, on-demand harness rather
than the default dev loop. Queue workers and the scheduler must be run manually.

---

## D-004 — Named Cloudflare tunnel, never a quick tunnel

**Date:** 2026-08-01 · **Status:** accepted

**Context.** Three external systems need a stable inbound URL: Meta webhooks, **ioTec callbacks
(configured per wallet in their portal UI, not per request)**, and MBA connectors (registered with
Meta as a base URL).

**Decision.** A named tunnel bound to a hostname on a Cloudflare-managed zone.

**Consequences.** Requires a domain on Cloudflare (prerequisite B1). Eliminates the daily churn of
re-pasting a random `trycloudflare.com` URL into two external portals.

---

## D-005 — Tech Provider model; no Multi-Partner Solution in v1

**Date:** 2026-08-01 · **Status:** accepted

**Context.** As a Tech Provider we have no credit line. Clients attach their own payment method and
Meta bills them directly. A Multi-Partner Solution with a Solution Partner would let their credit line
cover our clients.

**Decision.** Ship the Tech Provider model. Build a payment-readiness gate in onboarding (M0 §4).
Defer Multi-Partner Solutions.

**Consequences.** Onboarding friction — the client must add a card before messaging. If that proves
to be the main drop-off point, revisit. Note the solution model caps onboarding at 200 new clients per
rolling week, which is not currently a constraint.

---

## D-006 — Meta Business Agent instead of our own LLM agent

**Date:** 2026-08-01 · **Status:** accepted (user decision)

**Context.** MBA is a managed agent with conversation context, catalog grounding, tool calling via
Connectors, and event-driven proactivity. Building an equivalent means RAG, evals, hosting, and
guardrails per team.

**Decision.** Use MBA. Do not build a custom agent in v1.

**Consequences.**
- Fast per-client setup; no AI engineering per team.
- **Cost is $2.00/1M tokens ≈ 4–5¢ per message, charged even inside the 24h and 72h windows.**
- **Limited to 5 verticals** — clients outside them get no AI (risk R1, question D2).
- Our differentiation moves to the Connector layer and handover orchestration, which is where M5 puts
  the effort.
- Architectural cost: MBA becomes the primary responder and our app is a standby participant, which
  forces the thread-ownership state machine into M1.

---

## D-007 — Open: fallback agent for non-MBA verticals

**Date:** 2026-08-01 · **Status:** **open — needs your answer (prerequisite D2)**

**Context.** MBA covers Automotive, CPG, Professional Services, Retail & Ecommerce, Travel only.
Healthcare, education, fintech, logistics, and NGOs are excluded.

**Options.** (a) Accept the gap — those clients get an inbox with no AI. (b) Build a minimal
bring-your-own-LLM agent in Phase 4 behind the same `AgentProvider` interface.

**Blocked on:** the vertical mix of your pipeline.

---

## D-008 — ioTec Pay as the only payment provider, behind an interface

**Date:** 2026-08-01 · **Status:** accepted (user decision)

**Context.** Native WhatsApp payments exist only in India and Brazil. Uganda needs an external rail.
ioTec Pay covers MTN and Airtel mobile money, cards via PegPay, and bank transfers, in `UGX`/`USD`
with an `ITX` sandbox.

**Decision.** ioTec Pay only, but behind a `PaymentProvider` interface (M6 §4).

**Consequences.** The interface isolates three ioTec-specific hazards from the domain: `double`
amounts, a 300-second access token, and callbacks that fire for only three of nine statuses.

---

## D-009 — We generate `external_id`; ioTec's `id` is authoritative

**Date:** 2026-08-01 · **Status:** accepted

**Context.** ioTec's spec states explicitly that `externalId` "is not required to be unique."

**Decision.** Generate a ULID per attempt, unique-indexed per team. Treat ioTec's `id` (uuid) as the
authoritative reference for reconciliation. Retries always create a **new** `external_id`.

**Consequences.** Reconciliation joins on `provider_id`, with `external-id/{externalId}` as a recovery
path when a create response is lost in flight.

---

## D-010 — Mandatory status re-fetch on every ioTec callback

**Date:** 2026-08-01 · **Status:** accepted

**Context.** ioTec authenticates callbacks with a **static per-wallet header**, not an HMAC over the
body. A leaked or guessed value could fabricate a payment.

**Decision.** Verify the header, then **always re-fetch the transaction from the API and trust that
response**, never the callback body, before crediting anything.

**Consequences.** One extra API call per callback. Worth it — this is the control that makes fake
payment injection impractical.

---

## D-011 — Reconciliation poller is mandatory, not a fallback

**Date:** 2026-08-01 · **Status:** accepted

**Context.** ioTec sends callbacks only for `Success`, `Failed`, `SentToVendor`. Five other statuses
(`Pending`, `AwaitingApproval`, `Scheduled`, `RolledBack`, `Cancelled`, `Rejected`) never arrive.

**Decision.** A poller with backoff 10s → 30s → 1m → 5m → 15m → hourly to a 24h cap, plus a daily
reconciliation against `paged-history` and wallet balance.

**Consequences.** Scheduled and awaiting-approval payments resolve correctly. Discrepancies raise a
task; money is never silently adjusted.

---

## D-012 — Append-only for consent, money, and status

**Date:** 2026-08-01 · **Status:** accepted

**Decision.** `consents`, `usage_meters`, `wallet_entries`, `payment_events`, `message_events`,
`template_events`, `thread_control_events`, `webhook_deliveries`, `audit_logs` are append-only. Current
state lives in materialised read models (`consent_states`, `payments.status`, `messages.status`).

**Consequences.** Full auditability and safe replay. Corrections are new rows, so historical invoices
and compliance evidence are reproducible. Costs storage and requires the materialisers to be
idempotent.

---

## D-013 — Never block inbound processing for a billing reason

**Date:** 2026-08-01 · **Status:** accepted

**Decision.** A zero or negative wallet balance blocks campaign starts and (configurably) MBA replies.
It **never** blocks webhook ingest or inbound message persistence.

**Consequences.** Losing a customer's message because a client's wallet ran dry would breach the
"never drop a webhook" invariant. Asserted by a test (M8 AC7).

---

## D-014 — Explicit takeover from the AI, never implicit

**Date:** 2026-08-01 · **Status:** accepted

**Context.** Per Meta, our app takes thread control **simply by sending a message**. An agent typing
into a thread silently ends the AI's involvement.

**Decision.** While `state = ai` the composer is disabled behind an explicit "Take over from AI"
action. Hand-back calls Thread Control `pass` with structured `metadata`. All transitions logged to
`thread_control_events`.

**Consequences.** One extra click for agents, in exchange for not accidentally disabling a paid AI
mid-conversation and not leaving the customer with two responders.

---

## D-015 — MBA token costs estimated and labelled until Meta ships analytics

**Date:** 2026-08-01 · **Status:** accepted, pending H1

**Context.** MBA charging began Aug 1, 2026, but Meta's MBA analytics and webhook payload details were
still listed as forthcoming.

**Decision.** Estimate tokens as `messages × 22,500` (midpoint of Meta's stated 20k–25k) and **label
every MBA figure in the UI as an estimate**. Switch to real data and backfill when available.

**Consequences.** Billing on estimates is not acceptable long-term. Until real data exists, MBA token
charges are shown as indicative and not invoiced at margin.

---

## D-016 — Pin the Graph API version in config

**Date:** 2026-08-01 · **Status:** accepted

**Decision.** `config('meta.graph_version')`, assumed `v25.0`. No unversioned Graph URLs. A nightly CI
job asserts the pinned version is still supported.

**Consequences.** Upgrades are deliberate and testable rather than silent breakage.

---

## D-017 — Pin Graph API `v26.0` at project start (updates the value assumed in D-016)

**Date:** 2026-08-03 · **Status:** accepted

**Context.** D-016 assumed `v25.0`. A DevTools deprecations check on 2026-08-03 shows the latest
platform version is `v26.0` (released 2026-07-29) with no deprecations affecting our app. The v26.0
Commerce endpoint blocks target Facebook/Instagram Shops order management, not WhatsApp catalogs or
product messages, so M6 is unaffected. Nothing is built yet, so there is no migration cost.

**Decision.** Pin `v26.0` in `config('meta.graph_version')`. The D-016 mechanism (pinned in config,
nightly CI support check, deliberate upgrades) is unchanged.

**Consequences.** Longest possible runway before a forced version upgrade (~2 years). References
and `.env` examples updated to `v26.0` in the same change.

---

## D-018 — No fallback agent: all pipeline is within MBA's verticals (resolves D-007)

**Date:** 2026-08-03 · **Status:** accepted (user decision)

**Context.** D-007 was open pending the vertical mix of the client pipeline (prerequisite D2). The
user confirmed all pipeline clients fall within MBA's five supported verticals (Automotive, CPG,
Professional Services, Retail & Ecommerce, Travel).

**Decision.** Option (a): no bring-your-own-LLM fallback agent. MBA is the only agent in v1.

**Consequences.** Risk R1 in `00-product-brief.md` is closed. The Phase 4 fallback-agent decision
point drops off the roadmap. If the pipeline mix changes, reopen behind the same `AgentProvider`
interface boundary that M5 already defines.

---

## D-019 — App-side background processes run in the dev Docker stack (amends D-003)

**Date:** 2026-08-03 · **Status:** accepted (user decision)

**Context.** D-003 kept Docker to backing services and left queue workers to be started by hand
(`php artisan horizon`). In practice a forgotten worker means webhook deliveries silently pile up as
`pending` — the failure is invisible until someone looks at the table. Separately, prerequisite D6
always said Reverb "runs in the same Docker stack", but no service existed.

**Decision.** `docker/compose.dev.yml` now also runs **horizon**, **scheduler** and **reverb** from a
small `docker/worker/Dockerfile` (php:8.4-cli-alpine + pdo_pgsql, redis, intl, bcmath, gd, zip and
**pcntl**, which Horizon requires). They bind-mount the project, so code edits need only a container
restart, and take service-network env overrides (`DB_HOST=postgres`, `REDIS_HOST=redis`, …) which win
because Laravel's dotenv repository is immutable. Horizon gets a 30s `stop_grace_period` so in-flight
jobs finish rather than being killed mid-delivery.

**Consequences.** `docker compose up -d` yields a complete working environment; Herd still serves the
app (D-003's serving model is unchanged). Trade-off: the worker image's PHP must stay in step with
Herd's, and `vendor/` is shared across host and container — fine because it holds no
platform-specific binaries we depend on.

**Rejected.** Sail (D-003 stands: too slow on macOS for the request path); a supervisor on the host
(no auto-start with the stack, and one more thing to install).

---

## D-020 — "Tenants" become "Teams"; one team per user, one WABA per team

**Date:** 2026-08-04 · **Status:** accepted (user decision)

**Context.** The original model was many teams per user and many WABAs per team. That bought a
workspace switcher and a "current team" pointer on the user, and the pointer caused a real incident:
a live Embedded Signup landed in the wrong workspace, because `/onboarding` was not team-scoped while
`/demo/...` URLs switched the active team underneath it. The connection was made against whichever
workspace happened to be current, not the one the operator was looking at. Separately, "tenant" is our
word, not the client's — nobody signing up for a WhatsApp CRM calls their business a tenant.

**Decision.** Collapse the model: **one team per user, one WABA and one phone number per team.**
Rename the entity from "tenant" to "team" everywhere — table `teams`, pivot `team_user`, column
`team_id`, `Team`/`TeamInvitation`/`BelongsToTeam`/`TeamScope`/`TeamManager`, the `Teams` facade,
`MissingTeamContext`, and the RLS setting `app.team_id`. "Multi-tenant" survives only where it names
the architecture class, as in the title of `05-security-multitenancy.md`.

**Consequences.**
- No team switcher, and no `users.current_team_id`: the single `team_user` row *is* the context, so
  there is no pointer that can disagree with the membership. The wrong-workspace class of bug is
  designed out rather than guarded against.
- Onboarding cannot target the wrong workspace — there is only one it can target.
- A person who runs two businesses needs two logins, one per team. We do not merge, switch, or link
  them (`modules/m0-onboarding.md` §8).
- The settings surface simplifies to a single `/settings/whatsapp` page — connected account, business
  profile, billing link-out, disconnect — instead of a list of numbers.
- Per-number user scoping (`team_user.phone_number_ids`) disappears; role is the only authorization
  axis within a team.
- `team_user` needs a **user-aware** RLS policy, because a user's membership must be readable before
  team context exists (`05-security-multitenancy.md` §1 layer 2).

**Rejected.** Keeping many-to-many with a hardened switcher — the incident was a design smell, not a
missing guard, and every route would have carried the burden of proving it was team-scoped. Also
rejected: keeping the word "tenant" for the entity, which forces a translation layer between our
schema and every sentence we say to a customer.

---

## D-021 — Disconnect never deregisters

**Date:** 2026-08-04 · **Status:** accepted (user decision)

**Context.** Disconnecting a team originally called `POST /{phone-number-id}/deregister` before
clearing the local records. That conflated two unrelated things: the client leaving *us*, and the
client's number leaving the *Cloud API*. Deregistering is close to irreversible in practice — the
number stops sending and receiving for every provider until it is registered again with its
six-digit PIN, the endpoint is capped at 10 attempts per number per rolling 72 hours (`133016`), it
is refused outright if the number sent paid messages in the last 30 days, and Meta will not
deregister a Coexistence number at all. That last refusal left Coexistence teams with no way to
leave Luminous except by unlinking the handset.

**Decision.** Disconnect is a Luminous-side operation. It clears the WABA, the number, every vaulted
business credential and the team's signup sessions, and calls `DELETE /{waba-id}/subscribed_apps` so
Meta stops delivering that account's webhooks to us. The number is left registered. `deregister` is
removed from the `GraphClient` contract entirely, so no code path can reach it.

**Consequences.**
- Leaving is reversible and cheap: reconnect through ES with no re-registration, or hand the number
  to another provider.
- One exit, not two, and nothing for the UI to branch on — a Coexistence number disconnects like any
  other, and `CoexistenceDeregisterNotPermitted` is gone.
- The unsubscribe is best effort: it is exactly the call that fails on an already-broken connection,
  so a failure is audited (`webhooks_unsubscribed: false`) and surfaced as a warning rather than
  blocking the disconnect. A team must always be able to leave.
- A client who genuinely wants the number off the Cloud API does it in WhatsApp Manager, where Meta
  shows them the 30-day paid-message block and the consequences in their own words.

**Rejected.** Offering both exits side by side — two destructive buttons a few pixels apart, one of
them irreversible and rate-limited, is a UI that eventually costs someone their number. Also
rejected: keeping `deregister()` on the client with no caller, which is a loaded method waiting to
be wired up by accident.

---

## Template for new entries

```
## D-0NN — <short title>
**Date:** YYYY-MM-DD · **Status:** proposed | accepted | superseded by D-0NN | open
**Context.** Why a decision is needed.
**Decision.** What we chose.
**Consequences.** What this costs and enables.
**Rejected.** Alternatives and why not.
```
