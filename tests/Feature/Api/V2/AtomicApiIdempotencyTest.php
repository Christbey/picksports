<?php

use App\Http\Middleware\EnsureIdempotentApiRequest;
use App\Models\ApiIdempotencyKey;
use App\Models\CbbBracket;
use App\Models\Group;
use App\Models\User;
use App\Models\UserAlertPreference;
use App\Models\UserBet;
use App\Services\Api\V2\AtomicIdempotentRoutes;
use App\Services\Auth\Native\DevicePushRegistrationService;
use App\Services\Auth\Native\DeviceSessionTokenService;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

function atomicWriteRequest(User $user, string $key = 'atomic-operation', array $payload = []): Request
{
    $request = Request::create('/api/v2/user-bets', 'POST', $payload);
    $request->headers->set('Idempotency-Key', $key);
    $request->setUserResolver(fn () => $user);
    $route = new RoutingRoute('POST', 'api/v2/user-bets', fn () => null);
    $route->name('v2.user-bets.store')->bind($request);
    $request->setRouteResolver(fn () => $route);

    return $request;
}

function atomicWriteEffect(User $user): UserBet
{
    return UserBet::factory()->create(['user_id' => $user->id]);
}

it('rolls back business writes when response receipt persistence fails and resumes the reservation safely', function () {
    $user = User::factory()->create();
    $request = atomicWriteRequest($user);
    $middleware = app(EnsureIdempotentApiRequest::class);
    $event = 'eloquent.updating: '.ApiIdempotencyKey::class;
    Event::listen($event, function (ApiIdempotencyKey $receipt): void {
        if ($receipt->completed_at !== null) {
            throw new RuntimeException('Injected receipt persistence failure');
        }
    });

    try {
        expect(fn () => $middleware->handle($request, fn () => response()->json(['id' => atomicWriteEffect($user)->id], 201)))
            ->toThrow(RuntimeException::class, 'Injected receipt');
    } finally {
        Event::forget($event);
    }

    expect(UserBet::query()->count())->toBe(0)
        ->and(ApiIdempotencyKey::query()->sole()->completed_at)->toBeNull()
        ->and(ApiIdempotencyKey::query()->sole()->execution_mode)->toBe(ApiIdempotencyKey::ATOMIC_LOCAL);

    $response = $middleware->handle($request, fn () => response()->json(['id' => atomicWriteEffect($user)->id], 201));
    $replay = $middleware->handle($request, fn () => throw new RuntimeException('Must not execute a replay'));

    expect($response->getStatusCode())->toBe(201)
        ->and($replay->getContent())->toBe($response->getContent())
        ->and($replay->headers->get('Idempotency-Replayed'))->toBe('true')
        ->and(UserBet::query()->count())->toBe(1);
});

it('retains the safe reservation and rolls back after a downstream exception', function () {
    $user = User::factory()->create();
    $request = atomicWriteRequest($user);
    $middleware = app(EnsureIdempotentApiRequest::class);
    expect(fn () => $middleware->handle($request, function () use ($user) {
        atomicWriteEffect($user);
        throw new RuntimeException('Interrupted before response');
    }))->toThrow(RuntimeException::class, 'Interrupted');

    expect(UserBet::query()->count())->toBe(0)
        ->and(ApiIdempotencyKey::query()->count())->toBe(1);
    $middleware->handle($request, fn () => response()->json(['id' => atomicWriteEffect($user)->id]));
    expect(UserBet::query()->count())->toBe(1);
});

it('rolls back local writes for unsuccessful HTTP responses without caching them as completed', function (int $status) {
    $user = User::factory()->create();
    $response = app(EnsureIdempotentApiRequest::class)->handle(atomicWriteRequest($user), function () use ($user, $status) {
        atomicWriteEffect($user);

        return response()->json(['message' => 'Rejected'], $status);
    });
    expect($response->getStatusCode())->toBe($status)
        ->and(UserBet::query()->count())->toBe(0)
        ->and(ApiIdempotencyKey::query()->sole()->completed_at)->toBeNull();
})->with([403, 422, 500, 503]);

it('never expires or prunes incomplete legacy outcomes and fails closed before executing', function () {
    $user = User::factory()->create();
    $middleware = app(EnsureIdempotentApiRequest::class);
    $request = atomicWriteRequest($user);
    expect(fn () => $middleware->handle($request, fn () => throw new RuntimeException('Before work')))
        ->toThrow(RuntimeException::class);
    ApiIdempotencyKey::query()->update(['execution_mode' => null, 'expires_at' => now()->subDays(2)]);

    $response = $middleware->handle($request, fn () => response()->json(['id' => atomicWriteEffect($user)->id]));
    expect($response->getStatusCode())->toBe(409)
        ->and(json_decode($response->getContent(), true)['error']['code'])->toBe('idempotency_outcome_unknown')
        ->and((new ApiIdempotencyKey)->prunable()->count())->toBe(0)
        ->and(UserBet::query()->count())->toBe(0);
});

it('keeps an expired pending atomic fingerprint but safely resumes its original operation', function () {
    $user = User::factory()->create();
    $middleware = app(EnsureIdempotentApiRequest::class);
    $request = atomicWriteRequest($user, payload: ['amount' => 10]);
    expect(fn () => $middleware->handle($request, fn () => throw new RuntimeException('Before work')))
        ->toThrow(RuntimeException::class);
    ApiIdempotencyKey::query()->update(['expires_at' => now()->subDays(2)]);

    $conflict = $middleware->handle(atomicWriteRequest($user, payload: ['amount' => 20]), fn () => throw new RuntimeException('Must not run'));
    expect($conflict->getStatusCode())->toBe(409)
        ->and((new ApiIdempotencyKey)->prunable()->count())->toBe(0);
    $middleware->handle($request, fn () => response()->json(['id' => atomicWriteEffect($user)->id]));
    expect(UserBet::query()->count())->toBe(1)
        ->and(ApiIdempotencyKey::query()->sole()->expires_at->isFuture())->toBeTrue();
});

it('prunes completed expired receipts only', function () {
    $user = User::factory()->create();
    app(EnsureIdempotentApiRequest::class)->handle(atomicWriteRequest($user), fn () => response()->json(['ok' => true]));
    ApiIdempotencyKey::query()->update(['expires_at' => now()->subSecond()]);
    expect((new ApiIdempotencyKey)->prunable()->count())->toBe(1);
});

it('canonicalizes object key order but preserves list order', function () {
    $user = User::factory()->create();
    $middleware = app(EnsureIdempotentApiRequest::class);
    $response = $middleware->handle(atomicWriteRequest($user, payload: ['a' => 1, 'b' => [2, 3]]), fn () => response()->json(['id' => atomicWriteEffect($user)->id]));
    $replay = $middleware->handle(atomicWriteRequest($user, payload: ['b' => [2, 3], 'a' => 1]), fn () => throw new RuntimeException('Must replay'));
    $conflict = $middleware->handle(atomicWriteRequest($user, payload: ['a' => 1, 'b' => [3, 2]]), fn () => throw new RuntimeException('Must reject'));
    expect($replay->getContent())->toBe($response->getContent())
        ->and($conflict->getStatusCode())->toBe(409);
});

it('admits exactly the reviewed idempotent routes and checks the default connection', function () {
    $actual = collect(Route::getRoutes())->filter(fn ($route) => in_array('v2.idempotent', $route->gatherMiddleware(), true))
        ->map(fn ($route) => $route->getName())->sort()->values()->all();
    $classified = array_keys(AtomicIdempotentRoutes::operations());
    sort($classified);
    expect($actual)->toBe($classified)
        ->and(app(AtomicIdempotentRoutes::class)->supports('v2.user-bets.store', 'unrelated'))->toBeFalse();
});

it('rejects keys for unclassified operations but preserves optional unkeyed requests', function () {
    $user = User::factory()->create();
    $request = atomicWriteRequest($user);
    $request->route()->name('unclassified.mutation');
    $middleware = app(EnsureIdempotentApiRequest::class);
    $denied = $middleware->handle($request, fn () => throw new RuntimeException('Must refuse'));
    expect($denied->getStatusCode())->toBe(503)
        ->and(ApiIdempotencyKey::query()->count())->toBe(0);
    $request->headers->remove('Idempotency-Key');
    expect($middleware->handle($request, fn () => response()->json(['unchanged' => true]))->getStatusCode())->toBe(200);
});

it('rejects malformed keys before reserving work', function (string $key) {
    $response = app(EnsureIdempotentApiRequest::class)->handle(atomicWriteRequest(User::factory()->create(), $key), fn () => throw new RuntimeException('Must refuse'));
    expect($response->getStatusCode())->toBe(400)
        ->and(ApiIdempotencyKey::query()->count())->toBe(0);
})->with(['', 'key with spaces', str_repeat('x', 256)]);

it('does not replay an update key for a different route resource', function () {
    $user = User::factory()->create();
    $middleware = app(EnsureIdempotentApiRequest::class);
    $make = function (string $id) use ($user): Request {
        $request = atomicWriteRequest($user);
        $route = new RoutingRoute('PUT', 'api/v2/user-bets/{bet}', fn () => null);
        $route->name('v2.user-bets.update');
        $request->setMethod('PUT');
        $request->server->set('REQUEST_URI', '/api/v2/user-bets/'.$id);
        $route->bind(Request::create('/api/v2/user-bets/'.$id, 'PUT'));
        $request->setRouteResolver(fn () => $route);

        return $request;
    };
    $middleware->handle($make('1'), fn () => response()->json(['ok' => true]));
    $conflict = $middleware->handle($make('2'), fn () => throw new RuntimeException('Must reject another resource'));
    expect($conflict->getStatusCode())->toBe(409);
});

it('replays every classified controller without external dispatch or delivery', function (string $operation) {
    config()->set('cbb_bracket.locks.2026', null);
    Http::preventStrayRequests();
    Bus::fake();
    Mail::fake();
    Notification::fake();
    $user = User::factory()->create();
    $bet = UserBet::factory()->create(['user_id' => $user->id]);
    $group = Group::query()->create(['owner_id' => $user->id, 'name' => 'Existing group', 'type' => 'bracket_pool']);
    $bracket = CbbBracket::query()->create(['user_id' => $user->id, 'season' => 2026, 'picks' => []]);
    UserAlertPreference::factory()->create(['user_id' => $user->id]);
    $session = app(DeviceSessionTokenService::class)->issue($user, 'Atomic test', 'ios')->deviceSession;
    app(DevicePushRegistrationService::class)->register($session, 'fcm', 'synthetic-device-token');
    $sessionId = $session->public_id;
    [$method, $uri, $payload] = match ($operation) {
        'v2.user-bets.store' => ['POST', '/api/v2/user-bets', ['bet_amount' => 10, 'odds' => '-110', 'bet_type' => 'moneyline']],
        'v2.user-bets.update' => ['PUT', '/api/v2/user-bets/'.$bet->id, ['result' => 'won']],
        'v2.user-bets.destroy' => ['DELETE', '/api/v2/user-bets/'.$bet->id, []],
        'v2.cbb-brackets.store' => ['POST', '/api/v2/cbb-brackets', ['season' => 2026, 'picks' => ['game:1' => 'team:10']]],
        'v2.cbb-brackets.current.upsert' => ['PUT', '/api/v2/cbb-brackets/current', ['season' => 2026, 'picks' => ['game:1' => 'team:10']]],
        'v2.cbb-brackets.update' => ['PATCH', '/api/v2/cbb-brackets/'.$bracket->public_id, ['name' => 'Updated']],
        'v2.cbb-brackets.destroy' => ['DELETE', '/api/v2/cbb-brackets/'.$bracket->public_id, []],
        'v2.groups.store' => ['POST', '/api/v2/groups', ['name' => 'New group']],
        'v2.groups.update' => ['PATCH', '/api/v2/groups/'.$group->public_id, ['name' => 'Updated']],
        'v2.alert-preferences.store' => ['POST', '/api/v2/alert-preferences', [
            'enabled' => true, 'notification_types' => ['email'], 'minimum_edge' => 5,
            'time_window_start' => '08:00', 'time_window_end' => '22:00', 'digest_mode' => 'realtime',
        ]],
        'v2.alert-preferences.update' => ['PUT', '/api/v2/alert-preferences', ['enabled' => false]],
        'v2.auth.device-sessions.push-registrations.store' => ['POST', '/api/v2/auth/device-sessions/'.$sessionId.'/push-registrations', ['provider' => 'fcm', 'device_token' => 'synthetic-device-token']],
        'v2.auth.device-sessions.push-registrations.destroy' => ['DELETE', '/api/v2/auth/device-sessions/'.$sessionId.'/push-registrations/fcm', []],
        'v2.auth.device-sessions.destroy' => ['DELETE', '/api/v2/auth/device-sessions/'.$sessionId, []],
    };
    $headers = ['Idempotency-Key' => 'all-classified-operations'];
    $first = $this->actingAs($user)->json($method, $uri, $payload, $headers);
    $first->assertSuccessful()->assertHeader('Idempotency-Replayed', 'false');
    $second = $this->json($method, $uri, $payload, $headers);
    $second->assertStatus($first->getStatusCode())->assertHeader('Idempotency-Replayed', 'true');
    expect($second->getContent())->toBe($first->getContent())
        ->and(ApiIdempotencyKey::query()->count())->toBe(1);
    Bus::assertNothingDispatched();
    Mail::assertNothingSent();
    Mail::assertNothingQueued();
    Notification::assertNothingSent();
})->with(array_keys(AtomicIdempotentRoutes::operations()));

it('refuses to roll back atomic provenance while preserving existing receipts', function () {
    $user = User::factory()->create();
    app(EnsureIdempotentApiRequest::class)->handle(atomicWriteRequest($user), fn () => response()->json(['ok' => true]));
    $receipt = ApiIdempotencyKey::query()->sole();
    $migration = require database_path('migrations/2026_11_25_010000_add_atomic_mode_to_api_idempotency_keys.php');

    expect(fn () => $migration->down())->toThrow(LogicException::class, 'Preserve atomic idempotency provenance')
        ->and(Schema::hasColumn('api_idempotency_keys', 'execution_mode'))->toBeTrue()
        ->and($receipt->fresh()->execution_mode)->toBe(ApiIdempotencyKey::ATOMIC_LOCAL)
        ->and($receipt->fresh()->completed_at)->not->toBeNull()
        ->and(ApiIdempotencyKey::query()->count())->toBe(1);
});
