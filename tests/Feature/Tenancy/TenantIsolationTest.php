<?php

use App\Exceptions\MissingTenantContext;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Label;
use App\Models\Tenant;
use App\Support\Facades\Tenancy;
use App\Support\TenancyManager;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Tenant isolation is a security boundary, not a filter (docs/05 §1).
 * These tests exercise all three layers: Eloquent scope, Postgres RLS,
 * and the context lifecycle.
 */

/** Every model class in app/Models using the BelongsToTenant trait. */
function tenantScopedModels(): array
{
    $models = [];

    foreach (glob(dirname(__DIR__, 3).'/app/Models/*.php') ?: [] as $file) {
        $class = 'App\\Models\\'.basename($file, '.php');

        if (class_exists($class) && in_array(BelongsToTenant::class, class_uses_recursive($class), true)) {
            $models[] = $class;
        }
    }

    return $models;
}

test('the connected role cannot bypass row level security', function () {
    $superuser = DB::selectOne('select usesuper from pg_user where usename = current_user');

    expect((bool) $superuser->usesuper)->toBeFalse(
        'Tests must run as a non-superuser role or every RLS assertion is meaningless.',
    );

    $bypass = DB::selectOne('select rolbypassrls from pg_roles where rolname = current_user');
    expect((bool) $bypass->rolbypassrls)->toBeFalse();
});

test('every tenant-scoped model has an RLS policy on its table', function (string $model) {
    $table = (new $model)->getTable();

    $policy = DB::selectOne(
        'select 1 from pg_policies where tablename = ? and policyname = ?',
        [$table, 'tenant_isolation'],
    );
    expect($policy)->not->toBeNull("Table {$table} is missing the tenant_isolation policy.");

    $force = DB::selectOne(<<<'SQL'
        select c.relforcerowsecurity
        from pg_class c
        join pg_namespace n on n.oid = c.relnamespace
        where n.nspname = 'public' and c.relkind = 'r' and c.relname = ?
        SQL, [$table]);
    expect((bool) $force->relforcerowsecurity)->toBeTrue("Table {$table} must FORCE row level security.");
})->with(tenantScopedModels());

test('every table with a tenant_id column is either RLS-protected or a documented exception', function () {
    // tenant_user is the context-bootstrapping bridge; tenant_invitations is
    // accessed pre-auth by unguessable code (see the RLS migration).
    $exceptions = ['tenant_user', 'tenant_invitations'];

    $unprotected = DB::select(<<<'SQL'
        select c.table_name
        from information_schema.columns c
        join pg_class pc on pc.relname = c.table_name
        where c.table_schema = 'public'
          and c.column_name = 'tenant_id'
          and pc.relkind = 'r'
          and pc.relrowsecurity = false
        SQL);

    expect(collect($unprotected)->pluck('table_name')->diff($exceptions)->values()->all())
        ->toBeEmpty();
});

test('creating a tenant-scoped record with no tenant context throws', function () {
    Tenancy::forget();

    expect(fn () => Label::create([
        'name' => 'vip',
        'color' => '#f59e0b',
        'kind' => 'contact',
    ]))->toThrow(MissingTenantContext::class);
});

test('the eloquent scope hides other tenants and shows the current one', function () {
    [$tenantA, $tenantB] = Tenant::factory()->count(2)->create();

    Tenancy::initialize($tenantA);
    Label::create(['name' => 'kampala', 'color' => '#0ea5e9', 'kind' => 'contact']);

    expect(Label::count())->toBe(1);

    Tenancy::initialize($tenantB);
    expect(Label::count())->toBe(0);

    Tenancy::initialize($tenantA);
    expect(Label::count())->toBe(1);
});

test('RLS is enforced even for a raw DB::table query', function () {
    [$tenantA, $tenantB] = Tenant::factory()->count(2)->create();

    Tenancy::initialize($tenantA);
    Label::create(['name' => 'wholesale', 'color' => '#22c55e', 'kind' => 'contact']);

    // Raw query — no Eloquent scope involved. RLS alone must hide the row.
    Tenancy::initialize($tenantB);
    expect(DB::table('labels')->count())->toBe(0);

    Tenancy::initialize($tenantA);
    expect(DB::table('labels')->count())->toBe(1);
});

test('RLS rejects a raw insert claiming another tenant', function () {
    [$tenantA, $tenantB] = Tenant::factory()->count(2)->create();

    Tenancy::initialize($tenantB);

    expect(fn () => DB::table('labels')->insert([
        'id' => (string) Str::uuid(),
        'tenant_id' => $tenantA->id, // forged: not the current tenant
        'name' => 'forged',
        'color' => '#ef4444',
        'kind' => 'contact',
    ]))->toThrow(QueryException::class);
});

test('tenant context does not survive a container scope reset (Octane leak guard)', function () {
    Tenancy::initialize(Tenant::factory()->create());
    expect(Tenancy::initialized())->toBeTrue();

    // Octane resets scoped bindings between requests; simulate that boundary.
    app()->forgetScopedInstances();

    expect(app(TenancyManager::class)->initialized())->toBeFalse()
        ->and(app(TenancyManager::class)->currentId())->toBeNull();
});
