<?php

namespace App\Http\Controllers;

use App\Http\Resources\UserAlertPreferenceResource;
use App\Models\UserAlertPreference;
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
        $validated = $request->validate([
            'enabled' => 'required|boolean',
            'sports' => 'required|array',
            'sports.*' => 'string|in:nfl,nba,cbb,wcbb,mlb,cfb,wnba',
            'notification_types' => 'required|array',
            'notification_types.*' => 'string|in:email,sms,push',
            'minimum_edge' => 'required|numeric|min:0|max:100',
            'time_window_start' => 'required|date_format:H:i',
            'time_window_end' => 'required|date_format:H:i',
            'digest_mode' => 'required|in:realtime,daily_summary',
            'digest_time' => 'nullable|date_format:H:i',
            'phone_number' => 'nullable|string|max:20',
        ]);

        $preference = UserAlertPreference::updateOrCreate(
            ['user_id' => $request->user()->id],
            $validated
        );

        return new UserAlertPreferenceResource($preference);
    }

    public function update(Request $request): UserAlertPreferenceResource
    {
        $preference = UserAlertPreference::where('user_id', $request->user()->id)->firstOrFail();

        $validated = $request->validate([
            'enabled' => 'sometimes|boolean',
            'sports' => 'sometimes|array',
            'sports.*' => 'string|in:nfl,nba,cbb,wcbb,mlb,cfb,wnba',
            'notification_types' => 'sometimes|array',
            'notification_types.*' => 'string|in:email,sms,push',
            'minimum_edge' => 'sometimes|numeric|min:0|max:100',
            'time_window_start' => 'sometimes|date_format:H:i',
            'time_window_end' => 'sometimes|date_format:H:i',
            'digest_mode' => 'sometimes|in:realtime,daily_summary',
            'digest_time' => 'nullable|date_format:H:i',
            'phone_number' => 'nullable|string|max:20',
        ]);

        $preference->update($validated);

        return new UserAlertPreferenceResource($preference->fresh());
    }
}
