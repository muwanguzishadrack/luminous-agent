# M0 — Tenant Onboarding & Platform

**Goal:** a business clicks "Connect WhatsApp" on our site and, unattended, ends up with a live
number and a working inbox in under 10 minutes.

Tables: `tenants`, `users`, `tenant_user`, `audit_logs`, `waba_accounts`, `phone_numbers`,
`meta_credentials`, `onboarding_sessions`.

---

## 1. Embedded Signup v4

**Build on v4 only. v2 is removed October 15, 2026.**

Flow:

```
Browser                          Our server                     Meta
  │  click "Connect WhatsApp"
  │  create onboarding_session ──▶ (nonce)
  │  FB.login({config_id, response_type:'code',
  │            override_default_response_type:true,
  │            extras:{ setup:{}, featureType:'', sessionInfoVersion:'3' }})
  │ ─────────────────────────────────────────────────────────▶ ES window
  │ ◀──── session events (WA_EMBEDDED_SIGNUP) ─────────────────
  │       capture every event into onboarding_sessions.events
  │ ◀──── FINISH: { waba_id, phone_number_id, code }
  │  POST /onboarding/exchange {nonce, code, waba_id, phone_number_id}
  │                            ──▶ exchange code → business token
  │                            ──▶ register phone number (+PIN)
  │                            ──▶ POST /{waba}/subscribed_apps
  │                            ──▶ fetch number + WABA metadata
  │                            ──▶ tenant status = active
```

The `extras.setup` object can pre-fill business details to shorten the flow — worth doing when we
already know the business name, email, and website.

### Server steps in order (`Actions/Onboarding/`)

| # | Action | Notes |
|---|---|---|
| 1 | `ExchangeSignupCode` | `POST /oauth/access_token` with `client_id`, `client_secret`, `code`. Store as `meta_credentials.type=business`. **Never log the code or token.** |
| 2 | `RegisterPhoneNumber` | `POST /{phone_number_id}/register`. PIN rule: if the number **already has two-step verification**, Meta requires *that* PIN (else `133005`) — the client supplies it and we pass it through for one run only; otherwise we generate a 6-digit PIN. Persist `pin_set`. **Skip entirely for Coexistence numbers** — already registered. |
| 3 | `SubscribeWabaWebhooks` | `POST /{waba_id}/subscribed_apps`; verify with a `GET` and assert our app id is present |
| 4 | `SyncWabaAssets` | Fetch WABA + numbers; populate `waba_accounts`, `phone_numbers` incl. `quality_rating`, `messaging_limit_tier`, `throughput`, `platform_type`, `is_on_biz_app`. Also confirm template analytics (`POST /{waba_id}?is_enabled_for_insights=true` — one-time, irreversible; required before M8 can pull `template_analytics`) |
| 5 | `SyncTemplates` | Initial template pull so M3 is populated on arrival |
| 6 | `CheckPaymentReadiness` | Tech Provider: the **client** must attach their own payment method. Set `waba_accounts.payment_ready` |
| 7 | `CompleteOnboarding` | tenant `status = active`, dispatch a welcome, emit audit log |

Each step is idempotent and independently retryable, driven from `onboarding_sessions.status` so a
half-finished onboarding can be resumed rather than restarted.

---

## 2. Token vault

Two credential types. See `05-security-multitenancy.md` for storage rules.

| Type | Used for | Obtained |
|---|---|---|
| `business` | All Cloud API calls | Embedded Signup code exchange |
| `bisu` | **All Meta Business Agent calls** | Separate BISU flow (M5) |

`GraphClient` resolves the correct credential from the tenant + the API family being called. A
missing or revoked credential throws a typed exception that the UI renders as a reconnect prompt —
never a 500.

Circuit breaker: 3 consecutive `190`/`401` → `revoked_at`, suspend sends, prompt reconnect.

---

## 3. Coexistence (onboarding WhatsApp Business app users)

Our strongest SMB wedge: the owner keeps using the WhatsApp Business app on their phone **and** the
team uses our CRM on the same number, with history in both.

Enable by adding `featureType: 'whatsapp_business_app_onboarding'` to the ES `extras`. The WABA
selection screen is replaced by a "connect your existing account" screen. The finish event is
`FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING`.

Requirements: client on WhatsApp Business app **2.24.17+**.

### The 24-hour, one-shot sync

```
onboard (skip phone registration — already registered)
   │
   ├─▶ POST /{phone_number_id}/smb_app_data {sync_type: "smb_app_state_sync"}   ← contacts
   │      → smb_app_state_sync webhooks (snapshot, then incremental forever)
   │
   └─▶ POST /{phone_number_id}/smb_app_data {sync_type: "history"}              ← messages
          → history webhooks in phases and chunks with a progress % (0–100)
          → media arrives as `media_placeholder`, then a SECOND webhook with the
            real content + media id — only for messages from the last two weeks
          → if the client declined sharing: a history webhook with error 2593109
```

Hard rules:

| Rule | Consequence if violated |
|---|---|
| Sync must start **within 24 hours** of onboarding | Client must offboard and redo the entire ES flow |
| Each `sync_type` may be called **once** | Same |
| We must be subscribed to `history`, `smb_app_state_sync`, `smb_message_echoes` **before** syncing | Webhooks are lost, unrecoverably |

UX requirements during sync:
- Tell the client we are importing and **ask them to keep the WhatsApp Business app open**.
- Show live progress from the webhook `progress` field.
- Tell them when it is complete.
- If `2593109`, complete onboarding gracefully and explain history is unavailable.

Ongoing: every message the owner sends from their handset arrives as `smb_message_echoes` and must be
rendered in the thread with `messages.origin = 'owner_device'`.

Offboarding: we **cannot** use Deregister on a Coexistence number. The client disconnects from
*WhatsApp Business app → Settings → Account → Business Platform → Disconnect*, which fires
`account_update` with `PARTNER_REMOVED` and a `disconnection_info` object.

Verify status any time:
```
GET /{phone_number_id}?fields=is_on_biz_app,platform_type
→ is_on_biz_app: true, platform_type: "CLOUD_API"  ⇒ Coexistence number
```

> **Do not ship Coexistence until webhook ingest is proven under load.** A dropped `history` chunk
> cannot be re-requested.

---

## 4. Payment readiness (Tech Provider specific)

We have no credit line. The client attaches their own payment method to their WABA; Meta bills them
for messaging, we bill them for the platform.

- Watch `payment_configuration_update`.
- Error `131042` on send ⇒ payment issue. Block sends, show a blocking banner with a link to Meta's
  help article, and do not let the client start a campaign.
- Optional future path: a Multi-Partner Solution with a Solution Partner so their credit line covers
  our clients. Onboarding limit in that model: **200 new clients per rolling week**. Deferred —
  see `92-decisions.md` D-005.

---

## 5. Tenant lifecycle

| `account_update` event | Our action |
|---|---|
| `PARTNER_ADDED`, `PARTNER_APP_INSTALLED` | Provision/reactivate tenant, capture `waba_id`, `solution_id`, `owner_business_id` |
| `PARTNER_REMOVED` | Suspend tenant, capture `disconnection_info`, retain data per retention policy, notify the account owner |

Offboarding also needs a self-serve path: export everything, then disconnect.

---

## 6. Platform layer

| Feature | Notes |
|---|---|
| RBAC | `owner`, `admin`, `supervisor`, `agent`, `viewer`; optional per-number scoping via `tenant_user.phone_number_ids` |
| Invitations | Email invite → accept → role assignment; pending invites visible |
| Audit log | Every send, template edit, export, token use, agent config change |
| White-label | Custom domain, logo, colour tokens per tenant (`tenants.settings`) |
| Public API + outbound webhooks | Token-scoped tenant API; signed outbound webhooks with retry |
| Impersonation | Time-boxed, audited, visibly banner-ed |
| Sandbox tenants | Meta sandbox test accounts, valid 30 days, for demos and E2E tests |

---

## 7. UI surface

| Route | Screen |
|---|---|
| `/onboarding` | Connect WhatsApp — ES launcher, live step progress, resumable |
| `/onboarding/sync` | Coexistence import progress with the "keep your app open" guidance |
| `/settings/numbers` | Numbers, quality, tier, throughput, profile editing, PIN, registration state |
| `/settings/team` | Users, roles, invitations, per-number scoping |
| `/settings/billing` | Plan, wallet balance, payment-readiness status |
| `/settings/api` | API tokens, outbound webhook endpoints |
| `/settings/audit` | Filterable audit log |

---

## 8. Edge cases

| Case | Handling |
|---|---|
| Client abandons the ES window mid-flow | `onboarding_sessions` stays `started`; resumable from the last completed step |
| `code` exchange fails | Show the real Meta error; allow retry without restarting ES |
| Phone registration fails (`133010`) | Offer request-code/verify-code flow inline |
| Number already has two-step verification (`133005`) | Meta requires the number's **existing** 6-digit PIN on register. The UI prompts for it and resumes with it; the PIN is used for that run only and is never persisted or logged. If the client cannot recall it, they change it in WhatsApp Manager and enter the new one. |
| Repeated registration attempts (`133016`) | Registration is capped at **10 attempts per number per rolling 72 hours** — surface remaining attempts and never retry automatically |
| Number already connected to another tenant of ours | Refuse with a clear message; do not silently move it |
| Client revokes our app in Business Suite | `PARTNER_REMOVED` → suspend, prompt reconnect |
| Same person onboards two businesses | Supported — user belongs to multiple tenants |
| Webhook arrives for a WABA we do not know | Park as `ignored`, alert. **Never auto-create a tenant from a webhook.** |

---

## 9. Acceptance criteria

1. A sandbox business completes ES v4 and reaches an active tenant with a registered number, no
   manual steps.
2. Killing the server mid-onboarding and restarting resumes from the last completed step.
3. A Coexistence onboarding imports contacts and history, renders them in the inbox, and mirrors a
   message sent from the handset within 5 seconds.
4. Declined history sharing (`2593109`) completes onboarding with a clear explanation.
5. Revoking the app in Business Suite suspends the tenant within one webhook cycle.
6. No token value appears in any log, Inertia prop, or error page — asserted by a test.
