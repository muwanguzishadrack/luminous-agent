<?php

use App\Models\PhoneNumber;
use App\Models\User;
use App\Models\WabaAccount;
use App\Support\Facades\Teams;
use Database\Factories\OnboardingSessionFactory;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * The onboarding UI surface (docs/modules/m0-onboarding.md §7): the
 * Embedded Signup launcher page and the team's numbers screen.
 */
test('guests are redirected to the login page', function (string $routeName) {
    $this->get(route($routeName))->assertRedirect(route('login'));
})->with(['onboarding.index', 'whatsapp.show']);

test('the onboarding page renders the embedded signup config for the current team', function () {
    config()->set('meta.app_id', '918140000000000');
    config()->set('meta.es_config_id', '112233445566778');
    config()->set('meta.graph_version', 'v26.0');

    $user = User::factory()->withTeam()->create();

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

    $user = User::factory()->withTeam()->create();

    $this->actingAs($user)
        ->get(route('onboarding.index'))
        ->assertOk()
        ->assertDontSee('super-secret-value');
});

test('the latest onboarding session is exposed with its failure verbatim and no nonce', function () {
    $user = User::factory()->withTeam()->create();
    Teams::initialize($user->team);

    OnboardingSessionFactory::new()->create([
        'id' => (string) Str::uuid7(now()->subMinute()),
        'team_id' => $user->team->id,
    ]);

    $failed = OnboardingSessionFactory::new()->finished()->create([
        'team_id' => $user->team->id,
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
    $user = User::factory()->withTeam()->create();
    Teams::initialize($user->team);

    $session = OnboardingSessionFactory::new()->create([
        'team_id' => $user->team->id,
    ]);

    $this->actingAs($user)
        ->get(route('onboarding.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('session.status', 'started')
            ->where('session.nonce', $session->nonce));
});

test('another team session is never surfaced', function () {
    $alice = User::factory()->withTeam()->create();
    Teams::initialize($alice->team);

    OnboardingSessionFactory::new()->create([
        'team_id' => $alice->team->id,
    ]);

    $mallory = User::factory()->withTeam()->create();

    $this->actingAs($mallory)
        ->get(route('onboarding.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('session', null));
});

test('settings whatsapp shows the team single waba and number', function () {
    $user = User::factory()->withTeam()->create();
    Teams::initialize($user->team);

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
        ->get(route('whatsapp.show'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/whatsapp')
            ->where('wabaAccount.name', 'Acme Stores')
            ->where('wabaAccount.wabaId', $wabaAccount->waba_id)
            ->where('wabaAccount.paymentReady', false)
            ->where('phoneNumber.id', $number->id)
            ->where('phoneNumber.displayPhoneNumber', '+256 700 000 001')
            ->where('phoneNumber.verifiedName', 'Acme Stores')
            ->where('phoneNumber.qualityRating', 'YELLOW')
            ->where('phoneNumber.throughputLevel', 'STANDARD')
            ->where('phoneNumber.isOnBizApp', true)
            ->where('phoneNumber.pinSet', false));
});

test('settings whatsapp renders nothing connected when the team has no waba', function () {
    $user = User::factory()->withTeam()->create();

    $this->actingAs($user)
        ->get(route('whatsapp.show'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/whatsapp')
            ->where('wabaAccount', null)
            ->where('phoneNumber', null));
});

test('the dashboard flags whether the team has a connected number', function () {
    $user = User::factory()->withTeam()->create();

    $this->actingAs($user)
        ->get(route('dashboard', ['team' => $user->team->slug]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('hasWhatsAppNumbers', false));

    Teams::initialize($user->team);
    PhoneNumber::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard', ['team' => $user->team->slug]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('hasWhatsAppNumbers', true));
});

test('the onboarding page names the workspace the number will belong to', function () {
    $user = User::factory()->withTeam('Acme Trading')->create();

    $this->actingAs($user)
        ->get(route('onboarding.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('onboarding/index')
            ->where('team.name', 'Acme Trading')
            ->where('team.slug', $user->team->slug)
            ->where('connectedWabaId', null),
        );
});

test('the onboarding page reports a team that is already connected', function () {
    $user = User::factory()->withTeam()->create();
    Teams::initialize($user->team);

    $waba = WabaAccount::factory()->create();

    $this->actingAs($user)
        ->get(route('onboarding.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('onboarding/index')
            ->where('connectedWabaId', $waba->waba_id),
        );
});
