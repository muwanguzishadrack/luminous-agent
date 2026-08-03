<?php

namespace App\Actions\Onboarding;

use App\Models\OnboardingSession;
use App\Services\Meta\Exceptions\GraphApiException;
use App\Support\AuditLog;
use Throwable;

/**
 * Orchestrates the seven Embedded Signup v4 server steps in order from the
 * session's current status (docs/modules/m0-onboarding.md §1). On failure
 * the verbatim error is recorded in onboarding_sessions.failure and the
 * chain stops — /onboarding/resume continues from the failed step, so a
 * half-finished onboarding is resumed rather than restarted.
 */
class RunOnboardingChain
{
    /**
     * @var list<class-string<OnboardingStep>>
     */
    public const STEPS = [
        ExchangeSignupCode::class,
        RegisterPhoneNumber::class,
        SubscribeWabaWebhooks::class,
        SyncWabaAssets::class,
        SyncTemplates::class,
        CheckPaymentReadiness::class,
        CompleteOnboarding::class,
    ];

    public function handle(OnboardingSession $session, ?OnboardingInput $input = null): OnboardingSession
    {
        if ($session->status === OnboardingStatus::COMPLETE) {
            return $session;
        }

        if ($session->status === OnboardingStatus::FAILED) {
            // Rewind to the status the failed step ran at and try again.
            $failure = $session->failure ?? [];

            $session->fill([
                'status' => (string) ($failure['at'] ?? OnboardingStatus::FINISHED),
                'failure' => null,
            ])->save();
        }

        $input ??= new OnboardingInput;

        foreach (self::STEPS as $stepClass) {
            $step = app($stepClass);

            if (! $step->shouldRun($session)) {
                continue;
            }

            try {
                $step->handle($session, $input);
            } catch (Throwable $e) {
                $this->recordFailure($session, $step, $e);

                return $session;
            }
        }

        return $session;
    }

    private function recordFailure(OnboardingSession $session, OnboardingStep $step, Throwable $e): void
    {
        $session->fill([
            'failure' => [
                'step' => $step->name(),
                'at' => $session->status,
                // The Graph error object is retained verbatim — never
                // discard the payload (docs/reference §8, docs/m0 §8).
                'error' => $e instanceof GraphApiException
                    ? $e->error
                    : ['message' => $e->getMessage(), 'exception' => $e::class],
            ],
            'status' => OnboardingStatus::FAILED,
        ])->save();

        AuditLog::record('onboarding.step_failed', subject: $session, context: [
            'step' => $step->name(),
        ]);
    }
}
