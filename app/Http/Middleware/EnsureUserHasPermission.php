<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasPermission
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();

        if (! $user) {
            return redirect()->route('login');
        }

        if (! $user->hasPermissionTo($permission)) {
            return $this->denyAccess($request, $permission);
        }

        return $next($request);
    }

    protected function denyAccess(Request $request, string $permission): Response
    {
        if ($request->expectsJson()) {
            return response()->json([
                'message' => "You don't have permission to access this resource. Required permission: {$permission}",
            ], 403);
        }

        return Inertia::location(route('subscription.plans'));
    }
}
