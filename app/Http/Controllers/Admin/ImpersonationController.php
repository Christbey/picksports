<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ImpersonationController extends Controller
{
    private const SESSION_KEY = 'impersonator_id';

    public function start(Request $request, User $user): RedirectResponse
    {
        $admin = $request->user();

        if (! $admin || ! $admin->isAdmin()) {
            abort(403, 'Unauthorized access.');
        }

        if ($request->session()->has(self::SESSION_KEY)) {
            return $this->backError('Stop the active impersonation session before starting another one.');
        }

        if ($admin->is($user)) {
            return $this->backError('You cannot impersonate your own account.');
        }

        $request->session()->put(self::SESSION_KEY, $admin->id);
        Auth::loginUsingId($user->id);
        $request->session()->regenerate();

        return redirect()
            ->route('dashboard')
            ->with('success', "Now impersonating {$user->name}.");
    }

    public function stop(Request $request): RedirectResponse
    {
        $impersonatorId = $request->session()->pull(self::SESSION_KEY);

        if (! $impersonatorId) {
            return $this->backError('No active impersonation session found.');
        }

        $impersonator = User::query()->find($impersonatorId);

        if (! $impersonator || ! $impersonator->isAdmin()) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()
                ->route('login')
                ->with('error', 'Original admin account is no longer available.');
        }

        Auth::login($impersonator);
        $request->session()->regenerate();

        return redirect()
            ->route('admin.subscriptions')
            ->with('success', 'Impersonation ended. You are back on your admin account.');
    }
}
