<?php

use App\Http\Middleware\AddServerTiming;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

it('does not accumulate query listeners across requests in one application', function () {
    $middleware = app(AddServerTiming::class);
    $request = Request::create('/timing-probe');
    $dispatcher = DB::connection()->getEventDispatcher();
    $before = count($dispatcher->getListeners(QueryExecuted::class));
    for ($i = 0; $i < 3; $i++) {
        $response = $middleware->handle($request, function () {
            DB::select('select 1');

            return response('ok');
        });
        expect($response->headers->get('Server-Timing'))->toContain('desc="1 queries"');
    }
    expect(count($dispatcher->getListeners(QueryExecuted::class)))->toBe($before);
});

it('logs route templates without credential-bearing request paths', function () {
    config(['performance.slow_request_ms' => 0]);
    Log::spy();
    $request = Request::create('/reset-password/synthetic-secret?email=synthetic@example.test');
    $route = new Route('GET', 'reset-password/{token}', fn () => null);
    $route->name('password.reset');
    $request->setRouteResolver(fn () => $route);
    app(AddServerTiming::class)->handle($request, fn () => response('ok'));
    Log::shouldHaveReceived('warning')->once()->with('Slow application request detected.', Mockery::on(
        fn (array $context) => ($context['path'] ?? null) === 'reset-password/{token}'
            && ! str_contains(json_encode($context), 'synthetic-secret')
            && ! str_contains(json_encode($context), 'synthetic@example.test'),
    ));
});

it('clears failed request measurements and keeps nested request counts independent', function () {
    config(['performance.slow_request_logging_enabled' => false]);
    $middleware = app(AddServerTiming::class);
    $request = Request::create('/timing-probe');
    expect(fn () => $middleware->handle($request, function () {
        DB::select('select 1');
        throw new RuntimeException('Synthetic downstream failure');
    }))->toThrow(RuntimeException::class, 'Synthetic downstream failure');
    DB::select('select 1');
    $outer = $middleware->handle($request, function () use ($middleware, $request) {
        DB::select('select 1');
        $inner = $middleware->handle($request, function () {
            DB::select('select 1');

            return response('inner');
        });
        expect($inner->headers->get('Server-Timing'))->toContain('desc="1 queries"');

        return response('outer');
    });
    expect($outer->headers->get('Server-Timing'))->toContain('desc="2 queries"');
    $disabled = config('performance.server_timing_enabled');
    config(['performance.server_timing_enabled' => false]);
    expect($middleware->handle($request, fn () => response('off'))->headers->has('Server-Timing'))->toBeFalse();
    config(['performance.server_timing_enabled' => $disabled]);
});
