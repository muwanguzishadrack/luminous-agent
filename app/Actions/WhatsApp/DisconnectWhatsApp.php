<?php

namespace App\Actions\WhatsApp;

use App\Enums\ActorType;
use App\Enums\MetaCredentialType;
use App\Exceptions\CoexistenceDeregisterNotPermitted;
use App\Models\MetaCredential;
use App\Models\OnboardingSession;
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

        DB::transaction(function () use ($account, $number, $actor, $context): void {
            // Every business credential the team holds, not just the ones
            // linked to this WABA: a revoked token from an earlier, failed
            // connection attempt has a null waba_account_id and would
            // otherwise survive the disconnect. The rows go, never just the
            // flag — a vaulted token must not outlive its connection.
            MetaCredential::query()
                ->where('type', MetaCredentialType::Business)
                ->delete();

            $number->delete();
            $account->delete();

            // The signup sessions go too. They describe a connection that no
            // longer exists, and the newest one saying "complete" made the
            // launcher report the team as connected with nothing behind it —
            // and would have offered to resume a finished flow. The audit log
            // keeps the history.
            //
            // Scoped by hand: OnboardingSession carries no BelongsToTeam
            // trait, because webhook-time inserts land before team context
            // exists. An unscoped delete would take other teams' sessions and
            // the platform-level team_id IS NULL rows with it.
            OnboardingSession::query()
                ->where('team_id', $account->team_id)
                ->delete();

            // Inside the transaction on purpose. Auditing a destructive
            // action is not optional, so a failure here must take the
            // disconnect with it rather than leave the connection torn down
            // with no record of who did it.
            AuditLog::record(
                'whatsapp.disconnected',
                $actor === null ? ActorType::System : ActorType::User,
                (string) $actor?->id,
                context: $context,
            );
        });
    }
}
