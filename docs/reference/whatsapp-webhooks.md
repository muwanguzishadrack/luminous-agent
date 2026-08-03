# Reference — WhatsApp Webhooks

Verified 2026-08-01. This is the complete list of fields we subscribe to and what each one drives.

> **Live check 2026-08-03** (Meta DevTools, app 918140253822152): `standby`, `messaging_handovers`
> and `message_echoes` are all confirmed as valid fields on the `whatsapp_business_account` topic —
> but the app's current subscription **does not include any of them**. Add `standby` and
> `messaging_handovers` in the App Dashboard before Phase 1 (they drive the M1/M5 ownership state
> machine); decide on `message_echoes` after the H2 empirical test. The current subscription also
> includes `calls` and the `group_*` fields, which are out of scope for v1 — harmless, but their
> deliveries will be parked as unhandled.

---

## 1. Endpoint contract

### Verification (`GET`)

```
GET /webhooks/meta?hub.mode=subscribe&hub.challenge=…&hub.verify_token=…
→ 200 with the raw hub.challenge value as the body
```

Compare `hub.verify_token` with `hash_equals`. Never log the token.

### Delivery (`POST`)

```php
$expected = hash_hmac('sha256', $request->getContent(), config('meta.app_secret'));
abort_unless(hash_equals("sha256={$expected}", $request->header('X-Hub-Signature-256', '')), 401);
```

Computed over the **raw body**. Register outside the `web` middleware group so nothing re-encodes it.

### Envelope

```json
{
  "object": "whatsapp_business_account",
  "entry": [{
    "id": "<WABA_ID>",
    "changes": [{ "value": { … }, "field": "<field>" }]
  }]
}
```

One delivery may contain **many entries, each with many changes, each with many messages/statuses**.
Iterate all of it; never assume one message per request.

Processing contract: verify → persist raw → **200 immediately** → process async, per-change, with
each change isolated so one bad object does not lose the rest.

---

## 2. Subscribed fields

| Field | Fires when | What it drives |
|---|---|---|
| **`messages`** | Inbound customer message, **or** an outbound status update — while *we* hold thread control | M1 inbox, contacts, CSW timer, media, orders, CTWA referral |
| **`standby`** | Inbound customer message while **MBA holds control** | M1 read-only mirror of the AI conversation |
| **`messaging_handovers`** | Thread control passes/taken/requested, or app roles change | M1/M5 ownership state machine — **authoritative** |
| `smb_message_echoes` | Coexistence: the owner sends from the WhatsApp Business app | M1 thread mirroring |
| `smb_app_state_sync` | Coexistence: contacts snapshot and subsequent changes | M2 contact import |
| `history` | Coexistence: past message history chunks | M1 history backfill |
| `user_preferences` | Customer changes marketing preference ("Stop promotions") | **M2 consent — authoritative opt-out** |
| `message_template_status_update` | Template approved/rejected/paused/disabled | M3 status |
| `message_template_quality_update` | Template quality score change | M3 quality |
| `message_template_components_update` | Template components edited by Meta | M3 sync |
| `template_category_update` | Meta re-categorises a template | **M3 + M8 — the price just changed** |
| `phone_number_quality_update` | Quality rating or messaging tier change | M8 health |
| `phone_number_name_update` | Display name approval | M0 |
| `account_update` | `PARTNER_ADDED`, `PARTNER_APP_INSTALLED`, `PARTNER_REMOVED`, verification changes, disconnections | **M0 tenant lifecycle** |
| `account_review_update` | WABA review decision | M8 health |
| `account_alerts` | Policy/capability alerts | M8 health |
| `business_capability_update` | Messaging limit / capability change | M8 health |
| `partner_solutions` | Multi-Partner Solution state changes | M0 (only if we join a solution) |
| `payment_configuration_update` | Client's payment method state | M0 payment gate |
| `security` | 2FA / security setting changes | M0 audit |
| `calls` | Calling API events | **not subscribed — calling is out of scope for v1** |

---

## 3. `messages` — value object anatomy

```json
{
  "messaging_product": "whatsapp",
  "metadata": { "display_phone_number": "…", "phone_number_id": "…" },
  "contacts": [{ "profile": { "name": "…" }, "wa_id": "…" }],
  "messages": [ … ],
  "statuses": [ … ],
  "errors": [ … ]
}
```

`metadata.phone_number_id` is how we resolve the tenant. **If it does not match a known
`phone_numbers` row, park the delivery as `ignored` and alert — never guess a tenant.**

### Inbound message sub-types to handle

`text`, `image`, `audio`, `video`, `document`, `sticker`, `location`, `contacts`, `reaction`,
`button`, `interactive` (button_reply / list_reply / nfm_reply for Flows), `order`, `system`,
`unsupported`.

Notable extras:

| Key | Meaning |
|---|---|
| `context.id` | The wamid this message replies to |
| `context.forwarded` / `frequently_forwarded` | Forwarded content |
| `context.referred_product` | Customer asked about a specific catalogue product |
| **`referral`** | **CTWA attribution** — `source_id`, `source_type`, `source_url`, `headline`, `body`, `media_type`, `ctwa_clid`. Opens the 72h FEP window. |
| `order` | Cart submission: `catalog_id`, `product_items[]`, `text` |
| `system` | Customer changed number → identity change handling |
| `errors` | Inbound-side failures, e.g. `131060` on Coexistence |

### `statuses` sub-object

```json
{
  "id": "wamid…",
  "status": "sent|delivered|read|failed",
  "timestamp": "…",
  "recipient_id": "…",
  "conversation": { "id": "…", "origin": { "type": "…" }, "expiration_timestamp": "…" },
  "pricing": { "billable": true, "pricing_model": "PMP", "type": "regular", "category": "service" },
  "errors": [ { "code": 131056, "title": "…", "error_data": { "details": "…" } } ]
}
```

Handling rules:

1. **`pricing.category` is the billing truth.** Values include `marketing`, `utility`,
   `authentication`, `service`, and (from Aug 1, 2026) a Meta Business Agent value. Record it on the
   message and feed the meter.
2. **Statuses arrive out of order.** `read` may precede `delivered`. Append to `message_events`
   always; only advance `messages.status` through a legal forward transition.
3. **A status may arrive for a wamid we have never seen.** Create a stub message row.
4. `conversation.expiration_timestamp`, when present, is a second source for the CSW deadline —
   prefer it over our own arithmetic when available.

---

## 4. `standby` vs `messages` — the routing rule

| Thread owner | Customer message arrives on |
|---|---|
| Meta Business Agent | `standby` |
| Our app | `messages` |

Both must land in the **same conversation and message timeline**. The only difference is the
resulting `conversations.state` and whether the composer is enabled.

While MBA holds control we also receive **copies of the agent's outbound messages and their delivery
and read receipts**, so the thread stays complete. Those are stored with `messages.origin = 'mba'`.

---

## 5. `messaging_handovers`

Sub-events: `pass_thread_control`, `take_thread_control`, `request_thread_control`, `app_roles`.

Each carries `previous_owner_app_id`, `new_owner_app_id`, and a free-text `metadata` string.
`previous_owner_app_id` may be `null` (thread was idle).

**This field is the authoritative owner of `conversations.state`.** Our local transitions are
optimistic; every handover event reconciles. Append to `thread_control_events` unconditionally.

We put a structured JSON string in `metadata` on every `pass` we initiate
(`{"reason":"agent_handback","user_id":"…"}`) so the audit trail explains *why* control moved.

---

## 6. Coexistence fields

| Field | Contents |
|---|---|
| `smb_app_state_sync` | The client's WhatsApp contacts, initially as a snapshot then incrementally |
| `history` | Past threads in **phases and chunks**, with a `progress` percentage 0–100. Media messages first arrive as `media_placeholder`, then a **second** webhook carries the actual content and media id — but only for messages from the last two weeks. `MESSAGE_STATUS` values seen here: `DELIVERED`, `ERROR`, `PENDING`, `PLAYED`, `READ`, `SENT`. |
| `smb_message_echoes` | Every message the owner subsequently sends from their handset. Includes a `to` field, which normal `messages` payloads do not. |

Hard constraints:
- Sync must be initiated **within 24 hours** of onboarding, and each sync type **only once**.
- If the client declined history sharing, we get a `history` webhook with error **`2593109`**.
- Progress can take several minutes; the client should keep the app open.

---

## 7. `user_preferences`

Fires when the customer changes their marketing preference in WhatsApp. Writes a `consents` row with
`source = whatsapp_native`. **A native `revoked` state overrides every other consent source and can
never be overridden by an import, an agent, or an API call.**

---

## 8. `account_update` events we act on

| Event | Action |
|---|---|
| `PARTNER_ADDED` / `PARTNER_APP_INSTALLED` | Provision or reactivate the tenant; capture `waba_id`, `solution_id`, `owner_business_id` |
| `PARTNER_REMOVED` | Suspend the tenant; inspect `disconnection_info` for reason and whether client- or system-initiated |
| Verification / capability changes | Update `waba_accounts`, raise `health_events` |

---

## 9. Local testing

Cloudflare named tunnel + Meta's webhook config in the Developer Portal (see
`03-local-development.md`). For fast iteration without Meta, replay a fixture:

```bash
php artisan webhook:replay tests/Fixtures/meta/messages/text_inbound.json
```

This posts through the real route with a correctly computed signature, so signature verification and
ingest are both exercised.
