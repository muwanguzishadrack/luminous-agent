<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Model events must stay enabled (no WithoutModelEvents): BelongsToTeam
     * fills team_id from the team context in a `creating` hook, and
     * Postgres RLS rejects team-scoped rows without it.
     */
    public function run(): void
    {
        $this->call(DemoTeamSeeder::class);
    }
}
