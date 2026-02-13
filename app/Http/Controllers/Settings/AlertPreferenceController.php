<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\NotificationTemplate;
use App\Models\UserAlertPreference;
use App\Services\AlertService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AlertPreferenceController extends Controller
{
    public function edit(Request $request): Response
    {
        $preference = UserAlertPreference::where('user_id', $request->user()->id)->first();

        $data = [
            'preference' => $preference ? [
                'enabled' => $preference->enabled,
                'sports' => $preference->sports,
                'notification_types' => $preference->notification_types,
                'enabled_template_ids' => $preference->enabled_template_ids ?? [],
                'minimum_edge' => $preference->minimum_edge,
                'time_window_start' => $preference->time_window_start?->format('H:i'),
                'time_window_end' => $preference->time_window_end?->format('H:i'),
                'digest_mode' => $preference->digest_mode,
                'digest_time' => $preference->digest_time?->format('H:i'),
                'phone_number' => $preference->phone_number,
            ] : null,
            'availableTemplates' => NotificationTemplate::query()
                ->active()
                ->orderBy('name')
                ->get(['id', 'name', 'description'])
                ->toArray(),
        ];

        if ($request->user()->isAdmin() || $request->user()->can('view-alert-stats')) {
            $data['adminStats'] = [
                'total_users_with_alerts' => UserAlertPreference::where('enabled', true)->count(),
                'total_preferences' => UserAlertPreference::count(),
                'users_by_sport' => [
                    'nfl' => UserAlertPreference::whereJsonContains('sports', 'nfl')->count(),
                    'nba' => UserAlertPreference::whereJsonContains('sports', 'nba')->count(),
                    'cbb' => UserAlertPreference::whereJsonContains('sports', 'cbb')->count(),
                    'wcbb' => UserAlertPreference::whereJsonContains('sports', 'wcbb')->count(),
                    'mlb' => UserAlertPreference::whereJsonContains('sports', 'mlb')->count(),
                    'cfb' => UserAlertPreference::whereJsonContains('sports', 'cfb')->count(),
                    'wnba' => UserAlertPreference::whereJsonContains('sports', 'wnba')->count(),
                ],
            ];
        }

        return Inertia::render('settings/AlertPreferences', $data);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'enabled' => 'required|boolean',
            'sports' => 'required|array',
            'sports.*' => 'string|in:nfl,nba,cbb,wcbb,mlb,cfb,wnba',
            'notification_types' => 'required|array',
            'notification_types.*' => 'string|in:email,sms,push',
            'enabled_template_ids' => 'nullable|array',
            'enabled_template_ids.*' => 'integer|exists:notification_templates,id',
            'minimum_edge' => 'required|numeric|min:0|max:100',
            'time_window_start' => 'required|date_format:H:i',
            'time_window_end' => 'required|date_format:H:i',
            'digest_mode' => 'required|in:realtime,daily_summary',
            'digest_time' => 'nullable|date_format:H:i',
            'phone_number' => 'nullable|string|max:20',
        ]);

        UserAlertPreference::updateOrCreate(
            ['user_id' => $request->user()->id],
            $validated
        );

        return to_route('alert-preferences.edit')->with('status', 'preferences-updated');
    }

    public function checkAlerts(Request $request, AlertService $alertService): RedirectResponse
    {
        if (! $request->user()->isAdmin() && ! $request->user()->can('trigger-alerts')) {
            abort(403);
        }

        $sport = $request->input('sport');

        if ($sport) {
            $result = $alertService->checkForValueOpportunities($sport);
            $message = "Checked {$sport} - sent {$result} alert(s)";
        } else {
            $results = $alertService->checkAllSports();
            $total = array_sum($results);
            $message = "Checked all sports - sent {$total} total alert(s)";
        }

        return back()->with('flash', [
            'data' => ['message' => $message],
        ]);
    }
}
