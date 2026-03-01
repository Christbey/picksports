<?php

namespace App\Http\Middleware;

use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();
        $tier = $user?->subscriptionTier();
        $impersonatorId = $request->session()->get('impersonator_id');
        $isImpersonating = $user !== null && $impersonatorId !== null && (int) $impersonatorId !== (int) $user->id;
        $impersonator = $isImpersonating
            ? User::query()->select(['id', 'name', 'email'])->find($impersonatorId)
            : null;

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $user,
            ],
            'impersonation' => [
                'active' => $isImpersonating,
                'impersonator' => $impersonator
                    ? [
                        'id' => $impersonator->id,
                        'name' => $impersonator->name,
                        'email' => $impersonator->email,
                    ]
                    : null,
            ],
            'subscription' => [
                'tier' => $tier?->slug ?? 'free',
                'tier_name' => $tier?->name ?? 'Free',
                'is_subscribed' => $user?->subscribed() ?? false,
                'features' => $tier?->features ?? [],
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }
}
