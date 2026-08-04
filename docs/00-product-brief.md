# 00 — Product Brief

## What this is

A multi-tenant SaaS WhatsApp CRM. Businesses connect their own WhatsApp Business Account through
our portal and get a shared team inbox, contact management, message templates, broadcast campaigns,
an AI agent, in-conversation commerce with local payments, ad attribution, and analytics.

One business is one **team**: a user belongs to one team, and a team holds one WhatsApp Business
Account with one phone number. No workspace switching — someone running two businesses signs up
twice (`92-decisions.md` D-020).

We operate as a **Meta Tech Provider**. That has three hard consequences that shape the product:

| Consequence | Impact |
|---|---|
| We have **no credit line** | Each client attaches their own payment method to their WABA. Meta bills them for messaging; we bill them for the platform. |
| Clients' assets are **shared with our app**, not owned by us | Every API call is made with a per-team token, never a global one. |
| Onboarding is **self-serve via Embedded Signup** | The onboarding flow is a first-class product surface, not an internal tool. |

## Who it is for

| Segment | Why they buy |
|---|---|
| Ugandan retail & e-commerce SMBs | Sell in WhatsApp, collect via MTN/Airtel money, one inbox for the team |
| Service businesses (salons, clinics, schools, logistics) | Booking, reminders, and a team inbox instead of one phone passed around |
| Agencies / resellers | White-label the platform for their own client base |
| Businesses running Click-to-WhatsApp ads | Attribution and closed-loop ROAS they cannot get elsewhere |

## Scope — eight modules

| ID | Module | One-line outcome |
|---|---|---|
| M0 | Onboarding & platform | A client self-onboards in minutes and their number is live |
| M1 | Team inbox | Multiple agents handle one WhatsApp number without stepping on each other |
| M2 | Contacts, consent & segmentation | Know who you are talking to, and never message someone who opted out |
| M3 | Template management | Create, submit, and monitor templates without touching WhatsApp Manager |
| M4 | Campaigns & broadcast | Send to a segment, safely, inside rate limits and budget |
| M5 | Meta Business Agent | Meta's AI answers first, with our CRM context, and hands off cleanly |
| M6 | Commerce & payments | Sell in-thread and collect money via ioTec Pay |
| M7 | Ads that Click to WhatsApp | Attribute every lead to its ad and report conversions back to Meta |
| M8 | Analytics, billing & health | Know what it costs, what it earns, and whether the number is healthy |

Details in `modules/`.

## Explicit non-goals for v1

| Not building | Why |
|---|---|
| WhatsApp Calling API / SIP | Out of scope by decision. Revisit after M8. |
| Groups API | Out of scope by decision. |
| Our own LLM agent | MBA replaces it. **Risk logged below.** |
| Native WhatsApp Payments (India UPI / Brazil Pix) | Not available in Uganda. ioTec Pay is the payment rail. |
| Instagram / Messenger channels | WhatsApp only. |
| On-premises API | Deprecated by Meta; Cloud API only. |

## Known risks

| # | Risk | Mitigation |
|---|---|---|
| R1 | **MBA covers only 5 verticals** (Automotive, CPG, Professional Services, Retail/Ecommerce, Travel). Clients outside them get an inbox with no AI at all. | **Closed 2026-08-03** — pipeline confirmed all-in-vertical (D-018). Eligibility gating stays as a safety net; reopen if the pipeline mix changes. |
| R2 | **Oct 1, 2026**: all non-template messages become chargeable, including human agent replies that are free today. Every team's cost profile changes. | Build the dual-meter cost engine (M8) before that date and ship the impact projector. |
| R3 | **Embedded Signup v2 is removed Oct 15, 2026.** | Build on v4 from day one. Never touch v2. |
| R4 | Coexistence history sync is a **one-shot 24-hour window**. A failed sync means the client must offboard and redo the whole flow. | Do not ship Coexistence until webhook ingest is proven under load. |
| R5 | MBA context boundaries are undocumented (does it see pre-enablement history? messages we sent while holding control?). | Empirical test on a sandbox number before designing handoff UX. Tracked in `91-prerequisites.md`. |
| R6 | ioTec `externalId` is explicitly **not required to be unique** by the provider. | We generate our own ULID `external_id` with a local unique index and treat ioTec's `id` (uuid) as the authoritative reference. |

## Success criteria for v1

1. A brand-new client can go from "click Connect" to "sent a real WhatsApp message" in under 10 minutes, unattended.
2. Zero lost inbound messages over a 7-day soak with induced failures.
3. A broadcast to 10,000 contacts completes inside rate limits with per-recipient outcome visibility.
4. A customer can order and pay by MTN or Airtel money inside one WhatsApp conversation, with the payment reconciled automatically.
5. Every team can see, for any date range: messages sent, cost, MBA token spend, revenue collected, and per-campaign ROI.
