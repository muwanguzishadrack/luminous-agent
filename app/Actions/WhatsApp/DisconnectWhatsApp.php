<?php

namespace App\Actions\WhatsApp;

use App\Enums\ActorType;
use App\Enums\MetaCredentialType;
use App\Models\MetaCredential;
use App\Models\OnboardingSession;
use App\Models\PhoneNumber;
use App\Models\User;
use App\Models\WabaAccount;
use App\Services\Meta\CredentialResolver;
use App\Services\Meta\Exceptions\CredentialMissing;
use App\Services\Meta\Exceptions\CredentialRevoked;
use App\Services\Meta\Exceptions\GraphApiException;
use App\Support\AuditLog;
use Illuminate\Support\Facades\DB;

/**
 * Removes the team's WhatsApp connection from Luminous
 * (docs/modules/m0-onboarding.md §7).
 *
 * Deliberately reversible. `POST /{phone-number-id}/deregister` is never
 * called, so the number keeps working — the client can hand it to another
 * provider or reconnect here through Embedded Signup, with no re-registration
 * and none of deregister's costs: the 10-attempts-per-72h cap (`133016`), the
 * refusal after paid messages in the last 30 days, and the flat refusal for a
 * Coexistence number (`is_on_biz_app`), which used to leave those teams with
 * no way out at all.
 *
 * What it does call is `DELETE /{waba-id}/subscribed_apps`, which stops Meta
 * delivering that WABA's webhooks to us immediately. Without it we would go on
 * receiving messages for a team that no longer has anywhere to put them.
 */
class DisconnectWhatsApp
{
    public function __construct(private readonly CredentialResolver $credentials) {}

    /**
     * @return bool whether webhooks were unsubscribed; false means Meta may
     *              still deliver for this WABA and the client has to remove
     *              the app in Business Manager
     */
    public function handle(WabaAccount $account, PhoneNumber $number, ?User $actor = null): bool
    {
        $failure = $this->unsubscribe($account);

        $context = array_filter([
            'phone_number_id' => $number->phone_number_id,
            'display_phone_number' => $number->display_phone_number,
            'waba_id' => $account->waba_id,
            'webhooks_unsubscribed' => $failure === null,
            'unsubscribe_error' => $failure,
        ], fn (mixed $value): bool => $value !== null);

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

        return $failure === null;
    }

    /**
     * Best effort, and deliberately so. The usual reason to disconnect is a
     * connection that is already broken — a revoked token, an app the client
     * removed in Business Suite — and in exactly those cases the unsubscribe
     * call is the one that fails. Letting it block the disconnect would leave
     * the team holding a connection it cannot get rid of.
     *
     * @return string|null the failure reason, or null when Meta accepted it
     */
    private function unsubscribe(WabaAccount $account): ?string
    {
        try {
            $this->credentials->businessClient()->delete("{$account->waba_id}/subscribed_apps");

            return null;
        } catch (GraphApiException $e) {
            return $e->getMessage();
        } catch (CredentialRevoked) {
            return 'The business token has been revoked.';
        } catch (CredentialMissing) {
            return 'No business token is vaulted for this team.';
        }
    }
}
