# M3 — Template Management

**Goal:** a client creates, submits, and monitors templates entirely inside our product, and always
knows what each one costs.

Tables: `templates`, `template_group`, `template_events`.

---

## 1. Why this module matters commercially

Templates are the only message type sendable **outside** the 24-hour customer service window, and the
template's **category determines the price**. A client who accidentally builds a Marketing template
for a transactional purpose pays marketing rates forever. Our editor must make that impossible to do
by accident.

---

## 2. Endpoints

| Operation | Endpoint |
|---|---|
| List / create | `GET|POST /{waba-id}/message_templates` |
| Read / update | `GET|POST /{template-id}` |
| Delete | `DELETE /{waba-id}/message_templates?name=…` (all languages) · `&hsm_id=…` for one · `?hsm_ids=[…]` bulk |
| Compare | `GET /{template-id}/compare?template_ids=[…]&start=…&end=…` (7/30/60/90-day windows; both templates ≥1,000 sends) |

Deleting an approved template locks its name for 30 days; `DISABLED` templates cannot be deleted
(see `reference/whatsapp-cloud-api.md` §4).

Sync is two-way:
- **Push** on create/edit from our editor.
- **Pull** on a schedule and on webhook, because templates can be created or altered in WhatsApp
  Manager, and Meta can edit components or re-categorise.

`templates.last_synced_at` + a nightly reconcile job. Divergence is surfaced, not silently overwritten.

---

## 3. Editor

Three-pane: component tree · form · live WhatsApp-accurate preview.

| Component | Support |
|---|---|
| Header | text (with 1 variable), image, video, document, location |
| Body | text with `{{n}}` variables, formatting |
| Footer | static text |
| Buttons | quick reply, URL (static + dynamic suffix), phone number, copy code, flow, catalog, MPM/SPM |

### Category selection with a cost warning

The category picker shows, inline, the **live rate for the team's most common recipient country**
pulled from `pricing_analytics`, plus a one-line explanation:

> **Utility** — order updates, receipts, reminders. Free inside the 24-hour service window
> *(until Oct 1, 2026)*.
> **Marketing** — promotions, offers, re-engagement. **Always charged.**
> **Authentication** — OTP only.

A heuristic linter flags likely miscategorisation ("this Utility template contains 'discount' and
'offer' — Meta may re-categorise it as Marketing") before submission. Meta's own re-categorisation
arrives later via `template_category_update`; catching it pre-submission saves the client money.

### Rich template types to support

Marketing: carousel, media card carousel, coupon code, limited-time offer, location, catalog,
single-product (SPM), multi-product (MPM), product carousel, call-permission-request.
Utility: standard, location, order/appointment updates.
Authentication: one-tap autofill, zero-tap, copy-code.

Plus **Template Library** — one-click creation from Meta's pre-approved library, which approves
faster. The library picker should be the default entry point for common cases.

---

## 4. Variable mapping

`templates.variable_map` binds each placeholder to a CRM path with a fallback:

```jsonc
{
  "header": { "1": { "field": "contact.display_name", "fallback": "there" } },
  "body": {
    "1": { "field": "contact.display_name", "fallback": "there" },
    "2": { "field": "order.reference",      "fallback": null },
    "3": { "field": "order.total_formatted","fallback": null }
  },
  "buttons": { "0": { "field": "order.tracking_url", "fallback": null } }
}
```

Rules:

1. **A variable with no fallback and no value blocks the send** for that recipient with
   `suppression_reason = missing_variable`. Never ship a literal `{{1}}`.
2. Available paths come from the M2 customer context object plus campaign-supplied values, so the
   picker only offers fields that actually resolve.
3. Resolved values are stored per recipient in `campaign_recipients.variables` for audit and
   reproducibility.
4. Preview renders against a real contact chosen by the user, not lorem ipsum.

---

## 5. Multi-language groups

`template_group` is a logical set: one `key`, many `templates` differing by `language`.

Campaign targeting selects a **group**; per recipient we resolve
`contact.locale → group's template in that language → team default language`. If neither exists,
suppress with `unsupported_language` rather than sending the wrong language.

---

## 6. Status & quality monitoring

Driven by webhooks (`whatsapp-webhooks.md`):

| Webhook | Effect |
|---|---|
| `message_template_status_update` | `templates.status`, `rejected_reason` verbatim |
| `message_template_quality_update` | `quality_score` GREEN/YELLOW/RED |
| `message_template_components_update` | Components changed by Meta — re-pull and diff |
| `template_category_update` | **Category and therefore price changed** — high-severity notification |

Every event appends to `template_events` so the client sees a full history.

### UI treatment

- `REJECTED` shows Meta's reason **verbatim**, plus our plain-language interpretation and a
  "duplicate and fix" action. Never paraphrase away the original text.
- `PAUSED` explains that Meta paused it for quality and shows when it may resume.
- `DISABLED` is terminal — offer a rebuild path.
- `quality_score = RED` shows a warning on every campaign that uses it.
- `template_category_update` produces a banner: *"Meta re-categorised **order_update** from Utility
  to Marketing. Sends now cost X instead of Y."*

### Pacing and pausing

Surface Meta's throttling states so a client understands a slow campaign:

| State | Meaning |
|---|---|
| Template pacing | New/low-quality templates are throttled while Meta samples engagement |
| Portfolio pacing | Account-wide marketing throttle |
| Per-user marketing limits | Meta drops over-frequent marketing to a given user — M4 pre-suppresses |

A campaign pre-flight check warns when the chosen template is being paced.

---

## 7. Other controls

| Control | Purpose |
|---|---|
| TTL override (`ttl_seconds`) | Expire a time-sensitive message rather than deliver it late |
| Tap-target override | Control button behaviour |
| Bulk management | Import/export templates as JSON — the transport an agency uses to move a template set between the teams it runs |
| Preview API | Meta's template previews where available |

---

## 8. UI surface

| Route | Screen |
|---|---|
| `/templates` | Table: name, language(s), category with rate, status, quality, last used, usage count |
| `/templates/new` | Library picker → editor |
| `/templates/{id}` | Editor + status history + analytics (from M8 `template_analytics`) |
| `/templates/groups` | Multi-language group management |

The list's **usage count and last-used** columns matter: clients accumulate dozens of dead templates
and cannot tell which are safe to delete.

---

## 9. Edge cases

| Case | Handling |
|---|---|
| Template created directly in WhatsApp Manager | Nightly pull imports it as read-only-then-editable |
| Meta edits our components | Diff view; client accepts to resync our copy |
| Rejected for a reason we cannot parse | Show verbatim; link Meta's guidance; never block the client |
| Same name, different language | Unique on `(waba, name, language)` — this is legal and expected |
| Deleting a template used by an active campaign | Refuse; require the campaign be stopped first |
| Variable count mismatch after an edit | Validate `variable_map` against components on save; refuse on mismatch |
| Template approved but number quality is RED | Warn at campaign time, not at template time |

---

## 10. Acceptance criteria

1. A template created in our editor appears in WhatsApp Manager with identical components.
2. Approval, rejection, pause, quality change, and category change each surface in the UI within one
   webhook cycle, with history retained.
3. A category change produces a visible cost-impact notification naming both rates.
4. A send with a missing, fallback-less variable is suppressed with `missing_variable` and never
   transmits a literal `{{n}}`.
5. A campaign against a multi-language group delivers the correct language per contact locale and
   suppresses unsupported locales explicitly.
6. A template edited in WhatsApp Manager is detected by the nightly reconcile and flagged as diverged.
