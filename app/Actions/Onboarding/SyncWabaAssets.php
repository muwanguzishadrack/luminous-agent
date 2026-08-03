<?php

namespace App\Actions\Onboarding;

use App\Enums\MetaCredentialType;
use App\Models\MetaCredential;
use App\Models\OnboardingSession;
use App\Models\PhoneNumber;
use App\Models\WabaAccount;
use App\Services\Meta\CredentialResolver;
use App\Support\AuditLog;

/**
 * Step 4 — pull the WABA and its numbers into waba_accounts/phone_numbers,
 * and perform the one-time (irreversible) template-analytics opt-in
 * (docs/modules/m0-onboarding.md §1 step 4,
 * docs/reference/whatsapp-cloud-api.md §5–§6).
 */
class SyncWabaAssets extends OnboardingStep
{
    public function __construct(private readonly CredentialResolver $credentials) {}

    public function runsAt(): string
    {
        return OnboardingStatus::SYNCING;
    }

    public function handle(OnboardingSession $session, ?string $code = null): void
    {
        $this->assertFinishPayload($session);

        $client = $this->credentials->businessClient();
        $wabaId = (string) $session->waba_id;

        $waba = $client->get($wabaId, [
            'fields' => 'name,currency,timezone_id,account_review_status,business_verification_status,owner_business_info',
        ]);

        $account = WabaAccount::query()->where('waba_id', $wabaId)->first();
        $ownerBusiness = (array) ($waba['owner_business_info'] ?? []);

        $attributes = [
            'owner_business_id' => (string) ($ownerBusiness['id'] ?? $account->owner_business_id ?? ''),
            'solution_id' => $account->solution_id ?? config('meta.solution_id'),
            'name' => (string) ($waba['name'] ?? $wabaId),
            'timezone_id' => (string) ($waba['timezone_id'] ?? 'UTC'),
            'currency' => (string) ($waba['currency'] ?? 'USD'),
            'review_status' => (string) ($waba['account_review_status'] ?? 'PENDING'),
            'account_status' => 'ACTIVE',
            'business_verification_status' => (string) ($waba['business_verification_status'] ?? 'unknown'),
            'is_subscribed' => true, // step 3 verified it
        ];

        if ($account === null) {
            // One-time template analytics opt-in — irreversible, required
            // before M8 can pull template_analytics (reference §6). Done
            // before the local row exists so a failed create still retries
            // it; posting true twice is harmless.
            $client->post($wabaId, ['is_enabled_for_insights' => true]);

            $account = WabaAccount::query()->create(
                $attributes + ['waba_id' => $wabaId, 'onboarded_at' => now()],
            );
        } else {
            $account->update($attributes);
        }

        // Scope the vaulted business token to the WABA it was issued for.
        $alreadyScoped = MetaCredential::query()
            ->where('type', MetaCredentialType::Business)
            ->where('waba_account_id', $account->id)
            ->whereNull('revoked_at')
            ->exists();

        if (! $alreadyScoped) {
            MetaCredential::query()
                ->where('type', MetaCredentialType::Business)
                ->whereNull('waba_account_id')
                ->whereNull('revoked_at')
                ->update(['waba_account_id' => $account->id]);
        }

        $numbers = $client->get("{$wabaId}/phone_numbers", [
            'fields' => 'id,verified_name,display_phone_number,quality_rating,messaging_limit_tier,throughput,platform_type,is_on_biz_app,code_verification_status',
        ]);

        $synced = 0;

        foreach ((array) ($numbers['data'] ?? []) as $number) {
            $this->upsertPhoneNumber($session, $account, (array) $number);
            $synced++;
        }

        AuditLog::record('onboarding.assets_synced', subject: $session, context: [
            'waba_id' => $wabaId,
            'phone_numbers' => $synced,
        ]);
    }

    /**
     * @param  array<int|string, mixed>  $number
     */
    private function upsertPhoneNumber(OnboardingSession $session, WabaAccount $account, array $number): void
    {
        $phoneNumberId = (string) ($number['id'] ?? '');

        if ($phoneNumberId === '') {
            return;
        }

        $throughput = (array) ($number['throughput'] ?? []);

        // If the number exists under ANOTHER tenant, the scope hides it and
        // the create below hits the phone_number_id unique index — the step
        // fails rather than silently moving the number (docs/m0 §8).
        $phone = PhoneNumber::query()->firstOrNew(['phone_number_id' => $phoneNumberId]);

        if (! $phone->exists) {
            // RegisterPhoneNumber ran with a generated PIN earlier in this
            // chain, unless this is a Coexistence session — then the PIN
            // state on the handset is unknown to us.
            $registeredByUs = $phoneNumberId === $session->phone_number_id
                && $session->feature_type !== OnboardingStatus::COEXISTENCE_FEATURE;

            $phone->forceFill([
                'profile' => [],
                'pin_set' => $registeredByUs,
                'registered_at' => $registeredByUs ? now() : null,
            ]);
        }

        $phone->fill([
            'waba_account_id' => $account->id,
            'display_phone_number' => (string) ($number['display_phone_number'] ?? ''),
            'verified_name' => (string) ($number['verified_name'] ?? ''),
            'code_verification_status' => (string) ($number['code_verification_status'] ?? 'NOT_VERIFIED'),
            'quality_rating' => (string) ($number['quality_rating'] ?? 'UNKNOWN'),
            'messaging_limit_tier' => (string) ($number['messaging_limit_tier'] ?? 'TIER_250'),
            'throughput_level' => (string) ($throughput['level'] ?? 'STANDARD'),
            'platform_type' => (string) ($number['platform_type'] ?? 'CLOUD_API'),
            'is_on_biz_app' => (bool) ($number['is_on_biz_app'] ?? false),
            'status' => 'active',
        ])->save();
    }
}
