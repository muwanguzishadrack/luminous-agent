<?php

use App\Enums\TeamRole;
use App\Models\PhoneNumber;
use App\Models\Team;
use App\Models\User;
use App\Models\WabaAccount;
use App\Support\Facades\Teams;
use Database\Seeders\DemoTeamSeeder;
use Illuminate\Support\Facades\DB;

/**
 * Phase 0 deliverable 0.7 (docs/90-roadmap.md). The seed has to satisfy the
 * one-team / one-WABA / one-number constraints rather than sidestep them
 * (D-020), so running it end to end is the proof.
 */
test('the demo seeder produces exactly one team with five members, one WABA and one number', function () {
    $this->seed(DemoTeamSeeder::class);

    expect(Team::query()->count())->toBe(1);

    $team = Team::query()->sole();
    Teams::initialize($team);

    expect($team->slug)->toBe('demo')
        ->and($team->status)->toBe('active');

    $roles = DB::table('team_user')
        ->where('team_id', $team->id)
        ->pluck('role')
        ->sort()
        ->values()
        ->all();

    expect($roles)->toEqualCanonicalizing(array_column(TeamRole::cases(), 'value'))
        ->and(DB::table('team_user')->count())->toBe(5)
        ->and(User::query()->count())->toBe(5);

    expect(WabaAccount::query()->count())->toBe(1)
        ->and(PhoneNumber::query()->count())->toBe(1)
        ->and(PhoneNumber::query()->sole()->waba_account_id)->toBe(WabaAccount::query()->sole()->id);

    // The fixture data the demo UI depends on is still there.
    expect(DB::table('contacts')->count())->toBe(2000)
        ->and(DB::table('conversations')->count())->toBe(30)
        ->and(DB::table('templates')->count())->toBe(12)
        ->and(DB::table('campaigns')->count())->toBe(3)
        ->and(DB::table('messages')->count())->toBeGreaterThan(0);
});
