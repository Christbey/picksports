<?php

namespace App\Http\Controllers\Auth;

use App\Models\Group;
use App\Models\GroupJoinLink;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class GroupJoinLinkController
{
    public function show(Request $request, string $token): RedirectResponse
    {
        $joinLink = GroupJoinLink::query()
            ->with('group')
            ->where('token', $token)
            ->firstOrFail();

        abort_unless($joinLink->isActive(), 404);

        if ($request->user()) {
            self::joinGroup($joinLink->group, $request->user());

            return redirect()->route('march-madness-bracket')
                ->with('status', "Joined {$joinLink->group->name}.");
        }

        $request->session()->put('group_join_link.token', $joinLink->token);
        $request->session()->put('group_join_link.redirect_to', route('march-madness-bracket', absolute: false));

        return redirect("/register?join={$joinLink->token}");
    }

    public static function joinGroup(Group $group, User $user): void
    {
        $group->users()->syncWithoutDetaching([
            $user->id => [
                'role' => 'member',
                'joined_at' => now(),
            ],
        ]);
    }
}
