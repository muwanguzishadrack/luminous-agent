<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Model events must stay enabled (no WithoutModelEvents): BelongsToTenant
     * fills tenant_id from the tenancy context in a `creating` hook, and
     * Postgres RLS rejects tenant-scoped rows without it.
     */
    public function run(): void
    {
        $this->call(DemoTenantSeeder::class);
    }
}
