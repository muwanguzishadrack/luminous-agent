<?php

namespace App\Actions\Onboarding;

use App\Models\OnboardingSession;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * One server step of Embedded Signup v4 (docs/modules/m0-onboarding.md §1).
 * Every step is idempotent and independently retryable: it runs only at the
 * session status it advances the chain from, so a half-finished onboarding
 * resumes rather than restarts.
 */
abstract class OnboardingStep
{
    /**
     * The onboarding_sessions.status this step runs at.
     */
    abstract public function runsAt(): string;

    /**
     * Execute the step. $input carries the per-run secrets (ES code, 2FA PIN);
     * they are never persisted or logged.
     */
    abstract public function handle(OnboardingSession $session, OnboardingInput $input): void;

    public function shouldRun(OnboardingSession $session): bool
    {
        return $session->status === $this->runsAt();
    }

    /**
     * The step's snake_case name, recorded in onboarding_sessions.failure
     * and audit context.
     */
    public function name(): string
    {
        return Str::snake(class_basename(static::class));
    }

    protected function advance(OnboardingSession $session, string $status): void
    {
        $session->fill(['status' => $status])->save();
    }

    /**
     * Steps beyond the exchange need the asset ids captured from the ES
     * FINISH event.
     */
    protected function assertFinishPayload(OnboardingSession $session): void
    {
        if ($session->waba_id === null || $session->phone_number_id === null) {
            throw new RuntimeException(
                'The onboarding session is missing waba_id/phone_number_id from the ES FINISH event.',
            );
        }
    }
}
