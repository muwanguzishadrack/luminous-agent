# Reference — ioTec Pay

**Verified 2026-08-01 against the live OpenAPI document.**

| | |
|---|---|
| Spec | `https://pay.iotec.io/swagger/v1/swagger.json` (OpenAPI 3.0.4, title "ioTec Pay v1") |
| Human docs | <https://iotec.io/api-docs/pay> · <https://iotec.io/docs/pay> |
| Swagger UI | <https://pay.iotec.io/swagger/index.html> |
| API base URL | `https://pay.iotec.io` |
| Auth server | `https://id.iotec.io/connect/token` |
| Support | support@iotec.io |

> The spec declares no `servers` block and no `securitySchemes`. Both are documented in prose in the
> spec description; the values above are taken from there.

---

## 1. Authentication — OAuth 2.0 client credentials

Credentials (`client_id`, `client_secret`) are emailed to the address used at ioTec Pay registration.

```bash
curl --request POST \
  --url https://id.iotec.io/connect/token \
  --header 'Content-Type: application/x-www-form-urlencoded' \
  --data client_id=[client_id] \
  --data client_secret=[client_secret] \
  --data grant_type=client_credentials
```

```json
{
  "access_token": "xxxx.xxxxxxxxxxxxx.xxxxxxxxxxxxxxxx",
  "expires_in": 300,
  "token_type": "Bearer",
  "scope": "profile email"
}
```

Then `Authorization: Bearer <access_token>` on every request.

### ⚠️ `expires_in` is **300 seconds**

This is a 5-minute token. Implementation requirements:

1. Cache in Redis with a TTL of **240s** (60s safety margin), key `iotec:token`.
2. Wrap acquisition in a **single-flight lock** (`Cache::lock('iotec:token:refresh', 10)`) so a burst
   of 200 campaign jobs does not trigger 200 token requests.
3. On any `401`, invalidate the cache and retry **once**.
4. Never persist the access token to Postgres — Redis only.

---

## 2. Endpoints

| Method | Path | Purpose |
|---|---|---|
| POST | `/api/collections/collect` | Initiate mobile money collection |
| POST | `/api/collections/collect/card` | Initiate card collection (PegPay hosted form) |
| GET | `/api/collections/status/{requestId}` | Collection status by ioTec id |
| GET | `/api/collections/external-id/{externalId}` | Collection status by our reference |
| POST | `/api/disbursements/disburse` | Disburse to mobile money |
| POST | `/api/disbursements/bank-disburse` | Disburse to a bank account |
| GET | `/api/disbursements/status/{transactionId}` | Disbursement status |
| GET | `/api/disbursements/external-id/{externalId}` | Disbursement status by our reference |
| GET | `/api/disbursements/bank-list` | Supported banks |
| GET | `/api/disbursements/paged-history` | Paged disbursement history |
| GET | `/api/wallet-balance/{walletId}` | Wallet actual + available balance |

---

## 3. Enums (exact wire values)

```
Currency                 ITX | UGX | USD
                         ITX = sandbox/test currency. UGX = production Uganda. 

PaymentCategory          MobileMoney | WalletToWallet | BankTransfer

RequestStatus            Pending | SentToVendor | Success | Failed |
                         AwaitingApproval | RolledBack | Scheduled | Cancelled | Rejected

PaymentChannel           Api | Portal | Link | Bulk | Woocommerce

Vendor                   Mock | Mtn | Airtel | Internal | Stanbic |
                         MtnMerchant | AirtelMerchant | Visa | MasterCard

BankTransferType         InternalTransfer | Rtgs | Eft | Swift

PayeeNameStatus          Pending | Fetched | Failed | Matched | NotMatched | NotFound | Barred
```

Mirror each of these as a PHP backed enum with `tryFromWire()` returning `null` + a log line on an
unknown value.

---

## 4. Collections

### `POST /api/collections/collect` — mobile money

| Field | Type | Req | Notes |
|---|---|---|---|
| `category` | string | | Transaction category |
| `currency` | Currency | | `UGX` in production, `ITX` in sandbox |
| `walletId` | uuid | | Our ioTec wallet to credit |
| `externalId` | string\|null | | Our reference. **ioTec explicitly says it need not be unique — we enforce uniqueness ourselves.** |
| `payer` | string | ✅ | MSISDN (`256XXXXXXXXX` or `0XXXXXXXXX`) |
| `payerName` | string\|null | | May be overwritten from name verification |
| `payerNote` | string\|null | | Shown to the payer |
| `payeeNote` | string\|null | | Our own reference note |
| `amount` | double | ✅ | **Minimum 500** |
| `channel` | string\|null | | Source channel |
| `transactionChargesCategory` | enum | | Who bears the fee |
| `redirectUrl` | uri\|null | | https, post-payment redirect |

### `POST /api/collections/collect/card` — card via PegPay

Same shape, except:
- `category` is fixed to `Card`
- **`payer` is the customer's *email address*, not a phone number**
- `payerName` populates the PegPay form
- the response carries `cardRedirectUrl` — the hosted page the customer must be sent to

### Response — `CollectionViewModel`

```
id                      uuid    ← AUTHORITATIVE transaction reference
createdAt               datetime
category                PaymentCategory
status                  RequestStatus
paymentChannel          PaymentChannel
statusCode              string  machine-friendly
statusMessage           string  human detail
externalId              string  our reference (echoed)
amount                  double
currency                Currency
payer / payerName       string
payerNote / payeeNote   string
wallet                  ComboModel
vendor                  Vendor
vendorTransactionId     string  MTN/Airtel/PegPay reference
transactionCharge       double  ioTec's fee
vendorCharge            double  telco/PSP fee
totalTransactionCharge  double  sum of both
chargeModel             string
createdBy/createdByData
lastUpdated             datetime
processedAt             datetime
transactions            array
cardRedirectUrl         string  card flows only
redirectUrl             string
```

---

## 5. Disbursements

### `POST /api/disbursements/disburse`

| Field | Type | Req | Notes |
|---|---|---|---|
| `category` | PaymentCategory | | `MobileMoney`, `BankTransfer`, `WalletToWallet` |
| `currency` | Currency | | |
| `walletId` | uuid | | Wallet to debit |
| `externalId` | string\|null | | our reference |
| `payee` | string | ✅ | valid MSISDN |
| `payeeName` | string\|null | | |
| `payeeEmail` | string\|null | | recipient notification |
| `amount` | double | ✅ | **minimum 500** |
| `payerNote` / `payeeNote` | string\|null | | |
| `bankId` \| `bankIdentificationCode` | uuid \| string | | one or the other; SWIFT code |
| `bankTransferType` | BankTransferType | | |
| `sendAt` | datetime\|null | | omit for immediate; set for scheduled → status `Scheduled` |

### `POST /api/disbursements/bank-disburse`

| Field | Req | Notes |
|---|---|---|
| `walletId` | ✅ | |
| `accountName` | ✅ | exactly as on the recipient's bank account |
| `accountNumber` | ✅ | |
| `amount` | ✅ | min 500 |
| `bankId` \| `bankIdentificationCode` | | |
| `transferType` | | `InternalTransfer` \| `Rtgs` \| `Eft` \| `Swift` |
| `currency`, `externalId`, `payeeNote`, `channel`, `sendAt` | | |

### `DisbursementViewModel` extras beyond the collection model

```
payee, payeeName, payeeUploadName, payeeNameStatus
bulkId                 parent bulk transaction, when part of a batch
internalRequestId      our-side id when the vendor cannot handle a GUID
bankId, bank, bankTransferType
approvalDecision, decisionMadeBy, decisionMadeAt, decisionRemarks, decisions[]
sendAt
```

> **Maker–checker:** disbursements can enter `AwaitingApproval` and require a human decision in the
> ioTec portal. Our UI must render this state distinctly — money has *not* moved and will not until
> approved. `Rejected` is a terminal state distinct from `Failed`.

---

## 6. Callbacks

ioTec POSTs to a callback URL **when status becomes `Success`, `Failed`, or `SentToVendor`**. The
payload is *identical to the Get-transaction-status response*.

### Configuration is per-wallet, in the portal — not per request

1. Log in to <https://pay.iotec.io>, open the wallet's details page.
2. **Settings tab → Callback URLs card → Add.**
3. Provide:
   - **Callback Category**: `Collection` or `Disbursement` (separate URLs)
   - **Callback URL**: e.g. `https://dev.<your-domain>/webhooks/iotec/collection`
   - **Security Headers**: header name + value ioTec will send to us

Consequences for us:

| Consequence | Implication |
|---|---|
| The URL is not per-request | Our public hostname must be **stable** → named Cloudflare tunnel (`03-local-development.md`) |
| Two categories = two URLs | Separate routes: `/webhooks/iotec/collection` and `/webhooks/iotec/disbursement` |
| Auth is a **static shared header**, not an HMAC | Weaker than Meta's signature. **Mandatory mitigation: re-fetch status from the API before crediting anything.** See `05-security-multitenancy.md` §3. |
| Only 3 statuses fire | `Pending`, `AwaitingApproval`, `Scheduled`, `RolledBack`, `Cancelled`, `Rejected` never arrive by callback → **a reconciliation poller is required**, not optional |

`WalletBalance` exposes the configured values, useful for a settings health check:
`collectionCallBackUrl`, `disbursementCallBackUrl`, `callBackSecurityHeaderKey`,
`callBackSecurityHeaderValue`.

---

## 7. Sandbox test numbers

From ioTec's own documentation:

| MSISDN pattern | Resulting status | Example |
|---|---|---|
| `011177777(0-9)` | `Success` | `0111777771` |
| `011177799(0-9)` | `Failed` | `0111777991` |
| `011177778(0-9)` | `Pending` | `0111777781` |
| `011177779(0-9)` | `SentToVendor` | `0111777791` |

Use currency `ITX` with the sandbox wallet. `FakeIotecClient` implements exactly these behaviours.

---

## 8. Errors

`ApiErrorResponse`: `{ "message": string|null, "code": string|null }`.
Also `UnauthorizedResult` and `NotFoundResult` schemas. The spec does not enumerate business error
codes — capture every `code` we observe into `docs/reference/iotec-error-codes.md` as we meet them.

---

## 9. Implementation notes

| # | Note |
|---|---|
| 1 | **`externalId` uniqueness is ours to enforce.** Generate a ULID, unique index locally, and treat ioTec's `id` (uuid) as the authoritative reference for all reconciliation. |
| 2 | **Minimum amount 500.** Validate before the call; a rejection wastes a round trip and confuses the customer mid-conversation. |
| 3 | **Amounts are `double` on the wire.** Convert to integer minor units immediately at the client boundary. Never let a float reach the domain. |
| 4 | **Fees are returned per transaction** (`transactionCharge`, `vendorCharge`, `totalTransactionCharge`). Persist them — they are real cost and belong in the `payment_fee` usage meter. |
| 5 | **`SentToVendor` is not success.** It means MTN/Airtel has the request. Never fulfil an order on `SentToVendor`. |
| 6 | **Poller is mandatory.** Statuses that never fire a callback require polling `status/{id}` with backoff (10s, 30s, 1m, 5m, 15m, then hourly to a 24h cap). |
| 7 | **Card flow needs an email, not a phone.** The commerce UX must collect an email address before offering the card option. |
| 8 | **Legal status transitions only.** `Pending → SentToVendor → Success|Failed`; `AwaitingApproval → Success|Rejected`; `Scheduled → Pending`. Reject any callback or poll implying a backwards move, and record it in `payment_events` as an anomaly. |
| 9 | **Wallet balance check before disbursement** to fail fast with a clear message rather than an opaque provider error. |
| 10 | **Contract test:** check in a copy of `swagger/v1/swagger.json` and diff it nightly in CI. |
