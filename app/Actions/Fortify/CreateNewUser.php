<?php

namespace App\Actions\Fortify;

use App\Actions\Teams\AcceptTeamInvitation;
use App\Actions\Teams\CreateTeam;
use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\CreatesNewUsers;

/**
 * Registration is the one moment a team is created (D-020). A registration
 * that answers a valid invitation joins that team instead — otherwise an
 * invited person would land on a team of their own and could never accept.
 */
class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    public function __construct(
        private CreateTeam $createTeam,
        private AcceptTeamInvitation $acceptTeamInvitation,
    ) {
        //
    }

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        Validator::make($input, [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
            'invitation' => ['nullable', 'string'],
        ])->validate();

        return DB::transaction(function () use ($input) {
            $user = User::create([
                'name' => $input['name'],
                'email' => $input['email'],
                'password' => $input['password'],
            ]);

            $invitation = $this->pendingInvitationFor($user, $input['invitation'] ?? null);

            if ($invitation !== null) {
                $this->acceptTeamInvitation->handle($user, $invitation);

                return $user;
            }

            $this->createTeam->handle($user, $user->name."'s Team");

            return $user;
        });
    }

    /**
     * The pending invitation this registration answers, if any. An invitation
     * addressed to somebody else is ignored, not honoured.
     */
    private function pendingInvitationFor(User $user, ?string $code): ?TeamInvitation
    {
        if ($code === null || $code === '') {
            return null;
        }

        return TeamInvitation::query()
            ->where('code', $code)
            ->whereRaw('LOWER(email) = ?', [strtolower($user->email)])
            ->whereNull('accepted_at')
            ->where(fn ($query) => $query
                ->whereNull('expires_at')
                ->orWhere('expires_at', '>', now()))
            ->first();
    }
}
