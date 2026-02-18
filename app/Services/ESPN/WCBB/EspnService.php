<?php

namespace App\Services\ESPN\WCBB;

use App\Services\ESPN\BaseEspnService;

class EspnService extends BaseEspnService
{
    public function __construct()
    {
        parent::__construct('wcbb');
    }

    public function getTeams(): ?array
    {
        $url = $this->buildUrl('site', 'teams');

        return $this->get($url);
    }

    public function getTeam(string $teamId): ?array
    {
        $url = $this->buildUrl('site', 'team', ['teamId' => $teamId]);

        return $this->get($url);
    }

    public function getRoster(string $teamId): ?array
    {
        $url = $this->buildUrl('site', 'roster', ['teamId' => $teamId]);

        return $this->get($url);
    }

    public function getScoreboard(?string $date = null): ?array
    {
        $url = $this->buildUrl('site', 'scoreboard');
        $url .= '?limit=200&groups=50';
        if ($date) {
            $url .= "&dates={$date}";
        }

        return $this->get($url, useCache: false);
    }

    public function getGame(string $eventId): ?array
    {
        $url = $this->buildUrl('site', 'summary', ['eventId' => $eventId]);

        return $this->get($url);
    }

    public function getPlays(string $eventId, string $competitionId): ?array
    {
        // WCBB doesn't have plays endpoint in config, return null
        return null;
    }

    public function getGames(int $season, int $seasonType, int $week): ?array
    {
        // WCBB doesn't have weekly_events endpoint in config, return null
        return null;
    }

    public function getSchedule(string $teamId, ?int $season = null): ?array
    {
        $url = "https://site.api.espn.com/apis/site/v2/sports/basketball/womens-college-basketball/teams/{$teamId}/schedule";

        if ($season) {
            $url .= "?season={$season}";
        }

        return $this->get($url);
    }
}
