<?php

use App\Models\Group;
use App\Models\GroupInvitation;
use App\Models\GroupJoinLink;
use App\Models\User;
use App\Services\Auth\GroupMembershipAdmission;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\Support\TestDatabaseSafety;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    TestDatabaseSafety::assertSafe(config()->all(), app()->configurationIsCached(), Env::get('PICKSPORTS_TEST_MYSQL_DATABASE'), Env::get('PICKSPORTS_TEST_MYSQL_SOCKET'));
    expect(DB::connection()->getDriverName())->toBe('mysql');
    expect(Artisan::call('migrate:fresh', ['--force' => true]))->toBe(0);
    $this->travelTo('2026-11-27T12:00:00Z');
    Http::preventStrayRequests();
    $this->ownerName = DB::getDefaultConnection();
    config(['database.connections.group_native_peer' => config('database.connections.'.$this->ownerName)]);
    foreach ([$this->ownerName, 'group_native_peer'] as $name) {
        DB::connection($name)->statement("SET SESSION time_zone = '+00:00'");
        DB::connection($name)->statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        DB::connection($name)->statement('SET SESSION innodb_lock_wait_timeout = 1');
    }
    expect(DB::connection()->getPdo())->not->toBe(DB::connection('group_native_peer')->getPdo());
    expect(DB::selectOne('SELECT CONNECTION_ID() AS id')->id)->not->toBe(DB::connection('group_native_peer')->selectOne('SELECT CONNECTION_ID() AS id')->id);
});

afterEach(function () {
    DB::setDefaultConnection($this->ownerName);
    foreach ([$this->ownerName, 'group_native_peer'] as $name) {
        while (DB::connection($name)->transactionLevel() > 0) {
            DB::connection($name)->rollBack();
        }
    }
    DB::purge('group_native_peer');
    $this->travelBack();
});

function nativeGroupFixture(string $kind): array
{
    $owner = User::factory()->create();
    $user = User::factory()->create();
    $group = Group::query()->create(['owner_id' => $owner->id, 'name' => 'Native synthetic group', 'type' => 'bracket_pool', 'sport' => 'cbb', 'season' => 2026]);
    $token = $kind === 'invite'
        ? GroupInvitation::query()->create(['group_id' => $group->id, 'invited_by' => $owner->id, 'email' => $user->email, 'expires_at' => now()->addDay()])
        : GroupJoinLink::query()->create(['group_id' => $group->id, 'created_by' => $owner->id]);

    return [$group, $user, $token];
}

function nativeAcceptGroup($token, User $user): void
{
    $service = app(GroupMembershipAdmission::class);
    $token instanceof GroupInvitation ? $service->acceptInvitation($token, $user) : $service->acceptJoinLink($token, $user);
}

function nativeGroupRefused(Closure $action): void
{
    $failure = null;
    try {
        $action();
    } catch (ModelNotFoundException|HttpExceptionInterface $e) {
        $failure = $e;
    }
    expect($failure)->not->toBeNull();
    if ($failure instanceof HttpExceptionInterface) {
        expect($failure->getStatusCode())->toBe(404);
    }
}

function nativeGroupTimeout(Closure $action): void
{
    $failure = null;
    try {
        $action();
    } catch (QueryException $e) {
        $failure = $e;
    }
    expect($failure)->toBeInstanceOf(QueryException::class)->and((int) ($failure?->errorInfo[1] ?? 0))->toBe(1205);
}

it('rejects current expired or revoked admission despite an older native RR view', function (string $kind) {
    [$group, $user, $token] = nativeGroupFixture($kind);
    $owner = DB::connection();
    $peer = DB::connection('group_native_peer');
    $field = $kind === 'invite' ? 'expires_at' : 'revoked_at';
    $owner->beginTransaction();
    try {
        $old = $owner->table($token->getTable())->where('id', $token->id)->value($field);
        $peer->table($token->getTable())->where('id', $token->id)->update([$field => now()->subMinute()]);
        expect($owner->table($token->getTable())->where('id', $token->id)->value($field))->toBe($old);
        nativeGroupRefused(fn () => nativeAcceptGroup($token, $user));
    } finally {
        $owner->rollBack();
    }
    expect($group->users()->count())->toBe(0);
    if ($token instanceof GroupInvitation) {
        expect($token->fresh()->accepted_at)->toBeNull();
    }
})->with(['invite', 'join']);

it('serializes competing actual acceptance and handles the retry without duplicate membership', function (string $kind) {
    [$group, $user, $token] = nativeGroupFixture($kind);
    $peerToken = $token::on('group_native_peer')->findOrFail($token->id);
    $peerUser = User::on('group_native_peer')->findOrFail($user->id);
    $once = false;
    $token::retrieved(function ($current) use (&$once, $token, $peerToken, $peerUser) {
        if (! $once && $current->getConnectionName() !== 'group_native_peer' && $current->id === $token->id) {
            $once = true;
            nativeGroupTimeout(fn () => nativeAcceptGroup($peerToken, $peerUser));
        }
    });
    nativeAcceptGroup($token, $user);
    expect($once)->toBeTrue()->and($group->users()->count())->toBe(1);
    if ($token instanceof GroupInvitation) {
        $receipt = $token->fresh()->getRawOriginal();
        nativeGroupRefused(fn () => nativeAcceptGroup($peerToken, $peerUser));
        expect($token->fresh()->getRawOriginal())->toBe($receipt);
    } else {
        nativeAcceptGroup($peerToken, $peerUser);
    }
    expect($group->users()->count())->toBe(1);
})->with(['invite', 'join']);

it('lets a prior revocation or rotation lock win and refuses old-token retries', function (string $change) {
    [$group, $user, $token] = nativeGroupFixture('join');
    $peer = DB::connection('group_native_peer');
    $peer->beginTransaction();
    try {
        $peer->table($token->getTable())->where('id', $token->id)->lockForUpdate()->first();
        $values = $change === 'rotate' ? ['token' => (string) Str::uuid()] : ['revoked_at' => now()];
        $peer->table($token->getTable())->where('id', $token->id)->update($values);
        nativeGroupTimeout(fn () => nativeAcceptGroup($token, $user));
        expect($peer->table('group_users')->where('group_id', $group->id)->count())->toBe(0);
        $peer->commit();
    } finally {
        if ($peer->transactionLevel() > 0) {
            $peer->rollBack();
        }
    }
    nativeGroupRefused(fn () => nativeAcceptGroup($token, $user));
    expect($group->users()->count())->toBe(0);
})->with(['revoke', 'rotate']);

it('blocks revocation behind acceptance then preserves the committed membership', function () {
    [$group, $user, $token] = nativeGroupFixture('join');
    $peer = DB::connection('group_native_peer');
    $once = false;
    GroupJoinLink::retrieved(function ($current) use (&$once, $peer, $token) {
        if (! $once && $current->getConnectionName() !== 'group_native_peer' && $current->id === $token->id) {
            $once = true;
            nativeGroupTimeout(fn () => $peer->table('group_join_links')->where('id', $token->id)->update(['revoked_at' => now()]));
        }
    });
    nativeAcceptGroup($token, $user);
    expect($once)->toBeTrue()->and($group->users()->count())->toBe(1);
    $peer->table('group_join_links')->where('id', $token->id)->update(['revoked_at' => now()]);
    nativeGroupRefused(fn () => nativeAcceptGroup($token, $user));
    expect($group->users()->count())->toBe(1);
});

it('rolls back native membership when the invitation receipt fails', function (bool $cancel) {
    [$group, $user, $token] = nativeGroupFixture('invite');
    $reached = false;
    GroupInvitation::saving(function ($saving) use ($cancel, &$reached) {
        if ($saving->exists && $saving->accepted_by !== null) {
            $reached = true;
            if ($cancel) {
                return false;
            }
            throw new RuntimeException('synthetic native receipt failure');
        }
    });
    expect(fn () => nativeAcceptGroup($token, $user))->toThrow(RuntimeException::class);
    expect($reached)->toBeTrue()->and($group->users()->count())->toBe(0)->and($token->fresh()->accepted_at)->toBeNull();
})->with([false, true]);
