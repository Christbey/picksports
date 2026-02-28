<?php

namespace App\Services\ESPN;

abstract class AbstractCollegeBasketballEspnService extends BaseEspnService
{
    protected const SCOREBOARD_USE_CACHE = false;

    protected const SCOREBOARD_EVENT_LIMIT = 200;

    protected const SCOREBOARD_EVENT_GROUPS = 50;
}
