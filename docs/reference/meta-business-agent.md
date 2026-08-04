# Reference — Meta Business Agent (MBA)

Verified 2026-08-01. Docs root: <https://developers.facebook.com/documentation/meta-business-agent/overview>

> **Two overlapping doc trees exist.** `/documentation/meta-business-agent/` (updated Jun 30, 2026)
> is newer and authoritative. An older `/documentation/business-ai/` tree (May 29, 2026) disagrees on
> two points: it calls the Skills endpoint "Instructions", and it lists `message_echoes` instead of
> `standby` in the webhook subscription list. **Build against `meta-business-agent`** and verify the
> webhook field list empirically on a sandbox number before finalising ingest.
>
> **Partially resolved 2026-08-03:** Meta's live webhook topic list (via DevTools, H2) shows
> `standby`, `messaging_handovers` **and** `message_echoes` all exist as fields on the
> `whatsapp_business_account` topic — the newer tree is right that `standby` is real; the two
> fields are not synonyms. Still to verify empirically: which field(s) actually carry the agent's
> outbound copies, and the payload shapes.

---

## 1. What it is

Meta's own enterprise AI agent, running on Meta's infrastructure, that becomes the **primary
responder** on the client's WhatsApp number. Our app is a **standby participant** until it takes
thread control.

It has:
- conversation context across turns
- product knowledge from the client's Meta catalog
- knowledge from business info, FAQs, crawled websites, uploaded files
- real tool calling via **Connectors** into our (or the client's) APIs
- event-driven proactivity via **Agent Event**

It does **not** have:
- our CRM's view of the customer (lifecycle stage, LTV, open tickets, consent state, campaign
  history) — **only via a Connector we build**
- documented visibility of pre-enablement history *(unverified — see §8)*
- documented visibility of messages we sent while holding control *(unverified — see §8)*

---

## 2. Availability

| Dimension | Constraint |
|---|---|
| Verticals | **Automotive, Consumer Packaged Goods, Professional Services, Retail & Ecommerce, Travel** — these five only |
| Countries | 182 supported (Uganda, Kenya, Tanzania, Nigeria, Ghana, South Africa, and most of Africa are included) |
| Programmatic check | Agent **Eligibility** endpoint, per phone number |
| UI surface | A "Meta Business Agent" tab appears in WhatsApp Manager when any number is eligible |

Our UI must call Eligibility and hide/explain the feature rather than letting a client configure an
agent that will never answer.

---

## 3. Prerequisites and auth — different from Cloud API

| Requirement | Detail |
|---|---|
| WABA + business phone number | Standard |
| App permission | `whatsapp_business_messaging` (plus `whatsapp_business_management` on the token) |
| **Client ToS** | The client accepts **Meta Business Agent Terms of Service in WhatsApp Manager** |
| **Our ToS** | We must additionally have accepted **Tech Provider Terms of Service** in the Developer Portal |
| **Enforcement** | **MBA API calls are rejected until both are accepted** |
| **Token** | **BISU token** for BSPs / Tech Providers — *not* the business token used for Cloud API. Requires `whatsapp_business_messaging` + `whatsapp_business_management`. |
| App subscription | `POST /{WABA_ID}/subscribed_apps` |
| Webhook fields | `messages`, `standby`, `messaging_handovers` |

> The BISU token is the single most likely thing to be missed in implementation. It is a separate
> credential type in our vault (`meta_credentials.type = 'bisu'`).

---

## 4. API surface

### Onboard

| Endpoint | Purpose |
|---|---|
| **Eligibility** | Can this phone number use MBA? |
| **Onboarding** | Prepare the agent on a number — creates its configuration and knowledge. **Required before enabling.** |
| **Settings** | Turn on/off; set behaviour, persona, language, handoff policy, follow-up policy. Enabling makes the agent start responding to **new** conversations. |
| **Allowlist** | Restrict the agent to a specific set of consumer phone numbers — our canary mechanism |

### Configure

| Endpoint | Purpose |
|---|---|
| **Skills** | System instructions shaping tone, priorities, brand voice |
| **Business info** | Hours, locations, policies |
| **FAQs** | Question/answer pairs |
| **Websites** | URLs for the agent to crawl and reference |
| **Files** | Uploaded knowledge sources |
| **Connectors** | Define an external API the agent may call |
| **Connector tools** | Define the individual operations on a connector |
| Product catalog | Not an MBA endpoint — managed in **Meta Commerce Manager**; the catalog supplies product knowledge |

### Operate

| Endpoint | Purpose |
|---|---|
| **Thread Control (Cloud API)** | `pass` / take control between our app and the agent |
| **Agent Event** | Trigger an agent action in response to a business event (purchase completed, payment confirmed) |
| **Agent Test** | Send test messages for automated testing |
| **Agent Eval** | Evaluate agent performance |

---

## 5. Conversation routing — the part that shapes our architecture

When MBA is enabled it is the primary responder. Our app is a standby participant: we still receive
the customer's messages, **plus copies of the agent's outbound messages and their delivery and read
receipts**, so we stay in sync.

| Who holds control | Customer message arrives on |
|---|---|
| Meta Business Agent | `standby` |
| Our app | `messages` |

- `messaging_handovers` notifies us **whenever control changes** and is the authoritative source.
- **To respond, our app needs control. Our app takes control simply by sending a message.**
- To hand control back so the agent resumes, call **Thread Control with the `pass` action**.

### The implementation consequence

An agent clicking into a thread and typing silently ends the AI's involvement. Our UI must therefore:

1. Render ownership unmistakably (`AI` badge vs `You` badge).
2. Disable the composer while `state = ai`, behind an explicit **"Take over"** button.
3. Offer an explicit **"Hand back to AI"** action that calls Thread Control `pass`.
4. Log every transition to `thread_control_events` with a reason in the handover `metadata`.

### Sandbox reset

Typing `reset` in a WhatsApp chat restarts the conversation with the agent — useful after a handoff.
**This must be enabled for your specific test consumer numbers by your Meta contact**; it is not
available by default.

---

## 6. Pricing — per token, not per message

| Item | Value |
|---|---|
| Meter | **Per token** |
| Rate | **$2.00 USD per 1M tokens**, one global rate |
| Typical consumption | **~20,000–25,000 tokens per message** |
| Effective cost | **~4–5 US cents per message** |
| Charge composition | AI usage **and** message delivery as a **single** charge by Meta |
| Free in 24h customer service window? | **No** |
| Free in 72h free entry point window? | **No** (token charges still apply) |
| Charging live from | **August 1, 2026** |

Meta's worked examples:

| Interaction | Example | Msgs | Tokens | Est. cost |
|---|---|---|---|---|
| Simple | "At what time do you open?" | 4 | ~80,000 | ~16–20¢ |
| Complex | "I'm stuck on step 3 of this assembly — walk me through it?" | 10 | ~250,000 | ~40–50¢ |

10,000 AI-powered replies to users in Brazil (Meta's comparison):

| Option | AI cost/msg | Delivery/msg | Total |
|---|---|---|---|
| Third-party AI, lower complexity | ~2¢ | 0.68¢ | ~$268 |
| Third-party AI, higher complexity | ~9¢ | 0.68¢ | ~$968 |
| **Meta Business Agent** | 4–5¢ bundled | — | **~$400–500** |

### The one-charge rule

A message has exactly one category and one charge. A non-template message is charged **either** as a
Meta Business Agent message **or** as a service message, never both. Promotional content in a
non-template message does **not** additionally incur a marketing template charge.

### ⚠️ Analytics gap

Meta stated that **analytics and webhook payload details for MBA messages** would be published
*before charging took effect on August 1, 2026*. As of writing they were still listed as
forthcoming. **Re-check before implementing the token meter (M8).** Until confirmed, our token
figures are estimates derived from message counts, and must be labelled as estimates in the UI.

---

## 7. Related pricing changes (context for M8)

| Date | Change |
|---|---|
| **Aug 1, 2026** | MBA messages become chargeable, per token |
| **Oct 1, 2026** | **All** non-template messages become chargeable. Service messages (human agent replies) priced at utility/authentication market rates, **no volume tiers**. Utility templates inside the CSW stop being free. |
| Rate announcement | Meta will publish the Oct 1 rates, including service messages, **by Sept 1, 2026** |
| Pricing calendar | Rates change only on Jan 1 / Apr 1 / Jul 1 / Oct 1, with ≥1 month notice for rate-card updates and ≥6 months for pricing-model changes |

Service messages are identifiable today: `pricing_analytics` with `pricing_category = SERVICE`, and
`pricing.category = "service"` in status webhooks.

---

## 8. Unverified behaviours — test before designing around them

| # | Question | Why it matters | How to test |
|---|---|---|---|
| U1 | Does MBA see messages **we** sent while we held control? | If not, a human resolution is invisible to the agent after hand-back, and it may repeat questions or contradict the agent | Hold control, send a distinctive fact, pass back, ask the agent about it |
| U2 | Does MBA see conversation history from **before** it was enabled? | Determines whether we must seed context via a Connector on activation | Enable on a number with existing history, ask about an earlier detail |
| U3 | Any long-term/cross-conversation customer memory? | Determines how much our Connector must carry each turn | Start a fresh conversation with a known customer, ask something answered previously |
| U4 | Latency budget for Connector calls | The agent is waiting mid-conversation | Instrument our endpoint; deliberately delay and observe agent behaviour |
| U5 | Behaviour when a Connector errors or times out | Decides our fallback design | Return 500 / hang, observe |
| U6 | Does it honour our consent state? | It will not know about opt-outs unless told | Expose consent in the context Connector and observe |

Record answers in `92-decisions.md` and update this file.

---

## 9. Our integration strategy

MBA is a very capable agent with **no knowledge of the customer as a CRM knows them**. Our value is
the layer it lacks:

| Our role | Delivered via |
|---|---|
| **Context provider** | `GET /connectors/v1/{team}/customer-context?wa_id=…` returning lifecycle stage, LTV, open orders, entitlements, language, consent state, active campaign |
| **Action broker** | Write tools: log intent, create ticket, update order, book slot — so AI conversations produce CRM records instead of vanishing |
| **Consent & compliance gate** | MBA does not know our consent ledger; we enforce it |
| **Handover orchestration** | AI ⇄ queue ⇄ human state machine, skills routing, SLA |
| **Cost governance** | Token spend visibility, per-team budgets |
| **Cross-channel identity** | One customer across WhatsApp, orders, payments, ads |

Connector contract details in [../modules/m5-meta-business-agent.md](../modules/m5-meta-business-agent.md).

---

## 10. Sources

- <https://developers.facebook.com/documentation/meta-business-agent/overview>
- <https://developers.facebook.com/documentation/meta-business-agent/get-started>
- <https://developers.facebook.com/documentation/business-messaging/whatsapp/pricing/non-template-messages>
- <https://developers.facebook.com/videos/2026/the-architecture-of-meta-business-agent-on-whatsapp/>
- Older/conflicting tree: <https://developers.facebook.com/documentation/business-ai/get-started>
