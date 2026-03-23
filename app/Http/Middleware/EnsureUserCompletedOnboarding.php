<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserCompletedOnboarding
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || $user->hasCompletedRequiredOnboarding()) {
            return $next($request);
        }

        if ($request->routeIs('oauth.onboarding.*') || $request->routeIs('logout')) {
            return $next($request);
        }

        return redirect()->guest(route('oauth.onboarding.show', absolute: false));
    }
}
