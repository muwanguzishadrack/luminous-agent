<?php

namespace App\Actions\Onboarding;

use App\Enums\MetaCredentialType;
use App\Models\MetaCredential;
use App\Models\OnboardingSession;
use App\Models\PhoneNumber;
use App\Models\WabaAccount;
use App\Services\Meta\CredentialResolver;
use App\Support\AuditLog;
use RuntimeException;

/**
 * Step 4 — pull the WABA and the one number the client onboarded into
 * waba_accounts/phone_numbers, and perform the one-time (irreversible)
 * template-analytics opt-in (docs/modules/m0-onboarding.md §1 step 4,
 * docs/reference/whatsapp-cloud-api.md §5–§6).
 *
 * A team holds one WABA and one number (D-020). Meta's WABA may carry several
 * numbers; we bind exactly the one named by the ES FINISH payload and record
 * the rest as ignored rather than importing them.
 */
class SyncWabaAssets extends OnboardingStep
{
    public function __construct(private readonly CredentialResolver $credentials) {}

    public function runsAt(): string
    {
        return OnboardingStatus::SYNCING;
    }

    public function handle(OnboardingSession $session, OnboardingInput $input): void
    {
        $this->assertFinishPayload($session);

        $client = $this->credentials->businessClient();
        $wabaId = (string) $session->waba_id;

        $waba = $client->get($wabaId, [
            'fields' => 'name,currency,timezone_id,account_review_status,business_verification_status,owner_business_info,whatsapp_business_manager_messaging_limit',
        ]);

        $account = WabaAccount::query()->first();

        if ($account !== null && $account->waba_id !== $wabaId) {
            // Defence in depth behind the guard in ExchangeSignupCode and the
            // unique index on waba_accounts.team_id.
            throw new RuntimeException(
                "This workspace already holds WhatsApp Business Account {$account->waba_id}; refusing to bind {$wabaId} as well.",
            );
        }

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
            // The messaging limit is a property of the business portfolio, not
            // the number. Its per-number predecessor, messaging_limit_tier, was
            // deprecated by Meta on 2026-05-21 and returns nothing on v24.0+.
            'portfolio_messaging_limit' => isset($waba['whatsapp_business_manager_messaging_limit'])
                ? (string) $waba['whatsapp_business_manager_messaging_limit']
                : null,
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
            'fields' => 'id,verified_name,display_phone_number,quality_rating,throughput,platform_type,is_on_biz_app,code_verification_status,name_status',
        ]);

        $onboarded = (string) $session->phone_number_id;

        /** @var list<array<int|string, mixed>> $returned */
        $returned = array_map(fn ($number): array => (array) $number, (array) ($numbers['data'] ?? []));

        $number = collect($returned)
            ->first(fn (array $number): bool => (string) ($number['id'] ?? '') === $onboarded);

        if ($number === null) {
            throw new RuntimeException(
                "Number {$onboarded} from the Embedded Signup FINISH payload is absent from {$wabaId}/phone_numbers — refusing to bind a different number.",
            );
        }

        $this->upsertPhoneNumber($session, $account, $number);

        $ignored = collect($returned)
            ->map(fn (array $number): string => (string) ($number['id'] ?? ''))
            ->reject(fn (string $id): bool => $id === '' || $id === $onboarded)
            ->values()
            ->all();

        AuditLog::record('onboarding.assets_synced', subject: $session, context: [
            'waba_id' => $wabaId,
            'phone_number_id' => $onboarded,
            // A team holds one number (D-020). Any other number on the WABA is
            // left alone — recorded here so the choice is auditable, not silent.
            'ignored_phone_number_ids' => $ignored,
        ]);
    }

    /**
     * @param  array<int|string, mixed>  $number
     */
    private function upsertPhoneNumber(OnboardingSession $session, WabaAccount $account, array $number): void
    {
        $phoneNumberId = (string) $number['id'];
        $throughput = (array) ($number['throughput'] ?? []);

        // If the number exists under ANOTHER team, the scope hides it and
        // the create below hits the phone_number_id unique index — the step
        // fails rather than silently moving the number (docs/m0 §8).
        $phone = PhoneNumber::query()->firstOrNew(['phone_number_id' => $phoneNumberId]);

        if (! $phone->exists) {
            // RegisterPhoneNumber ran with a generated PIN earlier in this
            // chain, unless this is a Coexistence session — then the PIN
            // state on the handset is unknown to us.
            $registeredByUs = $session->feature_type !== OnboardingStatus::COEXISTENCE_FEATURE;

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
            // UNVERIFIED, not NOT_VERIFIED: Meta returns exactly two values
            // for this field and inventing a third would leak into the UI.
            'code_verification_status' => (string) ($number['code_verification_status'] ?? 'UNVERIFIED'),
            'quality_rating' => (string) ($number['quality_rating'] ?? 'UNKNOWN'),
            'name_status' => (string) ($number['name_status'] ?? 'NONE'),
            'throughput_level' => (string) ($throughput['level'] ?? 'STANDARD'),
            'platform_type' => (string) ($number['platform_type'] ?? 'CLOUD_API'),
            'is_on_biz_app' => (bool) ($number['is_on_biz_app'] ?? false),
            'status' => 'active',
        ])->save();
    }
}
