<?php

use App\Enums\WebhookDeliveryStatus;
use App\Jobs\ProcessWebhookDelivery;
use App\Models\Tenant;
use App\Models\WabaAccount;
use App\Models\WebhookDelivery;
use App\Services\Webhooks\FieldHandlerRegistry;
use App\Support\Facades\Tenancy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/**
 * Webhook ingest never loses a message (docs/06 §1). These tests exercise the
 * REAL route with real signatures — no shortcuts around verification.
 */
function signedPost(string $body): array
{
    return [
        'uri' => '/webhooks/meta',
        'body' => $body,
        'headers' => [
            'Content-Type' => 'application/json',
            'X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $body, (string) config('meta.app_secret')),
        ],
    ];
}

function metaFixture(string $path): string
{
    return (string) file_get_contents(base_path("tests/Fixtures/meta/{$path}"));
}

beforeEach(function () {
    config()->set('meta.app_id', '918140000000000');
    config()->set('meta.app_secret', 'test-app-secret');
    config()->set('meta.webhook_verify_token', 'test-verify-token');
});

test('the GET handshake echoes hub.challenge for a valid verify token', function () {
    $this->get('/webhooks/meta?hub_mode=subscribe&hub_challenge=12345&hub_verify_token=test-verify-token')
        ->assertOk()
        ->assertSee('12345');
});

test('the id-suffixed URL form still works, and a foreign app id is a 404', function () {
    $this->get('/webhooks/meta/'.config('meta.app_id').'?hub_mode=subscribe&hub_challenge=99&hub_verify_token=test-verify-token')
        ->assertOk()
        ->assertSee('99');

    $this->get('/webhooks/meta/000000000000000?hub_mode=subscribe&hub_challenge=99&hub_verify_token=test-verify-token')
        ->assertNotFound();
});

test('the GET handshake rejects a wrong verify token', function () {
    $this->get('/webhooks/meta?hub_mode=subscribe&hub_challenge=12345&hub_verify_token=wrong')
        ->assertForbidden();
});

test('it rejects a bad signature and persists nothing', function () {
    $body = metaFixture('messages/text_inbound.json');

    $this->call('POST', '/webhooks/meta', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'wrong-secret'),
    ], $body)->assertUnauthorized();

    expect(WebhookDelivery::on('pgsql')->count())->toBe(0);
});

test('a valid delivery is persisted raw and acked with a queued job', function () {
    Queue::fake();

    $post = signedPost(metaFixture('messages/text_inbound.json'));

    $this->call('POST', $post['uri'], [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_HUB_SIGNATURE_256' => $post['headers']['X-Hub-Signature-256'],
    ], $post['body'])->assertOk();

    Queue::assertPushed(ProcessWebhookDelivery::class, 1);

    $delivery = WebhookDelivery::sole();
    expect($delivery->status)->toBe(WebhookDeliveryStatus::Pending)
        ->and($delivery->payload['entry'][0]['changes'][0]['field'])->toBe('messages')
        ->and($delivery->tenant_id)->toBeNull();
});

test('a duplicate delivery is a no-op ack, not a second row', function () {
    Queue::fake();

    $post = signedPost(metaFixture('messages/text_inbound.json'));
    $server = [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_HUB_SIGNATURE_256' => $post['headers']['X-Hub-Signature-256'],
    ];

    $this->call('POST', $post['uri'], [], [], [], $server, $post['body'])->assertOk();
    $this->call('POST', $post['uri'], [], [], [], $server, $post['body'])->assertOk();

    expect(WebhookDelivery::count())->toBe(1);
    Queue::assertPushed(ProcessWebhookDelivery::class, 1);
});

test('an unhandled field with an unresolvable tenant is parked as ignored, never guessed', function () {
    $post = signedPost(metaFixture('messages/text_inbound.json'));

    $this->call('POST', $post['uri'], [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_HUB_SIGNATURE_256' => $post['headers']['X-Hub-Signature-256'],
    ], $post['body'])->assertOk();

    // messages has no handler yet (M1) — the change parks without a tenant.
    (new ProcessWebhookDelivery(WebhookDelivery::sole()->id))
        ->handle(app(FieldHandlerRegistry::class));

    $delivery = WebhookDelivery::sole();
    expect($delivery->status)->toBe(WebhookDeliveryStatus::Ignored)
        ->and($delivery->processed_at)->not->toBeNull();
});

test('a malformed change does not lose its siblings', function () {
    $tenant = Tenant::factory()->create();
    Tenancy::initialize($tenant);
    WabaAccount::factory()->create(['waba_id' => '102290129340398']);
    Tenancy::forget();

    $payload = json_decode(metaFixture('account_update/partner_removed.json'), true);
    // Prepend a failing sibling: a handled field on a WABA no tenant owns —
    // tenant resolution refuses to guess and the change fails.
    array_unshift($payload['entry'], [
        'id' => '999999999999999',
        'changes' => [['field' => 'account_update', 'value' => ['event' => 'PARTNER_REMOVED']]],
    ]);
    $body = json_encode($payload);

    $post = signedPost($body);
    $this->call('POST', $post['uri'], [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_HUB_SIGNATURE_256' => $post['headers']['X-Hub-Signature-256'],
    ], $body)->assertOk();

    (new ProcessWebhookDelivery(WebhookDelivery::sole()->id))
        ->handle(app(FieldHandlerRegistry::class));

    // The good sibling processed despite the failing one, and the failure is
    // recorded on the delivery (partial, with per-change evidence).
    $delivery = WebhookDelivery::sole();
    expect($tenant->fresh()->status)->toBe('suspended')
        ->and($delivery->status)->toBe(WebhookDeliveryStatus::Partial)
        ->and($delivery->error)->toHaveKey('0.0');
});

test('a PARTNER_REMOVED account_update suspends the tenant within one processing cycle', function () {
    $tenant = Tenant::factory()->create(['status' => 'active']);
    Tenancy::initialize($tenant);
    $waba = WabaAccount::factory()->create(['waba_id' => '102290129340398']);
    Tenancy::forget();

    $post = signedPost(metaFixture('account_update/partner_removed.json'));
    $this->call('POST', $post['uri'], [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_HUB_SIGNATURE_256' => $post['headers']['X-Hub-Signature-256'],
    ], $post['body'])->assertOk();

    (new ProcessWebhookDelivery(WebhookDelivery::sole()->id))
        ->handle(app(FieldHandlerRegistry::class));

    Tenancy::initialize($tenant);
    expect($tenant->fresh()->status)->toBe('suspended')
        ->and($tenant->fresh()->suspended_reason)->toBe('partner_removed')
        ->and($waba->fresh()->is_subscribed)->toBeFalse()
        ->and(DB::table('audit_logs')->where('action', 'waba.account_update')->count())->toBe(1);
});

test('replaying a processed delivery does not duplicate its effects', function () {
    $tenant = Tenant::factory()->create(['status' => 'active']);
    Tenancy::initialize($tenant);
    WabaAccount::factory()->create(['waba_id' => '102290129340398']);
    Tenancy::forget();

    $post = signedPost(metaFixture('account_update/partner_removed.json'));
    $server = [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_HUB_SIGNATURE_256' => $post['headers']['X-Hub-Signature-256'],
    ];
    $this->call('POST', $post['uri'], [], [], [], $server, $post['body'])->assertOk();

    $registry = app(FieldHandlerRegistry::class);
    $deliveryId = WebhookDelivery::sole()->id;

    (new ProcessWebhookDelivery($deliveryId))->handle($registry);
    // Re-run the processor (replay path): must be a no-op on a processed row.
    (new ProcessWebhookDelivery($deliveryId))->handle($registry);

    expect(WebhookDelivery::sole()->attempts)->toBe(1);
});

test('the ack responds inside the latency budget', function () {
    Queue::fake();

    $post = signedPost(metaFixture('messages/text_inbound.json'));
    $start = hrtime(true);

    $this->call('POST', $post['uri'], [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_HUB_SIGNATURE_256' => $post['headers']['X-Hub-Signature-256'],
    ], $post['body'])->assertOk();

    $ms = (hrtime(true) - $start) / 1e6;

    // 50ms is the production p95 budget; in-process test overhead gets 250ms.
    expect($ms)->toBeLessThan(250.0);
});
