<?php

use App\Models\PhoneNumber;
use App\Models\User;
use App\Models\WabaAccount;
use App\Support\Facades\Tenancy;
use Database\Factories\OnboardingSessionFactory;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * The onboarding UI surface (docs/modules/m0-onboarding.md §7): the
 * Embedded Signup launcher page and the tenant's numbers screen.
 */
test('guests are redirected to the login page', function (string $routeName) {
    $this->get(route($routeName))->assertRedirect(route('login'));
})->with(['onboarding.index', 'numbers.index']);

test('the onboarding page renders the embedded signup config for the current tenant', function () {
    config()->set('meta.app_id', '918140000000000');
    config()->set('meta.es_config_id', '112233445566778');
    config()->set('meta.graph_version', 'v26.0');

    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('onboarding.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('onboarding/index')
            ->where('appId', '918140000000000')
            ->where('configId', '112233445566778')
            ->where('graphVersion', 'v26.0')
            ->where('session', null));
});

test('the app secret never appears in the onboarding page props', function () {
    config()->set('meta.app_secret', 'super-secret-value');

    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('onboarding.index'))
        ->assertOk()
        ->assertDontSee('super-secret-value');
});

test('the latest onboarding session is exposed with its failure verbatim and no nonce', function () {
    $user = User::factory()->create();
    Tenancy::initialize($user->currentTenant);

    OnboardingSessionFactory::new()->create([
        'id' => (string) Str::uuid7(now()->subMinute()),
        'tenant_id' => $user->currentTenant->id,
    ]);

    $failed = OnboardingSessionFactory::new()->finished()->create([
        'tenant_id' => $user->currentTenant->id,
        'status' => 'failed',
        'failure' => [
            'step' => 'register_phone_number',
            'at' => 'exchanged',
            'error' => ['message' => 'PIN mismatch', 'code' => 133010],
        ],
    ]);

    $this->actingAs($user)
        ->get(route('onboarding.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('onboarding/index')
            ->where('session.id', $failed->id)
            ->where('session.status', 'failed')
            ->where('session.failure.step', 'register_phone_number')
            ->where('session.failure.error.code', 133010)
            ->where('session.nonce', null));
});

test('a started session exposes its nonce so the browser flow can resume', function () {
    $user = User::factory()->create();
    Tenancy::initialize($user->currentTenant);

    $session = OnboardingSessionFactory::new()->create([
        'tenant_id' => $user->currentTenant->id,
    ]);

    $this->actingAs($user)
        ->get(route('onboarding.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('session.status', 'started')
            ->where('session.nonce', $session->nonce));
});

test('another tenant session is never surfaced', function () {
    $alice = User::factory()->create();
    Tenancy::initialize($alice->currentTenant);

    OnboardingSessionFactory::new()->create([
        'tenant_id' => $alice->currentTenant->id,
    ]);

    $mallory = User::factory()->create();

    $this->actingAs($mallory)
        ->get(route('onboarding.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('session', null));
});

test('settings numbers lists the tenant wabas and numbers', function () {
    $user = User::factory()->create();
    Tenancy::initialize($user->currentTenant);

    $wabaAccount = WabaAccount::factory()->create([
        'name' => 'Acme Stores',
        'payment_ready' => false,
    ]);

    $number = PhoneNumber::factory()
        ->coexistence()
        ->create([
            'waba_account_id' => $wabaAccount->id,
            'display_phone_number' => '+256 700 000 001',
            'verified_name' => 'Acme Stores',
            'quality_rating' => 'YELLOW',
            'pin_set' => false,
        ]);

    $this->actingAs($user)
        ->get(route('numbers.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/numbers')
            ->has('wabaAccounts', 1)
            ->where('wabaAccounts.0.name', 'Acme Stores')
            ->where('wabaAccounts.0.wabaId', $wabaAccount->waba_id)
            ->where('wabaAccounts.0.paymentReady', false)
            ->has('wabaAccounts.0.phoneNumbers', 1)
            ->where('wabaAccounts.0.phoneNumbers.0.id', $number->id)
            ->where('wabaAccounts.0.phoneNumbers.0.displayPhoneNumber', '+256 700 000 001')
            ->where('wabaAccounts.0.phoneNumbers.0.verifiedName', 'Acme Stores')
            ->where('wabaAccounts.0.phoneNumbers.0.qualityRating', 'YELLOW')
            ->where('wabaAccounts.0.phoneNumbers.0.messagingLimitTier', 'TIER_1K')
            ->where('wabaAccounts.0.phoneNumbers.0.throughputLevel', 'STANDARD')
            ->where('wabaAccounts.0.phoneNumbers.0.isOnBizApp', true)
            ->where('wabaAccounts.0.phoneNumbers.0.pinSet', false));
});

test('settings numbers renders an empty list when nothing is connected', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('numbers.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/numbers')
            ->has('wabaAccounts', 0));
});

test('the dashboard flags whether the tenant has connected numbers', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('hasWhatsAppNumbers', false));

    Tenancy::initialize($user->currentTenant);
    PhoneNumber::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('hasWhatsAppNumbers', true));
});
