# M4 — Campaigns & Broadcast

**Goal:** send to a segment safely — inside Meta's rate limits, inside consent, inside budget — with
per-recipient visibility.

Tables: `campaigns`, `campaign_recipients`, `sequences`, `sequence_steps`, `sequence_enrollments`.

---

## 1. Campaign lifecycle

```
draft → scheduled → queueing → sending → completed
                         │         │
                         │         ├─▶ paused  (manual or budget/quality trip)
                         └─────────┴─▶ cancelled | failed
```

### Queueing phase (the audience snapshot)

Before a single message is sent, the whole audience is resolved and frozen:

```
ResolveAudience(segment) → for each contact:
    INSERT campaign_recipients (campaign_id, contact_id, status, variables)
      UNIQUE (campaign_id, contact_id)      ← the real double-send guard
    apply suppression rules NOW, recording the reason
```

Suppression happens at queueing so the client sees an honest count before sending starts:

| `suppression_reason` | Cause |
|---|---|
| `no_consent` | Marketing template + revoked/absent marketing consent |
| `blocked` | `contacts.is_blocked` |
| `invalid_number` | Previously failed with `131026` |
| `per_user_cap` | Meta's per-user marketing frequency cap likely to drop it |
| `missing_variable` | Fallback-less variable with no value |
| `unsupported_language` | No template in the contact's locale within the group |
| `duplicate` | Same contact already in this campaign |
| `budget` | Budget cap reached before this recipient was reached |

The pre-flight screen shows: *"5,000 in segment · 4,700 will send · 300 suppressed"* with a
breakdown, before the client confirms.

---

## 2. The rate-limited sender

This is the hardest part of the module. Numbers come from `config/limits.php`
(source: `reference/pricing-and-limits.md`).

```
DispatchCampaignBatch (queue: campaigns)
  ├─ acquire per-number throughput token   80 msgs/sec STANDARD · 1,000 HIGH · 20 Coexistence
  │                                        (from phone_numbers.throughput_level; keep our own
  │                                         ceiling below Meta's, e.g. 80% of it)
  ├─ acquire per-recipient pair gate       1 msg / 6s to the same wa_id (the 45-in-6s burst
  │                                        allowance is per pair — irrelevant to broadcasts,
  │                                        which hit each recipient once)
  ├─ check messaging limit headroom        unique users messaged outside CSW in rolling 24h vs
  │                                        the PORTFOLIO limit (2K/10K/100K/UNLIMITED)
  ├─ check budget                          campaigns.spent_minor < budget_cap_minor
  ├─ SendTemplateMessage(...)              ← the same Action the inbox uses
  └─ on 131056  → requeue with 4^X backoff, do NOT fail the recipient
     on 130429  → throughput exceeded: shrink batch rate, requeue, do NOT fail the recipient
     on 131057  → number mid-throughput-upgrade (~1 min): requeue with short delay
     on 131026  → mark contact undeliverable, suppress from future campaigns
     on 131042  → PAUSE the whole campaign (payment problem), alert the team
     on 80007   → management-API backoff (should not occur on the send path)
     on 190     → trip credential breaker, pause campaign
```

> The **5,000 req/hr per active WABA** limit applies to Business Management endpoints (template
> CRUD, phone number reads, `subscribed_apps`) — it is **not** a message-send limit and must not
> gate the sender. Send rate is governed by per-number throughput (mps) plus the per-pair gate.
> See `reference/pricing-and-limits.md` §3.

Implementation notes:

1. **Redis token buckets**, keyed `rl:mps:{phone_number_id}` (per-second window sized from
   `throughput_level`) and `rl:pair:{phone_number_id}:{wa_id}`.
2. The per-recipient gate matters even in a broadcast, because a contact may also be in an active
   conversation — the inbox and the campaign share the same bucket.
3. Batch size adapts to observed rate-limit headroom rather than being a fixed constant.
4. A campaign is **never** a single long-running job. It is many small idempotent batches, so a
   deploy or crash mid-send loses nothing.
5. Every send records `campaign_recipients.cost_minor` from the eventual `pricing` webhook, and rolls
   into `campaigns.spent_minor`.

---

## 3. Scheduling

| Mode | Behaviour |
|---|---|
| `fixed` | One instant, team timezone (WABA timezone drives Meta's day boundaries) |
| `recipient_local` | Bucket recipients by inferred timezone and send in each local window |

Quiet hours are enforced per team (default 21:00–08:00 local): a campaign scheduled into quiet
hours is held to the next allowed window rather than refused.

---

## 4. Routing: Cloud API vs Marketing Messages API

| Routing | When | Endpoint |
|---|---|---|
| `cloud_api` | Utility, authentication, and marketing by default | `POST /{phone_number_id}/messages` |
| `mm_api` | Marketing templates, when the team opts in | `POST /{phone_number_id}/marketing_messages` |

MM API gives up to **9% higher delivery** on high-engagement content plus automatic creative
optimisation, and adds:

| Parameter | Use |
|---|---|
| `product_policy` | `CLOUD_API_FALLBACK` (default — fall back if onboarding requirements are unmet) or `STRICT` |
| `message_activity_sharing` | Per-message override of the WABA-level setting |

Status webhooks for MM API sends carry `pricing.category` / `conversation.origin.type` =
`marketing_lite`. **We must record which route each message took** so `pricing_analytics`
reconciliation is possible — the message id alone does not reveal it.

MM API is send-only: replies always come back through Cloud API on the same number.

---

## 5. Budget & safety controls

| Control | Behaviour |
|---|---|
| Campaign budget cap | Hard stop; remaining recipients suppressed with reason `budget` |
| Team daily cap | Kill-switch across all campaigns |
| Meta max price enrolment | Surface and configure Meta's own max-price setting |
| Quality trip | If `phone_number_quality_update` drops to RED mid-send, auto-pause and alert |
| Test send | Send to up to 5 nominated numbers before the real send; required for first-time templates |
| Dry run | Full queueing with suppression report and cost estimate, no sends |

The cost estimate uses `pricing_analytics` rates for the actual recipient country mix — not a single
average rate — because Ugandan vs international recipients differ materially.

---

## 6. A/B testing

`campaigns.variant_group_id` + `variant_weight`. Recipients are split deterministically by
`hash(contact_id + variant_group_id)` so re-running is stable.

Winner selection on read rate or click rate from `template_analytics` after a configurable settle
period, then optional auto-send of the winner to the remaining audience.

---

## 7. Click tracking

URL buttons are wrapped: `https://{team-domain}/c/{token}` → 302 to the target.
`token` resolves to `(campaign_id, contact_id, button_index)`. We record the click, then redirect.

Also read Meta's own `template_analytics` click metrics (`url_button`, `quick_reply_button`,
`unique_url_button`) for cross-checking. Ours is per-contact; Meta's is aggregate.

Redirect safety: only team-configured destination domains may be wrapped, to stop the platform
becoming an open redirector.

---

## 8. Drip sequences

`sequences` → ordered `sequence_steps`; `sequence_enrollments` tracks each contact's position.

Step types: `send_template`, `wait(duration)`, `branch(condition)`, `tag`, `assign`,
`exit_if_replied`, **`exit_if_ai_resolved`**, `webhook`.

`exit_if_ai_resolved` is the MBA-aware exit: if the contact's conversation was handled and closed by
the agent, stop the drip. Without it, a customer whose problem the AI already solved keeps getting
follow-ups.

Enrollment triggers: segment entry, CTWA arrival, order status change, payment status change, label
applied, manual.

---

## 9. Campaign → MBA interaction

Replies to a broadcast land in a conversation where **MBA may hold control**. Consequences:

1. The campaign context must be visible to the agent — the M5 `customer-context` Connector includes
   `attribution.campaign` and the last template sent.
2. Conversations opened by a campaign reply are tagged with the campaign for ROI attribution.
3. `sequences` must respect the AI: do not send step 3 while the AI is mid-conversation on step 2's
   subject. `exit_if_ai_resolved` plus a "pause drip while conversation is active" flag.

---

## 10. UI surface

| Route | Screen |
|---|---|
| `/campaigns` | List with status, audience size, sent/delivered/read/clicked/replied, spend |
| `/campaigns/new` | Wizard: audience → template & variables → schedule → budget → pre-flight → confirm |
| `/campaigns/{id}` | Live progress, funnel, spend vs budget, suppression breakdown, error triage |
| `/campaigns/{id}/recipients` | Virtualised per-recipient table, filterable by status/error, retry cohort |
| `/sequences` | Sequence builder and enrollment stats |

The pre-flight screen is the most important one: audience count, suppression breakdown with reasons,
**estimated cost**, rate-limit-based ETA, and quality warnings. Nothing sends until it is confirmed.

---

## 11. Edge cases

| Case | Handling |
|---|---|
| Deploy mid-campaign | Small idempotent batches; resumes automatically |
| Segment changes during send | Audience was snapshotted at queueing — no drift |
| Contact opts out mid-campaign | Checked again inside `SendTemplateMessage`; suppressed even if queued |
| Template rejected/paused mid-campaign | Auto-pause the campaign, notify |
| Same contact in two concurrent campaigns | Per-recipient 6s gate serialises them; per-user marketing cap may suppress one |
| Budget exhausted mid-send | Remaining recipients suppressed with `budget`, campaign completes as partial |
| 100k-recipient campaign | Queueing is itself chunked; pre-flight count is estimated over a threshold |
| Team wallet empty | Refuse to start; do not begin a send we cannot pay for |

---

## 12. Acceptance criteria

1. 10,000-recipient campaign completes with **zero** `131056` errors and zero duplicate sends.
2. Killing all workers mid-campaign and restarting resumes with no duplicates and no skips.
3. Pre-flight suppression counts exactly match post-send reality.
4. A mid-send opt-out is suppressed, not sent.
5. A RED quality event auto-pauses the campaign within one webhook cycle.
6. Budget cap stops sending within one batch of the cap, never over it.
7. Cost estimate is within 5% of actual for a mixed-country audience.
8. MM API-routed messages are distinguishable from Cloud API ones in reporting.
