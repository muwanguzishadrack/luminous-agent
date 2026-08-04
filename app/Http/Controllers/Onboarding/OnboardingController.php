<?php

namespace App\Http\Controllers\Onboarding;

use App\Actions\Onboarding\OnboardingInput;
use App\Actions\Onboarding\OnboardingStatus;
use App\Actions\Onboarding\RunOnboardingChain;
use App\Enums\ActorType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Onboarding\AppendOnboardingEventRequest;
use App\Http\Requests\Onboarding\ExchangeSignupCodeRequest;
use App\Models\OnboardingSession;
use App\Models\WabaAccount;
use App\Support\AuditLog;
use App\Support\Facades\Teams;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * The server side of Embedded Signup v4 (docs/modules/m0-onboarding.md §1).
 * JSON endpoints for the ES launcher: start a session, capture session
 * events, exchange the FINISH payload, and resume a half-finished chain.
 */
class OnboardingController extends Controller
{
    /**
     * Create an onboarding session with a fresh nonce tying the browser's
     * ES window to the later exchange.
     */
    public function start(Request $request): JsonResponse
    {
        $team = Teams::current();

        abort_if($team === null, 403, 'A team is required to start onboarding.');

        // One WABA per team (D-020): a second Embedded Signup has nothing to
        // connect to, so it is refused before the browser opens Meta's window.
        abort_if(
            WabaAccount::query()->exists(),
            409,
            'This workspace is already connected to WhatsApp. A workspace holds one WhatsApp Business Account and one number.',
        );

        $session = new OnboardingSession([
            'nonce' => Str::random(40),
            'es_version' => 'v4', // v2 is removed October 15, 2026 — v4 only
            'events' => [],
            'status' => OnboardingStatus::STARTED,
        ]);
        $session->team()->associate($team);
        $session->save();

        AuditLog::record(
            'onboarding.session_started',
            ActorType::User,
            (string) $request->user()?->id,
            $session,
        );

        return response()->json([
            'id' => $session->id,
            'nonce' => $session->nonce,
            'status' => $session->status,
        ], 201);
    }

    /**
     * Append a WA_EMBEDDED_SIGNUP session event — every event is captured
     * (docs/m0 §1). The Coexistence FINISH variant marks the session so
     * RegisterPhoneNumber knows to skip.
     */
    public function events(AppendOnboardingEventRequest $request): JsonResponse
    {
        $session = $this->sessionByNonce((string) $request->validated('nonce'));

        /** @var array<string, mixed> $event */
        $event = (array) $request->validated('event');

        $attributes = ['events' => [...$session->events, $event]];

        if (($event['event'] ?? null) === OnboardingStatus::COEXISTENCE_FINISH_EVENT) {
            $attributes['feature_type'] = OnboardingStatus::COEXISTENCE_FEATURE;
        }

        $session->fill($attributes)->save();

        return $this->state($session);
    }

    /**
     * The FINISH payload arrived: capture the asset ids and run the chain.
     * The one-time code passes straight through to the exchange step — it
     * is never persisted, logged, or echoed back.
     */
    public function exchange(ExchangeSignupCodeRequest $request, RunOnboardingChain $chain): JsonResponse
    {
        $session = $this->sessionByNonce((string) $request->validated('nonce'));

        $session->fill([
            'waba_id' => (string) $request->validated('waba_id'),
            'phone_number_id' => (string) $request->validated('phone_number_id'),
            'feature_type' => $request->validated('feature_type') ?? $session->feature_type,
        ]);

        if ($session->status === OnboardingStatus::STARTED) {
            $session->status = OnboardingStatus::FINISHED;
        }

        $session->save();

        return $this->state($chain->handle($session, new OnboardingInput(
            code: (string) $request->validated('code'),
            pin: $request->validated('pin'),
        )));
    }

    /**
     * Re-run the chain from the last incomplete step — resumable, never
     * restarted (docs/m0 §1, §8). Resolved by hand rather than implicit
     * binding: SubstituteBindings runs before EstablishTeamContext has
     * set the RLS session variable.
     */
    public function resume(Request $request, string $session, RunOnboardingChain $chain): JsonResponse
    {
        $session = OnboardingSession::query()
            ->whereKey($session)
            ->where('team_id', Teams::currentIdOrFail())
            ->firstOrFail();

        // A number that already has two-step verification requires ITS pin on
        // register (133005); the client supplies it here on retry.
        $pin = $request->validate(['pin' => ['nullable', 'digits:6']])['pin'] ?? null;

        return $this->state($chain->handle($session, new OnboardingInput(pin: $pin)));
    }

    private function sessionByNonce(string $nonce): OnboardingSession
    {
        return OnboardingSession::query()
            ->where('nonce', $nonce)
            ->where('team_id', Teams::currentIdOrFail())
            ->firstOrFail();
    }

    /**
     * Inertia-friendly session state: ids, status and the verbatim failure
     * for the UI to render — never a token, never the code (docs/m0 §9
     * criterion 6).
     */
    private function state(OnboardingSession $session): JsonResponse
    {
        return response()->json([
            'id' => $session->id,
            'status' => $session->status,
            'failure' => $session->failure,
        ]);
    }
}
