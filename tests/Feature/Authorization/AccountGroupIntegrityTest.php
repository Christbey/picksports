<?php

use App\Models\CbbBracket;
use App\Models\Group;
use App\Models\GroupJoinLink;
use App\Models\SubscriptionTier;
use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Http::preventStrayRequests();
    Notification::fake();
    config()->set('founding_users.enabled', false);
    config()->set('inertia.ssr.enabled', false);
});

function reviewedFreeTier(): SubscriptionTier
{
    return SubscriptionTier::query()->create([
        'name' => 'Free', 'slug' => 'free', 'permissions' => [], 'data_permissions' => [],
        'features' => [], 'is_default' => true, 'is_active' => true,
    ]);
}

function failAfterGroupQuery(string $table, string $verb, Closure $operation): void
{
    $connection = DB::connection();
    $original = $connection->getEventDispatcher();
    $faults = clone $original;
    $faults->listen(QueryExecuted::class, function (QueryExecuted $query) use ($table, $verb): void {
        if (preg_match('/\A'.$verb.' [`"]?'.preg_quote($table, '/').'/i', $query->sql) === 1) {
            throw new RuntimeException('Injected group write failure');
        }
    });
    $connection->setEventDispatcher($faults);
    try {
        $operation();
    } finally {
        $connection->setEventDispatcher($original);
    }
}

it('resolves the checkout return request and redirects without an external billing call', function () {
    reviewedFreeTier();
    $this->actingAs(User::factory()->create())->get(route('subscription.success'))->assertRedirect();
    Http::assertNothingSent();
});

it('requires email verification on routes explicitly marked verified', function () {
    $user = User::factory()->unverified()->create();
    $this->actingAs($user)->getJson(route('web-push.config'))->assertForbidden();
    $this->get(route('dashboard'))->assertRedirect(route('verification.notice'));
    $user->markEmailAsVerified();
    $this->getJson(route('web-push.config'))->assertOk();
});

it('dispatches the intended verification notification when a new account registers', function () {
    $this->post(route('register.store'), [
        'name' => 'Verification account', 'email' => 'verify-account@example.test', 'password' => 'password',
        'password_confirmation' => 'password', 'age_verified' => '1',
    ])->assertRedirect();
    $user = User::query()->where('email', 'verify-account@example.test')->sole();
    expect($user->hasVerifiedEmail())->toBeFalse();
    Notification::assertSentTo($user, VerifyEmail::class);
});

it('removes an inactive dynamic paid tier role without stripping unrelated roles or admin identity', function () {
    reviewedFreeTier();
    $paidPermission = Permission::findOrCreate('view-private-dynamic-signal', 'web');
    $manualPermission = Permission::findOrCreate('manual-support-access', 'web');
    SubscriptionTier::query()->create(['name' => 'Dynamic VIP', 'slug' => 'dynamic-vip', 'is_active' => false, 'permissions' => [$paidPermission->name]]);
    $vip = Role::findOrCreate('dynamic-vip', 'web');
    $vip->givePermissionTo($paidPermission);
    $manual = Role::findOrCreate('support-independent', 'web');
    $manual->givePermissionTo($manualPermission);
    $user = User::factory()->admin()->create();
    $user->assignRole($vip, $manual);
    expect($user->hasPermissionTo($paidPermission))->toBeTrue();

    $user->syncRoleFromTier();

    expect($user->fresh()->hasRole('dynamic-vip'))->toBeFalse()
        ->and($user->fresh()->hasPermissionTo($paidPermission))->toBeFalse()
        ->and($user->fresh()->hasRole('free'))->toBeTrue()
        ->and($user->fresh()->hasRole('support-independent'))->toBeTrue()
        ->and($user->fresh()->hasPermissionTo($manualPermission))->toBeTrue()
        ->and($user->fresh()->isAdmin())->toBeTrue();
});

it('preserves existing owner role and original membership time when reusing a join link', function () {
    $owner = User::factory()->create();
    $group = Group::query()->create(['owner_id' => $owner->id, 'name' => 'Owner pool']);
    $joined = now()->subWeek()->startOfSecond();
    $group->users()->attach($owner->id, ['role' => 'owner', 'joined_at' => $joined]);
    $link = GroupJoinLink::query()->create(['group_id' => $group->id, 'created_by' => $owner->id]);
    $before = DB::table('group_users')->where('group_id', $group->id)->get()->map(fn ($row) => (array) $row)->all();
    $linkBefore = $link->fresh()->getRawOriginal();
    $this->actingAs($owner)->get(route('groups.join.show', $link->token))->assertOk();
    expect(DB::table('group_users')->where('group_id', $group->id)->get()->map(fn ($row) => (array) $row)->all())->toBe($before)
        ->and($link->fresh()->getRawOriginal())->toBe($linkBefore);
    $this->post(route('groups.join.accept', $link->token))->assertRedirect();
    $this->travel(2)->hours();
    $this->get(route('groups.join.show', $link->token))->assertOk();
    expect(DB::table('group_users')->where('group_id', $group->id)->get()->map(fn ($row) => (array) $row)->all())->toBe($before);
    $this->post(route('groups.join.accept', $link->token))->assertRedirect();
    expect(DB::table('group_users')->where('group_id', $group->id)->get()->map(fn ($row) => (array) $row)->all())->toBe($before)
        ->and($link->fresh()->getRawOriginal())->toBe($linkBefore);
    $membership = $group->users()->whereKey($owner->id)->sole()->pivot;
    expect($membership->role)->toBe('owner')->and($membership->joined_at)->toBe($joined->toDateTimeString());
});

it('rolls back admin group creation if creating its join link fails', function () {
    $admin = User::factory()->admin()->create();
    failAfterGroupQuery('group_join_links', 'insert into', function () use ($admin): void {
        $this->actingAs($admin)->post('/settings/admin/groups', ['name' => 'Atomic admin pool', 'season' => 2026])->assertStatus(500);
    });
    expect(Group::query()->count())->toBe(0)
        ->and(DB::table('group_users')->count())->toBe(0)
        ->and(GroupJoinLink::query()->count())->toBe(0);
});

it('rolls back member removal if clearing its bracket assignment fails', function () {
    $admin = User::factory()->admin()->create();
    $member = User::factory()->create();
    $group = Group::query()->create(['owner_id' => $admin->id, 'name' => 'Atomic removal', 'type' => 'bracket_pool', 'sport' => 'cbb']);
    $group->users()->attach($member->id, ['role' => 'member', 'joined_at' => now()]);
    $bracket = CbbBracket::query()->create(['user_id' => $member->id, 'group_id' => $group->id, 'season' => 2026]);
    failAfterGroupQuery('cbb_brackets', 'update', function () use ($admin, $group, $member): void {
        $this->actingAs($admin)->delete('/settings/admin/groups/users', ['group_id' => $group->id, 'user_id' => $member->id])->assertStatus(500);
    });
    expect($group->users()->whereKey($member->id)->exists())->toBeTrue()
        ->and($bracket->fresh()->group_id)->toBe($group->id);
});

it('adds a new link member once and preserves the original membership on repeated joins', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $group = Group::query()->create(['owner_id' => $owner->id, 'name' => 'Repeated member pool']);
    $link = GroupJoinLink::query()->create(['group_id' => $group->id, 'created_by' => $owner->id]);
    $linkBefore = $link->fresh()->getRawOriginal();
    expect($group->users()->count())->toBe(0);
    $this->actingAs($member)->get(route('groups.join.show', $link->token))->assertOk();
    expect($group->users()->count())->toBe(0)
        ->and($group->fresh()->owner_id)->toBe($owner->id)
        ->and($link->fresh()->getRawOriginal())->toBe($linkBefore);
    $this->post(route('groups.join.accept', $link->token))->assertRedirect();
    $original = $group->users()->whereKey($member->id)->sole()->pivot->joined_at;
    $this->travel(2)->hours();
    $before = DB::table('group_users')->where('group_id', $group->id)->get()->map(fn ($row) => (array) $row)->all();
    $this->get(route('groups.join.show', $link->token))->assertOk();
    expect(DB::table('group_users')->where('group_id', $group->id)->get()->map(fn ($row) => (array) $row)->all())->toBe($before)
        ->and($group->fresh()->owner_id)->toBe($owner->id)
        ->and($link->fresh()->getRawOriginal())->toBe($linkBefore);
    $this->post(route('groups.join.accept', $link->token))->assertRedirect();
    expect(DB::table('group_users')->where('group_id', $group->id)->get()->map(fn ($row) => (array) $row)->all())->toBe($before)
        ->and($link->fresh()->getRawOriginal())->toBe($linkBefore);
    $membership = $group->users()->whereKey($member->id)->sole()->pivot;
    expect($membership->role)->toBe('member')->and($membership->joined_at)->toBe($original)
        ->and($group->users()->count())->toBe(1);
});

it('keeps account recovery profile and existing native authentication context available before verification', function () {
    $user = User::factory()->unverified()->create();
    $this->actingAs($user)->get(route('profile.edit'))->assertOk();
    $this->getJson('/api/v2/auth/me')->assertOk();
    $this->postJson('/api/v2/auth/device-sessions', [
        'device_name' => 'Unverified account device', 'platform' => 'ios',
    ])->assertCreated();
    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});
