# M0 — Onboarding & Platform

**Goal:** a business clicks "Connect WhatsApp" on our site and, unattended, ends up with a live
number and a working inbox in under 10 minutes.

Tables: `teams`, `users`, `team_user`, `audit_logs`, `waba_accounts`, `phone_numbers`,
`meta_credentials`, `onboarding_sessions`.

Cardinality (D-020): **one team per user, one WABA per team, one phone number per team.** Onboarding
always targets the signed-in user's own team — there is no team to choose and none to get wrong.

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
  │                            ──▶ team status = active
```

The `extras.setup` object can pre-fill business details to shorten the flow — worth doing when we
already know the business name, email, and website.

### Server steps in order (`Actions/Onboarding/`)

| # | Action | Notes |
|---|---|---|
| 1 | `ExchangeSignupCode` | `POST /oauth/access_token` with `client_id`, `client_secret`, `code`. Store as `meta_credentials.type=business`. **Never log the code or token.** |
| 2 | `RegisterPhoneNumber` | `POST /{phone_number_id}/register`. PIN rule: if the number **already has two-step verification**, Meta requires *that* PIN (else `133005`) — the client supplies it and we pass it through for one run only; otherwise we generate a 6-digit PIN. Persist `pin_set`. **Skip entirely for Coexistence numbers** — already registered. |
| 3 | `SubscribeWabaWebhooks` | `POST /{waba_id}/subscribed_apps`; verify with a `GET` and assert our app id is present |
| 4 | `SyncWabaAssets` | Fetch WABA + numbers; populate `waba_accounts`, `phone_numbers` incl. `quality_rating`, `name_status`, `throughput`, `platform_type`, `is_on_biz_app`, and `portfolio_messaging_limit` from the WABA node's `whatsapp_business_manager_messaging_limit` (**not** the deprecated per-number `messaging_limit_tier`). Also confirm template analytics (`POST /{waba_id}?is_enabled_for_insights=true` — one-time, irreversible; required before M8 can pull `template_analytics`) |
| 5 | `SyncTemplates` | Initial template pull so M3 is populated on arrival |
| 6 | `CheckPaymentReadiness` | Tech Provider: the **client** must attach their own payment method. Set `waba_accounts.payment_ready` |
| 7 | `CompleteOnboarding` | team `status = active`, dispatch a welcome, emit audit log |

Each step is idempotent and independently retryable, driven from `onboarding_sessions.status` so a
half-finished onboarding can be resumed rather than restarted.

---

## 2. Token vault

Two credential types. See `05-security-multitenancy.md` for storage rules.

| Type | Used for | Obtained |
|---|---|---|
| `business` | All Cloud API calls | Embedded Signup code exchange |
| `bisu` | **All Meta Business Agent calls** | Separate BISU flow (M5) |

`GraphClient` resolves the correct credential from the team + the API family being called. A
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

## 5. Team lifecycle

| `account_update` event | Our action |
|---|---|
| `PARTNER_ADDED`, `PARTNER_APP_INSTALLED` | Provision/reactivate team, capture `waba_id`, `solution_id`, `owner_business_id` |
| `PARTNER_REMOVED` | Suspend team, capture `disconnection_info`, retain data per retention policy, notify the account owner |

Offboarding also needs a self-serve path: export everything, then disconnect.

---

## 6. Platform layer

| Feature | Notes |
|---|---|
| RBAC | `owner`, `admin`, `supervisor`, `agent`, `viewer`. Role is the only axis — a team has one number, so there is nothing to scope per-number |
| Invitations | Email invite → accept → role assignment; pending invites visible. An address that already belongs to a team cannot accept a second invite (D-020) — the invite is refused with that reason, not silently queued |
| Audit log | Every send, template edit, export, token use, agent config change |
| White-label | Custom domain, logo, colour tokens per team (`teams.settings`) |
| Public API + outbound webhooks | Token-scoped team API; signed outbound webhooks with retry |
| Impersonation | Time-boxed, audited, visibly banner-ed |
| Sandbox teams | Meta sandbox test accounts, valid 30 days, for demos and E2E tests |

---

## 7. UI surface

| Route | Screen |
|---|---|
| `/onboarding` | Connect WhatsApp — ES launcher, live step progress, resumable |
| `/onboarding/sync` | Coexistence import progress with the "keep your app open" guidance |
| `/settings/whatsapp` | The team's single WhatsApp connection: connected-account panel, editable business profile, billing link-out, two-step PIN, disconnect. Detail below |
| `/settings/team` | Users, roles, invitations |
| `/invitations` | Accept or decline invitations addressed to you. **Not team-prefixed** — the people who need it have no team to prefix it with. Decline-only once you already belong to a team |
| `/invitations/{code}/join` | The invitee's landing page and the target of the invitation email. **Public** — they have no account yet; the 64-character code is the credential and the route is throttled. They set a name and password and join in one step. **The email address is not a field** — it is the invitation's, so an invitation cannot be redirected to another account. An address we already know redirects to sign-in instead; a spent or expired code renders an explanation, not a 404 |
| `/settings/billing` | Plan, wallet balance, payment-readiness status |
| `/settings/api` | API tokens, outbound webhook endpoints |
| `/settings/audit` | Filterable audit log |

### `/settings/whatsapp`

One page, because a team has one WABA and one number (D-020). Four panels. Every field and endpoint
is specified in `reference/whatsapp-cloud-api.md` §5.

| Panel | Contents | Source |
|---|---|---|
| Connected account | Number, display name, connection status, quality rating, messaging tier, throughput, platform type, Coexistence flag; WABA name, account review status, business verification status. **Two-step verification (`code_verification_status`: `VERIFIED`/`UNVERIFIED`) and display-name status (`name_status`: `APPROVED`/`AVAILABLE_WITHOUT_REVIEW`/`DECLINED`/`EXPIRED`/`PENDING_REVIEW`/`NONE`) are separate rows.** Never render one under the other's label | `GET /{phone-number-id}?fields=…`, `GET /{waba-id}` |
| Business profile | Editable: about (1–139), address (≤256), description (≤512), email (≤128), websites (max 2, scheme required), vertical (21 values), profile picture. Validate client-side against the same limits so Meta's error is the exception, not the norm. Re-GET after every save — the write returns `{"success": true}` and echoes nothing | `GET\|POST /{phone-number-id}/whatsapp_business_profile` |
| Billing | Payment-readiness state plus a link-out to Meta's billing centre. Tech Provider: the client attaches their own payment method and **we cannot see their spend** — the copy must not imply otherwise. `payment_configuration_update` drives the state; `131042` on send raises the blocking banner (§4) | `waba_accounts.payment_ready` |
| Danger zone | Two-step PIN reset (`POST /{phone-number-id}`, no current PIN required — the escape hatch for `133005`) and disconnect. **Disconnect branches on `is_on_biz_app`:** a normal number deregisters; a Coexistence number cannot, and we instead show the WhatsApp Business app path (§3) | `POST /{phone-number-id}/deregister` |

---

## 8. Edge cases

| Case | Handling |
|---|---|
| Client abandons the ES window mid-flow | `onboarding_sessions` stays `started`; resumable from the last completed step |
| `code` exchange fails | Show the real Meta error; allow retry without restarting ES |
| Phone registration fails (`133010`) | Offer request-code/verify-code flow inline |
| Number already has two-step verification (`133005`) | Meta requires the number's **existing** 6-digit PIN on register. The UI prompts for it and resumes with it; the PIN is used for that run only and is never persisted or logged. If the client cannot recall it, they change it in WhatsApp Manager and enter the new one. |
| Repeated registration attempts (`133016`) | Registration is capped at **10 attempts per number per rolling 72 hours** — surface remaining attempts and never retry automatically |
| Number already connected to another team of ours | Refuse with a clear message; do not silently move it |
| Client revokes our app in Business Suite | `PARTNER_REMOVED` → suspend, prompt reconnect |
| Team already has a WABA and runs ES again | Refuse before the ES window opens. One WABA per team (D-020); reconnecting means disconnecting the existing one first |
| Same person onboards two businesses | **Not supported.** A user belongs to one team and a team holds one WABA (D-020). Tell them plainly: the second business needs its own login (a separate email address), which creates its own team. We do not merge, switch, or link the two |
| Webhook arrives for a WABA we do not know | Park as `ignored`, alert. **Never auto-create a team from a webhook.** |
| Invitee has no account | `/invitations/{code}/join` creates it: name + password only, email taken from the invitation. Joining marks the address verified — receiving the code already proved control of the mailbox, so a second confirmation link would prove nothing. **Never route a new invitee through the generic registration form:** it has a writable email field and only honours the invitation when the typed address happens to match, so anyone who preferred a different address silently got a team of their own |
| Invitee already has an account but no team | The email links to `/login?invitation=…`. On sign-in they land on `/invitations`, not their profile — the code must not be dropped. Every "where does this user belong" decision goes through `Support\HomeRedirect`, including the guest-route bounce: `route('dashboard')` needs a `{team}` segment a teamless user cannot supply, so the default would throw |
| Invitee is signed in already and clicks the email | Guest middleware bounces them off `/login` through the same `HomeRedirect`, landing on `/invitations` |

---

## 9. Acceptance criteria

1. A sandbox business completes ES v4 and reaches an active team with a registered number, no
   manual steps.
2. Killing the server mid-onboarding and restarting resumes from the last completed step.
3. A Coexistence onboarding imports contacts and history, renders them in the inbox, and mirrors a
   message sent from the handset within 5 seconds.
4. Declined history sharing (`2593109`) completes onboarding with a clear explanation.
5. Revoking the app in Business Suite suspends the team within one webhook cycle.
6. No token value appears in any log, Inertia prop, or error page — asserted by a test.
7. Onboarding always lands in the signed-in user's own team: a second ES run on a team that already
   holds a WABA is refused, and a user who already belongs to a team cannot accept an invitation to
   another. Both asserted by tests.
8. `/settings/whatsapp` renders `code_verification_status` and `name_status` as separate rows, and
   offers deregister only when `is_on_biz_app` is false.
