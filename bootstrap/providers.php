<?php

use App\Providers\ApiTokenPolicyServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\NativeOAuthServiceProvider;
use App\Providers\RequestTimingServiceProvider;

return [
    AppServiceProvider::class,
    FortifyServiceProvider::class,
    NativeOAuthServiceProvider::class,
    ApiTokenPolicyServiceProvider::class,
    RequestTimingServiceProvider::class,
];
