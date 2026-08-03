<?php

namespace App\Actions\Onboarding;

use App\Models\OnboardingSession;
use App\Support\AuditLog;
use RuntimeException;

/**
 * Step 7 — the business is live: tenant status active, session complete,
 * audit entry (docs/modules/m0-onboarding.md §1 step 7).
 */
class CompleteOnboarding extends OnboardingStep
{
    public function runsAt(): string
    {
        return OnboardingStatus::SYNCING;
    }

    public function handle(OnboardingSession $session, ?string $code = null): void
    {
        $tenant = $session->tenant;

        if ($tenant === null) {
            throw new RuntimeException('The onboarding session has no tenant to activate.');
        }

        $tenant->update(['status' => 'active']);

        $this->advance($session, OnboardingStatus::COMPLETE);

        AuditLog::record('onboarding.completed', subject: $session, context: [
            'waba_id' => $session->waba_id,
            'phone_number_id' => $session->phone_number_id,
        ]);
    }
}
