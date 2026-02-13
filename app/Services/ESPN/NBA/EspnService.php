<?php

namespace App\Services\ESPN\NBA;

use App\Services\ESPN\BaseEspnService;

class EspnService extends BaseEspnService
{
    public function __construct()
    {
        parent::__construct('nba');
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
        if ($date) {
            $url .= "?dates={$date}";
        }

        return $this->get($url);
    }

    public function getGame(string $eventId): ?array
    {
        $url = $this->buildUrl('site', 'summary', ['eventId' => $eventId]);

        return $this->get($url);
    }

    public function getPlays(string $eventId, string $competitionId): ?array
    {
        $url = $this->buildUrl('core', 'plays', [
            'eventId' => $eventId,
            'competitionId' => $competitionId,
        ]);

        return $this->get($url);
    }

    public function getGames(int $season, int $seasonType, int $week): ?array
    {
        $url = $this->buildUrl('core', 'weekly_events', [
            'year' => $season,
            'seasonType' => $seasonType,
            'week' => $week,
        ]);

        return $this->get($url);
    }
}
