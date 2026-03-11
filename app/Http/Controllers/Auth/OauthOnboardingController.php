<?php

namespace App\Http\Controllers\Auth;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OauthOnboardingController
{
    public function show(Request $request): Response|RedirectResponse
    {
        if ($request->user()?->hasCompletedRequiredOnboarding()) {
            return redirect()->route('dashboard', absolute: false);
        }

        return Inertia::render('auth/OAuthOnboarding', [
            'termsUrl' => route('terms'),
            'submitUrl' => route('oauth.onboarding.store'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'age_verified' => ['required', 'accepted'],
        ]);

        $request->user()->forceFill([
            'age_verified_at' => now(),
        ])->save();

        return redirect()->intended(route('dashboard', absolute: false))
            ->with('status', $validated['age_verified'] ? 'Account setup complete.' : null);
    }
}
