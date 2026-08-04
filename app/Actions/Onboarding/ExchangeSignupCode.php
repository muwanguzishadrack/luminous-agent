<?php

namespace App\Actions\Onboarding;

use App\Enums\MetaCredentialType;
use App\Models\MetaCredential;
use App\Models\OnboardingSession;
use App\Models\WabaAccount;
use App\Services\Meta\GraphClient;
use App\Support\AuditLog;
use RuntimeException;

/**
 * Step 1 — exchange the one-time ES code for a per-team business token
 * and vault it (docs/modules/m0-onboarding.md §1 step 1, §2).
 *
 * NEVER log or expose the code or the token — only token_last4 ever leaves
 * the vault (docs/m0 §9 criterion 6).
 */
class ExchangeSignupCode extends OnboardingStep
{
    public function __construct(private readonly GraphClient $client) {}

    public function runsAt(): string
    {
        return OnboardingStatus::FINISHED;
    }

    public function handle(OnboardingSession $session, OnboardingInput $input): void
    {
        $this->assertTeamHasNoOtherWaba($session);

        if ($session->code_exchanged_at !== null) {
            // Already exchanged on an earlier attempt — the code is spent;
            // just move the session forward.
            $this->advance($session, OnboardingStatus::EXCHANGED);

            return;
        }

        if ($input->code === null || $input->code === '') {
            throw new RuntimeException(
                'An Embedded Signup code is required — re-submit the FINISH payload to /onboarding/exchange.',
            );
        }

        $response = $this->client->exchangeCode($input->code);

        $token = (string) ($response['access_token'] ?? '');

        if ($token === '') {
            throw new RuntimeException('The Embedded Signup code exchange returned no access_token.');
        }

        // One active business credential per team: rotate the token in
        // place rather than inserting a competing row
        // (meta_credentials_active_unique).
        $credential = MetaCredential::query()
            ->where('type', MetaCredentialType::Business)
            ->whereNull('revoked_at')
            ->first() ?? new MetaCredential;

        $credential->fill([
            'type' => MetaCredentialType::Business,
            'token' => $token, // encrypted cast on the model
            'token_last4' => substr($token, -4),
            'scopes' => (array) config('meta.permissions'),
            'issued_at' => now(),
            'expires_at' => isset($response['expires_in']) ? now()->addSeconds((int) $response['expires_in']) : null,
            'failure_count' => 0,
        ])->save();

        $session->fill(['code_exchanged_at' => now()])->save();

        $this->advance($session, OnboardingStatus::EXCHANGED);

        AuditLog::record('onboarding.code_exchanged', subject: $session, context: [
            'credential_id' => $credential->id,
            'token_last4' => $credential->token_last4,
        ]);
    }

    /**
     * One WABA per team (D-020). A second Embedded Signup is refused before a
     * token is exchanged — never merged, never silently overwritten. Re-running
     * against the *same* WABA is a resume and stays allowed.
     *
     * @throws RuntimeException
     */
    private function assertTeamHasNoOtherWaba(OnboardingSession $session): void
    {
        $existing = WabaAccount::query()->first();

        if ($existing === null || $existing->waba_id === $session->waba_id) {
            return;
        }

        throw new RuntimeException(
            "This workspace is already connected to WhatsApp Business Account {$existing->waba_id}. ".
            'A workspace holds one WhatsApp Business Account and one number — disconnect the current one, '.
            'or use a separate login for the other business.',
        );
    }
}
