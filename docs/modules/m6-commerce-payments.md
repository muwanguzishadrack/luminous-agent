# M6 — Commerce & Payments (ioTec Pay)

**Goal:** a customer browses, orders, and pays by MTN or Airtel money inside one WhatsApp
conversation, and the payment reconciles automatically.

API facts: [`../reference/iotec-pay.md`](../reference/iotec-pay.md).
Tables: `catalogs`, `products`, `orders`, `payments`, `payment_events`, `iotec_wallets`.

---

## 1. Why the payment-link pattern

Native in-WhatsApp payments exist **only in India (UPI) and Brazil (Pix)**. Uganda has neither.
So the flow is:

```
in-thread product discovery  →  order  →  ioTec collection request  →  MTN/Airtel PIN prompt
                                                                        on the customer's phone
                                              └─ or card → PegPay hosted page (needs an email)
```

The customer never leaves WhatsApp for mobile money — the telco prompt arrives on their handset. That
is a better experience than a card checkout and should be the default offer.

---

## 2. Catalog

| Step | Detail |
|---|---|
| Source | Shopify / WooCommerce / CSV / our own product editor |
| Sync target | Meta catalog via Commerce Manager + Commerce Settings API |
| Key | `products.retailer_id` — the SKU used in WhatsApp product messages |
| Double duty | The same catalog grounds Meta Business Agent's product knowledge (M5) |
| Commerce settings | Cart visibility and catalog visibility per phone number |

**Catalog quality now drives AI answer quality.** A product with a missing description makes the agent
vague. Surface a completeness score per product.

### Product messages

Single-product (SPM), multi-product (MPM), product carousel, catalog message, catalog-link message —
sendable from the composer via a product picker, and by the agent via the catalog.

---

## 3. Orders

Created three ways:

| Source | Trigger |
|---|---|
| `whatsapp_cart` | `order` message webhook — customer submitted a cart. `origin_wamid` recorded. |
| `agent` | An agent builds it in the inbox side panel |
| `mba` | The `create_order` write connector (M5 §4.2) |

Lifecycle:

```
draft → pending_payment → partially_paid → paid → fulfilling → shipped → completed
                    └────────────────────────────────▶ cancelled | refunded
```

Order lifecycle templates: `order_details` (with the payment ask) then `order_status` for each
transition. Both are Utility templates — free inside the CSW **until Oct 1, 2026**, which is worth
flagging to the client in the M8 projector.

---

## 4. Payment provider abstraction

Even though ioTec is the only provider today, the boundary is worth having: it isolates the
`double`-amount conversion, the 300-second token, and the poller from the domain.

```php
interface PaymentProvider
{
    public function collect(CollectionIntent $intent): PaymentAttempt;
    public function collectByCard(CardCollectionIntent $intent): PaymentAttempt; // returns redirect URL
    public function disburse(DisbursementIntent $intent): PaymentAttempt;
    public function status(string $providerId): PaymentSnapshot;
    public function statusByExternalId(string $externalId): PaymentSnapshot;
    public function walletBalance(string $walletId): WalletBalance;
    public function banks(): array;
}
```

`IotecPaymentProvider` is the only implementation. All money crossing this boundary is
`Money` (integer minor units) — the `double` from ioTec is converted inside the client and never
escapes.

---

## 5. Collection flow (mobile money)

```
1. Validate:  amount ≥ 500 (ioTec minimum)  ·  MSISDN normalised (256… or 0…)
              wallet exists for the currency  ·  consent/CSW not required (this is transactional)
2. Create payments row:
     external_id   = ULID            ← OUR uniqueness guarantee (ioTec does not enforce it)
     idempotency_key = hash(order_id + amount + payer + minute-bucket)
     status        = Pending
3. POST /api/collections/collect
     { category, currency, walletId, externalId, payer, payerName,
       amount, payerNote, payeeNote, redirectUrl? }
4. Persist provider_id (ioTec uuid) — the AUTHORITATIVE reference
5. Tell the customer in-thread: "Check your phone for the MTN prompt"
6. Schedule PollPaymentStatus with backoff
7. Await callback and/or poll → advance status
8. On Success: mark order paid → Agent Event `payment_confirmed` (M5) →
   order_status template → update contact LTV
```

### Card collection differences

| Difference | Detail |
|---|---|
| Endpoint | `POST /api/collections/collect/card` |
| `payer` | **the customer's email address**, not a phone number |
| `payerName` | populates the PegPay form |
| Response | `cardRedirectUrl` — the hosted page the customer must open |
| UX | Send the URL as a CTA button; the UI must collect an email **before** offering card |

---

## 6. Status handling — the part that must be exactly right

### Legal transitions only

```
Pending ──▶ SentToVendor ──▶ Success
   │              │
   │              └────────▶ Failed
   ├──▶ Failed
   ├──▶ Cancelled
AwaitingApproval ──▶ Success | Rejected        (disbursement maker–checker)
Scheduled ──▶ Pending                          (sendAt reached)
Success ──▶ RolledBack                         (provider reversal)
```

Anything else — notably any move *out of* `Success` other than `RolledBack`, or `Success → Pending` —
is rejected and recorded in `payment_events` as an anomaly with an alert.

### `SentToVendor` is not success

It means MTN/Airtel has the request. **Never fulfil an order on `SentToVendor`.** This is the single
most likely money bug in the system.

### Callbacks

ioTec fires callbacks for **`Success`, `Failed`, `SentToVendor` only**, to a URL configured
**per wallet in the ioTec portal**, authenticated with a **static header**.

```
POST /webhooks/iotec/collection      (and /disbursement)
 1. Constant-time compare the configured security header      → 401
 2. Persist to webhook_deliveries (source='iotec')
 3. 200 immediately
 4. Queue: RE-FETCH status from the API for provider_id       ← MANDATORY
 5. Trust the API response, not the callback body
 6. Append payment_events; advance payments.status if legal
 7. On Success → CreditOrder (idempotent, guarded by a lock on payment_id)
```

Step 4 is not optional. A static shared header is a weak authenticator; re-fetching means a leaked or
guessed header cannot fabricate a payment. See `05-security-multitenancy.md` §3.

### The poller is mandatory, not a fallback

Statuses that **never** arrive by callback: `Pending`, `AwaitingApproval`, `Scheduled`, `RolledBack`,
`Cancelled`, `Rejected`. Without a poller, a scheduled disbursement or an awaiting-approval payment
would sit unresolved forever.

Backoff: **10s, 30s, 1m, 5m, 15m, then hourly to a 24-hour cap**, then mark `stale` and raise a
reconciliation task. Query by `provider_id`; fall back to `external-id/{externalId}` if the create
response was lost mid-flight.

### Fees

`transactionCharge` (ioTec) + `vendorCharge` (telco/PSP) = `totalTransactionCharge`. Persist all
three and emit a `payment_fee` usage meter entry. These are real costs and belong in the tenant P&L
(M8), not discarded.

---

## 7. Disbursements

Used for refunds, supplier payouts, and agent commissions.

| Endpoint | Use |
|---|---|
| `POST /api/disbursements/disburse` | Mobile money (or wallet-to-wallet) |
| `POST /api/disbursements/bank-disburse` | Bank: `InternalTransfer` / `Rtgs` / `Eft` / `Swift` |
| `GET /api/disbursements/bank-list` | Populate the bank picker; cache 24h |
| `GET /api/wallet-balance/{walletId}` | **Check before disbursing** — fail fast with a clear message |

Two states the UI must render distinctly:

- **`AwaitingApproval`** — maker–checker. Money has **not** moved and will not until someone approves
  in the ioTec portal. Never present this as sent.
- **`Rejected`** — a human declined it. Terminal, and different from `Failed` (a technical failure).
  `decisionMadeBy`, `decisionMadeAt`, `decisionRemarks` explain it; show them.

`payeeNameStatus` (`Matched`, `NotMatched`, `NotFound`, `Barred`, …) is a fraud signal: warn loudly
before completing a disbursement where the name does not match the account.

`sendAt` enables scheduled disbursements → status `Scheduled` until the time arrives.

---

## 8. Reconciliation

A daily job that proves our books match ioTec's:

1. `GET /api/disbursements/paged-history` for the window; diff against our `payments`.
2. Re-fetch any local payment not in a terminal state.
3. Compare `iotec_wallets` balance against our computed ledger position.
4. Report: matched, missing-locally, missing-remotely, amount mismatches, fee mismatches.

Any discrepancy raises a task, never an automatic correction. Money is never silently adjusted.

---

## 9. In-conversation UX

| Step | Message |
|---|---|
| Order summary | `order_details` template with items and total |
| Payment ask | Reply buttons: **MTN/Airtel Money** · **Card** · **Pay later** |
| MoMo chosen | "Enter your PIN on the prompt we just sent to 07XX…" + a live status chip in the agent's view |
| Card chosen | Collect email → CTA button to `cardRedirectUrl` |
| Pending too long | Follow-up after 2 minutes: "Still waiting — resend the prompt?" |
| Success | `order_status` template + receipt; Agent Event to MBA |
| Failed | Plain-language reason + retry button with a fresh `external_id` |

Retries always create a **new** `payments` row with a new `external_id`. Never re-submit an existing
one.

---

## 10. UI surface

| Route | Screen |
|---|---|
| `/commerce/products` | Catalog with sync status and completeness score |
| `/commerce/orders` | Orders with payment status, filterable |
| `/commerce/orders/{id}` | Line items, payment attempts timeline, linked conversation, actions |
| `/commerce/payments` | All payments, status, fees, ioTec references, retry/poll actions |
| `/commerce/disbursements` | Payouts incl. `AwaitingApproval` queue and bank details |
| `/commerce/reconciliation` | Daily report and open discrepancies |
| `/settings/payments` | Wallets, currency mapping, callback URL health check, sandbox toggle |

The settings screen should **verify the callback configuration** by reading
`collectionCallBackUrl` / `disbursementCallBackUrl` / `callBackSecurityHeaderKey` from
`GET /api/wallet-balance/{walletId}` and comparing them with our expected values — this catches the
most common integration failure (a stale tunnel hostname in the ioTec portal).

---

## 11. Edge cases

| Case | Handling |
|---|---|
| Customer submits payment twice | `idempotency_key` on the minute bucket returns the existing attempt |
| Callback arrives before we persisted `provider_id` | Look up by `externalId` endpoint; if still unknown, park and retry |
| Duplicate callback for a `Success` payment | Recorded in `payment_events`, no re-credit (lock on `payment_id`) |
| `Failed` arrives after `SentToVendor` | Legal; advance to `Failed` |
| Amount below 500 | Rejected client- and server-side with a clear message before any API call |
| Currency mismatch to wallet | Refuse; wallets are per-currency |
| Card flow with no email | Card option hidden until an email is captured |
| Payment succeeds, order already cancelled | Flag for refund; do not auto-refund |
| Wallet empty on disbursement | Pre-flight balance check blocks it |
| `payeeNameStatus = NotMatched` | Require explicit confirmation |
| ioTec token expired mid-batch | Single-flight refresh, retry once |
| Tunnel hostname changed | Settings health check surfaces it; ioTec portal must be updated manually |
| Sandbox vs production | `ITX` currency + sandbox wallet + the four test MSISDN patterns |

---

## 12. Acceptance criteria

1. A sandbox collection with `0111777771` reaches `Success`, marks the order paid, and fires the Agent
   Event — exactly once, verified under a duplicated callback.
2. `0111777791` (`SentToVendor`) does **not** mark the order paid.
3. `0111777781` (`Pending`) is resolved by the poller, not by a callback.
4. `0111777991` (`Failed`) shows a plain-language reason and a retry that creates a new `external_id`.
5. A callback with a wrong security header is rejected with 401 and nothing is persisted to `payments`.
6. A callback body claiming `Success` while the API says `Pending` does **not** credit the order.
7. `Success → Pending` is rejected and logged as an anomaly.
8. Amounts round-trip UGX major↔minor with zero loss across 10,000 generated cases.
9. Fees from ioTec appear in `usage_meters` as `payment_fee`.
10. The daily reconciliation report is clean against a seeded set of 200 payments.
11. A disbursement in `AwaitingApproval` is never presented as sent.
