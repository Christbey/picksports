<?php

namespace App\Http\Controllers;

use App\Http\Resources\UserAlertPreferenceResource;
use App\Models\UserAlertPreference;
use App\Support\Validation\AlertPreferenceRules;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

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

        $preference = UserAlertPreference::updateOrCreate(
            ['user_id' => $request->user()->id],
            $validated
        );

        return new UserAlertPreferenceResource($preference);
    }

    public function update(Request $request): UserAlertPreferenceResource
    {
        $preference = UserAlertPreference::where('user_id', $request->user()->id)->firstOrFail();

        $validated = $request->validate(AlertPreferenceRules::apiUpdate());

        $preference->update($validated);

        return new UserAlertPreferenceResource($preference->fresh());
    }
}
