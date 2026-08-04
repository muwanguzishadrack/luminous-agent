<?php

namespace App\Actions\WhatsApp;

use App\Enums\ActorType;
use App\Models\PhoneNumber;
use App\Models\User;
use App\Models\WabaAccount;
use App\Services\Meta\CredentialResolver;
use App\Support\AuditLog;

/**
 * Re-reads the connected-account panel from Graph and persists it
 * (docs/modules/m0-onboarding.md §7). Two calls: the number node and the WABA
 * node. Field names and value sets are fixed by
 * docs/reference/whatsapp-cloud-api.md §5.
 */
class RefreshWhatsAppConnection
{
    /**
     * Everything the connected-account panel renders. `code_verification_status`
     * and `name_status` are both requested because they are distinct fields:
     * two-step verification state and display-name review state.
     */
    public const NUMBER_FIELDS = 'display_phone_number,verified_name,quality_rating,code_verification_status,name_status,throughput,platform_type,is_on_biz_app';

    /**
     * The messaging limit belongs to the business portfolio and is read from
     * the WABA node. Its per-number predecessor, messaging_limit_tier, was
     * deprecated by Meta on 2026-05-21 and returns nothing on v24.0+.
     */
    public const WABA_FIELDS = 'name,account_review_status,business_verification_status,currency,timezone_id,whatsapp_business_manager_messaging_limit';

    public function __construct(
        private readonly CredentialResolver $credentials,
        private readonly ReadBusinessProfile $profile,
    ) {}

    public function handle(WabaAccount $account, PhoneNumber $number, ?User $actor = null): void
    {
        $client = $this->credentials->businessClient();

        $remote = $client->get($number->phone_number_id, ['fields' => self::NUMBER_FIELDS]);
        $waba = $client->get($account->waba_id, ['fields' => self::WABA_FIELDS]);

        $throughput = (array) ($remote['throughput'] ?? []);

        $attributes = array_filter([
            'display_phone_number' => $this->string($remote, 'display_phone_number'),
            'verified_name' => $this->string($remote, 'verified_name'),
            'quality_rating' => $this->string($remote, 'quality_rating'),
            'code_verification_status' => $this->string($remote, 'code_verification_status'),
            'name_status' => $this->string($remote, 'name_status'),
            'throughput_level' => isset($throughput['level']) ? (string) $throughput['level'] : null,
            'platform_type' => $this->string($remote, 'platform_type'),
        ], fn (?string $value): bool => $value !== null);

        if (array_key_exists('is_on_biz_app', $remote)) {
            $attributes['is_on_biz_app'] = (bool) $remote['is_on_biz_app'];
        }

        $number->fill([...$attributes, 'last_synced_at' => now()])->save();

        $account->fill(array_filter([
            'name' => $this->string($waba, 'name'),
            'review_status' => $this->string($waba, 'account_review_status'),
            'business_verification_status' => $this->string($waba, 'business_verification_status'),
            'timezone_id' => $this->string($waba, 'timezone_id'),
            'currency' => $this->string($waba, 'currency'),
            'portfolio_messaging_limit' => $this->string($waba, 'whatsapp_business_manager_messaging_limit'),
        ], fn (?string $value): bool => $value !== null))->save();

        // One button, one expectation: the business profile is part of what
        // the screen shows, so Refresh re-reads it too rather than leaving the
        // form displaying a stale mirror.
        $this->profile->persist($number, $client->businessProfile($number->phone_number_id));

        AuditLog::record(
            'whatsapp.connection_refreshed',
            $actor === null ? ActorType::System : ActorType::User,
            (string) $actor?->id,
            $number,
            [
                'phone_number_id' => $number->phone_number_id,
                'waba_id' => $account->waba_id,
                'quality_rating' => $number->quality_rating,
                'name_status' => $number->name_status,
                'code_verification_status' => $number->code_verification_status,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function string(array $payload, string $key): ?string
    {
        return array_key_exists($key, $payload) ? (string) $payload[$key] : null;
    }
}
