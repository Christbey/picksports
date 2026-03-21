<?php

namespace App\Http\Controllers;

use App\Http\Resources\UserAlertPreferenceResource;
use App\Models\UserAlertPreference;
use App\Support\SportCatalog;
use App\Support\Validation\AlertPreferenceRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AlertPreferenceController extends Controller
{
    public function show(Request $request): UserAlertPreferenceResource|JsonResponse
    {
        $preference = UserAlertPreference::where('user_id', $request->user()->id)->first();

        if (! $preference) {
            return response()->json([
                'message' => 'No alert preferences found. Please create your preferences first.',
                'data' => null,
            ], 404);
        }

        return new UserAlertPreferenceResource($preference);
    }

    public function store(Request $request): UserAlertPreferenceResource
    {
        $validated = $request->validate(AlertPreferenceRules::apiStore());
        $existing = UserAlertPreference::query()
            ->where('user_id', $request->user()->id)
            ->first();

        $sports = is_array($existing?->sports) && ! empty($existing->sports)
            ? $existing->sports
            : SportCatalog::ALL;

        $preference = UserAlertPreference::updateOrCreate(
            ['user_id' => $request->user()->id],
            [...$validated, 'sports' => $sports]
        );

        return new UserAlertPreferenceResource($preference);
    }

    public function update(Request $request): UserAlertPreferenceResource
    {
        $preference = UserAlertPreference::where('user_id', $request->user()->id)->firstOrFail();

        $validated = $request->validate(AlertPreferenceRules::apiUpdate());

        $sports = is_array($preference->sports) && ! empty($preference->sports)
            ? $preference->sports
            : SportCatalog::ALL;

        $preference->update([...$validated, 'sports' => $sports]);

        return new UserAlertPreferenceResource($preference->fresh());
    }
}
