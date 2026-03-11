<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
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
        Validator::make($input, [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
            'age_verified' => ['required', 'accepted'],
        ])->validate();

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

        return $user;
    }
}
