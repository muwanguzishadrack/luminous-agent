# M1 — Team Inbox

**Goal:** several agents handle one WhatsApp number without colliding, and no inbound message is
ever lost.

Tables: `webhook_deliveries`, `conversations`, `messages`, `message_events`, `media`,
`thread_control_events`, `labels`, `notes`, `canned_replies`.

---

## 1. Webhook ingest — the most important code in the system

```
POST /webhooks/meta/{app}
 ┌─────────────────────────────────────────────────────────────┐
 │ 1. Verify X-Hub-Signature-256 over the RAW body   → 401     │
 │ 2. INSERT webhook_deliveries (source, body_sha256, payload) │
 │      unique(source, body_sha256) → duplicate = no-op        │
 │ 3. return 200                          ← budget: < 50ms     │
 │ 4. dispatch ProcessWebhookDelivery → queue `webhooks`       │
 └─────────────────────────────────────────────────────────────┘
                              │
        ┌─────────────────────┴──────────────────────┐
        │ per entry → per change → field dispatcher  │
        │ each change wrapped in its own try/catch   │
        │ failures recorded per-change, siblings OK  │
        └─────────────────────┬──────────────────────┘
                              ▼   queue `inbound`
        HandleMessages · HandleStandby · HandleHandovers ·
        HandleStatuses · HandleUserPreferences · HandleHistory ·
        HandleSmbEchoes · HandleSmbStateSync · HandleTemplateEvents ·
        HandleAccountEvents · HandleHealthEvents
```

### Non-negotiable rules

| # | Rule | Why |
|---|---|---|
| 1 | **Ack before processing** | Meta retries aggressively; a slow 200 causes duplicate storms |
| 2 | **Never trust ordering** | A `read` status can arrive before `delivered`, and a status can arrive for a wamid we have never seen. Create stub message rows. |
| 3 | **Idempotent on `wamid`** | Every message write is an UPSERT |
| 4 | **Isolate each change** | One malformed change must not lose its siblings |
| 5 | **Never guess a team** | Unresolvable `phone_number_id` → `status = ignored` + alert |
| 6 | **Everything replayable** | 30-day raw retention; `webhook:replay` command |
| 7 | **Status is append-only** | Write `message_events` always; advance `messages.status` only on a legal forward transition |

Legal message status transitions:

```
queued → sent → delivered → read
   └────────────────────→ failed        (from any non-terminal state)
```

---

## 2. Thread ownership

The state machine from `01-architecture.md`, driven by `messaging_handovers`.

| State | Composer | Inbound arrives on | Meaning |
|---|---|---|---|
| `ai` | **disabled**, behind "Take over" | `standby` | MBA is responding |
| `queued` | enabled | `messages` | Ours, unassigned |
| `human` | enabled | `messages` | Ours, assigned to a user |
| `closed` | enabled (reopens) | `messages` | Resolved |

### The takeover guard

Sending any message **implicitly takes control from MBA**. A careless click must not silently kill
the AI. Therefore:

- While `state = ai`, the composer is disabled and shows an explicit **"Take over from AI"** button.
- Taking over is a distinct Action (`TakeThreadControl`) that flips state, logs the reason, and
  notifies the team.
- **"Hand back to AI"** calls Thread Control `pass` with structured `metadata`
  (`{"reason":"agent_handback","user_id":"…"}`).
- Every transition appends to `thread_control_events`.

Reconciliation: on every `messaging_handovers` webhook, set `conversations.owner_app_id` and derive
`state`. Meta's view always wins over ours.

---

## 3. Customer Service Window

```php
$conversation->csw_expires_at = $lastInboundAt->addHours(24);
```

Prefer `statuses[].conversation.expiration_timestamp` when Meta supplies it.

UI: a live countdown chip on the conversation. When expired:
- free-form composer disabled with a plain-language explanation
- a **"Send a template instead"** affordance opens the template picker filtered to approved templates
- an attempted free-form send is blocked client-side **and** server-side (error `131047`)

Also track `fep_expires_at` (72h, set on a CTWA `referral`) and badge the conversation as
**free window** — delivery is free in it, which changes agent behaviour.

---

## 4. Message rendering

| Type | Rendering requirement |
|---|---|
| `text` | Link detection, `preview_url` handling |
| `image`, `video`, `sticker` | Inline thumbnail, lightbox, download |
| `audio` | Waveform player + **transcript** (voice-note STT) |
| `document` | Filename, size, icon, download |
| `location` | Static map thumbnail + coordinates |
| `contacts` | vCard card with "add to contacts" |
| `interactive` | Show the buttons/list *as sent*, and highlight which option the customer chose |
| `order` | Order card with line items and total → links to the M6 order |
| `reaction` | Attached to the target bubble, not a separate row |
| `template` | Rendered with variables resolved, plus a "Template: name" chip |
| `system` | Neutral system notice (e.g. customer changed number) |
| `unsupported` | Placeholder with the error code (e.g. `131060` on Coexistence) |
| Origin badges | `agent` (avatar), `mba` (AI badge), `campaign` (campaign name), `owner_device` (phone icon) |

Contextual replies render a quoted parent. Failed messages show the Meta error in plain language plus
a **Retry** button.

---

## 5. Media pipeline

Inbound:
```
messages webhook → media id → GET /{media-id} → url
  → GET url WITH Authorization header    (the URL alone is insufficient)
  → stream to S3/MinIO, compute sha256 (dedupe), sniff MIME
  → ClamAV scan (queue `media`) — do not serve until clean
  → generate thumbnail / extract duration
  → STT for audio
```

Outbound: upload once, reuse `meta_media_id` until `meta_expires_at` (~30 days), then re-upload from
our own copy automatically.

Serving: private bucket, short-lived signed URLs, team-scoped. Never public objects.

---

## 6. Collaboration features

| Feature | Notes |
|---|---|
| Assignment | Manual, round-robin, load-based, skill/label-based. AI is a valid owner. |
| Presence & typing | "Ada is viewing / typing" via Reverb, so two agents do not double-reply |
| Soft lock | Opening a thread claims a 60s advisory lock, shown to others |
| Labels | Conversation and contact labels, colour-coded, filterable |
| Notes & @mentions | Internal only; mention triggers a notification |
| Canned replies | `/shortcut` with CRM variable interpolation |
| Snooze | Hide until a time; reappears on inbound |
| Bulk actions | Close, assign, label, export across a filtered selection |
| Saved views | "Unassigned", "My open", "AI handled", "CSW expiring < 2h", "Failed sends" |
| SLA | First-response and resolution timers per view, breach highlighting |

---

## 7. Realtime

Laravel Reverb. Private channels: `team.{id}.inbox`, `conversation.{id}`.

| Event | Client behaviour |
|---|---|
| `MessageReceived` | Append to open thread; bump list; `aria-live` announce; badge counts |
| `MessageStatusChanged` | Update tick state in place |
| `ThreadControlChanged` | Swap ownership badge and enable/disable composer immediately |
| `ConversationAssigned` | Move between views |
| `MediaReady` | Replace placeholder with thumbnail |

Rule from `04-conventions.md`: realtime augments Inertia. Anything beyond a trivial append does
`router.reload({ only: [...] })` rather than maintaining a parallel client store.

---

## 8. Search

Meilisearch via Scout. Indexes: `messages` (body, contact name, labels, team_id, occurred_at),
`contacts`. Filters are pushed into Meilisearch filter expressions with `team_id` **always**
applied server-side — never from a client-supplied parameter.

---

## 9. Performance targets

| Surface | Target |
|---|---|
| Webhook ack | p95 < 50ms |
| Inbound message → visible in UI | p95 < 2s |
| Conversation list first paint (100k contacts) | < 400ms |
| Thread open, last 50 messages | < 300ms |
| Search | < 300ms |

Techniques: virtualised lists, Inertia deferred props for history beyond page 1, cursor pagination on
`(conversation_id, occurred_at)`, denormalised `conversations.last_message_at` / `unread_count`.

---

## 10. UI surface

```
/inbox                                three-pane persistent layout
  ├── left    saved views + filters + search
  ├── middle  virtualised conversation list (ownership badge, CSW chip, labels, snippet)
  └── right   thread + composer + contact side panel (M2 data, orders, consent, CTWA source)
/inbox/{conversation}
```

Composer: text, emoji, attachment, template picker, canned replies, voice note, product picker (M6),
send-and-close. Disabled states are always explained, never just greyed out.

---

## 11. Edge cases

| Case | Handling |
|---|---|
| Status for an unknown wamid | Create a stub message row |
| Two agents reply simultaneously | Soft lock + optimistic append; both messages send (Meta allows it) but the UI warns |
| Message fails after optimistic render | Bubble turns to failed state with error + retry |
| Media expired at Meta | Serve our copy; re-upload on next send |
| Customer changes number | `system` message → `contact_identifiers` retire + link; thread continuity preserved |
| Coexistence echo duplicates our own send | Dedupe on `wamid` |
| Inbound while `state = ai` but MBA is disabled mid-conversation | Handover event arrives; reconcile and enable composer |
| Very long thread (50k messages) | Cursor pagination; jump-to-date |

---

## 12. Acceptance criteria

1. 500 webhook deliveries/sec for 60s: zero drops, zero duplicates, p95 ack < 50ms.
2. Replaying the entire `webhook_deliveries` table produces byte-identical domain state.
3. A `read` status arriving before `delivered` leaves `messages.status = read` and both rows in
   `message_events`.
4. Composer is disabled outside the CSW and server-side send is refused.
5. Composer is disabled while `state = ai`; taking over is explicit and logged; handing back calls
   Thread Control `pass`.
6. Every message type in §4 renders without a console error, verified by a browser test against the
   demo seeder.
7. Two browser sessions on the same thread see each other's presence and each other's messages within
   2 seconds.
