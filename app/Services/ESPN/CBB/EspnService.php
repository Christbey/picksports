<?php

namespace App\Services\ESPN\CBB;

use App\Services\ESPN\AbstractCollegeBasketballEspnService;

class EspnService extends AbstractCollegeBasketballEspnService
{
    protected const SPORT_KEY = 'cbb';

    protected const TEAMS_LIMIT = 500;

    public function getRosterForSeason(string $teamId, int $season): ?array
    {
        return $this->get($this->buildUrl('site', 'roster', ['teamId' => $teamId]).'?season='.$season);
    }
}
