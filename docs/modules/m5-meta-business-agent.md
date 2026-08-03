# M5 — Meta Business Agent

**Goal:** Meta's AI answers first, *with our CRM's knowledge of the customer*, and hands off cleanly.

API facts: [`../reference/meta-business-agent.md`](../reference/meta-business-agent.md).
Tables: `mba_agents`, `mba_allowlist_entries`, `mba_knowledge_sources`, `mba_connectors`,
`mba_connector_tools`, `connector_tokens`, `mba_events`, `mba_evals`, `thread_control_events`.

---

## 1. The strategic frame

MBA is a capable agent that knows the conversation and the product catalog but has **no idea who the
customer is**. Every Tech Provider can switch it on. Not every one can make it *know things*.

**Our product is the Connector layer plus the handover orchestration.** Ship 5.8 (customer-context
Connector) *with* the agent, never after — an agent without CRM context is a commodity.

---

## 2. Enablement pipeline

```
1. CheckEligibility        → Agent Eligibility endpoint, per phone number
                             gate the UI: 5 verticals, 182 countries
2. Client accepts MBA ToS  → in WhatsApp Manager (we cannot do this for them)
   We accept Tech Provider ToS → Developer Portal, once, org-wide
   ⚠ API calls are REJECTED until both are done
3. Acquire BISU token      → separate credential, meta_credentials.type='bisu'
4. OnboardAgent            → Agent Onboarding endpoint (required before enabling)
5. ConfigureAgent          → Settings (persona, language, handoff, followup) + Skills
6. PushKnowledge           → Business info, FAQs, Websites, Files
7. RegisterConnectors      → Connectors + Connector tools (§4)
8. AllowlistCanary         → Agent Allowlist: a handful of consumer numbers
9. TestAndEval             → Agent Test + Agent Eval before going wide
10. Enable                 → Settings; starts responding to NEW conversations only
```

Blocking-state UI: each step shows done / blocked-on-client / blocked-on-us, because step 2 depends
on the client and will be the most common stall.

---

## 3. Agent studio (the configuration UI)

| Surface | Backed by |
|---|---|
| Persona & voice | Settings + Skills |
| Language | Settings |
| Handoff policy | Settings — when to escalate to a human |
| Follow-up policy | Settings |
| Business info | Knowledge – Business Info (hours, locations, policies) |
| FAQs | Knowledge – FAQs, with CSV bulk import and versioning |
| Websites | Knowledge – Websites, with a re-crawl schedule we manage |
| Files | Knowledge – Files |
| Products | Meta Commerce Manager catalog, synced from M6 |

Our copy of every source lives in `mba_knowledge_sources` and is the source of truth; Meta's is a
projection. `version` bumps force a re-push. Sync status is visible per source, with the last error.

**Website crawling is user-supplied URL fetching.** Route it through the SSRF-safe resolver
(`05-security-multitenancy.md` §6) — reject private ranges, non-HTTP schemes, and redirects to
internal hosts.

---

## 4. Connectors — where our value lives

Meta's agent calls **us**. Two connectors per tenant.

### 4.1 Read connector — `customer-context`

```
GET /connectors/v1/{tenant}/customer-context?wa_id={wa_id}
Authorization: Bearer <connector token>
```

Response (built by `BuildCustomerContext`, M2 §4, allowlisted fields only):

```jsonc
{
  "customer": {
    "name": "Grace N.",
    "language": "en",
    "lifecycle_stage": "customer",
    "is_returning": true,
    "since": "2025-03-11"
  },
  "consent": { "marketing": "revoked", "utility": "granted" },
  "orders": {
    "count": 4,
    "lifetime_value": "UGX 480,000",
    "open": [{
      "reference": "LC-10482",
      "status": "pending_payment",
      "total": "UGX 120,000",
      "items": [{ "name": "Shea Butter 500g", "qty": 2 }],
      "placed_at": "2026-07-31T14:02:00Z"
    }]
  },
  "last_payment": { "status": "Success", "amount": "UGX 120,000", "method": "MTN", "at": "…" },
  "attribution": { "source": "ctwa", "campaign": "August Promo" },
  "notes_for_agent": ["Prefers pickup at Ntinda branch"],
  "escalate_if": ["refund", "complaint", "wholesale enquiry"]
}
```

Design rules — these are safety rules, not style preferences:

| # | Rule |
|---|---|
| 1 | **Allowlist, never a serialised model.** The agent will speak this to the customer. A stray internal note or another contact's data becomes a data breach spoken aloud in WhatsApp. |
| 2 | **No free-text staff commentary** except a curated `notes_for_agent` field explicitly marked customer-safe. |
| 3 | **Pre-formatted, human-readable values** (`"UGX 480,000"`, not `480000`) so the agent does not invent formatting or currencies. |
| 4 | **`escalate_if` hints** carry our routing policy into the agent's decision-making. |
| 5 | **Include consent** so the agent does not offer marketing to an opted-out customer. |
| 6 | p95 **< 800ms**. The customer is waiting mid-conversation. Cache the context object per contact with short TTL and bust on order/payment/consent change. |
| 7 | **Fail soft.** On internal error return `200` with a minimal object rather than `500` — a partial answer beats the agent stalling. Log the degradation. |

### 4.2 Write connector — actions

| Tool | Method / path | Effect |
|---|---|---|
| `log_intent` | `POST .../intents` | Record detected intent + confidence on the conversation |
| `create_ticket` | `POST .../tickets` | Open a task assigned by rules |
| `check_stock` | `GET .../products/{retailer_id}/stock` | Live availability |
| `create_order` | `POST .../orders` | Draft order from the conversation (M6) |
| `request_payment` | `POST .../orders/{ref}/payment-request` | Trigger the ioTec collection flow (M6) |
| `book_slot` | `POST .../bookings` | Appointment |
| `escalate` | `POST .../escalations` | Move the conversation to a human queue |

Every write tool: declared `is_write`, a per-tool ability on the token, an idempotency key in the
request body, and an entry in `audit_logs` with `actor_type = 'mba'`.

`request_payment` is the highest-risk tool — it moves money. Constraints: amount must match an
existing order total, never a value the agent supplies freely; per-conversation rate limit; and the
customer still confirms in the ioTec/MTN flow.

### 4.3 Connector tokens

Per-tenant, hashed at rest (`connector_tokens.token_hash`), prefix-indexed for lookup, ability-scoped,
rotatable, with `last_used_at`. Token's `tenant_id` **must** match the `{tenant}` URL segment; a
mismatch returns 404, not 403.

---

## 5. Thread control & handoff

Behaviour and rules: `reference/meta-business-agent.md` §5 and `m1-team-inbox.md` §2.

| Direction | Trigger | Mechanism |
|---|---|---|
| AI → us | Agent's handoff policy fires; or a human clicks **Take over** | Our send takes control implicitly; `messaging_handovers` confirms |
| us → AI | Human clicks **Hand back to AI** | Thread Control `pass` with JSON `metadata` explaining why |

Guardrails:
- Composer disabled while `state = ai`; takeover is explicit (M1 §2).
- Every transition appends to `thread_control_events` with actor and reason.
- On hand-back, post an internal note summarising what the human did, so the next human has context
  even if the agent does not *(pending U1 in `reference/meta-business-agent.md` §8)*.

---

## 6. Agent Event triggers

Push business events so the agent acts proactively in an existing thread:

| Our event | Agent Event kind |
|---|---|
| Payment `Success` (M6) | `payment_confirmed` |
| Order `shipped` | `order_shipped` |
| Order `cancelled` | `order_cancelled` |
| Booking confirmed | `booking_confirmed` |

Recorded in `mba_events` with the response. **Note the cost:** every agent reply is charged per token
whether the customer asked for it or not. Agent Events must be deliberate and rate-limited per
conversation, and each kind must be individually switchable by the tenant.

---

## 7. Testing & evaluation

| Capability | Endpoint | Our surface |
|---|---|---|
| Scripted test conversations | Agent Test | A test suite per tenant, runnable before enabling and after any knowledge change |
| Performance scoring | Agent Eval | Client-facing quality report |
| Sandbox reset | `reset` keyword | **Requires Meta to enable it for specific test consumer numbers** — request during setup |

Derived metrics we compute ourselves (Meta does not give these):

| Metric | Definition |
|---|---|
| **Containment rate** | conversations resolved with `state = ai` throughout ÷ all AI-handled conversations |
| Escalation rate | AI → human transitions ÷ AI-handled conversations |
| Time-to-escalation | median |
| Cost per resolution | MBA token cost ÷ contained conversations |
| AI vs human cost | side-by-side, feeding M8 §8.8 |

Containment rate and cost per resolution are the two numbers that justify the subscription. Build
them in P2, not later.

---

## 8. Cost governance

| Control | Behaviour |
|---|---|
| Token meter | `usage_meters.meter = 'mba_tokens'` |
| Estimation fallback | **Meta's MBA analytics/webhook details were still "forthcoming" as of Aug 1, 2026.** Until confirmed, estimate as `messages × config('pricing.mba.est_tokens_per_message')` and **label it an estimate in the UI.** Re-check the pricing docs before implementing. |
| Per-tenant budget | Soft warning, then auto-disable the agent at a hard cap |
| Per-conversation cap | Disable the agent on a runaway thread and escalate to a human |
| Complexity insight | Flag question types that consume the most tokens, so the client can add an FAQ and cut cost |

That last one is a genuinely differentiated feature: "these 5 questions cost you UGX 400k last month —
add them as FAQs" turns a cost report into a product.

---

## 9. UI surface

| Route | Screen |
|---|---|
| `/agent` | Overview: enabled state, eligibility, containment rate, token spend, recent escalations |
| `/agent/setup` | The 10-step pipeline with blocked-on-client indicators |
| `/agent/knowledge` | Business info, FAQs (bulk import), websites (crawl status), files |
| `/agent/skills` | Persona, tone, handoff and follow-up policy |
| `/agent/connectors` | Connector + tool registry, token management, live call log with latency |
| `/agent/testing` | Test conversations, eval runs, score history |
| `/agent/costs` | Token spend, cost per resolution, expensive-intent breakdown |

The connector **call log** (which tool, which conversation, latency, status) is essential for
debugging: when the agent gives a wrong answer, the first question is always "what did we tell it?"

---

## 10. Edge cases

| Case | Handling |
|---|---|
| Ineligible vertical/country | Hide the module, explain why, link Meta's list |
| Client has not accepted ToS | Setup shows blocked-on-client with instructions; do not call the API |
| BISU token missing/expired | Typed error → reconnect prompt; never a 500 |
| Connector times out | Agent behaviour unknown *(U5)* — we return fast degraded data rather than hanging |
| Agent contradicts a human | Post-handoff internal note; escalate_if hints reduce recurrence |
| Agent enabled mid-conversation | Only affects **new** conversations — set expectations in the UI |
| Knowledge push partially fails | Per-source sync status; retry only failed sources |
| Website crawl target is internal | SSRF resolver rejects it |
| Runaway token spend | Per-conversation cap disables the agent and escalates |
| MBA disabled while it holds control | Reconcile from `messaging_handovers`; enable the composer |

---

## 11. Acceptance criteria

1. Eligibility is checked before the module is offered; ineligible numbers show a clear explanation.
2. Setup surfaces the ToS dependency as blocked-on-client and makes no rejected API calls.
3. `customer-context` returns only allowlisted fields — a test fails if a new field is added
   without declaration.
4. `customer-context` p95 < 800ms under 100 rpm.
5. A write tool call produces the CRM record **and** an `audit_logs` entry with `actor_type = 'mba'`.
6. Take over / hand back both work and are reflected in `thread_control_events` and in Meta's
   `messaging_handovers`.
7. Containment rate and cost per resolution are computed and displayed.
8. Token spend hitting the tenant hard cap disables the agent automatically.
9. U1–U6 in `reference/meta-business-agent.md` §8 are answered empirically and recorded in
   `92-decisions.md`.
