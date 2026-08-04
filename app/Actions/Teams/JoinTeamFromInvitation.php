<?php

namespace App\Actions\Teams;

use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Create an account for an invitee and join them to the inviting team in one
 * step (docs/modules/m0-onboarding.md §7).
 *
 * The email is taken from the **invitation**, never from user input. The
 * generic registration form asks for an email and only honours the invitation
 * when it happens to match — so an invitee who typed a different address got a
 * team of their own instead, silently. That is the wrong-workspace failure
 * D-020 exists to prevent, so here the address is not a field at all.
 */
class JoinTeamFromInvitation
{
    public function __construct(private readonly AcceptTeamInvitation $accept) {}

    public function handle(TeamInvitation $invitation, string $name, string $password): User
    {
        return DB::transaction(function () use ($invitation, $name, $password) {
            $user = User::create([
                'name' => $name,
                'email' => $invitation->email,
                'password' => $password,
            ]);

            // Receiving the code proves control of the mailbox we sent it to,
            // which is exactly what verification establishes — a second link
            // would prove nothing further. Set outside the fillable list on
            // purpose: self-service registration must never reach this.
            $user->forceFill(['email_verified_at' => now()])->save();

            $this->accept->handle($user, $invitation);

            return $user;
        });
    }
}
