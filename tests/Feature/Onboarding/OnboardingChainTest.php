<?php

use App\Actions\Onboarding\OnboardingInput;
use App\Actions\Onboarding\OnboardingStatus;
use App\Actions\Onboarding\RunOnboardingChain;
use App\Enums\MetaCredentialType;
use App\Models\MetaCredential;
use App\Models\OnboardingSession;
use App\Models\PhoneNumber;
use App\Models\Template;
use App\Models\User;
use App\Models\WabaAccount;
use App\Services\Meta\GraphClient;
use App\Support\Facades\Tenancy;
use Database\Factories\OnboardingSessionFactory;
use Illuminate\Support\Facades\DB;
use Tests\Fakes\FakeGraphClient;

/**
 * The Embedded Signup v4 server chain (docs/modules/m0-onboarding.md §1):
 * exchange → register → subscribe → sync assets → sync templates → payment
 * readiness → complete, resumable rather than restarted.
 */
beforeEach(function () {
    config()->set('meta.app_id', '918140000000000');
});

/**
 * Canned Graph fixtures for a full onboarding of one WABA + one number.
 *
 * @param  array<string, mixed>  $overrides
 */
function primeGraphFixtures(FakeGraphClient $fake, string $wabaId, string $phoneNumberId, array $overrides = []): void
{
    $fake->fake("GET {$wabaId}/subscribed_apps", $overrides['subscribed_apps'] ?? [
        'data' => [['whatsapp_business_api_data' => [
            'id' => config('meta.app_id'),
            'name' => 'Luminous',
            'link' => 'https://luminous.test',
        ]]],
    ]);

    $fake->fake("GET {$wabaId}", [
        'id' => $wabaId,
        'name' => 'Acme Stores',
        'currency' => 'UGX',
        'timezone_id' => 'Africa/Kampala',
        'account_review_status' => 'APPROVED',
        'business_verification_status' => 'verified',
        'owner_business_info' => ['id' => '515151515151515', 'name' => 'Acme Holdings'],
        'is_payment_enabled' => true,
    ]);

    $fake->fake("GET {$wabaId}/phone_numbers", ['data' => [[
        'id' => $phoneNumberId,
        'verified_name' => 'Acme Stores',
        'display_phone_number' => '+256 700 000 001',
        'quality_rating' => 'GREEN',
        'messaging_limit_tier' => 'TIER_1K',
        'throughput' => ['level' => 'STANDARD'],
        'platform_type' => 'CLOUD_API',
        'is_on_biz_app' => $overrides['is_on_biz_app'] ?? false,
        'code_verification_status' => 'VERIFIED',
    ]]]);

    $fake->fake("GET {$wabaId}/message_templates", ['data' => [
        [
            'id' => '771111111111111',
            'name' => 'order_update',
            'language' => 'en',
            'category' => 'UTILITY',
            'status' => 'APPROVED',
            'components' => [['type' => 'BODY', 'text' => 'Your order {{1}} has shipped.']],
            'quality_score' => ['score' => 'GREEN', 'date' => 1754179200],
        ],
        [
            'id' => '772222222222222',
            'name' => 'welcome_offer',
            'language' => 'en',
            'category' => 'MARKETING',
            'status' => 'PENDING',
            'components' => [['type' => 'BODY', 'text' => 'Hi {{1}}, welcome!']],
        ],
    ], 'paging' => ['cursors' => ['before' => 'BEFORE', 'after' => 'AFTER']]]);
}

test('the exchange drives a session from started to complete, leaking no secrets', function () {
    $user = User::factory()->create();
    $tenant = $user->currentTenant;
    [$wabaId, $phoneNumberId] = ['102290129340398', '106540352242922'];

    /** @var FakeGraphClient $fake */
    $fake = app(GraphClient::class);
    primeGraphFixtures($fake, $wabaId, $phoneNumberId);

    $nonce = $this->actingAs($user)->postJson(route('onboarding.start'))->json('nonce');

    $response = $this->actingAs($user)->postJson(route('onboarding.exchange'), [
        'nonce' => $nonce,
        'code' => 'AQBxSensitiveOneTimeCode',
        'waba_id' => $wabaId,
        'phone_number_id' => $phoneNumberId,
    ])->assertOk();

    expect($response->json('status'))->toBe('complete')
        ->and($response->json('failure'))->toBeNull();

    Tenancy::initialize($tenant);

    $session = OnboardingSession::query()->sole();
    $credential = MetaCredential::query()->sole();
    $waba = WabaAccount::query()->sole();
    $phone = PhoneNumber::query()->sole();

    // Status flow ended at complete, with the exchange timestamped.
    expect($session->status)->toBe('complete')
        ->and($session->code_exchanged_at)->not->toBeNull()
        ->and($tenant->fresh()->status)->toBe('active');

    // Credential vaulted: business type, last4 for display, scoped to the WABA.
    expect($credential->type)->toBe(MetaCredentialType::Business)
        ->and($credential->token)->toStartWith('fake-business-token-')
        ->and($credential->token_last4)->toBe(substr($credential->token, -4))
        ->and($credential->waba_account_id)->toBe($waba->id);

    // Assets synced from the Graph responses.
    expect($waba->name)->toBe('Acme Stores')
        ->and($waba->currency)->toBe('UGX')
        ->and($waba->timezone_id)->toBe('Africa/Kampala')
        ->and($waba->owner_business_id)->toBe('515151515151515')
        ->and($waba->review_status)->toBe('APPROVED')
        ->and($waba->is_subscribed)->toBeTrue()
        ->and($waba->payment_ready)->toBeTrue()
        ->and($waba->onboarded_at)->not->toBeNull();

    expect($phone->phone_number_id)->toBe($phoneNumberId)
        ->and($phone->verified_name)->toBe('Acme Stores')
        ->and($phone->quality_rating)->toBe('GREEN')
        ->and($phone->messaging_limit_tier)->toBe('TIER_1K')
        ->and($phone->throughput_level)->toBe('STANDARD')
        ->and($phone->platform_type)->toBe('CLOUD_API')
        ->and($phone->is_on_biz_app)->toBeFalse()
        ->and($phone->pin_set)->toBeTrue();

    // Templates upserted from the initial pull.
    expect(Template::query()->count())->toBe(2);
    $template = Template::query()->where('name', 'order_update')->sole();
    expect($template->language)->toBe('en')
        ->and($template->status)->toBe('APPROVED')
        ->and($template->quality_score)->toBe('GREEN')
        ->and($template->components)->not->toBeEmpty();

    // Register was called with messaging_product + a generated 6-digit pin.
    $register = collect($fake->calls)->firstWhere('path', "{$phoneNumberId}/register");
    expect($register)->not->toBeNull()
        ->and($register['payload']['messaging_product'])->toBe('whatsapp')
        ->and($register['payload']['pin'])->toMatch('/^\d{6}$/');

    // Webhook subscription was created AND verified.
    expect(collect($fake->calls)->contains(fn (array $call) => $call['method'] === 'POST' && $call['path'] === "{$wabaId}/subscribed_apps"))->toBeTrue()
        ->and(collect($fake->calls)->contains(fn (array $call) => $call['method'] === 'GET' && $call['path'] === "{$wabaId}/subscribed_apps"))->toBeTrue();

    // One-time template analytics opt-in happened (reference §6).
    expect(collect($fake->calls)->contains(fn (array $call) => $call['method'] === 'POST'
        && $call['path'] === $wabaId
        && ($call['payload']['is_enabled_for_insights'] ?? false) === true))->toBeTrue();

    // Every step wrote an audit entry…
    foreach ([
        'onboarding.session_started',
        'onboarding.code_exchanged',
        'onboarding.phone_registered',
        'onboarding.waba_subscribed',
        'onboarding.assets_synced',
        'onboarding.templates_synced',
        'onboarding.payment_readiness_checked',
        'onboarding.completed',
    ] as $action) {
        expect(DB::table('audit_logs')->where('action', $action)->count())->toBe(1);
    }

    // …and neither the raw token nor the one-time code appears anywhere:
    // audit context, HTTP response, or the session row (docs/m0 §9 c6).
    $auditContexts = DB::table('audit_logs')->pluck('context')->implode(' ');
    expect($auditContexts)->not->toContain($credential->token)
        ->and($auditContexts)->not->toContain('AQBxSensitiveOneTimeCode')
        ->and($auditContexts)->toContain($credential->token_last4)
        ->and((string) $response->getContent())->not->toContain($credential->token)
        ->and((string) $response->getContent())->not->toContain('AQBxSensitiveOneTimeCode')
        ->and(json_encode($session->getAttributes()))->not->toContain('AQBxSensitiveOneTimeCode');
});

test('register is skipped entirely for coexistence sessions', function () {
    $user = User::factory()->create();
    $tenant = $user->currentTenant;
    [$wabaId, $phoneNumberId] = ['102290129340398', '106540352242922'];

    /** @var FakeGraphClient $fake */
    $fake = app(GraphClient::class);
    primeGraphFixtures($fake, $wabaId, $phoneNumberId, ['is_on_biz_app' => true]);

    $nonce = $this->actingAs($user)->postJson(route('onboarding.start'))->json('nonce');

    // The ES window reported the Coexistence FINISH variant (docs/m0 §3).
    $this->actingAs($user)->postJson(route('onboarding.events'), [
        'nonce' => $nonce,
        'event' => ['type' => 'WA_EMBEDDED_SIGNUP', 'event' => 'FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING', 'data' => ['waba_id' => $wabaId]],
    ])->assertOk();

    $this->actingAs($user)->postJson(route('onboarding.exchange'), [
        'nonce' => $nonce,
        'code' => 'AQBxCoexistenceCode',
        'waba_id' => $wabaId,
        'phone_number_id' => $phoneNumberId,
    ])->assertOk()->assertJsonPath('status', 'complete');

    Tenancy::initialize($tenant);

    // No register call ever went out — the number is already registered on
    // the WhatsApp Business app.
    expect(collect($fake->calls)->firstWhere('path', "{$phoneNumberId}/register"))->toBeNull();

    $phone = PhoneNumber::query()->sole();
    expect($phone->pin_set)->toBeFalse()
        ->and($phone->is_on_biz_app)->toBeTrue()
        ->and($tenant->fresh()->status)->toBe('active')
        ->and(DB::table('audit_logs')->where('action', 'onboarding.phone_registration_skipped')->count())->toBe(1)
        ->and(DB::table('audit_logs')->where('action', 'onboarding.phone_registered')->count())->toBe(0);
});

test('a 133010 register failure fails the session at the register step and resume completes it', function () {
    $user = User::factory()->create();
    $tenant = $user->currentTenant;
    Tenancy::initialize($tenant);

    MetaCredential::factory()->create(['waba_account_id' => null]);
    $session = OnboardingSessionFactory::new()->exchanged()->create(['tenant_id' => $tenant->id]);

    /** @var FakeGraphClient $fake */
    $fake = app(GraphClient::class);
    $fake->failWith(133010);

    app(RunOnboardingChain::class)->handle($session);

    $session->refresh();

    // Failed at the register step, the Graph error retained verbatim
    // (docs/m0 §8: offer request-code/verify-code inline).
    expect($session->status)->toBe('failed')
        ->and($session->failure['step'])->toBe('register_phone_number')
        ->and($session->failure['at'])->toBe('exchanged')
        ->and($session->failure['error']['code'])->toBe(133010);

    // Once fixed on Meta's side, resume continues from the failed step.
    primeGraphFixtures($fake, (string) $session->waba_id, (string) $session->phone_number_id);

    $this->actingAs($user)->postJson(route('onboarding.resume', ['session' => $session->id]))
        ->assertOk()
        ->assertJsonPath('status', 'complete')
        ->assertJsonPath('failure', null);

    Tenancy::initialize($tenant);

    // Resumed, not restarted: the code exchange never re-ran.
    expect(collect($fake->calls)->where('path', 'oauth/access_token')->count())->toBe(0)
        ->and(collect($fake->calls)->where('path', "{$session->phone_number_id}/register")->count())->toBe(2)
        ->and($session->fresh()->failure)->toBeNull()
        ->and($tenant->fresh()->status)->toBe('active');
});

test('a subscribed_apps verification miss fails the step loudly', function () {
    $user = User::factory()->create();
    $tenant = $user->currentTenant;
    Tenancy::initialize($tenant);

    MetaCredential::factory()->create(['waba_account_id' => null]);
    $session = OnboardingSessionFactory::new()->exchanged()->create(['tenant_id' => $tenant->id]);

    /** @var FakeGraphClient $fake */
    $fake = app(GraphClient::class);
    primeGraphFixtures($fake, (string) $session->waba_id, (string) $session->phone_number_id, [
        // Some OTHER app is subscribed — ours is missing.
        'subscribed_apps' => ['data' => [['whatsapp_business_api_data' => ['id' => '111000111000111']]]],
    ]);

    app(RunOnboardingChain::class)->handle($session);

    $session->refresh();

    expect($session->status)->toBe('failed')
        ->and($session->failure['step'])->toBe('subscribe_waba_webhooks')
        ->and($session->failure['at'])->toBe('registered')
        ->and($session->failure['error']['message'])->toContain('subscribed_apps')
        ->and($tenant->fresh()->status)->not->toBe('active');
});

test('re-running the chain on a completed session changes nothing', function () {
    $user = User::factory()->create();
    $tenant = $user->currentTenant;
    [$wabaId, $phoneNumberId] = ['102290129340398', '106540352242922'];

    /** @var FakeGraphClient $fake */
    $fake = app(GraphClient::class);
    primeGraphFixtures($fake, $wabaId, $phoneNumberId);

    $nonce = $this->actingAs($user)->postJson(route('onboarding.start'))->json('nonce');
    $sessionId = $this->actingAs($user)->postJson(route('onboarding.exchange'), [
        'nonce' => $nonce,
        'code' => 'AQBxIdempotencyCode',
        'waba_id' => $wabaId,
        'phone_number_id' => $phoneNumberId,
    ])->assertJsonPath('status', 'complete')->json('id');

    Tenancy::initialize($tenant);
    $snapshot = [
        'audits' => DB::table('audit_logs')->count(),
        'graph_calls' => count($fake->calls),
        'credentials' => MetaCredential::query()->count(),
        'token' => MetaCredential::query()->sole()->token,
        'wabas' => WabaAccount::query()->count(),
        'phones' => PhoneNumber::query()->count(),
        'templates' => Template::query()->count(),
    ];

    $this->actingAs($user)->postJson(route('onboarding.resume', ['session' => $sessionId]))
        ->assertOk()
        ->assertJsonPath('status', 'complete');

    Tenancy::initialize($tenant);
    expect(DB::table('audit_logs')->count())->toBe($snapshot['audits'])
        ->and(count($fake->calls))->toBe($snapshot['graph_calls'])
        ->and(MetaCredential::query()->count())->toBe($snapshot['credentials'])
        ->and(MetaCredential::query()->sole()->token)->toBe($snapshot['token'])
        ->and(WabaAccount::query()->count())->toBe($snapshot['wabas'])
        ->and(PhoneNumber::query()->count())->toBe($snapshot['phones'])
        ->and(Template::query()->count())->toBe($snapshot['templates']);
});

test('resuming a mid-flow session re-runs only idempotent sync steps', function () {
    $user = User::factory()->create();
    $tenant = $user->currentTenant;
    Tenancy::initialize($tenant);

    MetaCredential::factory()->create(['waba_account_id' => null]);
    $session = OnboardingSessionFactory::new()->exchanged()->create(['tenant_id' => $tenant->id]);

    /** @var FakeGraphClient $fake */
    $fake = app(GraphClient::class);
    primeGraphFixtures($fake, (string) $session->waba_id, (string) $session->phone_number_id);

    // First run completes; a second full run over the same session must not
    // duplicate any row (every step is status-gated and upsert-based).
    app(RunOnboardingChain::class)->handle($session);
    expect($session->refresh()->status)->toBe('complete');

    $audits = DB::table('audit_logs')->count();

    app(RunOnboardingChain::class)->handle($session);

    expect($session->refresh()->status)->toBe('complete')
        ->and(DB::table('audit_logs')->count())->toBe($audits)
        ->and(WabaAccount::query()->count())->toBe(1)
        ->and(PhoneNumber::query()->count())->toBe(1)
        ->and(Template::query()->count())->toBe(2)
        ->and(MetaCredential::query()->count())->toBe(1);
});

test('a number with existing two-step verification fails with 133005 and completes once the real PIN is supplied', function () {
    $user = User::factory()->create();
    $tenant = $user->currentTenant;
    Tenancy::initialize($tenant);

    MetaCredential::factory()->create(['waba_account_id' => null]);
    $session = OnboardingSessionFactory::new()->exchanged()->create(['tenant_id' => $tenant->id]);

    /** @var FakeGraphClient $graph */
    $graph = app(GraphClient::class);
    $graph->failWith(133005);

    app(RunOnboardingChain::class)->handle($session);

    $session->refresh();
    expect($session->status)->toBe(OnboardingStatus::FAILED)
        ->and($session->failure['step'])->toBe('register_phone_number')
        ->and($session->failure['error']['code'])->toBe(133005);

    // The client supplies the number's real PIN and resumes — the chain
    // continues from the failed step and reaches completion.
    primeGraphFixtures($graph, (string) $session->waba_id, (string) $session->phone_number_id);
    app(RunOnboardingChain::class)->handle($session, new OnboardingInput(pin: '246813'));

    $session->refresh();
    expect($session->status)->toBe(OnboardingStatus::COMPLETE);

    $register = collect($graph->calls)->where('path', "{$session->phone_number_id}/register")->last();
    expect($register['payload']['pin'])->toBe('246813');
});

test('the supplied PIN is never persisted or written to the audit trail', function () {
    $user = User::factory()->create();
    $tenant = $user->currentTenant;
    Tenancy::initialize($tenant);

    MetaCredential::factory()->create(['waba_account_id' => null]);
    $session = OnboardingSessionFactory::new()->exchanged()->create(['tenant_id' => $tenant->id]);

    /** @var FakeGraphClient $graph */
    $graph = app(GraphClient::class);
    primeGraphFixtures($graph, (string) $session->waba_id, (string) $session->phone_number_id);

    app(RunOnboardingChain::class)->handle($session, new OnboardingInput(pin: '135792'));

    $audit = DB::table('audit_logs')->pluck('context')->implode(' ');
    expect($audit)->not->toContain('135792')
        ->and(json_encode($session->fresh()->toArray()))->not->toContain('135792')
        ->and($audit)->toContain('client_supplied');
});
