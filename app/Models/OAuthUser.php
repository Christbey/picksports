<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Passport\Contracts\OAuthenticatable;
use Laravel\Passport\HasApiTokens;

/**
 * Passport-specific projection of the users table.
 *
 * The primary User model deliberately keeps Sanctum's token API for existing
 * web and first-party endpoints. This projection prevents the two packages'
 * incompatible HasApiTokens contracts from being mixed on one model.
 */
class OAuthUser extends Authenticatable implements OAuthenticatable
{
    use HasApiTokens;

    protected $table = 'users';

    protected $guarded = [];

    protected $hidden = [
        'password',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'remember_token',
    ];
}
