<?php

use App\Http\Middleware\EnsureIdempotentApiRequest;
use App\Models\ApiIdempotencyKey;
use App\Models\User;
use App\Models\UserBet;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Http::preventStrayRequests();
    Notification::fake();
    config()->set('database.connections.synthetic_receipt_other', [
        'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
    ]);
});

function receiptBoundaryRequest(User $user): Request
{
    $request = Request::create('/api/v2/user-bets', 'POST', ['bet_amount' => 10]);
    $request->headers->set('Idempotency-Key', 'synthetic-receipt-boundary');
    $request->setUserResolver(fn () => $user);
    $route = new RoutingRoute('POST', 'api/v2/user-bets', fn () => null);
    $route->name('v2.user-bets.store')->bind($request);
    $request->setRouteResolver(fn () => $route);

    return $request;
}

it('rolls back a business write when the final receipt is cancelled rewritten or removed', function (string $intervention) {
    $user = User::factory()->create();
    $request = receiptBoundaryRequest($user);
    $middleware = app(EnsureIdempotentApiRequest::class);
    $dispatcher = ApiIdempotencyKey::getEventDispatcher();
    ApiIdempotencyKey::setEventDispatcher(clone $dispatcher);
    $reached = false;
    $event = $intervention === 'deleted_after_update' ? 'updated' : 'updating';
    ApiIdempotencyKey::{$event}(function (ApiIdempotencyKey $receipt) use (&$reached, $intervention) {
        if ($receipt->completed_at === null) {
            return null;
        }
        $reached = true;
        if ($intervention === 'cancelled') {
            return false;
        }
        if ($intervention === 'body_rewrite') {
            $receipt->response_body = '{"id":"unexpected"}';
        } elseif ($intervention === 'principal_rewrite') {
            $receipt->principal_id = '999999';
        } elseif ($intervention === 'key_rewrite') {
            $receipt->key_hash = str_repeat('a', 64);
        } elseif ($intervention === 'primary_rewrite') {
            $receipt->id = 900000;
        } elseif ($intervention === 'connection_rewrite') {
            $receipt->setConnection('synthetic_receipt_other');
        } elseif ($intervention === 'deleted_after_update') {
            $receipt->getConnection()->table('api_idempotency_keys')->where('id', $receipt->id)->delete();
        }

        return null;
    });
    $error = null;
    try {
        $middleware->handle($request, fn () => response()->json(['id' => UserBet::factory()->create(['user_id' => $user->id])->id], 201));
    } catch (Throwable $caught) {
        $error = $caught;
    } finally {
        ApiIdempotencyKey::setEventDispatcher($dispatcher);
    }
    fwrite(STDERR, json_encode(['intervention' => $intervention, 'reached' => $reached,
        'business_rows' => UserBet::count(), 'receipt_rows' => ApiIdempotencyKey::count(),
        'exception' => $error === null ? null : $error::class]).PHP_EOL);
    expect($reached)->toBeTrue()->and($error)->toBeInstanceOf(RuntimeException::class)
        ->and(UserBet::count())->toBe(0)->and(ApiIdempotencyKey::count())->toBe(1);
    $pending = ApiIdempotencyKey::sole();
    expect($pending->completed_at)->toBeNull()->and($pending->response_body)->toBeNull()
        ->and($pending->principal_id)->toBe((string) $user->id);
    $response = $middleware->handle($request, fn () => response()->json(['id' => UserBet::factory()->create(['user_id' => $user->id])->id], 201));
    $replay = $middleware->handle($request, fn () => throw new RuntimeException('Completed retry must replay'));
    expect($response->getStatusCode())->toBe(201)->and($replay->getContent())->toBe($response->getContent())
        ->and($replay->headers->get('Idempotency-Replayed'))->toBe('true')->and(UserBet::count())->toBe(1);
    Http::assertNothingSent();
    Notification::assertNothingSent();
})->with(['cancelled', 'body_rewrite', 'principal_rewrite', 'deleted_after_update', 'key_rewrite', 'primary_rewrite', 'connection_rewrite']);

it('preserves a valid real receipt and replays exact zero values without another business write', function () {
    $user = User::factory()->create();
    $request = receiptBoundaryRequest($user);
    $middleware = app(EnsureIdempotentApiRequest::class);
    $first = $middleware->handle($request, function () use ($user) {
        UserBet::factory()->create(['user_id' => $user->id]);

        return response()->json(['zero' => 0, 'nested' => ['value' => 0.0]], 201);
    });
    $before = ApiIdempotencyKey::sole()->getRawOriginal();
    $replay = $middleware->handle($request, fn () => throw new RuntimeException('Must replay'));
    expect($first->getStatusCode())->toBe(201)->and($replay->getStatusCode())->toBe(201)
        ->and($replay->getContent())->toBe($first->getContent())->and($replay->headers->get('Idempotency-Replayed'))->toBe('true')
        ->and(ApiIdempotencyKey::sole()->getRawOriginal())->toBe($before)->and(UserBet::count())->toBe(1);
    Http::assertNothingSent();
    Notification::assertNothingSent();
});
