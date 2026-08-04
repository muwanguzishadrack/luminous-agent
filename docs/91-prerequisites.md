# 91 — Prerequisites: what is needed from you

Organised by **when** it blocks work, so nothing waits unnecessarily.

**Phase 0 (scaffold, schema, models, UI shell, seeders, tests) needs none of this.** It can start
immediately. Everything below is needed before the phase named against it.

---

## A. Needed before Phase 1 — Meta

| # | Item | Where to get it | Notes |
|---|---|---|---|
| A1 | **Meta App ID** | developers.facebook.com → your Tech Provider app → Settings → Basic | ✅ In hand (app 918140253822152 "Luminous CRM"). Goes into `.env` at Phase 0 scaffold. |
| A2 | **Meta App Secret** | same page | ✅ In hand. Put it straight into `.env` — never in chat. |
| A3 | **Business Portfolio ID** | business.facebook.com → Business Settings → Business Info | |
| A4 | **App mode** — Development or Live? | App Dashboard | ✅ **Confirmed live mode** (checked via DevTools 2026-08-03, app 918140253822152 "Luminous CRM"). |
| A5 | **App Review status** for `whatsapp_business_management` and `whatsapp_business_messaging` | App Dashboard → App Review | ✅ **Confirmed: Advanced Access is live on both** (checked via DevTools 2026-08-03). Real clients can onboard. |
| A6 | **Embedded Signup Configuration ID** (`config_id`) | App Dashboard → Facebook Login for Business → Configurations | ✅ In hand (user also holds `META_WEBHOOK_VERIFY_TOKEN` and a `META_SYSTEM_TOKEN`). **Verify it is a v4 configuration** during the first onboarding test — v2 is removed Oct 15, 2026. |
| A7 | **A sandbox test account** (or a spare WABA + phone number) | App Dashboard → WhatsApp → claim a sandbox account | ✅ Decided 2026-08-03: the test WABA will be created **through our own Embedded Signup flow** once it's implemented — the setup doubles as the flow's first end-to-end test. |
| A8 | **A WhatsApp-enabled phone** you can use for testing | your handset | Needed to receive messages and to test Coexistence later |
| A9 | Confirm the **Graph API version** to pin | — | Pinned `v26.0` (latest, released 2026-07-29; confirmed live via DevTools — see D-017). Say if you want a different one. |
| A11 | **Add `standby` + `messaging_handovers` to the app's webhook subscription** | App Dashboard → WhatsApp → Configuration → Webhook fields | 🔄 User adding 2026-08-03; last DevTools check did not show them yet — **re-verify before Phase 1**. Required for the M1/M5 ownership state machine. |
| A12 | **Do not repoint the app-level webhook callback URL** | — | It already points at production (`www.app.luminouscrm.com`). Dev uses the WABA-level callback override — see `01-architecture.md`. Also note the `www.app.` subdomain looks unusual; confirm it is correct. |
| A10 | Multi-Partner **Solution ID**, if you have one | App Dashboard → WhatsApp → Partner Solutions | Probably not applicable. Confirm either way. |

I will generate the webhook verify token myself and give you the callback URL to paste into the
Developer Portal.

---

## B. Needed before Phase 1 — Infrastructure

| # | Item | Notes |
|---|---|---|
| B1 | **A domain on Cloudflare** (e.g. `dev.yourdomain.com`) | ✅ Resolved 2026-08-03: `luminouscrm.com` is on Cloudflare; the dev tunnel hostname is **`platform.luminouscrm.com`**. |
| B2 | Ability to run `cloudflared tunnel login` on this machine | ✅ Already logged in — the tunnel can be created any time (`03-local-development.md` §3). |
| B3 | Where production will run | ✅ Decided 2026-08-03: **a VPS with Docker** — the FrankenPHP parity stack in `docker/compose.parity.yml` is the deploy shape. |
| B4 | Should I `git init` this repo, and is there a remote? | ⛔ Decided 2026-08-03: **do not init yet** (user decision). Revisit before code lands — an un-versioned repo is a risk once Phase 0 starts. |

---

## C. Needed before Phase 2 — Meta Business Agent

| # | Item | Notes |
|---|---|---|
| C1 | **Confirm you have accepted Tech Provider Terms of Service** | In the Developer Portal. **MBA API calls are rejected until this is done on your side and the client's side.** |
| C2 | **A system user with Admin role**, with your app and WABA assigned to it | Meta Business Suite → Users → System users. Needed to generate tokens. |
| C3 | **BISU token setup access** | As a Tech Provider you use BISU tokens for MBA, not the normal business token. I need to know you can generate these. |
| C4 | **Which verticals your clients are in** | MBA supports only **Automotive, CPG, Professional Services, Retail & Ecommerce, Travel**. This decides risk R1 — see question D2 below. |
| C5 | **An eligible phone number** to test against | I will check programmatically via the Eligibility endpoint once A7/A8 exist |
| C6 | **Ask your Meta contact to enable the `reset` command** for your test consumer numbers | Required for MBA testing — it lets a test conversation restart with the agent after a handoff. Not available by default. |

---

## D. Product decisions I need from you

These change what gets built. I have made a default assumption for each — tell me where I am wrong.

| # | Question | My assumption |
|---|---|---|
| D1 | **Markets** — Uganda only, or East Africa / wider? | ✅ Confirmed 2026-08-03: **Uganda only in v1.** `UGX` primary, `USD` supported. Phone normalisation defaults to `+256`. |
| D2 | **Verticals outside MBA's five** — do you have pipeline in healthcare, education, fintech, logistics, or NGOs? | ✅ Confirmed 2026-08-03: **all pipeline is within MBA's five verticals.** No fallback agent needed — risk R1 closed, D-007 resolved by D-018. |
| D3 | **Billing model** — subscription only, or subscription + markup on messaging? | Subscription + configurable markup, prepaid wallet. This is why `usage_meters` stores markup per row. |
| D4 | **White-label in v1?** | Built into the schema (`teams.settings`) but the UI lands in Phase 4. |
| D5 | **Coexistence priority** | Phase 4. It is your strongest SMB wedge but the 24h one-shot sync needs a proven ingest pipeline first. Say if you want it earlier and accept the risk. |
| D6 | **Realtime transport** — Laravel Reverb (self-hosted) or Pusher? | Reverb — no per-message cost, runs in the same Docker stack. |
| D7 | **Brand** — logo, primary colour, font | Using shadcn/ui defaults with neutral tokens until you provide these. |
| D8 | **Who else will work in this repo?** | Just you and me. Affects how prescriptive `04-conventions.md` needs to be. |
| D9 | **Ad spend source for ROAS** | Manual monthly entry per campaign in v1; Marketing API later. |

---

## E. Needed before Phase 3 — ioTec Pay

| # | Item | Where | Notes |
|---|---|---|---|
| E1 | **`client_id`** | Emailed to you at ioTec Pay registration (check spam/promotions; else support@iotec.io) | |
| E2 | **`client_secret`** | Same email | Send securely, not in chat |
| E3 | **Sandbox wallet ID** (currency `ITX`) | ioTec Pay portal → wallet details | `ITX` is their test currency. This is what I develop against. |
| E4 | **Production wallet ID** (currency `UGX`) | Same | Not needed until we go live |
| E5 | **Portal access to configure Callback URLs** | pay.iotec.io → wallet → Settings tab → Callback URLs card | **You (or I, with access) must do this manually per wallet.** It cannot be set via API. Two entries needed: `Collection` and `Disbursement`. |
| E6 | **A security header name + value** for callbacks | You choose | I will generate a strong value if you prefer; it goes in both the portal and our `.env` |
| E7 | **Is card collection (PegPay) enabled** on your account? | ioTec | Card flows need the customer's **email**, not a phone number — it changes the checkout UI |
| E8 | **Are disbursements enabled**, and is **maker–checker approval** switched on? | ioTec | If approval is on, payouts enter `AwaitingApproval` and someone must approve in the ioTec portal. The UI must not present those as sent. |
| E9 | Confirm any **rate limits or daily caps** on your ioTec account | ioTec support | Their OpenAPI does not document limits; I will add a conservative client-side limiter regardless |

Callback URLs I will need you to paste into the portal (once B1 exists):

```
https://platform.luminouscrm.com/webhooks/iotec/collection
https://platform.luminouscrm.com/webhooks/iotec/disbursement
```

---

## F. How to send me secrets

Do **not** paste secrets into this chat.

1. Create `/Users/sirshadrack/Herd/luminous-agent/.env` yourself and fill the values, or
2. Put them in your password manager and paste them into `.env` when I have generated `.env.example`.

I will write `.env.example` with every key and empty values. `.env` is gitignored. I will never read,
echo, or log a secret value, and the log scrubber in `05-security-multitenancy.md` §2 covers them at
runtime.

---

## G. Shortest path to unblock me

Status 2026-08-03 — everything Phase-0/1-blocking is resolved:

1. ~~**B1 + B2**~~ ✅ `luminouscrm.com` on Cloudflare, logged in; hostname `platform.luminouscrm.com`.
2. ~~**A1, A2, A6**~~ ✅ credentials in hand (go into `.env` at scaffold); ~~**A7**~~ ✅ test WABA
   will be created via our own ES flow.
3. ~~**D1 and D2**~~ ✅ Uganda only; all pipeline within MBA's verticals (D-018).
4. **E1, E2, E3** — ioTec sandbox credentials, still needed before Phase 3.

Remaining: A11 re-verification (webhook fields), the C-section items before Phase 2, and the
E-section items before Phase 3.

---

## H. Open items I am tracking on my side

| # | Item | Resolution |
|---|---|---|
| H1 | Meta's **MBA analytics and webhook payload details** were still "forthcoming" as of Aug 1, 2026 | Re-check before building the token meter; estimate and label until then |
| H2 | Two conflicting MBA doc trees (`meta-business-agent` vs `business-ai`) disagree on the Skills/Instructions endpoint name and on `standby` vs `message_echoes` | **Partially resolved 2026-08-03:** live topic list confirms `standby`, `messaging_handovers` and `message_echoes` all exist as distinct fields. Remaining: verify payloads/routing empirically on a sandbox number in Phase 2 |
| H3 | MBA context boundaries U1–U6 (`reference/meta-business-agent.md` §8) | Empirical test in Phase 2 |
| H4 | ioTec business error codes are not enumerated in their spec | Capture into `reference/iotec-error-codes.md` as encountered |
| H5 | Oct 1, 2026 service-message rates | Meta publishes by Sept 1, 2026 — update the rate card then |
