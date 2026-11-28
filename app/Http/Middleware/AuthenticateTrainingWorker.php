<?php

namespace App\Http\Middleware;

use App\Models\ModelTrainingWorker;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateTrainingWorker
{
    public function handle(Request $request, Closure $next, string $ability): Response
    {
        abort_unless(config('ml.remote.enabled', false), 404);
        $token = $request->bearerToken();
        abort_unless(is_string($token) && strlen($token) >= 64 && strlen($token) <= 256, 401);
        $worker = ModelTrainingWorker::query()->where('token_hash', hash('sha256', $token))->first();
        abort_unless($worker && $worker->revoked_at === null && $worker->expires_at->isFuture(), 401);
        abort_unless(in_array('training:'.$ability, $worker->abilities, true), 403);
        $request->attributes->set('training_worker', $worker);
        $response = $next($request);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
