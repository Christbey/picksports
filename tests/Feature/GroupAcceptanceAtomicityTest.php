<?php

use App\Actions\Fortify\CreateNewUser;
use App\Http\Controllers\Auth\GroupInvitationController;
use App\Models\Group;
use App\Models\GroupInvitation;
use App\Models\GroupJoinLink;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

function atomicGroupFixture(): array
{
    $owner = User::factory()->create();
    $group = Group::query()->create(['owner_id' => $owner->id, 'name' => 'Atomic synthetic group', 'type' => 'bracket_pool', 'sport' => 'cbb', 'season' => 2026]);

    return [$owner, $group];
}

it('rolls back membership when recording invitation acceptance fails', function () {
    [$owner, $group] = atomicGroupFixture();
    $user = User::factory()->create();
    $invite = GroupInvitation::query()->create(['group_id' => $group->id, 'invited_by' => $owner->id, 'email' => $user->email]);
    GroupInvitation::saving(function ($saving) {
        if ($saving->exists && $saving->accepted_by !== null) {
            throw new RuntimeException('synthetic receipt write failure');
        }
    });
    try {
        GroupInvitationController::acceptInvitation($invite, $user);
        $this->fail('The injected receipt failure must be reached.');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toBe('synthetic receipt write failure');
    }
    $this->assertDatabaseMissing('group_users', ['group_id' => $group->id, 'user_id' => $user->id]);
    expect($invite->fresh()->accepted_at)->toBeNull();
});

it('refuses a stale pending invitation after persisted expiry', function () {
    [$owner, $group] = atomicGroupFixture();
    $user = User::factory()->create();
    $invite = GroupInvitation::query()->create(['group_id' => $group->id, 'invited_by' => $owner->id, 'email' => $user->email, 'expires_at' => now()->addDay()]);
    GroupInvitation::query()->whereKey($invite->id)->update(['expires_at' => now()->subMinute()]);
    try {
        GroupInvitationController::acceptInvitation($invite, $user);
    } catch (HttpExceptionInterface $e) {
        expect($e->getStatusCode())->toBe(404);
    }
    $this->assertDatabaseMissing('group_users', ['group_id' => $group->id, 'user_id' => $user->id]);
    expect($invite->fresh()->accepted_at)->toBeNull();
});

it('rechecks the current token after account creation before guest membership writes', function (string $kind) {
    [$owner, $group] = atomicGroupFixture();
    $email = 'synthetic-stale-registration@example.test';
    $token = $kind === 'invite'
        ? GroupInvitation::query()->create(['group_id' => $group->id, 'invited_by' => $owner->id, 'email' => $email, 'expires_at' => now()->addDay()])
        : GroupJoinLink::query()->create(['group_id' => $group->id, 'created_by' => $owner->id]);
    User::created(function ($created) use ($kind, $token, $email) {
        if ($created->email === $email) {
            $token->newQuery()->whereKey($token->id)->update($kind === 'invite' ? ['expires_at' => now()->subMinute()] : ['revoked_at' => now()]);
        }
    });
    try {
        app(CreateNewUser::class)->create(['name' => 'Synthetic token race', 'email' => $email, 'password' => 'password', 'password_confirmation' => 'password', $kind === 'invite' ? 'invite_token' : 'join_token' => $token->token]);
    } catch (HttpExceptionInterface $e) {
        expect($e->getStatusCode())->toBe(404);
    }
    $created = User::query()->where('email', $email)->firstOrFail();
    $this->assertDatabaseMissing('group_users', ['group_id' => $group->id, 'user_id' => $created->id]);
})->with(['invite', 'join']);

it('does not follow a changed default connection away from explicitly bound token and user', function () {
    [$owner, $group] = atomicGroupFixture();
    $user = User::factory()->create();
    $invite = GroupInvitation::query()->create(['group_id' => $group->id, 'invited_by' => $owner->id, 'email' => $user->email]);
    $original = DB::getDefaultConnection();
    $invite->setConnection($original);
    $user->setConnection($original);
    config(['database.connections.group_empty_other' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]]);
    DB::setDefaultConnection('group_empty_other');
    try {
        GroupInvitationController::acceptInvitation($invite, $user);
    } finally {
        DB::setDefaultConnection($original);
        DB::purge('group_empty_other');
    }
    $this->assertDatabaseHas('group_users', ['group_id' => $group->id, 'user_id' => $user->id]);
    expect($invite->fresh()->accepted_by)->toBe($user->id);
});

it('refuses mismatched connection identities before membership writes', function () {
    [$owner, $group] = atomicGroupFixture();
    $user = User::factory()->create();
    $invite = GroupInvitation::query()->create(['group_id' => $group->id, 'invited_by' => $owner->id, 'email' => $user->email]);
    config(['database.connections.group_empty_other' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]]);
    $user->setConnection('group_empty_other');
    try {
        expect(fn () => GroupInvitationController::acceptInvitation($invite, $user))->toThrow(RuntimeException::class, 'persisted identities on one connection');
    } finally {
        DB::purge('group_empty_other');
    }
    $this->assertDatabaseMissing('group_users', ['group_id' => $group->id, 'user_id' => $user->id]);
    expect($invite->fresh()->accepted_at)->toBeNull();
});

it('does not reaccept or rewrite an already accepted invitation', function () {
    [$owner, $group] = atomicGroupFixture();
    $user = User::factory()->create();
    $invite = GroupInvitation::query()->create(['group_id' => $group->id, 'invited_by' => $owner->id, 'email' => $user->email]);
    GroupInvitationController::acceptInvitation($invite, $user);
    $before = $invite->fresh()->getRawOriginal();
    try {
        GroupInvitationController::acceptInvitation($invite, $user);
        $this->fail('An accepted invitation is no longer pending.');
    } catch (HttpExceptionInterface $e) {
        expect($e->getStatusCode())->toBe(404);
    }
    expect($invite->fresh()->getRawOriginal())->toBe($before)
        ->and($group->users()->where('users.id', $user->id)->count())->toBe(1);
});

it('rolls back membership when an acceptance saving listener cancels the receipt', function () {
    [$owner, $group] = atomicGroupFixture();
    $user = User::factory()->create();
    $invite = GroupInvitation::query()->create(['group_id' => $group->id, 'invited_by' => $owner->id, 'email' => $user->email]);
    $cancelled = false;
    GroupInvitation::saving(function ($saving) use (&$cancelled) {
        if ($saving->exists && $saving->accepted_by !== null) {
            $cancelled = true;

            return false;
        }
    });
    try {
        GroupInvitationController::acceptInvitation($invite, $user);
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toBe('Group invitation acceptance was not persisted.');
    }
    expect($cancelled)->toBeTrue();
    $this->assertDatabaseMissing('group_users', ['group_id' => $group->id, 'user_id' => $user->id]);
    expect($invite->fresh()->accepted_at)->toBeNull();
});
