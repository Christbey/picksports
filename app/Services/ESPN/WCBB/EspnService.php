<?php

namespace App\Services\ESPN\WCBB;

use App\Services\ESPN\AbstractCollegeBasketballEspnService;

class EspnService extends AbstractCollegeBasketballEspnService
{
    protected const SPORT_KEY = 'wcbb';

    protected const TEAMS_LIMIT = 500;

    protected const PLAYS_ENABLED = false;

    protected const WEEKLY_EVENTS_ENABLED = false;
}
