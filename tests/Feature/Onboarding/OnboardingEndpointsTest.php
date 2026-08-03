<?php

use App\Models\OnboardingSession;
use App\Models\User;
use App\Support\Facades\Tenancy;
use Illuminate\Support\Facades\DB;

/**
 * The ES launcher endpoints (docs/modules/m0-onboarding.md §1): session
 * creation with a nonce, and event capture from the WA_EMBEDDED_SIGNUP
 * message listener.
 */
test('guests cannot touch the onboarding endpoints', function () {
    $this->postJson(route('onboarding.start'))->assertUnauthorized();
    $this->postJson(route('onboarding.events'), ['nonce' => 'x', 'event' => ['event' => 'CANCEL']])->assertUnauthorized();
    $this->postJson(route('onboarding.exchange'), ['nonce' => 'x', 'code' => 'y', 'waba_id' => '1', 'phone_number_id' => '2'])->assertUnauthorized();
});

test('start creates a session with a random nonce for the current tenant', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson(route('onboarding.start'))->assertCreated();

    $session = OnboardingSession::query()->sole();

    expect($response->json('id'))->toBe($session->id)
        ->and($response->json('nonce'))->toBe($session->nonce)
        ->and($response->json('status'))->toBe('started')
        ->and(strlen($session->nonce))->toBe(40)
        ->and($session->tenant_id)->toBe($user->currentTenant->id)
        ->and($session->es_version)->toBe('v4')
        ->and($session->events)->toBe([]);

    Tenancy::initialize($user->currentTenant);
    expect(DB::table('audit_logs')->where('action', 'onboarding.session_started')->count())->toBe(1);
});

test('events are appended in order and the coexistence FINISH marks the session', function () {
    $user = User::factory()->create();

    $nonce = $this->actingAs($user)->postJson(route('onboarding.start'))->json('nonce');

    $this->actingAs($user)->postJson(route('onboarding.events'), [
        'nonce' => $nonce,
        'event' => ['type' => 'WA_EMBEDDED_SIGNUP', 'event' => 'CANCEL', 'data' => ['current_step' => 'BUSINESS_ACCOUNT_SELECTION']],
    ])->assertOk();

    $this->actingAs($user)->postJson(route('onboarding.events'), [
        'nonce' => $nonce,
        'event' => ['type' => 'WA_EMBEDDED_SIGNUP', 'event' => 'FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING', 'data' => ['waba_id' => '102290129340398']],
    ])->assertOk();

    $session = OnboardingSession::query()->sole();

    expect($session->events)->toHaveCount(2)
        ->and($session->events[0]['event'])->toBe('CANCEL')
        ->and($session->events[1]['event'])->toBe('FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING')
        ->and($session->feature_type)->toBe('whatsapp_business_app_onboarding')
        ->and($session->status)->toBe('started');
});

test('a nonce belonging to another tenant is a 404, never a crossover', function () {
    $alice = User::factory()->create();
    $mallory = User::factory()->create();

    $nonce = $this->actingAs($alice)->postJson(route('onboarding.start'))->json('nonce');

    $this->actingAs($mallory)->postJson(route('onboarding.events'), [
        'nonce' => $nonce,
        'event' => ['event' => 'CANCEL'],
    ])->assertNotFound();

    $this->actingAs($mallory)->postJson(route('onboarding.exchange'), [
        'nonce' => $nonce,
        'code' => 'AQBxAttackerCode',
        'waba_id' => '102290129340398',
        'phone_number_id' => '106540352242922',
    ])->assertNotFound();
});

test('resuming another tenant session is a 404', function () {
    $alice = User::factory()->create();
    $mallory = User::factory()->create();

    $id = $this->actingAs($alice)->postJson(route('onboarding.start'))->json('id');

    $this->actingAs($mallory)->postJson(route('onboarding.resume', ['session' => $id]))->assertNotFound();
});
