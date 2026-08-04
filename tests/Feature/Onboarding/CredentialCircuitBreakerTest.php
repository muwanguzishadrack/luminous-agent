<?php

use App\Models\MetaCredential;
use App\Models\User;
use App\Services\Meta\CredentialResolver;
use App\Services\Meta\Exceptions\CredentialMissing;
use App\Services\Meta\Exceptions\CredentialRevoked;
use App\Services\Meta\Exceptions\GraphApiException;
use App\Services\Meta\GraphClient;
use App\Support\Facades\Teams;
use Tests\Fakes\FakeGraphClient;

/**
 * The token vault circuit breaker (docs/modules/m0-onboarding.md §2):
 * 3 consecutive 190/401 → revoked_at, typed exception, reconnect prompt.
 */
beforeEach(function () {
    $user = User::factory()->withTeam()->create();
    Teams::initialize($user->team);
});

test('the resolver returns a working business client and stamps last_used_at', function () {
    $credential = MetaCredential::factory()->create(['waba_account_id' => null, 'last_used_at' => null]);

    $client = app(CredentialResolver::class)->businessClient();

    expect($client->get('102290129340398'))->toBeArray()
        ->and($credential->fresh()->last_used_at)->not->toBeNull();
});

test('a missing credential throws a typed exception, never a 500-shaped one', function () {
    expect(fn () => app(CredentialResolver::class)->businessClient())
        ->toThrow(CredentialMissing::class);
});

test('a revoked credential throws CredentialRevoked at resolution time', function () {
    MetaCredential::factory()->revoked()->create(['waba_account_id' => null]);

    expect(fn () => app(CredentialResolver::class)->businessClient())
        ->toThrow(CredentialRevoked::class);
});

test('the breaker trips at the third consecutive 190 and revokes the credential', function () {
    $credential = MetaCredential::factory()->create(['waba_account_id' => null]);

    /** @var FakeGraphClient $fake */
    $fake = app(GraphClient::class);
    $client = app(CredentialResolver::class)->businessClient();

    foreach ([1, 2] as $consecutiveFailures) {
        $fake->failWith(190);

        expect(fn () => $client->get('102290129340398'))->toThrow(GraphApiException::class)
            ->and($credential->fresh()->failure_count)->toBe($consecutiveFailures)
            ->and($credential->fresh()->revoked_at)->toBeNull();
    }

    $fake->failWith(190);

    expect(fn () => $client->get('102290129340398'))->toThrow(CredentialRevoked::class)
        ->and($credential->fresh()->failure_count)->toBe(3)
        ->and($credential->fresh()->revoked_at)->not->toBeNull();

    // Once tripped, the breaker refuses further calls with the typed
    // exception — the UI renders a reconnect prompt, never a 500.
    expect(fn () => $client->get('102290129340398'))->toThrow(CredentialRevoked::class);
});

test('a success resets the consecutive failure count', function () {
    $credential = MetaCredential::factory()->create(['waba_account_id' => null, 'failure_count' => 2]);

    /** @var FakeGraphClient $fake */
    $fake = app(GraphClient::class);
    $client = app(CredentialResolver::class)->businessClient();

    $client->get('102290129340398');

    expect($credential->fresh()->failure_count)->toBe(0);

    // 190s must be CONSECUTIVE to trip — after the reset this is failure #1.
    $fake->failWith(190);
    expect(fn () => $client->get('102290129340398'))->toThrow(GraphApiException::class)
        ->and($credential->fresh()->failure_count)->toBe(1)
        ->and($credential->fresh()->revoked_at)->toBeNull();
});

test('non-auth graph errors never move the breaker', function () {
    $credential = MetaCredential::factory()->create(['waba_account_id' => null]);

    /** @var FakeGraphClient $fake */
    $fake = app(GraphClient::class);
    $client = app(CredentialResolver::class)->businessClient();

    foreach ([131056, 130429, 133010] as $code) {
        $fake->failWith($code);
        expect(fn () => $client->get('102290129340398'))->toThrow(GraphApiException::class);
    }

    expect($credential->fresh()->failure_count)->toBe(0)
        ->and($credential->fresh()->revoked_at)->toBeNull();
});
