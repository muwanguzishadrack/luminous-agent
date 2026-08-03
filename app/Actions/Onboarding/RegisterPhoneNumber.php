<?php

namespace App\Actions\Onboarding;

use App\Models\OnboardingSession;
use App\Models\PhoneNumber;
use App\Services\Meta\CredentialResolver;
use App\Support\AuditLog;

/**
 * Step 2 — register the number for Cloud API messaging with a generated
 * two-step-verification PIN (docs/modules/m0-onboarding.md §1 step 2).
 *
 * Skipped entirely for Coexistence sessions: the number is already
 * registered on the WhatsApp Business app (docs/m0 §3). A 133010 failure is
 * recorded verbatim by the chain so the UI can offer the
 * request-code/verify-code flow (docs/m0 §8).
 */
class RegisterPhoneNumber extends OnboardingStep
{
    public function __construct(private readonly CredentialResolver $credentials) {}

    public function runsAt(): string
    {
        return OnboardingStatus::EXCHANGED;
    }

    public function handle(OnboardingSession $session, OnboardingInput $input): void
    {
        $this->assertFinishPayload($session);

        if ($session->feature_type === OnboardingStatus::COEXISTENCE_FEATURE) {
            $this->advance($session, OnboardingStatus::REGISTERED);

            AuditLog::record('onboarding.phone_registration_skipped', subject: $session, context: [
                'phone_number_id' => $session->phone_number_id,
                'reason' => 'coexistence_already_registered',
            ]);

            return;
        }

        // Meta requires the number's EXISTING two-step-verification PIN when
        // one is already set; a generated PIN is only valid for a number
        // without 2FA, and otherwise fails with 133005. The client supplies
        // theirs through the UI on retry. Never persisted, never logged.
        $pin = $input->pin ?? (string) random_int(100000, 999999);

        $this->credentials->businessClient()->post("{$session->phone_number_id}/register", [
            'messaging_product' => 'whatsapp',
            'pin' => $pin,
        ]);

        // The phone_numbers row usually does not exist yet — SyncWabaAssets
        // creates it next and persists pin_set there. Update in place when
        // re-registering after a partial sync.
        PhoneNumber::query()->where('phone_number_id', $session->phone_number_id)->update([
            'pin_set' => true,
            'registered_at' => now(),
        ]);

        $this->advance($session, OnboardingStatus::REGISTERED);

        AuditLog::record('onboarding.phone_registered', subject: $session, context: [
            'phone_number_id' => $session->phone_number_id,
            'pin_set' => true, // the PIN value itself is never persisted or logged
            'pin_source' => $input->pin !== null ? 'client_supplied' : 'generated',
        ]);
    }
}
