<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\Resources\AuthUserResource;
use App\Support\TierAccessBypass;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FrontendContextController extends Controller
{
    public function __invoke(Request $request, TierAccessBypass $access): JsonResponse
    {
        $user = $request->user();
        $tier = $user->subscriptionTier();
        $bypass = $access->userIsBypassed($user);
        $enforced = $access->tiersEnforced();

        return response()->json([
            'data' => [
                'name' => config('app.name'),
                'auth' => ['user' => (new AuthUserResource($user))->resolve($request)],
                'subscription' => [
                    'tier' => $tier?->slug ?? 'free',
                    'tier_name' => $tier?->name ?? 'Free',
                    'is_subscribed' => ! $enforced || $bypass || $user->subscribed() || $user->hasFoundingAccess(),
                    'is_founding_user' => $user->hasFoundingAccess(),
                    'features' => $tier?->features ?? [],
                    'tiers_enabled' => $enforced && ! $bypass,
                    'tiers_bypassed' => $bypass,
                ],
            ],
            'meta' => ['version' => 'v2', 'contract' => 'auth.context'],
        ])->header('Cache-Control', 'private, no-store');
    }
}
