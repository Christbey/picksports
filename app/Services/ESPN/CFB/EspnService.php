<?php

namespace App\Services\ESPN\CFB;

use App\Services\ESPN\BaseEspnService;

class EspnService extends BaseEspnService
{
    protected const SPORT_KEY = 'cfb';

    protected const SCOREBOARD_USE_CACHE = false;

    protected const SCOREBOARD_EVENT_LIMIT = 200;

    protected const SCOREBOARD_EVENT_GROUPS = 80;
}
