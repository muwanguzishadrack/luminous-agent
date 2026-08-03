# M7 — Ads that Click to WhatsApp

**Goal:** every lead is attributed to the ad that produced it, and conversions flow back to Meta so
ad optimisation actually learns.

Tables: `ctwa_referrals`, `conversions`, plus `conversations.fep_expires_at`.

---

## 1. Referral capture

When a customer arrives from a Click-to-WhatsApp ad, their **first inbound message** carries a
`referral` object in the `messages` webhook.

```jsonc
"referral": {
  "source_url": "https://fb.me/…",
  "source_id": "<AD_ID>",
  "source_type": "ad",
  "headline": "20% off shea butter this week",
  "body": "Message us to order",
  "media_type": "image",
  "image_url": "…",
  "ctwa_clid": "<CLICK_ID>"
}
```

Handling, in the inbound handler:

1. Persist `ctwa_referrals` linked to contact, conversation, and `message_wamid`.
2. Set `contacts.source = 'ctwa'` on first touch (never overwrite a later touch — keep first-touch
   attribution and record subsequent referrals as additional rows).
3. **Open the 72-hour free entry point window**: `conversations.fep_expires_at = now + 72h`.
4. Apply the tenant's CTWA routing rules: assign, label, enroll in a sequence, or leave to MBA.
5. Emit `CtwaLeadArrived` for automations.

`ctwa_clid` is the join key for Conversions API attribution and must be preserved for the life of the
contact — without it, Meta cannot attribute a later purchase to the ad.

---

## 2. The 72-hour free window

| Property | Value |
|---|---|
| Duration | **72 hours** from the ad click / CTA arrival |
| What is free | **Message delivery**, including template messages |
| What is still charged | **Meta Business Agent tokens** — never free |
| Sources | Click-to-WhatsApp ads and Facebook page call-to-action buttons |

This is real money. A tenant who works CTWA leads inside 72 hours pays nothing for delivery; one who
follows up on day four pays marketing rates. So:

- The conversation shows a **"Free window — 71h left"** chip, visually distinct from the CSW chip.
- A saved inbox view: **"CTWA leads — free window closing < 12h"**.
- Sequences triggered by CTWA arrival default to steps inside 72 hours.
- The campaign pre-flight cost estimate treats in-window recipients as zero delivery cost.

Both windows run concurrently and mean different things — CSW governs *what* you may send, FEP
governs *what it costs*. The UI must not conflate them.

---

## 3. Conversions API — the piece most integrations skip

Without reporting conversions back, Meta's optimiser is blind and the client's cost per lead stays
high. This is a genuine differentiator and it is cheap to build.

| Event | When we send it |
|---|---|
| `Lead` | Contact qualifies — a configurable rule, or MBA's `log_intent` write tool |
| `AddToCart` | `order` message received |
| `InitiateCheckout` | `order_details` sent with a payment ask |
| `Purchase` | Payment reaches `Success` (M6) — with `value` and `currency` |

Implementation:

- One row per event in `conversions` with a `dedup_key` unique index:
  `hash(event_name + contact_id + order_id)` for order-linked events; for events with no order
  (`Lead`), use `hash(event_name + contact_id + ctwa_referral_id)` — one `Lead` per contact per
  referral. A retry never double-reports.
- Include `ctwa_clid` from `ctwa_referrals` as the attribution signal.
- Queue `analytics`, retry with backoff, store the API response.
- Never block a customer-facing flow on a conversions call.
- A tenant toggle plus per-event toggles, because some clients will not want purchase values shared.

Also worth reporting on behalf of Coexistence clients, who commonly run CTWA ads — Meta explicitly
recommends it.

---

## 4. Other entry points

| Entry point | Capture |
|---|---|
| **QR codes / short links** | QR Code Management API on `/{phone-number-id}`; per-campaign `wa.me` links with prefilled text. Track scans → first message. |
| Facebook page CTA button | Same `referral` object; also opens the 72h window |
| Website click-to-chat | Our own generated links with a tracking parameter in the prefilled text |
| **Welcome message sequences** | Meta's native auto-welcome for CTWA arrivals — must be coordinated with MBA so the customer does not get two greetings |

The prefilled-text trick is the cheapest attribution channel available: encode a campaign token in the
message body, strip it before display, and record the source.

---

## 5. MBA interaction

CTWA leads are the highest-value place to put the AI: they arrive at all hours with buying intent.

1. On CTWA arrival, the M5 `customer-context` Connector includes
   `attribution: { source: 'ctwa', campaign, ad_headline }` so the agent can open with relevance
   ("you saw our shea butter offer…").
2. MBA qualifies the lead and calls `log_intent`; a qualifying intent triggers the `Lead` conversion.
3. `escalate_if` hints route high-value enquiries to a human immediately.

**Cost note:** MBA tokens are charged even inside the free window. A high-volume CTWA campaign
answered entirely by MBA has a real per-conversation cost — surface it in the campaign ROI view so
the client sees ad spend *and* AI spend against revenue.

---

## 6. Closed-loop ROAS

The report that sells the product:

```
ad spend (client-supplied or Marketing API)
   → CTWA clicks
   → conversations started        (ctwa_referrals)
   → leads qualified              (conversions: Lead)
   → orders                       (orders)
   → revenue collected            (payments status=Success)
   ─ messaging cost               (usage_meters: template/service)
   ─ MBA token cost               (usage_meters: mba_tokens)
   ─ payment fees                 (usage_meters: payment_fee)
   = contribution per ad
```

v1 accepts ad spend as a manual monthly input per campaign; Marketing API ingestion is a later
enhancement. Everything to the right of "CTWA clicks" we already own, which is more than most
competitors can assemble.

---

## 7. UI surface

| Route | Screen |
|---|---|
| `/growth/ctwa` | Leads by ad/campaign: volume, qualification rate, order rate, revenue, free-window usage |
| `/growth/ctwa/{ad}` | Drill-down to the conversations from one ad |
| `/growth/links` | QR codes and tracked `wa.me` links with scan/click counts |
| `/growth/conversions` | Conversions sent, failed, dedup stats, per-event toggles |
| `/growth/roas` | The closed-loop table above, with manual ad-spend entry |

In the inbox, a CTWA conversation shows an **attribution card** in the contact panel: ad headline,
creative thumbnail, campaign, and time since click. Agents close better when they can see what the
customer was promised.

---

## 8. Edge cases

| Case | Handling |
|---|---|
| Returning contact clicks a new ad | New `ctwa_referrals` row; `contacts.source` keeps first touch; latest referral drives the current conversation's attribution |
| `referral` present but `ctwa_clid` missing | Store what we have; conversions are sent without the click id and will attribute less precisely |
| Purchase 30 days after the click | Still report it; Meta handles the attribution window |
| Conversions API call fails | Retry with backoff; never block the order or payment flow |
| Client disables value sharing | Send the event without `value` |
| Duplicate purchase event | `dedup_key` unique index blocks it |
| Free window and CSW both expire | Conversation needs a template *and* it now costs money — UI must say both |
| Welcome message sequence plus MBA both reply | Detect and warn during setup; recommend one or the other |

---

## 9. Acceptance criteria

1. A CTWA first message creates a `ctwa_referrals` row with every field present in the payload and
   sets `fep_expires_at` to +72h.
2. The inbox shows an attribution card and a free-window chip distinct from the CSW chip.
3. A payment reaching `Success` sends a `Purchase` conversion with `ctwa_clid`, value and currency —
   exactly once under retry.
4. A duplicate conversion attempt is blocked by `dedup_key`.
5. Campaign cost estimates treat in-free-window recipients as zero delivery cost.
6. The ROAS view reconciles: revenue − messaging − MBA tokens − payment fees, matching
   `usage_meters` totals for the period.
7. A tracked `wa.me` link records the campaign token and strips it from the displayed message.
