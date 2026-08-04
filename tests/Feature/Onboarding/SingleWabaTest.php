<?php

use App\Actions\Onboarding\OnboardingInput;
use App\Actions\Onboarding\RunOnboardingChain;
use App\Models\MetaCredential;
use App\Models\PhoneNumber;
use App\Models\User;
use App\Models\WabaAccount;
use App\Services\Meta\GraphClient;
use App\Support\Facades\Teams;
use Database\Factories\OnboardingSessionFactory;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Fakes\FakeGraphClient;

/**
 * One WABA and one phone number per team (D-020, docs/modules/m0-onboarding.md
 * §8). A second Embedded Signup is refused with a clear message — never
 * merged, never silently overwritten.
 */
beforeEach(function () {
    config()->set('meta.app_id', '918140000000000');
});

test('starting a second embedded signup is refused before the ES window opens', function () {
    $user = User::factory()->withTeam()->create();
    Teams::initialize($user->team);

    WabaAccount::factory()->create();

    $this->actingAs($user)
        ->postJson(route('onboarding.start'))
        ->assertStatus(409)
        ->assertJsonPath('message', 'This workspace is already connected to WhatsApp. A workspace holds one WhatsApp Business Account and one number.');

    Teams::initialize($user->team);
    expect(DB::table('onboarding_sessions')->count())->toBe(0);
});

test('exchanging a second signup code fails the chain with a clear message and vaults no token', function () {
    $user = User::factory()->withTeam()->create();
    Teams::initialize($user->team);

    $existing = WabaAccount::factory()->create();
    $session = OnboardingSessionFactory::new()->finished()->create([
        'team_id' => $user->team->id,
        'waba_id' => '900900900900900',
    ]);

    /** @var FakeGraphClient $fake */
    $fake = app(GraphClient::class);

    app(RunOnboardingChain::class)->handle($session, new OnboardingInput(code: 'AQBxSecondSignupCode'));

    $session->refresh();

    expect($session->status)->toBe('failed')
        ->and($session->failure['step'])->toBe('exchange_signup_code')
        ->and($session->failure['error']['message'])->toContain($existing->waba_id)
        ->and($session->failure['error']['message'])->toContain('one WhatsApp Business Account and one number');

    Teams::initialize($user->team);

    // Nothing was merged or overwritten: the original WABA stands alone and
    // no token was exchanged for the second one.
    expect(WabaAccount::query()->count())->toBe(1)
        ->and(WabaAccount::query()->sole()->waba_id)->toBe($existing->waba_id)
        ->and(MetaCredential::query()->count())->toBe(0)
        ->and(collect($fake->calls)->where('path', 'oauth/access_token'))->toBeEmpty();
});

test('the asset sync binds only the number from the ES payload and records the rest as ignored', function () {
    $user = User::factory()->withTeam()->create();
    Teams::initialize($user->team);

    MetaCredential::factory()->create(['waba_account_id' => null]);

    $session = OnboardingSessionFactory::new()->exchanged()->create(['team_id' => $user->team->id]);
    [$wabaId, $onboarded] = [(string) $session->waba_id, (string) $session->phone_number_id];
    $spare = '299999999999999';

    /** @var FakeGraphClient $fake */
    $fake = app(GraphClient::class);
    primeGraphFixtures($fake, $wabaId, $onboarded);

    // Meta returns two numbers on the WABA; only one was onboarded.
    $fake->fake("GET {$wabaId}/phone_numbers", ['data' => [
        [
            'id' => $spare,
            'verified_name' => 'Acme Spare Line',
            'display_phone_number' => '+256 700 000 002',
            'quality_rating' => 'GREEN',
            'messaging_limit_tier' => 'TIER_1K',
            'throughput' => ['level' => 'STANDARD'],
            'platform_type' => 'CLOUD_API',
            'is_on_biz_app' => false,
            'code_verification_status' => 'VERIFIED',
        ],
        [
            'id' => $onboarded,
            'verified_name' => 'Acme Stores',
            'display_phone_number' => '+256 700 000 001',
            'quality_rating' => 'GREEN',
            'messaging_limit_tier' => 'TIER_1K',
            'throughput' => ['level' => 'STANDARD'],
            'platform_type' => 'CLOUD_API',
            'is_on_biz_app' => false,
            'code_verification_status' => 'VERIFIED',
        ],
    ]]);

    app(RunOnboardingChain::class)->handle($session);

    expect($session->refresh()->status)->toBe('complete');

    Teams::initialize($user->team);

    expect(PhoneNumber::query()->count())->toBe(1)
        ->and(PhoneNumber::query()->sole()->phone_number_id)->toBe($onboarded);

    $context = DB::table('audit_logs')->where('action', 'onboarding.assets_synced')->value('context');

    expect(json_decode((string) $context, true))
        ->toMatchArray([
            'waba_id' => $wabaId,
            'phone_number_id' => $onboarded,
            'ignored_phone_number_ids' => [$spare],
        ]);
});

test('the asset sync refuses to bind a different number when the onboarded one is absent', function () {
    $user = User::factory()->withTeam()->create();
    Teams::initialize($user->team);

    MetaCredential::factory()->create(['waba_account_id' => null]);

    $session = OnboardingSessionFactory::new()->exchanged()->create(['team_id' => $user->team->id]);
    $wabaId = (string) $session->waba_id;

    /** @var FakeGraphClient $fake */
    $fake = app(GraphClient::class);
    primeGraphFixtures($fake, $wabaId, (string) $session->phone_number_id);

    $fake->fake("GET {$wabaId}/phone_numbers", ['data' => [[
        'id' => '288888888888888', // not the number the client onboarded
        'verified_name' => 'Somebody Else',
        'display_phone_number' => '+256 700 000 009',
        'quality_rating' => 'GREEN',
        'messaging_limit_tier' => 'TIER_1K',
        'throughput' => ['level' => 'STANDARD'],
        'platform_type' => 'CLOUD_API',
        'is_on_biz_app' => false,
        'code_verification_status' => 'VERIFIED',
    ]]]);

    app(RunOnboardingChain::class)->handle($session);

    $session->refresh();

    expect($session->status)->toBe('failed')
        ->and($session->failure['step'])->toBe('sync_waba_assets')
        ->and($session->failure['error']['message'])->toContain('refusing to bind a different number');

    Teams::initialize($user->team);
    expect(PhoneNumber::query()->count())->toBe(0);
});

/**
 * The database is the backstop for both caps, not just the application.
 */
test('a second waba row for a team is refused by the database', function () {
    $user = User::factory()->withTeam()->create();
    Teams::initialize($user->team);

    WabaAccount::factory()->create();

    expect(fn () => DB::table('waba_accounts')->insert([
        'id' => (string) Str::uuid7(),
        'team_id' => $user->team->id,
        'waba_id' => '900900900900900',
        'owner_business_id' => '515151515151515',
        'name' => 'Second WABA',
        'timezone_id' => 'Africa/Kampala',
        'currency' => 'UGX',
        'review_status' => 'APPROVED',
        'account_status' => 'ACTIVE',
        'business_verification_status' => 'verified',
    ]))->toThrow(QueryException::class, 'waba_accounts_team_id_unique');
});

test('a second phone number row for a team is refused by the database', function () {
    $user = User::factory()->withTeam()->create();
    Teams::initialize($user->team);

    $waba = WabaAccount::factory()->create();
    PhoneNumber::factory()->create(['waba_account_id' => $waba->id]);

    expect(fn () => DB::table('phone_numbers')->insert([
        'id' => (string) Str::uuid7(),
        'team_id' => $user->team->id,
        'waba_account_id' => $waba->id,
        'phone_number_id' => '288888888888888',
        'display_phone_number' => '+256 700 000 009',
        'verified_name' => 'Second Number',
        'code_verification_status' => 'VERIFIED',
        'quality_rating' => 'GREEN',
        'messaging_limit_tier' => 'TIER_1K',
        'throughput_level' => 'STANDARD',
        'platform_type' => 'CLOUD_API',
        'profile' => '{}',
        'status' => 'active',
    ]))->toThrow(QueryException::class, 'phone_numbers_team_id_unique');
});
