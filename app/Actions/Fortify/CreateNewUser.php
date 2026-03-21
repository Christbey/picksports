<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Http\Controllers\Auth\GroupInvitationController;
use App\Http\Controllers\Auth\GroupJoinLinkController;
use App\Models\GroupInvitation;
use App\Models\GroupJoinLink;
use App\Models\User;
use App\Services\Auth\FoundingUserAccessService;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        $inviteToken = $input['invite_token'] ?? null;
        $joinToken = $input['join_token'] ?? null;
        $invitation = $inviteToken
            ? GroupInvitation::query()->with('group')->where('token', $inviteToken)->first()
            : null;
        $joinLink = $joinToken
            ? GroupJoinLink::query()->with('group')->where('token', $joinToken)->first()
            : null;

        $rules = [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
        ];

        if (($invitation && $invitation->isPending()) || ($joinLink && $joinLink->isActive())) {
            $rules['invite_token'] = ['required', 'string'];
            if ($joinLink && $joinLink->isActive()) {
                unset($rules['invite_token']);
                $rules['join_token'] = ['required', 'string'];
            }
        } else {
            $rules['age_verified'] = ['required', 'accepted'];
        }

        Validator::make($input, $rules)->validate();

        if ($invitation && (! $invitation->isPending() || strcasecmp($invitation->email, (string) $input['email']) !== 0)) {
            Validator::make([], [])->after(function ($validator) {
                $validator->errors()->add('email', 'This invite is invalid for the selected email.');
            })->validate();
        }

        if ($joinToken && (! $joinLink || ! $joinLink->isActive())) {
            Validator::make([], [])->after(function ($validator) {
                $validator->errors()->add('email', 'This group join link is no longer active.');
            })->validate();
        }

        $user = User::create([
            'name' => $input['name'],
            'email' => $input['email'],
            'age_verified_at' => now(),
            'password' => $input['password'],
        ]);

        $grantedFoundingRole = app(FoundingUserAccessService::class)
            ->assignFoundingRoleIfEligible($user);

        if (! $grantedFoundingRole) {
            $user->syncRoleFromTier();
        }

        if ($invitation && $invitation->isPending()) {
            GroupInvitationController::acceptInvitation($invitation, $user);
        } elseif ($joinLink && $joinLink->isActive()) {
            GroupJoinLinkController::joinGroup($joinLink->group, $user);
        }

        return $user;
    }
}
