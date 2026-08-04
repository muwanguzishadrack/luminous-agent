# 02 — Data Model

Postgres 17. Single database, single schema. **Every team-scoped table carries `team_id`** and
is protected by a global Eloquent scope plus a Postgres RLS policy as defence in depth
(see `05-security-multitenancy.md`).

Conventions:
- Primary keys: `uuid` (v7, time-ordered) via `HasUuids`. Exception: high-volume append-only tables
  use `bigIncrements` + a `uuid` public id.
- Timestamps: `timestamptz`. Always store UTC; render in the team's WABA timezone.
- Money: `bigint` **minor units** + `char(3) currency`. Never float. ioTec sends doubles — convert
  at the boundary.
- Enums: Postgres native enums for stable vocabularies, `varchar` + app-level validation for
  vocabularies Meta may extend.
- JSON: `jsonb` with GIN indexes only where queried.
- Soft deletes only on `contacts`, `templates`, `campaigns`, `segments`. Everything else is hard.

---

## 1. Teams & identity

### `teams`
| Column | Type | Notes |
|---|---|---|
| id | uuid pk | |
| name | varchar | |
| slug | varchar unique | subdomain / white-label key |
| status | enum | `onboarding`, `active`, `suspended`, `offboarded` |
| plan | varchar | billing plan key |
| country | char(2) | default `UG` |
| default_currency | char(3) | default `UGX` |
| settings | jsonb | white-label, branding, feature toggles |
| trial_ends_at | timestamptz null | |
| suspended_reason | varchar null | |
| created_at / updated_at | timestamptz | |

### `users`
Standard Laravel users plus: `avatar_path`, `last_active_at`, `locale`, `timezone`,
`two_factor_*`. **A user belongs to at most one team** (D-020). There is no `current_team_id`
pointer — the membership row *is* the team context.

### `team_user`
| Column | Type | Notes |
|---|---|---|
| team_id / user_id | uuid | composite pk |
| role | enum | `owner`, `admin`, `supervisor`, `agent`, `viewer` |
| status | enum | `invited`, `active`, `disabled` |
| invited_by / invited_at / joined_at | | |

Unique: `(user_id)`. One row per user, enforced by the database — a second membership cannot be
created by an invitation, an import, or a race. A person running two businesses needs two logins
(D-020).

### `audit_logs`
| Column | Type | Notes |
|---|---|---|
| id | bigint pk | |
| team_id | uuid index | |
| actor_type | enum | `user`, `system`, `mba`, `owner_device` |
| actor_id | uuid null | |
| action | varchar | `message.sent`, `template.updated`, `token.used`, `agent.enabled`, … |
| subject_type / subject_id | | polymorphic |
| context | jsonb | before/after, ip, user agent |
| created_at | timestamptz index | |

Retention: 24 months, then archive to object storage.

### `admin_sessions`
Impersonation audit trail (`05-security-multitenancy.md` §1).

| Column | Type | Notes |
|---|---|---|
| id | uuid pk | |
| team_id | uuid index | team being impersonated |
| admin_user_id | uuid | platform staff member |
| reason | varchar | required |
| started_at | timestamptz | |
| expires_at | timestamptz | time-boxed, non-extendable |
| ended_at | timestamptz null | |

Every action taken during the session is written to `audit_logs` with `actor_type = 'system'` and
`context.admin_session_id` set.

---

## 2. Meta assets (M0)

### `waba_accounts`
| Column | Type | Notes |
|---|---|---|
| id | uuid pk | |
| team_id | uuid index | |
| waba_id | varchar unique | Meta WABA ID |
| owner_business_id | varchar | business portfolio ID |
| solution_id | varchar null | set when onboarded via a Multi-Partner Solution |
| name | varchar | |
| timezone_id | varchar | drives analytics day boundaries |
| currency | char(3) | Meta's billing currency for this WABA |
| review_status | varchar | from `account_review_update` |
| account_status | varchar | from `account_update` |
| business_verification_status | varchar | |
| portfolio_messaging_limit | varchar null | `TIER_250`, `TIER_2K`, `TIER_10K`, `TIER_100K`, `TIER_UNLIMITED` — from `whatsapp_business_manager_messaging_limit` / `business_capability_update`. Null until Meta assigns one; never default it |
| is_subscribed | boolean | our app subscribed to this WABA's webhooks |
| payment_ready | boolean | client has attached a payment method |
| onboarded_at / offboarded_at | timestamptz null | |

Unique: `(team_id)`. **One WABA per team** (D-020). A second Embedded Signup run against a team that
already holds a WABA is refused, not merged.

### `phone_numbers`
| Column | Type | Notes |
|---|---|---|
| id | uuid pk | |
| team_id / waba_account_id | uuid index | |
| phone_number_id | varchar unique | Meta business phone number ID |
| display_phone_number | varchar | E.164-ish as Meta returns it |
| verified_name | varchar | |
| code_verification_status | varchar | two-step verification state. Meta's docs disagree on the value set (`UNVERIFIED` vs `NOT_VERIFIED`) — store what arrives, see `reference/whatsapp-cloud-api.md` §5. **Never a display-name state** |
| name_status | varchar | display-name review state: `APPROVED`, `AVAILABLE_WITHOUT_REVIEW`, `DECLINED`, `EXPIRED`, `PENDING_REVIEW`, `NONE`. **A different field from `code_verification_status`** — see `reference/whatsapp-cloud-api.md` §5 |
| quality_rating | enum | `GREEN`, `YELLOW`, `RED`, `UNKNOWN` |
| throughput_level | varchar | `STANDARD`, `HIGH`, … |
| platform_type | varchar | `CLOUD_API`, … |
| is_on_biz_app | boolean | **true = Coexistence number** |
| is_official_business_account | boolean | |
| registered_at | timestamptz null | |
| pin_set | boolean | 2FA PIN configured |
| profile | jsonb | about, address, description, email, websites, vertical, profile picture handle |
| connection_status | varchar null | Meta's `status` on the number node, e.g. `CONNECTED`. Null until first sync and **never defaulted** — Meta does not publish the returnable value set |
| status | enum | `pending`, `active`, `disconnected` — **app-level lifecycle**, a different thing from `connection_status` above |

Unique: `(team_id)`. **One phone number per team** (D-020). `waba_account_id` is therefore always
the team's single WABA; it stays as a column so Meta's asset graph is still modelled honestly.

### `meta_credentials`
Encrypted token vault. **Two distinct token types.**

| Column | Type | Notes |
|---|---|---|
| id | uuid pk | |
| team_id | uuid index | |
| waba_account_id | uuid null | |
| type | enum | `business` (Cloud API), `bisu` (Meta Business Agent), `system` |
| token | text | `encrypted` cast — Laravel app-key encryption |
| token_last4 | varchar(8) | for display only |
| scopes | jsonb | granted permissions |
| issued_at / expires_at | timestamptz null | |
| revoked_at | timestamptz null | |
| last_used_at | timestamptz null | |
| failure_count | int | trip a breaker after N consecutive auth failures |

Unique: `(team_id, waba_account_id, type)` where `revoked_at is null`.

### `onboarding_sessions`
Tracks an Embedded Signup run end-to-end so failures are debuggable.

| Column | Type | Notes |
|---|---|---|
| id | uuid pk | |
| team_id | uuid null | null until the team is created |
| nonce | varchar unique | ties the browser session to the exchange |
| feature_type | varchar null | `whatsapp_business_app_onboarding` for Coexistence |
| es_version | varchar | `v4` |
| events | jsonb | ordered list of ES session events received from the JS SDK |
| waba_id / phone_number_id | varchar null | captured on FINISH |
| code_exchanged_at | timestamptz null | |
| history_sync_requested_at | timestamptz null | **24h clock starts at onboarding** |
| history_sync_completed_at | timestamptz null | |
| contacts_sync_requested_at | timestamptz null | |
| status | enum | `started`, `finished`, `exchanged`, `registered`, `syncing`, `complete`, `failed` |
| failure | jsonb null | |

---

## 3. Webhook ingest (M1)

### `webhook_deliveries`
Raw, append-only. The audit trail and replay source for everything inbound.

| Column | Type | Notes |
|---|---|---|
| id | bigint pk | |
| source | enum | `meta`, `iotec` |
| body_sha256 | char(64) | **unique with `source`** → idempotent delivery |
| headers | jsonb | signature, delivery id |
| payload | jsonb | raw body |
| team_id | uuid null index | resolved during processing, null if unresolvable |
| received_at | timestamptz | |
| processed_at | timestamptz null | |
| attempts | smallint | |
| status | enum | `pending`, `processed`, `partial`, `failed`, `ignored` |
| error | jsonb null | |

Indexes: `(source, body_sha256) unique`, `(status, received_at)`, `(team_id, received_at)`.
Retention: 30 days hot, then archive.

---

## 4. Contacts & consent (M2)

### `contacts`
| Column | Type | Notes |
|---|---|---|
| id | uuid pk | |
| team_id | uuid index | |
| wa_id | varchar | WhatsApp user id (MSISDN, no `+`) |
| phone_e164 | varchar | normalised, for display and ioTec |
| profile_name | varchar null | as WhatsApp reports it — not editable by us |
| display_name | varchar null | CRM-editable |
| locale | varchar null | drives template language selection |
| lifecycle_stage | varchar | `lead`, `engaged`, `customer`, `churned` — team-configurable |
| owner_id | uuid null | assigned CRM owner (user) |
| source | varchar | `inbound`, `ctwa`, `import`, `coexistence`, `api`, `qr` |
| first_seen_at / last_inbound_at / last_outbound_at | timestamptz null | |
| lifetime_value | bigint | minor units, denormalised from orders |
| orders_count | int | denormalised |
| is_blocked | boolean | platform-level block applied |
| undeliverable_at | timestamptz null | set on send error `131026` (not on WhatsApp / cannot receive); drives campaign suppression `invalid_number`. Cleared if the contact later messages us. |
| custom_fields | jsonb | team-defined schema |
| deleted_at | timestamptz null | soft delete |

Unique: `(team_id, wa_id)`. Index: `(team_id, last_inbound_at desc)`, GIN on `custom_fields`.

### `contact_identifiers`
Handles BSUIDs and identity changes without losing history.

| Column | Type | Notes |
|---|---|---|
| id | uuid pk | |
| team_id / contact_id | uuid | |
| kind | enum | `wa_id`, `bsuid`, `parent_bsuid`, `phone` |
| value | varchar | |
| is_primary | boolean | |
| verified_at / retired_at | timestamptz null | retired when the number changes hands |

Unique: `(team_id, kind, value)`.

### `consents` — append-only, never updated
| Column | Type | Notes |
|---|---|---|
| id | bigint pk | |
| team_id / contact_id | uuid index | |
| scope | enum | `marketing`, `utility`, `authentication`, `all` |
| state | enum | `granted`, `revoked` |
| source | enum | `whatsapp_native`, `inbound_keyword`, `web_form`, `import`, `agent`, `api`, `system` (automatic events, e.g. marketing revoke on number change — M2 §2) |
| evidence | jsonb | wamid, form payload, IP, uploader, screenshot ref |
| occurred_at | timestamptz | |
| created_at | timestamptz | |

Current state is a derived read model:

### `consent_states` (materialised, one row per contact+scope)
`team_id, contact_id, scope, state, source, occurred_at, consent_id` — updated by a listener on
every `consents` insert. **This is the table the send guard reads.** Unique `(contact_id, scope)`.

> `user_preferences` webhook writes here with `source = whatsapp_native`. A native opt-out always
> wins over any other source.

### `labels` / `contact_label` / `conversation_label`
`labels`: `id, team_id, name, color, kind (contact|conversation), created_by`. Pivots are simple.

### `notes`
`id, team_id, contact_id, conversation_id null, user_id, body, mentions jsonb, created_at`.

### `segments`
| Column | Type | Notes |
|---|---|---|
| id | uuid pk | |
| team_id | uuid | |
| name | varchar | |
| definition | jsonb | AST of the filter tree (field/op/value + and/or) |
| is_dynamic | boolean | dynamic = re-evaluated at send; static = frozen membership |
| estimated_size / last_evaluated_at | | |

### `segment_members`
`segment_id, contact_id, added_at` — only populated for static segments and for campaign snapshots.

---

## 5. Conversations & messages (M1)

### `conversations`
| Column | Type | Notes |
|---|---|---|
| id | uuid pk | |
| team_id / phone_number_id / contact_id | uuid index | |
| state | enum | `ai`, `queued`, `human`, `closed` |
| owner_app_id | varchar null | from `messaging_handovers` — who Meta thinks holds control |
| assigned_user_id | uuid null | |
| assigned_at | timestamptz null | |
| csw_expires_at | timestamptz null | **24h customer service window** — last inbound + 24h |
| fep_expires_at | timestamptz null | **72h free entry point window** — set on CTWA arrival |
| last_message_at / last_inbound_at / last_outbound_at | timestamptz null | |
| unread_count | int | |
| first_response_at | timestamptz null | for FRT reporting |
| resolved_at | timestamptz null | |
| snoozed_until | timestamptz null | |
| sla_breached_at | timestamptz null | |
| ai_handled_count / human_handled_count | int | for containment-rate reporting |

Unique: `(team_id, phone_number_id, contact_id)`.
Indexes: `(team_id, state, last_message_at desc)`, `(assigned_user_id, state)`, `(csw_expires_at)`.

### `messages`
| Column | Type | Notes |
|---|---|---|
| id | uuid pk | |
| team_id / conversation_id | uuid index | |
| wamid | varchar **unique** | the idempotency key for everything inbound |
| direction | enum | `inbound`, `outbound` |
| type | varchar | `text`, `image`, `audio`, `video`, `document`, `sticker`, `location`, `contacts`, `interactive`, `button`, `order`, `reaction`, `template`, `system`, `unsupported` |
| body | text null | extracted plain text for search |
| payload | jsonb | full message object as sent/received |
| media_id | uuid null | → `media` |
| replied_to_wamid | varchar null | contextual reply |
| reaction_to_wamid | varchar null | |
| origin | enum | `agent`, `mba`, `campaign`, `automation`, `owner_device`, `customer`, `system` |
| sent_by_user_id | uuid null | |
| campaign_id | uuid null | |
| template_id | uuid null | |
| status | enum | `queued`, `sent`, `delivered`, `read`, `failed`, `deleted` |
| error_code | int null | Meta error code |
| error_detail | jsonb null | |
| pricing_category | varchar null | `marketing`, `utility`, `authentication`, `service`, `meta_business_agent` |
| billable | boolean null | |
| cost_minor | bigint null | resolved by the meter, may be backfilled |
| token_count | int null | **MBA messages only** |
| sent_at / delivered_at / read_at / failed_at | timestamptz null | |
| occurred_at | timestamptz index | canonical sort key (Meta timestamp for inbound) |

Indexes: `wamid unique`, `(conversation_id, occurred_at)`, `(team_id, occurred_at desc)`,
`(status)` partial where `status in ('queued','sent')`, `(campaign_id)`.

Search: `body` synced to Meilisearch, not a Postgres FTS index (multi-language, typo tolerance).

### `message_events` — append-only status ladder
`id bigint, team_id, message_id, wamid, status, error_code null, pricing jsonb null, occurred_at, payload jsonb`.
Lets a `read` arriving before `delivered` be recorded honestly rather than clobbering state.

### `media`
| Column | Type | Notes |
|---|---|---|
| id | uuid pk | |
| team_id | uuid | |
| meta_media_id | varchar null | Meta's id; expires ~30 days |
| sha256 | char(64) index | dedupe identical uploads |
| mime_type / size_bytes / filename | | |
| disk / path | varchar | our S3/MinIO copy |
| thumb_path | varchar null | |
| duration_ms | int null | audio/video |
| transcript | text null | voice-note STT |
| scan_status | enum | `pending`, `clean`, `infected`, `skipped` |
| meta_expires_at | timestamptz null | re-upload before this to reuse |

### `thread_control_events` — append-only
`id bigint, team_id, conversation_id, event (pass|take|request|app_roles), previous_owner_app_id, new_owner_app_id, metadata jsonb, actor_type, actor_id, occurred_at`.

### `canned_replies`
`id, team_id, shortcut, title, body, variables jsonb, is_shared, created_by`.

---

## 6. Templates (M3)

### `templates`
| Column | Type | Notes |
|---|---|---|
| id | uuid pk | |
| team_id / waba_account_id | uuid | |
| meta_template_id | varchar null | null until submitted |
| name | varchar | Meta's snake_case name |
| language | varchar | e.g. `en`, `en_US` |
| category | enum | `MARKETING`, `UTILITY`, `AUTHENTICATION` |
| sub_type | varchar null | `carousel`, `coupon_code`, `limited_time_offer`, `catalog`, `mpm`, `spm`, `location`, `otp`, `call_permission_request` |
| status | varchar | `DRAFT`, `PENDING`, `APPROVED`, `REJECTED`, `PAUSED`, `DISABLED`, `IN_APPEAL` |
| quality_score | varchar null | `GREEN`, `YELLOW`, `RED` |
| rejected_reason | varchar null | surfaced verbatim in the UI |
| components | jsonb | header/body/footer/buttons as Meta expects |
| variable_map | jsonb | `{"body": {"1": {"field":"contact.display_name","fallback":"there"}}}` |
| ttl_seconds | int null | time-to-live override |
| library_template_name | varchar null | when created from Meta's Template Library |
| paused_until | timestamptz null | |
| last_synced_at | timestamptz | |
| deleted_at | timestamptz null | |

Unique: `(waba_account_id, name, language)`.

### `template_group` (logical multi-language set)
`id, team_id, key, name` + `templates.template_group_id`. Lets a campaign target a group and
resolve the right language per contact.

### `template_events`
Append-only from `message_template_status_update`, `_quality_update`, `_components_update`,
`template_category_update`: `id, team_id, template_id, event, from, to, reason, payload, occurred_at`.

---

## 7. Campaigns (M4)

### `campaigns`
| Column | Type | Notes |
|---|---|---|
| id | uuid pk | |
| team_id / phone_number_id | uuid | |
| name | varchar | |
| template_id or template_group_id | uuid | |
| segment_id | uuid null | null when a static list is uploaded |
| routing | enum | `cloud_api`, `mm_api` (Marketing Messages API) |
| product_policy | varchar null | `CLOUD_API_FALLBACK`, `STRICT` — MM API only |
| status | enum | `draft`, `scheduled`, `queueing`, `sending`, `paused`, `completed`, `cancelled`, `failed` |
| scheduled_for | timestamptz null | |
| timezone_mode | enum | `fixed`, `recipient_local` |
| budget_cap_minor | bigint null | hard stop |
| spent_minor | bigint | running total |
| variant_group_id | uuid null | A/B parent |
| variant_weight | smallint null | |
| stats | jsonb | denormalised counters |
| started_at / completed_at | timestamptz null | |
| deleted_at | timestamptz null | |

### `campaign_recipients`
| Column | Type | Notes |
|---|---|---|
| id | bigint pk | |
| team_id / campaign_id / contact_id | index | |
| message_id | uuid null | |
| wamid | varchar null | |
| status | enum | `pending`, `suppressed`, `queued`, `sent`, `delivered`, `read`, `clicked`, `replied`, `failed` |
| suppression_reason | varchar null | `no_consent`, `per_user_cap`, `blocked`, `invalid_number`, `missing_variable`, `unsupported_language`, `budget`, `duplicate` |
| error_code | int null | |
| cost_minor | bigint null | |
| variables | jsonb | resolved variable values (for audit / reproducibility) |
| queued_at / sent_at / … | timestamptz null | |

Unique: `(campaign_id, contact_id)`. This is the table that guarantees no double-send.

### `campaign_clicks`
Per-contact click tracking for wrapped URL buttons (M4 §7).

| Column | Type | Notes |
|---|---|---|
| id | bigint pk | |
| team_id / campaign_id / contact_id | index | |
| button_index | smallint | which URL button |
| token | varchar unique | the `/c/{token}` value |
| target_url | text | resolved destination |
| clicked_at | timestamptz null | null until first click |
| click_count | int | subsequent clicks increment |
| user_agent / ip_hash | varchar null | |

Unique: `(campaign_id, contact_id, button_index)`. First click also advances
`campaign_recipients.status` to `clicked`.

### `sequences` / `sequence_steps` / `sequence_enrollments`
Drip journeys. Steps: `send_template`, `wait`, `branch`, `tag`, `assign`, `exit_if_replied`,
`exit_if_ai_resolved`, `webhook`.

---

## 8. Meta Business Agent (M5)

### `mba_agents`
| Column | Type | Notes |
|---|---|---|
| id | uuid pk | |
| team_id / phone_number_id | uuid unique | one agent per number |
| eligibility | jsonb | last Eligibility response + checked_at |
| vertical | varchar null | must be one of Meta's 5 approved verticals |
| tos_client_accepted_at | timestamptz null | client accepted in WhatsApp Manager |
| onboarded_at | timestamptz null | |
| enabled | boolean | |
| enabled_at / disabled_at | timestamptz null | |
| settings | jsonb | persona, language, tone, handoff policy, followup policy |
| skills | jsonb | system instructions |
| allowlist_mode | enum | `off`, `allowlist_only` |
| last_synced_at | timestamptz | |

### `mba_allowlist_entries`
`id, team_id, mba_agent_id, wa_id, added_by, added_at, removed_at null`.

### `mba_knowledge_sources`
| Column | Type | Notes |
|---|---|---|
| id | uuid pk | |
| team_id / mba_agent_id | uuid | |
| kind | enum | `business_info`, `faq`, `website`, `file` |
| external_id | varchar null | Meta's id for the source |
| payload | jsonb | our source of truth (question/answer, url, business fields) |
| media_id | uuid null | for `file` |
| url | text null | for `website` |
| recrawl_interval_hours | int null | |
| sync_status | enum | `pending`, `synced`, `failed` |
| last_synced_at / last_error | | |
| version | int | bump to force re-push |

### `mba_connectors` / `mba_connector_tools`
| Column | Type | Notes |
|---|---|---|
| id | uuid pk | |
| team_id / mba_agent_id | uuid | |
| external_id | varchar null | Meta's connector id |
| name / base_url / auth_scheme | | our own connector endpoints, per-team |
| token_id | uuid | → `connector_tokens` |
| enabled | boolean | |

`mba_connector_tools`: `id, connector_id, external_id, name, description, method, path, input_schema jsonb, output_schema jsonb, is_write, enabled`.

### `connector_tokens`
Bearer tokens Meta uses to call **us**. `id, team_id, name, token_hash, prefix, abilities jsonb, last_used_at, expires_at, revoked_at`. Store only a hash.

### `mba_events`
Agent Events we push: `id, team_id, conversation_id, kind, payload jsonb, external_id, status, sent_at, error`.

### `mba_evals`
`id, team_id, mba_agent_id, kind (test|eval), request jsonb, result jsonb, score numeric, run_at, run_by`.

---

## 9. Commerce & payments (M6)

### `catalogs` / `products`
| `products` column | Type | Notes |
|---|---|---|
| id | uuid pk | |
| team_id / catalog_id | uuid | |
| retailer_id | varchar | our SKU — the id used in WhatsApp product messages |
| meta_product_id | varchar null | |
| name / description | | |
| price_minor | bigint | |
| currency | char(3) | `UGX` |
| availability | enum | `in_stock`, `out_of_stock`, `preorder` |
| image_url / url | | |
| attributes | jsonb | |
| sync_status / last_synced_at | | |

Unique: `(team_id, retailer_id)`.

### `orders`
| Column | Type | Notes |
|---|---|---|
| id | uuid pk | |
| team_id / contact_id / conversation_id | uuid index | |
| reference | varchar unique | human-facing order number |
| source | enum | `whatsapp_cart`, `agent`, `mba`, `api` |
| origin_wamid | varchar null | the `order` message that created it |
| items | jsonb | `[{retailer_id, name, qty, unit_price_minor, currency}]` |
| subtotal_minor / shipping_minor / discount_minor / total_minor | bigint | |
| currency | char(3) | |
| status | enum | `draft`, `pending_payment`, `partially_paid`, `paid`, `fulfilling`, `shipped`, `completed`, `cancelled`, `refunded` |
| paid_at / cancelled_at | timestamptz null | |
| notes | text null | |
| meta | jsonb | |

### `payments`
One row per ioTec transaction attempt. **Append-only status via `payment_events`.**

| Column | Type | Notes |
|---|---|---|
| id | uuid pk | |
| team_id / order_id | uuid index | order_id nullable (standalone collection) |
| contact_id | uuid null | |
| direction | enum | `collection`, `disbursement` |
| provider | varchar | `iotec` |
| **external_id** | varchar **unique per team** | **our ULID** sent to ioTec as `externalId`. ioTec does not guarantee uniqueness — we do. |
| provider_id | uuid null unique | ioTec's transaction `id` — authoritative reference |
| vendor_transaction_id | varchar null | MTN/Airtel/PegPay reference |
| category | enum | `MobileMoney`, `Card`, `BankTransfer`, `WalletToWallet` |
| wallet_id | uuid | ioTec wallet used |
| currency | char(3) | `ITX` (sandbox), `UGX`, `USD` |
| amount_minor | bigint | ≥ 500 major units per ioTec rule — validate before send |
| payer | varchar null | MSISDN for MoMo, email for Card |
| payer_name | varchar null | |
| payee | varchar null | disbursements |
| payee_name | varchar null | |
| status | enum | `Pending`, `SentToVendor`, `Success`, `Failed`, `AwaitingApproval`, `RolledBack`, `Scheduled`, `Cancelled`, `Rejected` |
| status_code / status_message | varchar null | from ioTec |
| vendor | varchar null | `Mtn`, `Airtel`, `Visa`, `MasterCard`, `Stanbic`, `Mock`, … |
| payment_channel | varchar null | `Api`, `Portal`, `Link`, `Bulk`, `Woocommerce` |
| transaction_charge_minor / vendor_charge_minor / total_charge_minor | bigint null | |
| card_redirect_url | text null | PegPay hosted form URL |
| redirect_url | text null | where we send the payer after completion |
| requested_at / processed_at / last_polled_at | timestamptz null | |
| raw | jsonb | last full ioTec view model |
| idempotency_key | varchar unique | guards double-submission from our own UI |

Indexes: `(team_id, status)`, `(order_id)`, `(provider_id)`, `(status, last_polled_at)` for the poller.

### `payment_events` — append-only
`id bigint, team_id, payment_id, status, status_code, status_message, source (callback|poll|manual), raw jsonb, occurred_at, received_at`.
A callback and a poll reporting the same status are both recorded; the payment's `status` only
advances through a legal transition (see `modules/m6-commerce-payments.md`).

### `iotec_wallets`
`id, team_id null, iotec_wallet_id uuid, name, currency, actual_balance_minor, available_balance_minor, collection_callback_url, disbursement_callback_url, callback_header_name, callback_header_value (encrypted cast), last_synced_at`.
`team_id` is null for our own platform wallet. The callback header pair is what ioTec sends on
callbacks for this wallet (configured in their portal); null falls back to the values in
`config/iotec.php`.

---

## 10. Ads that Click to WhatsApp (M7)

### `ctwa_referrals`
| Column | Type | Notes |
|---|---|---|
| id | uuid pk | |
| team_id / contact_id / conversation_id | uuid index | |
| message_wamid | varchar | the first inbound message carrying the referral |
| source_id | varchar null | ad id |
| source_type | varchar null | `ad`, `post` |
| source_url | text null | |
| headline / body | text null | |
| media_type | varchar null | `image`, `video` |
| image_url / video_url / thumbnail_url | text null | |
| ctwa_clid | varchar null | click id for Conversions API attribution |
| welcome_message | jsonb null | |
| occurred_at | timestamptz | |

### `conversions`
What we report back to Meta. `id, team_id, contact_id, order_id null, event_name (Purchase|Lead|AddToCart|InitiateCheckout), value_minor, currency, ctwa_clid, event_time, dedup_key unique, status (pending|sent|failed), response jsonb, sent_at`.

---

## 11. Analytics, billing & health (M8)

### `usage_meters` — append-only, the billing source of truth
| Column | Type | Notes |
|---|---|---|
| id | bigint pk | |
| team_id | uuid index | |
| waba_account_id / phone_number_id | uuid null | |
| meter | enum | `template_message`, `service_message`, `mba_tokens`, `platform_seat`, `payment_fee` |
| category | varchar null | `marketing`, `utility`, `authentication`, `service` |
| country | char(2) null | rate varies by recipient country |
| quantity | bigint | messages, or tokens |
| unit_cost_micros | bigint null | Meta's cost per unit ×10⁶ |
| cost_minor | bigint | our computed cost |
| markup_minor | bigint | our margin |
| currency | char(3) | |
| source | enum | `webhook`, `pricing_analytics`, `mba_analytics`, `computed` |
| basis | enum | `estimate`, `actual`, `correction` — MBA token rows start as `estimate`; when real data arrives, append a `correction` row reversing the estimate plus an `actual` row. **Rows are never re-marked in place** (D-012). |
| occurred_on | date index | team WABA timezone day |
| message_id / campaign_id | uuid null | traceability |
| created_at | | |

Index: `(team_id, occurred_on, meter)`.

### `rate_cards`
Versioned Meta rate card (M8 §2). Rates change only on quarter boundaries.

| Column | Type | Notes |
|---|---|---|
| id | uuid pk | |
| effective_from | date | |
| region | varchar | country code or Meta market grouping |
| category | varchar | `marketing`, `utility`, `authentication`, `service`, `mba_tokens` |
| tier_min / tier_max | bigint null | volume tier bounds (utility/authentication); null = untiered |
| unit_cost_micros | bigint | USD ×10⁶ per message (or per token for `mba_tokens`) |
| source_url | text | where the rate was published |
| created_at | | |

Unique: `(effective_from, region, category, tier_min)`. Global, not team-scoped. Cost resolution
picks the row effective at `usage_meters.occurred_on` — never retroactively repriced.

### `wallet_entries` — team billing ledger, append-only
`id bigint, team_id, kind (topup|charge|adjustment|refund), amount_minor (signed), currency, balance_after_minor, reference_type, reference_id, description, created_at`.
Balance is always `SUM(amount_minor)`; `balance_after_minor` is a cached checkpoint, never trusted alone.

### `analytics_snapshots`
Cached pulls from Meta so dashboards do not hammer the Graph API.
`id, team_id, waba_account_id, field (analytics|conversation_analytics|pricing_analytics|template_analytics|template_group_analytics), granularity, start_at, end_at, dimensions jsonb, dimensions_hash char(64) (sha256 of the canonicalised dimensions json — computed in the model, stored, indexed), payload jsonb, fetched_at`.
Unique on `(waba_account_id, field, granularity, start_at, end_at, dimensions_hash)`.

> Lookback limits: messaging / conversation / pricing analytics = **1 year**.
> Template and template-group analytics = **90 days**. Snapshot aggressively for anything older.

### `health_events`
`id bigint, team_id, phone_number_id null, kind, severity (info|warning|critical), payload jsonb, occurred_at, acknowledged_at null, acknowledged_by null`.
Fed by `phone_number_quality_update`, `account_alerts`, `account_review_update`,
`business_capability_update`, `template_*_update`, and our own rate-limit watchdog.

---

## Table count summary

| Group | Tables |
|---|---|
| Teams & identity | 5 — teams, users, team_user, audit_logs, admin_sessions |
| Meta assets | 4 — waba_accounts, phone_numbers, meta_credentials, onboarding_sessions |
| Webhook ingest | 1 — webhook_deliveries |
| Contacts & consent | 10 — contacts, contact_identifiers, consents, consent_states, labels, contact_label, conversation_label, notes, segments, segment_members |
| Conversations & messages | 6 — conversations, messages, message_events, media, thread_control_events, canned_replies |
| Templates | 3 — templates, template_group, template_events |
| Campaigns | 6 — campaigns, campaign_recipients, campaign_clicks, sequences, sequence_steps, sequence_enrollments |
| Meta Business Agent | 8 — mba_agents, mba_allowlist_entries, mba_knowledge_sources, mba_connectors, mba_connector_tools, connector_tokens, mba_events, mba_evals |
| Commerce & payments | 6 — catalogs, products, orders, payments, payment_events, iotec_wallets |
| CTWA | 2 — ctwa_referrals, conversions |
| Analytics & billing | 5 — usage_meters, wallet_entries, analytics_snapshots, health_events, rate_cards |
| **Total** | **56** |

## Migration ordering

1. Teams & identity → 2. Meta assets → 3. Webhook ingest → 4. Contacts & consent →
5. Conversations & messages → 6. Templates → 7. Campaigns → 8. MBA → 9. Commerce →
10. CTWA → 11. Analytics.

Each group is one migration batch so a phase can be shipped independently.
