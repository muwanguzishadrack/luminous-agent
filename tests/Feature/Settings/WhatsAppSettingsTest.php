<?php

use App\Enums\TeamRole;
use App\Enums\WhatsAppVertical;
use App\Models\MetaCredential;
use App\Models\PhoneNumber;
use App\Models\User;
use App\Models\WabaAccount;
use App\Services\Meta\GraphClient;
use App\Support\Facades\Teams;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fakes\FakeGraphClient;

/**
 * `/settings/whatsapp` (docs/modules/m0-onboarding.md §7): the connected-account
 * panel, the editable business profile, the billing link-out and the disconnect
 * danger zone. Field names, value sets and limits come from
 * docs/reference/whatsapp-cloud-api.md §5.
 */

/**
 * A team with a live connection: one WABA, one number, one vaulted business
 * token (D-020). Returns the acting user in the requested role.
 *
 * @param  array<string, mixed>  $numberAttributes
 * @return array{user: User, account: WabaAccount, number: PhoneNumber}
 */
function connectedTeam(TeamRole $role = TeamRole::Owner, array $numberAttributes = []): array
{
    $owner = User::factory()->withTeam()->create();
    Teams::initialize($owner->team);

    $account = WabaAccount::factory()->create();
    $number = PhoneNumber::factory()->create(
        array_merge(['waba_account_id' => $account->id], $numberAttributes),
    );
    MetaCredential::factory()->create(['waba_account_id' => $account->id]);

    $user = $role === TeamRole::Owner
        ? $owner
        : User::factory()->memberOf($owner->team, $role)->create();

    Teams::initialize($owner->team);

    return ['user' => $user, 'account' => $account, 'number' => $number];
}

function fakeGraph(): FakeGraphClient
{
    /** @var FakeGraphClient $fake */
    $fake = app(GraphClient::class);

    return $fake;
}

/**
 * @param  array<string, mixed>  $profile
 */
function fakeProfileRead(FakeGraphClient $fake, PhoneNumber $number, array $profile = []): void
{
    $fake->fake("GET {$number->phone_number_id}/whatsapp_business_profile", [
        'data' => [array_merge([
            'about' => 'We reply in minutes.',
            'address' => 'Plot 12, Kampala',
            'email' => 'hello@acme.test',
            'description' => 'Acme sells things.',
            'vertical' => 'PROF_SERVICES',
            'websites' => ['https://acme.test', 'https://shop.acme.test'],
            'profile_picture_url' => 'https://cdn.example.test/picture.jpg',
        ], $profile)],
    ]);
}

test('two-step verification and display name status render as separate fields', function () {
    // EXPIRED is a name_status value. It is never a code_verification_status.
    ['user' => $user] = connectedTeam(numberAttributes: [
        'code_verification_status' => 'VERIFIED',
        'name_status' => 'EXPIRED',
    ]);

    $this->actingAs($user)
        ->get(route('whatsapp.show'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/whatsapp')
            ->where('phoneNumber.codeVerificationStatus', 'VERIFIED')
            ->where('phoneNumber.nameStatus', 'EXPIRED')
            ->where('canManage', true));
});

test('the connected panel exposes every field the screen renders', function () {
    ['user' => $user, 'account' => $account] = connectedTeam();

    $this->actingAs($user)
        ->get(route('whatsapp.show'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('wabaAccount.wabaId', $account->waba_id)
            ->where('wabaAccount.accountReviewStatus', $account->review_status)
            ->where('phoneNumber.qualityRating', 'GREEN')
            ->where('phoneNumber.messagingLimitTier', 'TIER_1K')
            ->where('phoneNumber.throughputLevel', 'STANDARD')
            ->where('phoneNumber.platformType', 'CLOUD_API')
            ->where('phoneNumber.isOnBizApp', false)
            ->has('links.whatsappManager')
            ->has('links.billing'));
});

test('the page offers all 21 meta verticals with meta labels', function () {
    ['user' => $user] = connectedTeam();

    $this->actingAs($user)
        ->get(route('whatsapp.show'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->count('verticals', 21)
            ->where('verticals.17', ['value' => 'PROF_SERVICES', 'label' => 'Professional services']));
});

test('the vaulted business token never reaches the page', function () {
    ['user' => $user, 'account' => $account] = connectedTeam();

    Teams::initialize($user->team);
    $token = MetaCredential::query()->where('waba_account_id', $account->id)->firstOrFail()->token;

    $this->actingAs($user)
        ->get(route('whatsapp.show'))
        ->assertOk()
        ->assertDontSee($token);
});

test('refresh re-reads the number and the waba from graph and persists them', function () {
    ['user' => $user, 'account' => $account, 'number' => $number] = connectedTeam();

    $fake = fakeGraph();
    $fake->fake("GET {$number->phone_number_id}", [
        'display_phone_number' => '+256 700 000 111',
        'verified_name' => 'Acme Renamed',
        'quality_rating' => 'YELLOW',
        'code_verification_status' => 'UNVERIFIED',
        'name_status' => 'PENDING_REVIEW',
        'messaging_limit_tier' => 'TIER_10K',
        'throughput' => ['level' => 'HIGH'],
        'platform_type' => 'CLOUD_API',
        'is_on_biz_app' => false,
    ]);
    $fake->fake("GET {$account->waba_id}", [
        'name' => 'Acme Holdings',
        'account_review_status' => 'REJECTED',
        'business_verification_status' => 'verified',
    ]);
    fakeProfileRead($fake, $number);

    $this->actingAs($user)
        ->post(route('whatsapp.refresh'))
        ->assertRedirect(route('whatsapp.show'));

    Teams::initialize($user->team);
    $number->refresh();
    $account->refresh();

    expect($number->verified_name)->toBe('Acme Renamed')
        ->and($number->quality_rating)->toBe('YELLOW')
        ->and($number->code_verification_status)->toBe('UNVERIFIED')
        ->and($number->name_status)->toBe('PENDING_REVIEW')
        ->and($number->messaging_limit_tier)->toBe('TIER_10K')
        ->and($number->throughput_level)->toBe('HIGH')
        ->and($number->last_synced_at)->not->toBeNull()
        ->and($account->name)->toBe('Acme Holdings')
        ->and($account->review_status)->toBe('REJECTED')
        ->and(DB::table('audit_logs')->where('action', 'whatsapp.connection_refreshed')->count())->toBe(1);
});

test('a graph failure on refresh renders meta\'s own message instead of a 500', function () {
    ['user' => $user] = connectedTeam();

    fakeGraph()->failWith(100);

    $this->actingAs($user)
        ->from(route('whatsapp.show'))
        ->post(route('whatsapp.refresh'))
        ->assertRedirect(route('whatsapp.show'))
        ->assertSessionHasErrors('meta');
});

test('saving the business profile writes to meta and re-reads the saved values', function () {
    ['user' => $user, 'number' => $number] = connectedTeam();

    $fake = fakeGraph();
    fakeProfileRead($fake, $number);

    $this->actingAs($user)
        ->post(route('whatsapp.profile.update'), [
            'about' => 'We reply in minutes.',
            'address' => 'Plot 12, Kampala',
            'email' => 'hello@acme.test',
            'description' => 'Acme sells things.',
            'vertical' => 'PROF_SERVICES',
            'websites' => ['https://acme.test', 'https://shop.acme.test'],
        ])
        ->assertRedirect(route('whatsapp.show'))
        ->assertSessionHasNoErrors();

    $write = collect($fake->calls)->first(
        fn (array $call): bool => $call['method'] === 'POST'
            && $call['path'] === "{$number->phone_number_id}/whatsapp_business_profile",
    );

    // messaging_product is required on every profile write (reference §5).
    expect($write['payload']['messaging_product'])->toBe('whatsapp')
        ->and($write['payload']['about'])->toBe('We reply in minutes.')
        ->and($write['payload']['vertical'])->toBe('PROF_SERVICES')
        ->and($write['payload']['websites'])->toBe(['https://acme.test', 'https://shop.acme.test']);

    // The write answers {"success": true} and echoes nothing, so the profile
    // is re-read afterwards and local state comes from Meta.
    $reads = collect($fake->calls)->filter(
        fn (array $call): bool => $call['method'] === 'GET'
            && $call['path'] === "{$number->phone_number_id}/whatsapp_business_profile",
    );

    expect($reads)->toHaveCount(1);

    Teams::initialize($user->team);
    $number->refresh();

    expect($number->profile['about'])->toBe('We reply in minutes.')
        ->and($number->profile['vertical'])->toBe('PROF_SERVICES')
        ->and($number->profile['profile_picture_url'])->toBe('https://cdn.example.test/picture.jpg')
        ->and($number->profile['websites'])->toBe(['https://acme.test', 'https://shop.acme.test'])
        ->and(DB::table('audit_logs')->where('action', 'whatsapp.business_profile_updated')->count())->toBe(1);
});

test('an empty about is omitted rather than sent, because meta rejects it', function () {
    ['user' => $user, 'number' => $number] = connectedTeam();

    $fake = fakeGraph();
    fakeProfileRead($fake, $number);

    $this->actingAs($user)
        ->post(route('whatsapp.profile.update'), ['about' => '', 'address' => ''])
        ->assertSessionHasNoErrors();

    $write = collect($fake->calls)->first(
        fn (array $call): bool => $call['method'] === 'POST'
            && $call['path'] === "{$number->phone_number_id}/whatsapp_business_profile",
    );

    expect($write['payload'])->not->toHaveKey('about')
        // A cleared address is forwarded as "" so Meta actually clears it.
        ->and($write['payload']['address'])->toBe('');
});

test('a profile picture is stored in the media library and uploaded for a handle', function () {
    Storage::fake('local');

    ['user' => $user, 'number' => $number] = connectedTeam();

    $fake = fakeGraph();
    fakeProfileRead($fake, $number);

    $this->actingAs($user)
        ->post(route('whatsapp.profile.update'), [
            'profile_picture' => UploadedFile::fake()->image('logo.jpg', 300, 300),
        ])
        ->assertSessionHasNoErrors();

    $upload = collect($fake->calls)->first(
        fn (array $call): bool => $call['path'] === 'uploads',
    );

    $write = collect($fake->calls)->first(
        fn (array $call): bool => $call['method'] === 'POST'
            && $call['path'] === "{$number->phone_number_id}/whatsapp_business_profile",
    );

    expect($upload)->not->toBeNull()
        ->and($upload['payload']['file_type'])->toBe('image/jpeg')
        // Written as a handle from the Resumable Upload API, never a URL.
        ->and($write['payload']['profile_picture_handle'])->toStartWith('fake-upload-handle-');

    Teams::initialize($user->team);

    expect(DB::table('media')->where('filename', 'logo.jpg')->count())->toBe(1);
});

test('meta profile limits are mirrored server side', function (array $payload, string $field) {
    ['user' => $user] = connectedTeam();

    $this->actingAs($user)
        ->from(route('whatsapp.show'))
        ->post(route('whatsapp.profile.update'), $payload)
        ->assertSessionHasErrors($field);

    expect(fakeGraph()->calls)->toBeEmpty();
})->with([
    'about over 139 characters' => [['about' => str_repeat('a', 140)], 'about'],
    'address over 256 characters' => [['address' => str_repeat('a', 257)], 'address'],
    'email over 128 characters' => [['email' => str_repeat('a', 120).'@example.test'], 'email'],
    'malformed email' => [['email' => 'not-an-email'], 'email'],
    'description over 512 characters' => [['description' => str_repeat('a', 513)], 'description'],
    'more than two websites' => [
        ['websites' => ['https://a.test', 'https://b.test', 'https://c.test']],
        'websites',
    ],
    'website without a scheme' => [['websites' => ['acme.test']], 'websites.0'],
    'website over 256 characters' => [
        ['websites' => ['https://'.str_repeat('a', 250).'.test']],
        'websites.0',
    ],
    'unknown vertical' => [['vertical' => 'CRYPTO'], 'vertical'],
]);

test('a profile picture must be a jpg or png under 5 mb', function () {
    ['user' => $user] = connectedTeam();

    $this->actingAs($user)
        ->from(route('whatsapp.show'))
        ->post(route('whatsapp.profile.update'), [
            'profile_picture' => UploadedFile::fake()->create('brochure.pdf', 100, 'application/pdf'),
        ])
        ->assertSessionHasErrors('profile_picture');

    $this->actingAs($user)
        ->from(route('whatsapp.show'))
        ->post(route('whatsapp.profile.update'), [
            'profile_picture' => UploadedFile::fake()->image('huge.png')->size(5121),
        ])
        ->assertSessionHasErrors('profile_picture');

    expect(fakeGraph()->calls)->toBeEmpty();
});

test('every one of meta\'s 21 verticals is accepted', function (string $vertical) {
    ['user' => $user, 'number' => $number] = connectedTeam();

    fakeProfileRead(fakeGraph(), $number, ['vertical' => $vertical]);

    $this->actingAs($user)
        ->post(route('whatsapp.profile.update'), ['vertical' => $vertical])
        ->assertSessionHasNoErrors();
})->with(array_map(
    fn (WhatsAppVertical $vertical): string => $vertical->value,
    WhatsAppVertical::cases(),
));

test('disconnecting deregisters the number and clears the connection', function () {
    ['user' => $user, 'account' => $account, 'number' => $number] = connectedTeam();

    $fake = fakeGraph();

    $this->actingAs($user)
        ->delete(route('whatsapp.disconnect'))
        ->assertRedirect(route('whatsapp.show'))
        ->assertSessionHasNoErrors();

    $deregister = collect($fake->calls)->first(
        fn (array $call): bool => $call['path'] === "{$number->phone_number_id}/deregister",
    );

    expect($deregister)->not->toBeNull()
        ->and($deregister['method'])->toBe('POST');

    Teams::initialize($user->team);

    expect(WabaAccount::query()->count())->toBe(0)
        ->and(PhoneNumber::query()->count())->toBe(0)
        ->and(MetaCredential::query()->where('waba_account_id', $account->id)->count())->toBe(0)
        ->and(DB::table('audit_logs')->where('action', 'whatsapp.disconnected')->count())->toBe(1);
});

test('a coexistence number is never deregistered and is given the handset path instead', function () {
    ['user' => $user, 'number' => $number] = connectedTeam(numberAttributes: ['is_on_biz_app' => true]);

    $this->actingAs($user)
        ->from(route('whatsapp.show'))
        ->delete(route('whatsapp.disconnect'))
        ->assertRedirect(route('whatsapp.show'))
        ->assertSessionHasErrors('meta');

    expect(fakeGraph()->calls)->toBeEmpty();

    Teams::initialize($user->team);
    $number->refresh();

    expect(PhoneNumber::query()->count())->toBe(1);
});

test('the disconnected page falls back to the reconnect prompt', function () {
    ['user' => $user] = connectedTeam();

    $this->actingAs($user)->delete(route('whatsapp.disconnect'));

    $this->actingAs($user)
        ->get(route('whatsapp.show'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('wabaAccount', null)
            ->where('phoneNumber', null)
            ->where('profile', null));
});

test('only owners and admins may edit the profile or disconnect', function (TeamRole $role, int $status) {
    ['user' => $user, 'number' => $number] = connectedTeam($role);

    fakeProfileRead(fakeGraph(), $number);

    $this->actingAs($user)
        ->post(route('whatsapp.refresh'))
        ->assertStatus($status === 403 ? 403 : 302);

    $this->actingAs($user)
        ->post(route('whatsapp.profile.update'), ['about' => 'Hello'])
        ->assertStatus($status === 403 ? 403 : 302);

    $this->actingAs($user)
        ->delete(route('whatsapp.disconnect'))
        ->assertStatus($status === 403 ? 403 : 302);
})->with([
    'owner' => [TeamRole::Owner, 302],
    'admin' => [TeamRole::Admin, 302],
    'supervisor' => [TeamRole::Supervisor, 403],
    'agent' => [TeamRole::Agent, 403],
    'viewer' => [TeamRole::Viewer, 403],
]);

test('a member who cannot manage whatsapp sees a read-only page', function () {
    ['user' => $user] = connectedTeam(TeamRole::Agent);

    $this->actingAs($user)
        ->get(route('whatsapp.show'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('canManage', false));
});

test('writing against a workspace with no connection is a 404, not a 500', function () {
    $user = User::factory()->withTeam()->create();

    $this->actingAs($user)
        ->post(route('whatsapp.refresh'))
        ->assertNotFound();
});
