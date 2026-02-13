<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsSubscribed
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, ?string $tier = null): Response
    {
        $user = $request->user();

        if (! $user) {
            return redirect()->route('login');
        }

        if (! $user->subscribed()) {
            return $this->redirectToSubscriptionPage($request);
        }

        if ($tier && ! $this->hasRequiredTier($user, $tier)) {
            return $this->redirectToUpgradePage($request, $tier);
        }

        return $next($request);
    }

    protected function redirectToSubscriptionPage(Request $request): Response
    {
        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'An active subscription is required to access this resource.',
            ], 403);
        }

        return Inertia::location(route('subscription.plans'));
    }

    protected function redirectToUpgradePage(Request $request, string $requiredTier): Response
    {
        if ($request->expectsJson()) {
            return response()->json([
                'message' => "This feature requires a {$requiredTier} subscription or higher.",
            ], 403);
        }

        return Inertia::location(route('subscription.plans'));
    }

    protected function hasRequiredTier($user, string $requiredTier): bool
    {
        $tiers = array_keys(config('subscriptions.tiers'));
        $currentTier = $this->getUserTier($user);

        $requiredTierIndex = array_search($requiredTier, $tiers);
        $currentTierIndex = array_search($currentTier, $tiers);

        return $currentTierIndex !== false && $currentTierIndex >= $requiredTierIndex;
    }

    protected function getUserTier($user): string
    {
        if (! $user->subscribed()) {
            return config('subscriptions.default_tier');
        }

        foreach (config('subscriptions.tiers') as $tier => $config) {
            if ($config['stripe_price_id']['monthly'] && $user->subscribedToPrice($config['stripe_price_id']['monthly'])) {
                return $tier;
            }

            if ($config['stripe_price_id']['yearly'] && $user->subscribedToPrice($config['stripe_price_id']['yearly'])) {
                return $tier;
            }
        }

        return config('subscriptions.default_tier');
    }
}
