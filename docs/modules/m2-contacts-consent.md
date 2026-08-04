# M2 — Contacts, Consent & Segmentation

**Goal:** know who you are talking to, and make it structurally impossible to message someone who
opted out.

Tables: `contacts`, `contact_identifiers`, `consents`, `consent_states`, `labels`, `contact_label`,
`conversation_label`, `notes`, `segments`, `segment_members`.

---

## 1. Contact record

Created automatically on first inbound message (or import). Keyed on `(team_id, wa_id)`.

| Field group | Contents |
|---|---|
| Identity | `wa_id`, `phone_e164`, `profile_name` (from WhatsApp, read-only), `display_name` (ours) |
| Classification | `lifecycle_stage`, `owner_id`, `source`, labels |
| Activity | `first_seen_at`, `last_inbound_at`, `last_outbound_at` |
| Commerce | `lifetime_value`, `orders_count` (denormalised from M6) |
| Custom | `custom_fields` jsonb against a team-defined field schema |
| State | `is_blocked` |

`profile_name` is whatever WhatsApp reports and we never overwrite it — agents edit `display_name`.

### Custom field schema

Team-defined field definitions (type: text, number, date, select, multi-select, boolean) stored in
`teams.settings.contact_fields`. Values live in `contacts.custom_fields` with a GIN index. Typed
validation happens on write so segment filters can rely on types.

---

## 2. Identity: BSUIDs and number changes

`contact_identifiers` decouples the contact from any single identifier.

| Kind | Purpose |
|---|---|
| `wa_id` | Primary WhatsApp identifier |
| `bsuid` / `parent_bsuid` | Business-scoped user IDs — used by Marketing Messages API and some flows |
| `phone` | Normalised E.164 for ioTec payments and display |

When a `system` message reports the customer changed number:
1. Retire the old `wa_id` identifier (`retired_at`).
2. Add the new one as primary.
3. **Keep the same contact and conversation** — history continuity is the whole point.
4. Flag the contact for review: the person behind a recycled number may not be the same human.
   Marketing consent is **reset to `revoked`** on a number change. Utility/transactional continues.

That last rule is deliberate and conservative: a recycled number reaching a stranger with marketing
is both a privacy incident and a quality-score hit.

---

## 3. Consent — the compliance backbone

Two tables, one purpose:

- **`consents`** — append-only event log. Never updated, never deleted.
- **`consent_states`** — materialised current state, one row per `(contact_id, scope)`. **This is the
  only table the send guard reads.**

```
consents (event)  ──listener──▶  consent_states (current)  ──read by──▶ SendMessage guard
```

### Scopes and sources

| Scope | Meaning |
|---|---|
| `marketing` | Promotional templates and MM API sends |
| `utility` | Transactional templates (order updates, reminders) |
| `authentication` | OTP |
| `all` | Blanket grant/revoke |

| Source | Authority |
|---|---|
| `whatsapp_native` | **Highest.** From the `user_preferences` webhook ("Stop promotions") |
| `inbound_keyword` | Customer texted STOP/START — configurable keyword set per team |
| `web_form` | Opt-in form with IP + timestamp evidence |
| `import` | CSV with a required attestation field |
| `agent` | An agent recorded it in conversation — requires a note |
| `api` | Team's own system |
| `system` | Automatic events we generate — e.g. the marketing revoke on a number change (§2) |

### Precedence rules

1. A `whatsapp_native` **revoke** overrides everything and **can never be overridden** by import,
   agent, or API. Only a later `whatsapp_native` grant can restore it.
2. Otherwise, latest `occurred_at` wins.
3. `all` applies to every scope unless a more specific, later event exists for that scope.
4. Absence of consent is treated as **revoked** for `marketing`. Utility/authentication default to
   granted for contacts who have messaged us (inbound implies a service relationship).

### Enforcement

The guard lives **inside** `Actions/Messaging/SendTemplateMessage`, not in the UI and not in the
campaign builder. Campaigns, the API, automations, and the inbox all pass through it.

```php
// pseudocode
if ($template->category === TemplateCategory::Marketing
    && ! $consent->allows($contact, ConsentScope::Marketing)) {
    return SendResult::suppressed(SuppressionReason::NoConsent);
}
```

Suppressions are **recorded**, not silently dropped: `campaign_recipients.suppression_reason` +
`audit_logs`. We must be able to prove suppression happened.

### Keyword handling

Per-team configurable, defaults: `STOP`, `UNSUBSCRIBE`, `OPTOUT` → revoke marketing and auto-reply
with a confirmation. `START`, `SUBSCRIBE` → grant. Keyword processing happens in the inbound handler
before assignment, so an opt-out is honoured even if no agent ever opens the thread.

---

## 4. Customer context object

The single canonical read model for a contact. Used by:
- the inbox side panel (M1)
- the MBA `customer-context` Connector (M5)
- campaign variable resolution (M4)

```jsonc
{
  "wa_id": "2567…",
  "display_name": "Grace N.",
  "locale": "en",
  "lifecycle_stage": "customer",
  "labels": ["vip", "kampala"],
  "consent": { "marketing": "revoked", "utility": "granted" },
  "csw_open": true,
  "csw_expires_at": "2026-08-02T09:14:00Z",
  "orders": { "count": 4, "lifetime_value": "480000 UGX", "open": [ /* … */ ] },
  "last_payment": { "status": "Success", "amount": "120000 UGX", "at": "…" },
  "attribution": { "source": "ctwa", "campaign": "August Promo", "ad_id": "…" },
  "custom": { "preferred_branch": "Ntinda" }
}
```

**Built by one Action, `BuildCustomerContext`, with an explicit allowlist of fields.** Because this
object is fed to an AI that will speak its contents to the customer, it must never contain internal
notes, other contacts' data, or free-text staff commentary. See `05-security-multitenancy.md` §3.

---

## 5. Segmentation

`segments.definition` is a serialised filter AST:

```jsonc
{
  "op": "and",
  "children": [
    { "field": "lifecycle_stage", "op": "=", "value": "customer" },
    { "field": "labels", "op": "includes", "value": "kampala" },
    { "field": "orders.lifetime_value", "op": ">=", "value": 100000 },
    { "field": "consent.marketing", "op": "=", "value": "granted" },
    { "op": "or", "children": [
      { "field": "last_inbound_at", "op": "within_days", "value": 30 },
      { "field": "campaign.replied", "op": "=", "value": "aug-promo" }
    ]}
  ]
}
```

Filterable field families: contact scalars, custom fields, labels, consent state, conversation
activity, order/payment history, campaign interaction, CTWA attribution, MBA interaction
(`ai_handled`, last intent).

Compilation: AST → Eloquent query builder with a whitelist per field. **No user string ever reaches
SQL.** Unknown field ⇒ validation error, not a silent empty result.

Dynamic vs static:
- **Dynamic** — re-evaluated at send time. Correct for "everyone who…".
- **Static** — membership frozen in `segment_members`. Correct for reproducible one-off campaigns.
- Every campaign snapshots its resolved audience into `campaign_recipients` regardless, so the send
  is auditable after the segment changes.

Size estimation runs as a `COUNT` with a 3-second timeout; over that, show "10,000+" rather than
blocking the UI.

---

## 6. Import / export / merge

| Operation | Rules |
|---|---|
| CSV import | Column mapping UI, E.164 normalisation with a default country (`UG`), duplicate strategy (skip / update / merge), **mandatory consent attestation column for marketing** |
| Coexistence import | From `smb_app_state_sync`; marks `source = coexistence`. **Imported contacts get no marketing consent** — the client never obtained it through us |
| API import | Same validation path as CSV |
| Duplicate detection | Same `phone_e164`; also fuzzy name + partial number for review |
| Merge | Picks a surviving contact, re-parents conversations/orders/payments/consents, keeps both identifiers, writes an audit entry. **Consent after merge is the most restrictive of the two.** |
| Export | CSV/JSON, per-segment, audited, rate-limited |
| Erasure | `PurgeContact` — deletes messages, media objects, notes, transcripts; retains a consent **tombstone** (decision + timestamp, no content) for legal defence |

---

## 7. Blocking

Platform-level Block API on `/{phone_number_id}` for abusive numbers. Sets `contacts.is_blocked`,
hides the conversation from default views, and suppresses all sends. Unblock is available and audited.

---

## 8. UI surface

| Route | Screen |
|---|---|
| `/contacts` | Virtualised table, saved filters, inline segment builder, bulk actions |
| `/contacts/{id}` | Profile: timeline (messages, orders, payments, campaigns, consent events), custom fields, labels, notes |
| `/contacts/import` | Mapping wizard with validation preview and attestation step |
| `/segments` | List, builder with live count, usage (which campaigns reference it) |
| `/settings/fields` | Custom field schema editor |
| `/settings/consent` | Keyword configuration, default policies, consent audit export |

The contact profile's **consent panel** shows current state per scope, the source, when, and the full
event history. This is the screen a client shows a regulator.

---

## 9. Edge cases

| Case | Handling |
|---|---|
| Inbound from a contact who opted out | Reply freely — inbound reopens the service relationship. Marketing stays revoked. |
| `user_preferences` for an unknown contact | Create the contact with the consent state recorded |
| Import claims consent for a natively opted-out contact | Reject that row and report it; native wins |
| Two contacts merge with conflicting consent | Most restrictive wins |
| Number recycled to a new person | Marketing consent reset to revoked (§2) |
| Segment references a deleted custom field | Segment marked invalid; campaigns using it refuse to send with a clear error |
| Contact with 50k messages | Timeline is cursor-paginated |

---

## 10. Acceptance criteria

1. `user_preferences` revoke propagates to `consent_states` and blocks the next marketing send —
   proven by a feature test on the Action, not the controller.
2. An import row asserting marketing consent for a natively opted-out contact is rejected and
   reported.
3. A campaign to a 5,000-contact segment where 300 lack consent sends 4,700 and records 300
   suppressions with reason `no_consent`.
4. Merging two contacts preserves all conversations, orders and payments, and yields the most
   restrictive consent.
5. `BuildCustomerContext` output contains no field outside its declared allowlist — asserted by a
   test that fails when a new field is added without being declared.
6. A malicious segment definition (`{"field":"id) OR 1=1--"}`) fails validation and never reaches SQL.
