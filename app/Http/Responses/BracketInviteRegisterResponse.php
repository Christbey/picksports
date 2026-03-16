<?php

namespace App\Http\Responses;

use Illuminate\Http\RedirectResponse;
use Laravel\Fortify\Contracts\RegisterResponse;

class BracketInviteRegisterResponse implements RegisterResponse
{
    public function toResponse($request): RedirectResponse
    {
        $redirectTo = $request->session()->pull('group_invitation.redirect_to')
            ?? $request->session()->pull('group_join_link.redirect_to')
            ?? route('dashboard', absolute: false);

        $request->session()->forget('group_invitation.token');
        $request->session()->forget('group_join_link.token');

        return redirect()->intended($redirectTo);
    }
}
