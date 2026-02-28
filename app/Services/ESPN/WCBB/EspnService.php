<?php

namespace App\Services\ESPN\WCBB;

use App\Services\ESPN\AbstractCollegeBasketballEspnService;

class EspnService extends AbstractCollegeBasketballEspnService
{
    protected const SPORT_KEY = 'wcbb';

    protected const PLAYS_ENABLED = false;

    protected const WEEKLY_EVENTS_ENABLED = false;
}
