# 06 — Testing Strategy

Pest 4. **No test touches the network.** Every external API has a fake bound in the container.

## Layers

| Layer | Tool | What it proves | Target count |
|---|---|---|---|
| Unit | Pest | Pure logic: money maths, CSW/FEP calculation, rate-limiter maths, segment AST → SQL, variable interpolation, state-machine transitions | high |
| Feature | Pest + Laravel | An Action does the right thing end-to-end against a real DB | highest |
| Contract | Pest + recorded fixtures | Our client parses real Meta/ioTec payloads correctly | one per endpoint & webhook field |
| Isolation | Pest | Tenant boundaries hold | one per tenant-scoped model |
| Browser | Pest 4 browser tests | Inbox flows work in a real browser | the critical paths only |
| Load | k6 or artillery | Webhook ingest survives burst | before each phase exit |

## The tests that must exist before anything ships

### 1. Webhook ingest never loses a message

```
it('is idempotent for a duplicate delivery')            → same body twice = one message row
it('records a status arriving before its message')      → statuses first, then messages
it('survives a malformed change object')               → other changes in the batch still process
it('parks an unresolvable phone_number_id')            → status=ignored + alert, no exception
it('acks in under 50ms')                               → assert response time budget
it('replays a failed delivery without duplication')    → re-run processor, still one row
it('rejects a bad signature')                          → 401, nothing persisted
```

### 2. Tenant isolation

A data provider iterating **every** model with `BelongsToTenant`:

```
it('never returns another tenant\'s records', function (string $model) {
    // seed 2 tenants, act as tenant A, assert count() sees only A
})->with('tenantScopedModels');
```

Plus:
```
it('throws when creating a record with no tenant context')
it('enforces RLS even with a raw DB::table query')
it('does not leak tenant context across Octane requests')
```

> The RLS test is only meaningful when the test DB connection uses the non-superuser
> `luminous_app` role (`03-local-development.md` §2). A superuser or table-owner connection
> bypasses RLS silently and the test passes without enforcing anything. The test should assert
> the connected role is not a superuser before asserting isolation.

That last one is the highest-value test in the suite. Simulate two sequential requests in one
process and assert `Tenancy::current()` is null at the start of the second.

### 3. Send guards cannot be bypassed

```
it('refuses a free-form message outside the CSW')
it('refuses marketing to a contact with revoked consent')
it('refuses when the tenant budget cap is reached')
it('respects the per-recipient 6-second gate')
it('respects the per-WABA hourly bucket')
it('suppresses rather than sends when the per-user marketing cap is hit')
```

Every one of these asserts on the **Action**, not the controller, because campaigns and the API call
the Action directly.

### 4. Thread ownership state machine

```
it('moves AI → HUMAN when we send a message')
it('moves HUMAN → AI on pass_thread_control')
it('reconciles local state from a messaging_handovers webhook')
it('routes inbound to the same conversation whether it arrives on messages or standby')
it('refuses an outbound send while state=AI unless takeover is explicit')
```

### 5. Payments are never double-credited

```
it('creates one payment for a duplicated submit')            → idempotency_key
it('ignores a callback for an already-Success payment')
it('re-fetches status before crediting an order')            → asserts the API was called
it('rejects a callback with a wrong security header')
it('handles Failed arriving after SentToVendor')
it('never advances Success → Pending')                       → illegal transition rejected
it('reconciles a payment we never got a callback for')       → poller path
it('rejects an amount below the ioTec minimum')
```

### 6. Money and rounding

```
it('round-trips UGX major↔minor without loss')
it('converts ioTec double amounts without float error')
it('sums a 10,000-row campaign cost exactly')
```

## Fakes

`tests/Fakes/`:

| Fake | Behaviour |
|---|---|
| `FakeGraphClient` | Returns fixtures keyed by method+path. Can be told to return error `131056`, `190`, `131026`, `133010`, and to emit rate-limit headers. |
| `FakeIotecClient` | Implements the four test MSISDN behaviours from ioTec's own docs: `011177777x` → Success, `011177799x` → Failed, `011177778x` → Pending, `011177779x` → SentToVendor. |
| `FakeMbaClient` | Records config pushes; can simulate a thread-control race. |

Fixtures in `tests/Fixtures/meta/` and `tests/Fixtures/iotec/`, captured from real responses and
**scrubbed of real phone numbers and tokens** by `php artisan fixtures:scrub` (an artisan command —
the repo has no Makefile), enforced by a CI step that fails when a fixture matches a real-looking
MSISDN or token pattern.

## Contract tests against the real spec

A test that fails when a provider changes their schema:

```
it('matches the ioTec OpenAPI schema for CollectionRequest')
```

Checked-in copy of ioTec's OpenAPI document (`tests/Fixtures/iotec/openapi.json`) plus a scheduled CI
job that re-downloads `https://pay.iotec.io/swagger/v1/swagger.json` and fails on a diff. Same idea
for the pinned Meta Graph version: a nightly job that asserts our pinned version is still supported.

## Load testing

Before each phase exit:

| Scenario | Target |
|---|---|
| Webhook burst | 500 deliveries/sec for 60s, zero drops, p95 ack < 50ms |
| Campaign send | 10,000 recipients, no `131056`, completes within the rate-limit envelope |
| Inbox with 100k contacts / 2M messages | List paint < 400ms, search < 300ms |
| Connector endpoint | 100 rpm sustained, p95 < 800ms (MBA is waiting on us in a live conversation) |

## Coverage policy

No global percentage target. Instead, these paths require line coverage:
webhook ingest, send guards, thread control, consent evaluation, payment state machine, usage meters,
tenant scoping. A PR touching any of them without a test is rejected.

## Seeders

`DemoTenantSeeder` builds a realistic tenant: 2 numbers, 5 users, 2,000 contacts with mixed consent
states, 30 conversations across all four ownership states, 12 templates spanning every status, 3
campaigns, 40 orders with payments in every ioTec status, and CTWA referrals. This is what makes UI
work possible before any real WABA is connected.
