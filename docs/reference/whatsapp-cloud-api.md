# Reference — WhatsApp Cloud API

Verified 2026-08-01 against Meta's developer documentation; endpoints re-verified 2026-08-03 via the
Meta DevTools doc search. **Pinned Graph version: `v26.0`** (released 2026-07-29 — latest at project
start, confirmed via DevTools deprecations check; spec examples still show `v23.0`–`v25.0`; pin one
and upgrade deliberately). The v26.0 Commerce endpoint blocks affect Facebook/Instagram Shops order
management only, not WhatsApp catalogs or product messages.

Base URL: `https://graph.facebook.com/{version}`
Docs root: <https://developers.facebook.com/documentation/business-messaging/whatsapp/>

---

## 1. Authentication & permissions

| Item | Value |
|---|---|
| Header | `Authorization: Bearer <token>` |
| Token type we use | **Business token** (per-team), obtained via Embedded Signup code exchange |
| Permissions | `whatsapp_business_management`, `whatsapp_business_messaging` |
| Tech Provider rule | Use **business tokens exclusively**. System tokens are only for Solution Partners sharing a credit line. |
| MBA exception | Meta Business Agent APIs use a **BISU token** — a different credential. See `meta-business-agent.md`. |

---

## 2. Sending messages

```
POST /{version}/{phone-number-id}/messages
Content-Type: application/json
```

Common envelope:

```json
{
  "messaging_product": "whatsapp",
  "recipient_type": "individual",
  "to": "<WA_ID or E.164>",
  "type": "<type>",
  "<type>": { ... },
  "context": { "message_id": "<wamid>" }
}
```

Response:

```json
{
  "messaging_product": "whatsapp",
  "contacts": [{ "input": "+16505551234", "wa_id": "16505551234" }],
  "messages": [{ "id": "wamid.HBgL…", "message_status": "accepted" }]
}
```

`message_status` ∈ `accepted` | `held_for_quality_assessment` | `paused`.
**`accepted` is not `delivered`** — delivery outcome arrives only via the `statuses` webhook.

### Message types we support

| Category | `type` values |
|---|---|
| Text | `text` (with `preview_url` for link previews) |
| Media | `image`, `audio`, `video`, `document`, `sticker` — each by `{id}` **or** `{link}` |
| Structured | `location`, `contacts`, `address` |
| Interactive | `interactive` → `button` (reply buttons), `list`, `cta_url`, `location_request`, `flow`, `product`, `product_list`, `catalog_message` |
| Reaction | `reaction` (`{message_id, emoji}`; empty emoji removes) |
| Template | `template` — the only type sendable **outside** the customer service window |
| Read receipt | `{"status":"read","message_id":"wamid…"}` |
| Typing indicator | `{"status":"read","message_id":"…","typing_indicator":{"type":"text"}}` |

Media by `id` is preferred over `link`: Meta caches it, sends are faster, and we control the asset.

### Customer Service Window (CSW)

- Opens for **24 hours** from the customer's most recent inbound message.
- Inside it: any message type, including free-form.
- Outside it: **templates only**. A free-form send fails with error `131047`.
- We compute `conversations.csw_expires_at = last_inbound_at + 24h` and gate the composer on it.

### Free Entry Point (FEP) window

- Opens for **72 hours** when the conversation starts from a Click-to-WhatsApp ad or a Facebook
  page CTA button.
- **Message delivery is free** in this window, templates included.
- MBA token charges still apply inside it.
- We store `conversations.fep_expires_at` when a `referral` object is seen.

---

## 3. Media

| Operation | Endpoint |
|---|---|
| Upload | `POST /{version}/{phone-number-id}/media` (multipart, `messaging_product=whatsapp`) |
| Get URL | `GET /{version}/{media-id}` → returns a short-lived `url` |
| Download | `GET <url>` **with the `Authorization` header** — the URL alone is not enough |
| Delete | `DELETE /{version}/{media-id}` |

Media IDs expire after approximately **30 days**. We always keep our own copy in S3/MinIO and
re-upload when a cached `media_id` has expired (`media.meta_expires_at`).

---

## 4. Templates

| Operation | Endpoint |
|---|---|
| List / create | `GET|POST /{version}/{waba-id}/message_templates` |
| Read / update | `GET|POST /{version}/{template-id}` |
| Delete | `DELETE /{version}/{waba-id}/message_templates?name=…` — deletes **all languages** of that name. Add `&hsm_id=…` (with `name`) to delete one specific template, or `?hsm_ids=[…]` for bulk (≤100, all-or-nothing). |
| Compare | `GET /{version}/{template-id}/compare?template_ids=[…]&start=…&end=…` |

Delete constraints: a deleted approved template's name is locked for **30 days**; already-sent but
undelivered messages keep delivering for 30 days (`PENDING_DELETION`); `DISABLED` templates cannot
be deleted. Compare constraints: two templates at a time, same WABA, each sent ≥1,000 times in the
window; lookback windows are 7/30/60/90 days only.

Categories: `MARKETING`, `UTILITY`, `AUTHENTICATION` — the category determines the price.
Statuses: `PENDING`, `APPROVED`, `REJECTED`, `PAUSED`, `DISABLED`, `IN_APPEAL`.

Rich types available: carousel, media card carousel, coupon code, limited-time offer, location,
catalog, single-product (SPM), multi-product (MPM), product carousel, call-permission-request,
authentication (one-tap autofill / zero-tap / copy-code).

Related controls to expose in our UI: template pacing, portfolio pacing, pausing, per-user marketing
limits, TTL override, tap-target override, Template Library.

---

## 5. Phone numbers & account assets

Field lists, value sets, and profile constraints in this section were verified **2026-08-04** against
Graph `v26.0` via the Meta Developer Tools MCP. They are what `/settings/whatsapp`
(`modules/m0-onboarding.md` §7) renders and writes.

| Operation | Endpoint |
|---|---|
| List numbers | `GET /{waba-id}/phone_numbers` |
| Number detail | `GET /{phone-number-id}?fields=…` |
| Register | `POST /{phone-number-id}/register` (`messaging_product`, `pin`) |
| Set two-step PIN | `POST /{phone-number-id}` (`pin`) — sets a **new** PIN; the current PIN is not required via the API (only via WhatsApp Manager). This is the escape hatch for `133005` when a client has forgotten theirs |
| Deregister | `POST /{phone-number-id}/deregister` |
| Request code | `POST /{phone-number-id}/request_code` |
| Verify code | `POST /{phone-number-id}/verify_code` |
| Settings | `GET|POST /{phone-number-id}/settings` |
| Business profile | `GET|POST /{phone-number-id}/whatsapp_business_profile` |
| Subscribe app | `GET|POST|DELETE /{waba-id}/subscribed_apps` |
| Block users | Block API on `/{phone-number-id}` |
| Conversational components | Conversational Automation API (ice breakers, commands) |
| QR codes | `/{phone-number-id}/message_qrdls` |

### Number fields — `GET /{phone-number-id}?fields=…`

| Field | Values |
|---|---|
| `display_phone_number` | as Meta formats it, e.g. `+256 762 850388` |
| `verified_name` | string |
| `status` | Meta's connection state for the number, e.g. `CONNECTED`. **Meta does not publish the returnable value set** — render what arrives rather than switching on a guessed enum. Not to be confused with our own `phone_numbers.status` lifecycle column |
| `quality_rating` | `GREEN` \| `YELLOW` \| `RED` \| `NA` \| `UNKNOWN` (a number Meta has not rated yet — what a freshly connected number returns) |
| `code_verification_status` | ⚠️ Meta's own docs disagree: the Phone Number API reference says `VERIFIED` \| `UNVERIFIED`, the Solution Providers "Client phone numbers" doc says `VERIFIED` \| `NOT_VERIFIED`. Store what arrives and make the UI tolerate both. Never a display-name state |
| ~~`code_verification_status`~~ | **`VERIFIED` \| `UNVERIFIED` only** — two-step verification state |
| `name_status` | `APPROVED` \| `AVAILABLE_WITHOUT_REVIEW` \| `DECLINED` \| `EXPIRED` \| `PENDING_REVIEW` \| `NONE` — display-name review state |
| `status` | `CONNECTED`, … |
| `throughput{level}` | `STANDARD` (80 mps) \| `HIGH` (1,000 mps) \| `NOT_APPLICABLE` |
| `platform_type` | `CLOUD_API` \| `ON_PREMISE` \| `NOT_APPLICABLE` |
| `is_on_biz_app` | bool — **true = Coexistence** |
| ~~`messaging_limit_tier`~~ | **Deprecated 2026-05-21.** Returns nothing on v24.0+, and we pin v26.0. Request `whatsapp_business_manager_messaging_limit` instead — it is a **business portfolio** limit, readable on the portfolio, the WABA, or a number within it. Values `TIER_50` \| `TIER_250` \| `TIER_1K` \| `TIER_2K` \| `TIER_10K` \| `TIER_100K` \| `TIER_UNLIMITED`. Asking for the old field returns an empty response, so any local default silently becomes fiction |
| `webhook_configuration` | per-number override |

> **`code_verification_status` and `name_status` are different fields and must never be conflated.**
> `EXPIRED` is a `name_status` value (the display-name certificate lapsed); it is not a possible
> value of `code_verification_status`. Render them as two rows with two labels.

`is_on_biz_app = true` **and** `platform_type = CLOUD_API` identifies a **Coexistence** number.

WABA-level (`GET /{waba-id}`): `id`, `name`, `account_review_status`, `business_verification_status`,
`timezone_id`, `message_template_namespace`.

### Business profile — `GET|POST /{phone-number-id}/whatsapp_business_profile`

GET `?fields=about,address,description,email,profile_picture_url,websites,vertical`.
POST requires `messaging_product: "whatsapp"` in the body on **every** write.

| Param | Constraint |
|---|---|
| `about` | 1–139 chars, non-empty. Emoji must be Java/JS-escaped unicode. No markdown; links render as plain text |
| `address` | ≤ 256 chars |
| `description` | ≤ 512 chars |
| `email` | valid address, ≤ 128 chars |
| `websites` | **max 2**, ≤ 256 chars each, each **must include `http://` or `https://`** |
| `vertical` | empty string, or one of the 21 values below |
| `profile_picture_handle` | write-only; a handle from the **Resumable Upload API** — not a URL and not a direct upload |

The read side returns `profile_picture_url`, the write side takes `profile_picture_handle`. The
write response is `{"success": true}` with no echo of the saved values, so re-GET after every save.

The 21 `vertical` values: `ALCOHOL`, `APPAREL`, `AUTO`, `BEAUTY`, `EDU`, `ENTERTAIN`, `EVENT_PLAN`,
`FINANCE`, `GOVT`, `GROCERY`, `HEALTH`, `HOTEL`, `NONPROFIT`, `ONLINE_GAMBLING`, `OTC_DRUGS`,
`OTHER`, `PHYSICAL_GAMBLING`, `PROF_SERVICES`, `RESTAURANT`, `RETAIL`, `TRAVEL`.

> These are **business-profile** verticals. They are not MBA's five supported verticals
> (`92-decisions.md` D-018) — different list, different purpose.

### Deregister — documented, never called

> **Luminous does not call this endpoint** (D-021). Disconnecting a team clears our records and
> unsubscribes our app; the number stays registered. `deregister()` is deliberately absent from the
> `GraphClient` contract. What follows is reference for reading Meta's behaviour, not a description
> of ours.

`POST /{phone-number-id}/deregister`, with two hard limits:

- **Not permitted for a Coexistence number** (`is_on_biz_app: true`). Unlinking the handset is the
  client's own action in the WhatsApp Business app (*Settings → Account → Business Platform →
  Disconnect*), which fires `account_update` / `PARTNER_REMOVED`.
- A number cannot be deleted if it was used to send paid messages in the last 30 days.

Also capped at **10 register/deregister attempts per number per rolling 72 hours** (`133016`, §8).
Deregistering makes the number unusable with Cloud API and disables local storage on it, but does
**not** delete the number or its message history; re-registering is the whole registration flow
again, PIN included.

None of that is needed to stop serving a WABA, which is why we don't. `DELETE
/{waba-id}/subscribed_apps` unsubscribes our app and **immediately stops all webhook deliveries** for
that account, leaving the number registered and working. It has none of deregister's limits and is
permitted for a Coexistence number.

*(Re-verified 2026-08-04 via Meta Developer Tools MCP: Registration, Phone Number Deregister API,
Subscribed Apps API, Managing Webhooks.)*

---

## 6. Analytics

```
GET /{version}/{waba-id}?fields=<field>.<filters>
```

| Field | Returns | Lookback |
|---|---|---|
| `analytics` | messages sent/delivered by number and type | **1 year** |
| `conversation_analytics` | conversation volume + cost by category/type | **1 year** |
| `pricing_analytics` | cost/volume by country, phone, pricing category, pricing type, tier | **1 year** |
| `template_analytics` | sent, delivered, read, clicked, cost per delivered, cost per URL click | 90 days |
| `template_group_analytics` | same, grouped | 90 days |

Granularity: `HALF_HOUR` / `DAY(ILY)` / `MONTH(LY)`.
`pricing_analytics` dimensions: `COUNTRY`, `PHONE`, `PRICING_CATEGORY`, `PRICING_TYPE`, `TIER`.

> `COST` is **not** returned for WABAs sharing a Solution Partner credit line. As a Tech Provider our
> clients pay Meta directly, so cost should be present — but handle its absence.

Because of the 1-year cap, snapshot into `analytics_snapshots` daily and never rely on Meta for
historical reporting.

Template analytics require a one-time, **irreversible** opt-in per WABA:
`POST /{waba-id}?is_enabled_for_insights=true` (do it during onboarding, M0 step 4). Read/click
event data for a given template message is only recorded for **7 days from send** — the 90-day
lookback returns history, but per-message read/click counts freeze after day 7.

---

## 7. Rate limits and throughput

| Limit | Value |
|---|---|
| **Message-send throughput (per number)** | **80 messages/sec default** (`STANDARD`), auto-upgraded to **1,000 mps** (`HIGH`) when eligible; **Coexistence numbers are fixed at 20 mps**. Inclusive of inbound + outbound, all message types. Exceeding it returns **`130429`**. |
| Business Management endpoints (`message_templates`, `phone_numbers`, `subscribed_apps`, WABA reads) | 200 req/hr per app per WABA; **5000 req/hr per app per *active* WABA** (≥1 registered number). **This is a management-API limit, not a message-send limit.** |
| Credit Line API | 5000 req/hr |
| **Per-recipient pair limit** | **1 message per 6 seconds** to the same WhatsApp user (≈10/min, 600/hr) |
| Pair burst allowance | up to **45 messages in a 6-second burst to the same user**, borrowed from future quota; then wait the equivalent time. **Per pair, not global.** |
| Post-burst retry | on failure, retry after `4^X` seconds, X starting at 0 |
| Exceeded pair limit error | **`131056`** |
| Messaging limits (unique users messaged outside CSW / rolling 24h) | **Portfolio-based since late 2025**: 2K → 10K → 100K → UNLIMITED per business portfolio, read via `whatsapp_business_manager_messaging_limit`; changes arrive on `business_capability_update` (`max_daily_conversations_per_business`). Legacy per-number `TIER_*` fields are deprecated. |
| Throughput field | `GET /{phone-number-id}?fields=throughput` → `STANDARD` or `HIGH` |

All of these belong in `config/limits.php` so they are testable and tunable in one place.

---

## 8. Errors we must handle explicitly

| Code | Meaning | Our handling |
|---|---|---|
| `131056` | Pair rate limit exceeded | Backoff `4^X`; requeue, do not fail the recipient |
| `130429` | Per-number throughput (mps) exceeded | Slow the campaign sender; requeue, do not fail the recipient |
| `131057` | Number briefly unusable during a throughput upgrade (~1 min) | Requeue with a short delay |
| `131047` | Re-engagement required (outside CSW) | Block in UI before send; if it happens, prompt template selection |
| `131026` | Message undeliverable (not on WhatsApp / cannot receive) | Mark contact `invalid`, suppress from future campaigns |
| `131042` | Business eligibility / payment issue | Surface "add a payment method" banner; suspend sends |
| `133005` | **Two-step verification PIN mismatch** — the number already has 2FA set | Register requires the number's **existing** PIN, not a generated one. Prompt the client for it; a generated PIN is only valid for a number without 2FA. Reset path: WhatsApp Manager → number → Settings → Two-step verification → Change PIN |
| `133008` / `133009` | Too many PIN guesses / guessed too fast | Stop retrying; honour the wait in `error_data.details` |
| `133010` | Number not registered | Prompt re-registration in onboarding |
| `133016` | **Register/deregister rate limit** — 10 attempts per number per rolling 72h | Never auto-retry registration; each Resume click spends one attempt |
| `190` | Token expired/invalid | Trip credential circuit breaker, prompt reconnect |
| `368` | Temporarily blocked for policy violation | Halt all sends on that number, raise a `health_events` critical |
| `80007` | Rate limit hit | Global backoff for the WABA |
| `2593109` | Coexistence: history sharing declined by the client | Complete onboarding without history; inform the client |
| `131060` | Coexistence: unsupported message type | Log, render a placeholder in the thread |

Every error response is persisted in `messages.error_detail` verbatim. Never discard the payload.

---

## 9. Idempotency

The Cloud API has **no idempotency key**. Our guarantees come from our own schema:

- Inbound: `messages.wamid` unique + `webhook_deliveries.body_sha256` unique.
- Outbound single sends: an application-level lock keyed on
  `(conversation_id, hash(payload))` for a short window, plus optimistic UI reconciliation.
- Campaigns: `campaign_recipients (campaign_id, contact_id)` unique — the real double-send guard.

---

## 10. Sources

- <https://developers.facebook.com/documentation/business-messaging/whatsapp/get-started>
- <https://developers.facebook.com/documentation/business-messaging/whatsapp/reference/whatsapp-business-phone-number/message-api>
- <https://developers.facebook.com/documentation/business-messaging/whatsapp/reference/whatsapp-business-phone-number> — number fields, register/deregister, two-step PIN (§5, re-verified 2026-08-04 via Meta Developer Tools MCP on `v26.0`)
- <https://developers.facebook.com/documentation/business-messaging/whatsapp/reference/whatsapp-business-profile> — business profile params, limits, and the 21 verticals (§5, same verification)
- <https://developers.facebook.com/documentation/business-messaging/whatsapp/about-the-platform>
- <https://developers.facebook.com/documentation/business-messaging/whatsapp/analytics>
- <https://developers.facebook.com/documentation/business-messaging/whatsapp/pricing>
