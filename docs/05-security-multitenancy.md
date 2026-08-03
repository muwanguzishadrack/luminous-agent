# 05 — Security & Multi-tenancy

Tenant isolation is the single most consequential property of this system. One cross-tenant leak of
WhatsApp conversations ends the business and is reportable under Meta's platform terms.

---

## 1. Tenancy model

**Single database, single schema, `tenant_id` column on every tenant-scoped table.** Not
database-per-tenant. Rationale in `92-decisions.md` D-002.

Three independent layers of enforcement. Any one of them failing must not cause a leak.

### Layer 1 — Application scope

```php
// app/Models/Concerns/BelongsToTenant.php
protected static function bootBelongsToTenant(): void
{
    static::addGlobalScope(new TenantScope());

    static::creating(function (Model $model) {
        $model->tenant_id ??= Tenancy::currentIdOrFail();
    });
}
```

`Tenancy::currentIdOrFail()` **throws** when there is no tenant context. There is no silent default.

### Layer 2 — Postgres Row-Level Security

Defence in depth against a missing global scope, a raw query, or a `DB::table()` call.

```sql
ALTER TABLE messages ENABLE ROW LEVEL SECURITY;
ALTER TABLE messages FORCE ROW LEVEL SECURITY;

CREATE POLICY tenant_isolation ON messages
  USING (tenant_id = current_setting('app.tenant_id', true)::uuid);
```

The app sets `app.tenant_id` per connection when tenant context is established, and resets it after.
The runtime connection uses the non-superuser `luminous_app` role; migrations and system jobs run as
`luminous_migrator` (`BYPASSRLS`). Both roles are created in `docker/postgres/init/02-roles.sql`
(`03-local-development.md` §2) — a superuser connection would bypass RLS and make this layer a no-op.

> This is the layer that saves us when someone writes `DB::table('messages')->where(...)` in a hurry.

### Layer 3 — Authorization policies

Every model has a Policy. `tenant_user.role` plus optional `phone_number_ids` scoping decides what a
user may see. A supervisor of number A must not read conversations on number B.

### The dangerous paths

| Path | Risk | Control |
|---|---|---|
| **Queued jobs** | No tenant context; a job could operate globally | `tenant_id` is a required constructor arg; job re-establishes context in `handle()` and asserts it |
| **Webhook handlers** | Arrive with no session — tenant must be *resolved* from the payload | Resolve `phone_number_id`/`waba_id` → tenant. If unresolvable, park in `webhook_deliveries` with `status = ignored` and alert. **Never guess.** |
| **MBA connector calls** | Meta calls us; tenant comes from the URL + token | Token must hash-match a `connector_tokens` row **whose `tenant_id` matches the URL segment**. Mismatch = 404, not 403. |
| **Octane** | Singletons persist between requests and can leak tenant state | `Octane::tick`/`RequestTerminated` listener flushes `Tenancy`; a dedicated test asserts context does not survive a request |
| **Impersonation** | Support staff viewing a tenant | Separate `admin_sessions` audit trail, time-boxed, banner in UI, all actions tagged `actor_type = system` |

---

## 2. Token vault

We hold three kinds of Meta credential plus ioTec credentials. All are bearer secrets that can send
messages and move money.

| Credential | Scope | Storage |
|---|---|---|
| Meta **business token** | Per-tenant, Cloud API | `meta_credentials.type = business`, `encrypted` cast |
| Meta **BISU token** | Per-tenant, Meta Business Agent | `meta_credentials.type = bisu` |
| Meta app secret | Global | env only, never DB |
| ioTec client id/secret | Global (our merchant account) | env only |
| ioTec access token | Global, 300s TTL | Redis only, never persisted to DB |
| Connector tokens | Per-tenant, inbound | `connector_tokens.token_hash` — **hash only, never the token** |

Rules:

1. **Encrypted at rest** using `APP_KEY`, via Eloquent `encrypted` casts. Rotating `APP_KEY`
   requires a re-encryption command — write it before the first production tenant.
2. **Never logged.** A log-scrubbing processor redacts `access_token`, `client_secret`,
   `Authorization`, `token`, `verify_token`, `sip_user_password`, `callBackSecurityHeaderValue`.
3. **Never sent to the browser.** Not in Inertia props, not in error pages. `token_last4` only.
4. **Circuit breaker.** After 3 consecutive `190`/`401` responses, mark the credential
   `revoked_at`, suspend outbound sending for that tenant, and surface a reconnect prompt. Do not
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
- Token's `tenant_id` must equal the `{tenant}` URL segment.
- Rate limit per token (default 120 rpm) and per conversation.
- **Read tools return only fields declared in the tool's `output_schema`.** Never return a whole
  model. The agent will repeat what we give it to the customer — a stray internal note or another
  contact's data becomes a data breach spoken aloud in WhatsApp.
- Write tools require an explicit `is_write` flag on the tool and a per-tool ability on the token.

---

## 4. Data protection

| Concern | Control |
|---|---|
| Media | Private S3 bucket; served through signed, short-lived URLs scoped to the tenant. Never public. |
| Message content | Encrypted at rest at the volume level; not field-encrypted (search requires plaintext). Documented in the DPA. |
| PII in logs | Phone numbers hashed in logs; full value only in the DB |
| Voice transcripts | Same retention as the message; deleted with it |
| Retention | Configurable per tenant; default: messages 24 months, `webhook_deliveries` 30 days, audit 24 months |
| Right to erasure | `PurgeContact` action removes messages, media objects, notes, transcripts, consent evidence bodies (keeping a tombstone of the consent decision), and reindexes search |
| Export | Per-contact and per-tenant JSON + media archive |
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
| Rate limits | Per-user, per-tenant, per-IP on auth, send, and export routes |
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
| Suspected cross-tenant leak | Enable RLS `FORCE` audit logging, freeze exports, identify affected tenants from `audit_logs` |
| Leaked Meta token | Revoke via `meta_credentials.revoked_at`, force tenant reconnect through Embedded Signup |
| Leaked connector token | Rotate token, disable connector at Meta, review `mba_events` for exfiltration |
| ioTec credential compromise | Rotate client secret with ioTec support, reconcile all `payments` for the window |
| Webhook flood / replay | `webhook_deliveries` dedupe already absorbs replays; add per-IP throttle at Cloudflare |
