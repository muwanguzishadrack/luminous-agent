# 05 — Security & Multi-tenancy

Team isolation is the single most consequential property of this system. One cross-team leak of
WhatsApp conversations ends the business and is reportable under Meta's platform terms.

---

## 1. Team isolation model

**Single database, single schema, `team_id` column on every team-scoped table.** Not
database-per-team. Rationale in `92-decisions.md` D-002.

### Membership

**One team per user**, one WABA and one phone number per team (D-020). The team a request runs as is
the user's single `team_user` row, resolved once at authentication. There is no team switcher, no
`current_team_id` pointer to fall out of step with the membership, and therefore no route that can
execute against a team the user did not intend — the failure that D-020 records. Support staff reach
a team only through an `admin_sessions` impersonation, never through a switcher.

### Enforcement

Three independent layers. Any one of them failing must not cause a leak.

### Layer 1 — Application scope

```php
// app/Models/Concerns/BelongsToTeam.php
protected static function bootBelongsToTeam(): void
{
    static::addGlobalScope(new TeamScope());

    static::creating(function (Model $model) {
        $model->team_id ??= Teams::currentIdOrFail();
    });
}
```

The `Teams` facade fronts `TeamManager`. `Teams::currentIdOrFail()` **throws `MissingTeamContext`**
when there is no team context. There is no silent default.

### Layer 2 — Postgres Row-Level Security

Defence in depth against a missing global scope, a raw query, or a `DB::table()` call.

```sql
ALTER TABLE messages ENABLE ROW LEVEL SECURITY;
ALTER TABLE messages FORCE ROW LEVEL SECURITY;

CREATE POLICY team_isolation ON messages
  USING (team_id = current_setting('app.team_id', true)::uuid);
```

The app sets `app.team_id` per connection when team context is established, and resets it after.
The runtime connection uses the non-superuser `luminous_app` role; migrations and system jobs run as
`luminous_migrator` (`BYPASSRLS`). Both roles are created in `docker/postgres/init/02-roles.sql`
(`03-local-development.md` §2) — a superuser connection would bypass RLS and make this layer a no-op.

> This is the layer that saves us when someone writes `DB::table('messages')->where(...)` in a hurry.

`team_user` is the one table the plain policy cannot cover: at authentication we must read a user's
membership **before** team context exists. Its policy is user-aware:

```sql
CREATE POLICY team_membership ON team_user
  USING (
    team_id = current_setting('app.team_id', true)::uuid
    OR user_id = current_setting('app.user_id', true)::uuid
  );
```

The app sets `app.user_id` alongside `app.team_id`. A signed-in user with no team context can read
exactly one row — their own membership — and that row is what establishes the context.

### Layer 3 — Authorization policies

Every model has a Policy. `team_user.role` decides what a user may see within their team. Because a
team has a single number, there is no per-number scoping to get wrong; role is the only axis.

### The dangerous paths

| Path | Risk | Control |
|---|---|---|
| **Queued jobs** | No team context; a job could operate globally | `team_id` is a required constructor arg; job re-establishes context in `handle()` and asserts it |
| **Webhook handlers** | Arrive with no session — team must be *resolved* from the payload | Resolve `phone_number_id`/`waba_id` → team. If unresolvable, park in `webhook_deliveries` with `status = ignored` and alert. **Never guess.** |
| **MBA connector calls** | Meta calls us; team comes from the URL + token | Token must hash-match a `connector_tokens` row **whose `team_id` matches the URL segment**. Mismatch = 404, not 403. |
| **Octane** | Singletons persist between requests and can leak team state | `Octane::tick`/`RequestTerminated` listener flushes `Teams`; a dedicated test asserts context does not survive a request |
| **Impersonation** | Support staff viewing a team | Separate `admin_sessions` audit trail, time-boxed, banner in UI, all actions tagged `actor_type = system` |

---

## 2. Token vault

We hold three kinds of Meta credential plus ioTec credentials. All are bearer secrets that can send
messages and move money.

| Credential | Scope | Storage |
|---|---|---|
| Meta **business token** | Per-team, Cloud API | `meta_credentials.type = business`, `encrypted` cast |
| Meta **BISU token** | Per-team, Meta Business Agent | `meta_credentials.type = bisu` |
| Meta app secret | Global | env only, never DB |
| ioTec client id/secret | Global (our merchant account) | env only |
| ioTec access token | Global, 300s TTL | Redis only, never persisted to DB |
| Connector tokens | Per-team, inbound | `connector_tokens.token_hash` — **hash only, never the token** |

Rules:

1. **Encrypted at rest** using `APP_KEY`, via Eloquent `encrypted` casts. Rotating `APP_KEY`
   requires a re-encryption command — write it before the first production team.
2. **Never logged.** A log-scrubbing processor redacts `access_token`, `client_secret`,
   `Authorization`, `token`, `verify_token`, `sip_user_password`, `callBackSecurityHeaderValue`.
3. **Never sent to the browser.** Not in Inertia props, not in error pages. `token_last4` only.
4. **Circuit breaker.** After 3 consecutive `190`/`401` responses, mark the credential
   `revoked_at`, suspend outbound sending for that team, and surface a reconnect prompt. Do not
   retry-loop a dead token — it looks like an attack to Meta.
5. **Least privilege.** Request only `whatsapp_business_management` and
   `whatsapp_business_messaging`. Nothing else.

---

## 3. Inbound authentication

### Meta webhooks

```php
$expected = hash_hmac('sha256', $request->getContent(), config('meta.app_secret'));
abort_unless(hash_equals("sha256={$expected}", $request->header('X-Hub-Signature-256', '')), 401);
```

- Compute over the **raw body**. Any middleware that decodes or re-encodes JSON breaks this.
- `hash_equals`, never `===`.
- `GET` verification compares `hub.verify_token` with `hash_equals` and echoes `hub.challenge`.
- Routes are registered **outside** the `web` middleware group: no session, no CSRF.

### ioTec callbacks

ioTec sends a **static security header** configured per wallet in their portal. A static shared
secret is weaker than an HMAC over the body, so we add a second gate:

1. Constant-time compare the configured header value. Mismatch → 401.
2. **Re-fetch the transaction** from `GET /api/collections/status/{requestId}` (or the disbursement
   equivalent) and trust *that* response, not the callback body, before crediting anything.

Step 2 is mandatory. It means a leaked or guessed header value cannot fabricate a payment.

### MBA connector calls

- `Authorization: Bearer <token>`; look up by `prefix`, verify with `hash_equals` against
  `token_hash`.
- Token's `team_id` must equal the `{team}` URL segment.
- Rate limit per token (default 120 rpm) and per conversation.
- **Read tools return only fields declared in the tool's `output_schema`.** Never return a whole
  model. The agent will repeat what we give it to the customer — a stray internal note or another
  contact's data becomes a data breach spoken aloud in WhatsApp.
- Write tools require an explicit `is_write` flag on the tool and a per-tool ability on the token.

---

## 4. Data protection

| Concern | Control |
|---|---|
| Media | Private S3 bucket; served through signed, short-lived URLs scoped to the team. Never public. |
| Message content | Encrypted at rest at the volume level; not field-encrypted (search requires plaintext). Documented in the DPA. |
| PII in logs | Phone numbers hashed in logs; full value only in the DB |
| Voice transcripts | Same retention as the message; deleted with it |
| Retention | Configurable per team; default: messages 24 months, `webhook_deliveries` 30 days, audit 24 months |
| Right to erasure | `PurgeContact` action removes messages, media objects, notes, transcripts, consent evidence bodies (keeping a tombstone of the consent decision), and reindexes search |
| Export | Per-contact and per-team JSON + media archive |
| Meta data policy | We follow the **local storage** model; document exactly what we retain and for how long, per Meta's Data Privacy & Security requirements |

---

## 5. Consent as a security control

Sending marketing to a revoked contact is not a bug, it is a compliance incident that degrades
number quality and can get a client's number blocked.

- `consent_states` is checked **inside** `SendMessage`/`SendTemplateMessage`, not in the UI and not
  in the campaign builder. The guard cannot be bypassed by any caller.
- A native opt-out (`user_preferences` webhook) is authoritative and cannot be overridden by an
  import or an agent action.
- Attempted sends to revoked contacts are recorded in `campaign_recipients.suppression_reason` and
  `audit_logs`, so we can prove suppression happened.

---

## 6. Application hardening

| Control | Implementation |
|---|---|
| Rate limits | Per-user, per-team, per-IP on auth, send, and export routes |
| CSP | Strict; nonce-based. `connect-src` allows only self + Reverb + Meilisearch proxy |
| CSRF | Enabled on all `web` routes; public routes excluded deliberately |
| SSRF | **Website knowledge sources and product image URLs are user-supplied.** Fetch through an allowlist-validated resolver that rejects private IP ranges, `file://`, redirects to internal hosts, and DNS-rebinding |
| File upload | MIME sniffing (not extension), size caps per type, ClamAV scan before the file is ever served or forwarded to Meta |
| Dependencies | `composer audit` and `npm audit` in CI; Dependabot |
| 2FA | Required for `owner` and `admin` roles |
| Session | Redis, 8-hour idle timeout, invalidate on role change |
| Secrets in CI | Never in the repo; `.env.example` carries keys with empty values only |

---

## 7. Incident playbook (stub — expand before production)

| Incident | First action |
|---|---|
| Suspected cross-team leak | Enable RLS `FORCE` audit logging, freeze exports, identify affected teams from `audit_logs` |
| Leaked Meta token | Revoke via `meta_credentials.revoked_at`, force team reconnect through Embedded Signup |
| Leaked connector token | Rotate token, disable connector at Meta, review `mba_events` for exfiltration |
| ioTec credential compromise | Rotate client secret with ioTec support, reconcile all `payments` for the window |
| Webhook flood / replay | `webhook_deliveries` dedupe already absorbs replays; add per-IP throttle at Cloudflare |
