<?php

namespace Database\Factories;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use App\Support\Facades\Teams;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * A user belongs to at most one team (D-020), so team placement is always
 * explicit here: `withTeam()` mirrors registration (their own team, as owner),
 * `memberOf()` puts them on somebody else's. A bare `create()` leaves the user
 * without a team — the state a removed member is left in.
 *
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ];
    }

    /**
     * Give the user their own team, as owner — what registration does.
     */
    public function withTeam(?string $name = null): static
    {
        return $this->afterCreating(function (User $user) use ($name) {
            $team = Team::factory()->create(['name' => $name ?? $user->name."'s Team"]);

            $this->join($user, $team, TeamRole::Owner);
        });
    }

    /**
     * Place the user on an existing team in the given role.
     */
    public function memberOf(Team $team, TeamRole $role = TeamRole::Agent): static
    {
        return $this->afterCreating(fn (User $user) => $this->join($user, $team, $role));
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Indicate that the model has two-factor authentication configured.
     */
    public function withTwoFactor(): static
    {
        return $this->state(fn (array $attributes) => [
            'two_factor_secret' => encrypt('secret'),
            'two_factor_recovery_codes' => encrypt(json_encode(['recovery-code-1'])),
            'two_factor_confirmed_at' => now(),
        ]);
    }

    /**
     * Write the single membership row. RLS requires the team's context first.
     */
    private function join(User $user, Team $team, TeamRole $role): void
    {
        Teams::initialize($team);

        $team->memberships()->create([
            'user_id' => $user->id,
            'role' => $role,
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $user->setRelation('team', $team);
    }
}
