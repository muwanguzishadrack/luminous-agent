<?php

namespace App\Actions\WhatsApp;

use App\Enums\ActorType;
use App\Exceptions\CoexistenceDeregisterNotPermitted;
use App\Models\MetaCredential;
use App\Models\PhoneNumber;
use App\Models\User;
use App\Models\WabaAccount;
use App\Services\Meta\CredentialResolver;
use App\Support\AuditLog;
use Illuminate\Support\Facades\DB;

/**
 * Deregisters the team's number and clears the connection
 * (docs/modules/m0-onboarding.md §7, docs/reference/whatsapp-cloud-api.md §5).
 *
 * A Coexistence number (`is_on_biz_app: true`) cannot be deregistered through
 * the API at all — the refusal is raised here as well as branched in the UI,
 * so no code path can send the call Meta will reject.
 */
class DisconnectWhatsApp
{
    public function __construct(private readonly CredentialResolver $credentials) {}

    /**
     * @throws CoexistenceDeregisterNotPermitted
     */
    public function handle(WabaAccount $account, PhoneNumber $number, ?User $actor = null): void
    {
        if ($number->is_on_biz_app) {
            throw new CoexistenceDeregisterNotPermitted($number->display_phone_number);
        }

        $this->credentials->businessClient()->deregister($number->phone_number_id);

        $context = [
            'phone_number_id' => $number->phone_number_id,
            'display_phone_number' => $number->display_phone_number,
            'waba_id' => $account->waba_id,
        ];

        DB::transaction(function () use ($account, $number): void {
            // The vaulted business token must not outlive the connection it
            // was issued for — the rows go, never just the flag.
            MetaCredential::query()->where('waba_account_id', $account->id)->delete();

            $number->delete();
            $account->delete();
        });

        AuditLog::record(
            'whatsapp.disconnected',
            $actor === null ? ActorType::System : ActorType::User,
            (string) $actor?->id,
            context: $context,
        );
    }
}
