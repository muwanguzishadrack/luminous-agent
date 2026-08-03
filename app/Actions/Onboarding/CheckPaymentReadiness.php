<?php

namespace App\Actions\Onboarding;

use App\Models\OnboardingSession;
use App\Models\WabaAccount;
use App\Services\Meta\CredentialResolver;
use App\Services\Meta\Exceptions\GraphApiException;
use App\Support\AuditLog;

/**
 * Step 6 — Tech Provider: the client must attach their own payment method
 * (docs/modules/m0-onboarding.md §1 step 6, §4). Meta exposes no
 * first-class "payment ready" read, so probe the WABA and default to false
 * when unknown; payment_configuration_update webhooks flip it later.
 */
class CheckPaymentReadiness extends OnboardingStep
{
    public function __construct(private readonly CredentialResolver $credentials) {}

    public function runsAt(): string
    {
        return OnboardingStatus::SYNCING;
    }

    public function handle(OnboardingSession $session, OnboardingInput $input): void
    {
        $this->assertFinishPayload($session);

        $account = WabaAccount::query()->where('waba_id', $session->waba_id)->firstOrFail();

        try {
            $waba = $this->credentials->businessClient()->get((string) $session->waba_id, [
                'fields' => 'is_payment_enabled',
            ]);

            $ready = (bool) ($waba['is_payment_enabled'] ?? false);
        } catch (GraphApiException) {
            // Unknown ⇒ not ready. Sends stay blocked until 131042 clears
            // or the payment_configuration_update webhook says otherwise.
            $ready = false;
        }

        $account->update(['payment_ready' => $ready]);

        AuditLog::record('onboarding.payment_readiness_checked', subject: $session, context: [
            'waba_id' => $session->waba_id,
            'payment_ready' => $ready,
        ]);
    }
}
