<?php

namespace App\Http\Controllers\Auth;

use App\Models\GroupInvitation;
use App\Models\User;
use App\Services\Auth\GroupMembershipAdmission;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class GroupInvitationController
{
    public function show(Request $request, string $token): RedirectResponse|View
    {
        $invitation = GroupInvitation::query()
            ->with('group')
            ->where('token', $token)
            ->firstOrFail();

        abort_unless($invitation->isPending(), 404);

        if ($request->user()) {
            if (strcasecmp($request->user()->email, $invitation->email) !== 0) {
                return redirect()->route('march-madness-bracket')
                    ->with('error', 'This invite is assigned to a different email address.');
            }

            return view('auth.group-acceptance', [
                'groupName' => $invitation->group->name,
                'action' => route('group-invitations.accept', ['token' => $invitation->token]),
            ]);
        }

        $request->session()->put('group_invitation.token', $invitation->token);
        $request->session()->put('group_invitation.redirect_to', route('march-madness-bracket', absolute: false));

        return redirect("/register?invite={$invitation->token}");
    }

    public function accept(Request $request, string $token): RedirectResponse
    {
        $invitation = GroupInvitation::query()->with('group')->where('token', $token)->firstOrFail();
        abort_unless($invitation->isPending(), 404);
        if (strcasecmp($request->user()->email, $invitation->email) !== 0) {
            return redirect()->route('march-madness-bracket')->with('error', 'This invite is assigned to a different email address.');
        }
        self::acceptInvitation($invitation, $request->user());

        return redirect()->route('march-madness-bracket')->with('status', "Joined {$invitation->group->name}.");
    }

    public static function acceptInvitation(GroupInvitation $invitation, User $user): void
    {
        app(GroupMembershipAdmission::class)->acceptInvitation($invitation, $user);
    }
}
