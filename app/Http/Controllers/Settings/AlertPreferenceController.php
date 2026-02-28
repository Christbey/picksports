<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\UserAlertPreference;
use App\Services\AlertService;
use App\Services\Settings\AlertPreferencePageDataService;
use App\Support\Validation\AlertPreferenceRules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AlertPreferenceController extends Controller
{
    public function __construct(private readonly AlertPreferencePageDataService $pageDataService) {}

    public function edit(Request $request): Response
    {
        return Inertia::render('settings/AlertPreferences', $this->pageDataService->build($request->user()));
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate(AlertPreferenceRules::settingsUpdate());

        UserAlertPreference::updateOrCreate(
            ['user_id' => $request->user()->id],
            $validated
        );

        return $this->routeFlash('alert-preferences.edit', 'status', 'preferences-updated');
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

        return $this->backFlash('flash', [
            'data' => ['message' => $message],
        ]);
    }
}
