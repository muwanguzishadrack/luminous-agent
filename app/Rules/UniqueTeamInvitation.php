<?php

namespace App\Rules;

use App\Models\Team;
use App\Models\TeamInvitation;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\DB;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * An invitation must be answerable. A person who already belongs to a team
 * cannot join another (D-020), so inviting them is refused at the point the
 * invitation is written rather than at the point it is accepted.
 */
class UniqueTeamInvitation implements ValidationRule
{
    public function __construct(protected Team $team)
    {
        //
    }

    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $email = strtolower($value);

        $isMember = $this->team->members()
            ->whereRaw('LOWER(email) = ?', [$email])
            ->exists();

        if ($isMember) {
            $fail(__('This user is already a member of the team.'));

            return;
        }

        // Cross-team by nature: their team is not ours to read, so ask the
        // SECURITY DEFINER function for the single bit we are entitled to.
        $belongsElsewhere = (bool) DB::scalar('SELECT email_belongs_to_a_team(?)', [$email]);

        if ($belongsElsewhere) {
            $fail(__('This person already belongs to another team. A person can only belong to one team — they need a separate login for yours.'));

            return;
        }

        $hasPendingInvitation = TeamInvitation::where('team_id', $this->team->id)
            ->whereRaw('LOWER(email) = ?', [$email])
            ->whereNull('accepted_at')
            ->where(function ($query) {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->exists();

        if ($hasPendingInvitation) {
            $fail(__('An invitation has already been sent to this email address.'));
        }
    }
}
