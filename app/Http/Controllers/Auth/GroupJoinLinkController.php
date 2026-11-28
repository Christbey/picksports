<?php

namespace App\Http\Controllers\Auth;

use App\Models\GroupJoinLink;
use App\Models\User;
use App\Services\Auth\GroupMembershipAdmission;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class GroupJoinLinkController
{
    public function show(Request $request, string $token): RedirectResponse|View
    {
        $joinLink = GroupJoinLink::query()
            ->with('group')
            ->where('token', $token)
            ->firstOrFail();

        abort_unless($joinLink->isActive(), 404);

        if ($request->user()) {
            return view('auth.group-acceptance', [
                'groupName' => $joinLink->group->name,
                'action' => route('groups.join.accept', ['token' => $joinLink->token]),
            ]);
        }

        $request->session()->put('group_join_link.token', $joinLink->token);
        $request->session()->put('group_join_link.redirect_to', route('march-madness-bracket', absolute: false));

        return redirect("/register?join={$joinLink->token}");
    }

    public function accept(Request $request, string $token): RedirectResponse
    {
        $joinLink = GroupJoinLink::query()->with('group')->where('token', $token)->firstOrFail();
        abort_unless($joinLink->isActive(), 404);
        self::acceptJoinLink($joinLink, $request->user());

        return redirect()->route('march-madness-bracket')->with('status', "Joined {$joinLink->group->name}.");
    }

    public static function acceptJoinLink(GroupJoinLink $joinLink, User $user): void
    {
        app(GroupMembershipAdmission::class)->acceptJoinLink($joinLink, $user);
    }
}
