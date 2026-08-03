# Reference — Pricing, Limits & Key Dates

Verified 2026-08-01. This file is the single source for every number the billing and rate-limiting
code depends on. Mirror it in `config/limits.php` and `config/pricing.php`.

---

## 1. Pricing model

Since **July 1, 2025** Meta charges **per message**, not per conversation
(conversation-based pricing is deprecated).

| Message type | Sender | Meter | Charged for AI | Charged for delivery | Free in 72h FEP | Free in 24h CSW |
|---|---|---|---|---|---|---|
| Template — Marketing | Business | per message | n/a | Yes (Meta) | Yes | **No** |
| Template — Utility | Business | per message | n/a | Yes (Meta) | Yes | **Free until Oct 1, 2026** |
| Template — Authentication | Business | per message | n/a | Yes (Meta) | Yes | **No** |
| Non-template — Service (human or 3rd-party AI) | Business | per message | By the AI provider, if any | Yes (Meta) — **from Oct 1, 2026** | Yes | **Free until Oct 1, 2026** |
| Non-template — **Meta Business Agent** | MBA | **per token** | **Yes, by Meta as one combined charge** | included | **No** | **No** |

### Rates

| Item | Rate |
|---|---|
| **Meta Business Agent** | **$2.00 USD / 1M tokens**, one global rate. ~20–25k tokens per message ⇒ **~4–5¢/message** |
| Service messages | Same market rates as utility/authentication. **No volume tiers.** |
| Marketing / Utility / Authentication templates | Per-market rate card, with volume tiers for utility and authentication |
| Rate card effective | April 1, 2026 (based on WABA timezone) |

### Rules that catch people out

1. **One category, one charge, per message.** A non-template message is charged either as MBA **or**
   as service, never both. Promotional content in a non-template message does not additionally incur
   a marketing charge.
2. **`SentToVendor`-style intermediate states do not exist for messages** — the billing signal is
   `pricing.category` on the `statuses` webhook.
3. **The 72h FEP window covers delivery only.** MBA token charges still apply inside it.
4. **Template category can change under you.** `template_category_update` means the price of every
   future send using that template just changed. Surface it loudly.

---

## 2. Key dates

| Date | Event | Our required action |
|---|---|---|
| **Aug 1, 2026** | MBA charging goes live (per token) | Token meter in M8; label as estimate until Meta's MBA analytics ship |
| **Sept 1, 2026** | Meta publishes Oct 1 rates, incl. service messages | Update rate card; re-run the impact projector |
| **Oct 1, 2026** | All non-template messages chargeable; utility-in-CSW no longer free | Cost projector shipped **before** this date; tenant comms prepared |
| **Oct 15, 2026** | **Embedded Signup v2 removed** | We build on v4 only — no action if we never use v2 |
| **Dec 1, 2025** (past) | Analytics lookback cut from 10 years to 1 year | Daily snapshots into `analytics_snapshots` |
| Quarterly | Jan 1 / Apr 1 / Jul 1 / Oct 1 — the only days Meta may change rates | Scheduled rate-card review task |

Notice periods Meta commits to: rate-card update ≥ 1 month; pricing model add-on ≥ 3 months;
pricing model change ≥ 6 months.

---

## 3. Rate limits

| Limit | Value | Error |
|---|---|---|
| **Message-send throughput, per number** | **80 msgs/sec default** (`STANDARD`); **1,000 mps** by automatic upgrade (`HIGH`); **20 mps fixed on Coexistence numbers**. Counts inbound + outbound, all types. **This is the number that governs campaign send rate.** | **`130429`** |
| Business Management endpoints, inactive WABA | 200 req/hr per app per WABA | `80007` |
| Business Management endpoints, **active** WABA (≥1 registered number) | **5,000 req/hr per app per WABA**. Covers `message_templates`, `phone_numbers`, `subscribed_apps`, WABA reads — **management calls only, never a gate on message sends** | `80007` |
| Credit Line API | 5,000 req/hr | |
| **Per-recipient pair limit** | **1 message / 6 seconds** to the same user (~10/min, 600/hr) | **`131056`** |
| Pair burst | up to **45 messages in 6 seconds to the same user**, borrowed from future quota; then wait the equivalent time. Per-pair, **not** a global campaign envelope. |  |
| Post-burst retry | `4^X` seconds, X from 0, incrementing per failure | |
| MBA Connector (ours) | our own limit — default 120 rpm per token | |
| ioTec | not documented; assume conservative and add our own limiter | |

### Messaging limits (unique customers messaged outside the CSW per rolling 24h)

**Portfolio-based since late 2025** (per-number `TIER_*` values are legacy): the business
portfolio's limit is `2000` → `10000` → `100000` → `UNLIMITED`, driven by quality rating and
business verification. Read via the `whatsapp_business_manager_messaging_limit` field (on the
portfolio, WABA, or phone number); changes arrive via `business_capability_update`
(`max_daily_conversations_per_business`) / `phone_number_quality_update`.

### Throughput

Per business phone number: `STANDARD` = 80 mps, `HIGH` = up to 1,000 mps (automatic, free upgrade
when the portfolio has an unlimited messaging limit, the number messages ≥100K unique users/24h,
and quality is YELLOW+). Coexistence numbers: fixed 20 mps. Read from
`GET /{phone-number-id}?fields=throughput`. Exceeding it returns `130429`; during the ~1-minute
upgrade window the number returns `131057`. Webhook servers must handle ~3× outbound send rate in
status webhooks plus expected inbound.

### Other send-side caps

| Cap | Meaning |
|---|---|
| Per-user marketing limits | Meta silently drops over-frequent marketing to a given user. Pre-suppress instead of paying for a drop. |
| Template pacing | New/low-quality templates are throttled while Meta samples engagement |
| Portfolio pacing | Account-wide throttle on marketing volume |

---

## 4. Analytics lookback

| Field | Lookback |
|---|---|
| `analytics` | 1 year |
| `conversation_analytics` | 1 year |
| `pricing_analytics` | 1 year |
| `template_analytics` | 90 days |
| `template_group_analytics` | 90 days |

⇒ We must snapshot daily. Anything older than a year exists only in our own database.

---

## 5. ioTec Pay limits

| Item | Value |
|---|---|
| Access token TTL | **300 seconds** — cache 240s with single-flight |
| Minimum transaction amount | **500** (major units) |
| Currencies | `ITX` (sandbox), `UGX`, `USD` |
| Callback statuses | `Success`, `Failed`, `SentToVendor` **only** — everything else needs polling |
| Callback auth | Static per-wallet security header (weak) → always re-fetch status before crediting |

Recommended poller backoff for non-callback statuses: 10s, 30s, 1m, 5m, 15m, then hourly to a 24h cap.

---

## 6. Config mapping

```php
// config/limits.php
return [
    'graph' => [
        'waba_requests_per_hour'        => 5_000,
        'waba_requests_per_hour_idle'   => 200,
    ],
    'send' => [
        'throughput_mps_standard'       => 80,
        'throughput_mps_high'           => 1_000,
        'throughput_mps_coexistence'    => 20,
        'per_recipient_seconds'         => 6,
        'pair_burst_messages'           => 45,  // per recipient pair, not global
        'pair_burst_window_seconds'     => 6,
        'retry_base'                    => 4,   // 4^X seconds
    ],
    'connector' => ['requests_per_minute' => 120],
    'iotec'     => ['token_ttl' => 240, 'min_amount_major' => 500],
];
```

```php
// config/pricing.php
return [
    'mba' => [
        'usd_per_million_tokens'    => 2.00,
        'est_tokens_per_message'    => 22_500,   // midpoint of 20k–25k
    ],
    'dates' => [
        'mba_charging_from'         => '2026-08-01',
        'all_non_template_charged'  => '2026-10-01',
        'es_v2_removed'             => '2026-10-15',
    ],
];
```

Every one of these values must be referenced from config, never inlined in a class.

---

## 7. Sources

- <https://developers.facebook.com/documentation/business-messaging/whatsapp/pricing>
- <https://developers.facebook.com/documentation/business-messaging/whatsapp/pricing/non-template-messages>
- <https://developers.facebook.com/documentation/business-messaging/whatsapp/throughput>
- <https://developers.facebook.com/documentation/business-messaging/whatsapp/upcoming-messaging-limits-changes/>
- <https://developers.facebook.com/documentation/business-messaging/whatsapp/about-the-platform>
- <https://developers.facebook.com/documentation/business-messaging/whatsapp/analytics>
- <https://pay.iotec.io/swagger/v1/swagger.json>
