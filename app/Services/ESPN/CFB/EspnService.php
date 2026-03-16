<?php

namespace App\Services\ESPN\CFB;

use App\Services\ESPN\BaseEspnService;

class EspnService extends BaseEspnService
{
    protected const SPORT_KEY = 'cfb';

    protected const TEAMS_LIMIT = 500;

    protected const SCOREBOARD_USE_CACHE = false;

    protected const SCOREBOARD_EVENT_LIMIT = 200;

    protected const SCOREBOARD_EVENT_GROUPS = 80;

    public function getTeamsPage(int $page = 1): ?array
    {
        $limit = $this->teamsLimit ?? 500;
        $url = "https://sports.core.api.espn.com/v2/sports/football/leagues/college-football/teams?limit={$limit}&page={$page}";

        return $this->getByRef($url, false);
    }
}
