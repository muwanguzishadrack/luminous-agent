<?php

use App\Exceptions\MissingTeamContext;
use App\Models\Concerns\BelongsToTeam;
use App\Models\Label;
use App\Models\Team;
use App\Models\User;
use App\Support\Facades\Teams;
use App\Support\TeamManager;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Team isolation is a security boundary, not a filter (docs/05 §1).
 * These tests exercise all three layers: Eloquent scope, Postgres RLS,
 * and the context lifecycle.
 */

/** Every model class in app/Models using the BelongsToTeam trait. */
function teamScopedModels(): array
{
    $models = [];

    foreach (glob(dirname(__DIR__, 3).'/app/Models/*.php') ?: [] as $file) {
        $class = 'App\\Models\\'.basename($file, '.php');

        if (class_exists($class) && in_array(BelongsToTeam::class, class_uses_recursive($class), true)) {
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

test('every team-scoped model has an RLS policy on its table', function (string $model) {
    $table = (new $model)->getTable();

    $policy = DB::selectOne(
        'select 1 from pg_policies where tablename = ? and policyname = ?',
        [$table, 'team_isolation'],
    );
    expect($policy)->not->toBeNull("Table {$table} is missing the team_isolation policy.");

    $force = DB::selectOne(<<<'SQL'
        select c.relforcerowsecurity
        from pg_class c
        join pg_namespace n on n.oid = c.relnamespace
        where n.nspname = 'public' and c.relkind = 'r' and c.relname = ?
        SQL, [$table]);
    expect((bool) $force->relforcerowsecurity)->toBeTrue("Table {$table} must FORCE row level security.");
})->with(teamScopedModels());

test('every table with a team_id column is either RLS-protected or a documented exception', function () {
    // team_invitations is accessed pre-auth by unguessable code (see the RLS
    // migration). team_user is NOT an exception: it carries a user-aware
    // policy of its own.
    $exceptions = ['team_invitations'];

    $unprotected = DB::select(<<<'SQL'
        select c.table_name
        from information_schema.columns c
        join pg_class pc on pc.relname = c.table_name
        where c.table_schema = 'public'
          and c.column_name = 'team_id'
          and pc.relkind = 'r'
          and pc.relrowsecurity = false
        SQL);

    expect(collect($unprotected)->pluck('table_name')->diff($exceptions)->values()->all())
        ->toBeEmpty();
});

test('creating a team-scoped record with no team context throws', function () {
    Teams::forget();

    expect(fn () => Label::create([
        'name' => 'vip',
        'color' => '#f59e0b',
        'kind' => 'contact',
    ]))->toThrow(MissingTeamContext::class);
});

test('the eloquent scope hides other teams and shows the current one', function () {
    [$teamA, $teamB] = Team::factory()->count(2)->create();

    Teams::initialize($teamA);
    Label::create(['name' => 'kampala', 'color' => '#0ea5e9', 'kind' => 'contact']);

    expect(Label::count())->toBe(1);

    Teams::initialize($teamB);
    expect(Label::count())->toBe(0);

    Teams::initialize($teamA);
    expect(Label::count())->toBe(1);
});

test('RLS is enforced even for a raw DB::table query', function () {
    [$teamA, $teamB] = Team::factory()->count(2)->create();

    Teams::initialize($teamA);
    Label::create(['name' => 'wholesale', 'color' => '#22c55e', 'kind' => 'contact']);

    // Raw query — no Eloquent scope involved. RLS alone must hide the row.
    Teams::initialize($teamB);
    expect(DB::table('labels')->count())->toBe(0);

    Teams::initialize($teamA);
    expect(DB::table('labels')->count())->toBe(1);
});

test('RLS rejects a raw insert claiming another team', function () {
    [$teamA, $teamB] = Team::factory()->count(2)->create();

    Teams::initialize($teamB);

    expect(fn () => DB::table('labels')->insert([
        'id' => (string) Str::uuid(),
        'team_id' => $teamA->id, // forged: not the current team
        'name' => 'forged',
        'color' => '#ef4444',
        'kind' => 'contact',
    ]))->toThrow(QueryException::class);
});

/**
 * The membership row establishes the context, so it must be readable before
 * the context exists — and nothing else may be (docs/05 §1 layer 2, D-020).
 */
test('a user reads only its own membership row before team context', function () {
    $alice = User::factory()->withTeam()->create();
    $bob = User::factory()->withTeam()->create();
    $colleague = User::factory()->memberOf($alice->team)->create();

    // Authentication has identified the user, but no team context yet.
    Teams::forget();
    Teams::actingAs($alice);

    $visible = DB::table('team_user')->get();

    expect($visible)->toHaveCount(1)
        ->and($visible->first()->user_id)->toBe($alice->id)
        ->and($visible->first()->team_id)->toBe($alice->team->id);

    // That single row is enough to resolve the team — and once it is the
    // context, the user's colleagues become visible but Bob never does.
    Teams::initialize($alice->team);

    expect(DB::table('team_user')->pluck('user_id')->sort()->values()->all())
        ->toEqualCanonicalizing([$alice->id, $colleague->id])
        ->and(DB::table('team_user')->where('user_id', $bob->id)->exists())->toBeFalse();
});

test('team context does not survive a container scope reset (Octane leak guard)', function () {
    Teams::initialize(Team::factory()->create());
    expect(Teams::initialized())->toBeTrue();

    // Octane resets scoped bindings between requests; simulate that boundary.
    app()->forgetScopedInstances();

    expect(app(TeamManager::class)->initialized())->toBeFalse()
        ->and(app(TeamManager::class)->currentId())->toBeNull();
});
