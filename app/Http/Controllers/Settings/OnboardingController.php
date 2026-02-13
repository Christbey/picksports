<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OnboardingController extends Controller
{
    /**
     * Show the onboarding settings page.
     */
    public function __invoke(Request $request): Response
    {
        return Inertia::render('settings/OnboardingSettings');
    }
}
