<?php

namespace Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * RefreshDatabase, but migrations run on the BYPASSRLS migrator connection —
 * exactly like dev and production (docs/03 §2). The runtime role the tests
 * connect with never owns the tables, so FORCE RLS stays binding and
 * SECURITY DEFINER helpers (resolve_webhook_team) genuinely bypass.
 *
 * A composing trait is required: Pest applies traits to the generated child
 * class, where they shadow any TestCase method of the same name.
 */
trait RefreshesTestDatabase
{
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    protected function migrateFreshUsing()
    {
        $seeder = $this->seeder();

        return array_merge(
            [
                '--database' => 'pgsql_migrator',
                '--drop-views' => $this->shouldDropViews(),
                '--drop-types' => $this->shouldDropTypes(),
            ],
            $seeder ? ['--seeder' => $seeder] : ['--seed' => $this->shouldSeed()],
        );
    }
}
