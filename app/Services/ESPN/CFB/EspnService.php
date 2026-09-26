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

    public function getPlays(string $eventId, string $competitionId): ?array
    {
        $response = parent::getPlays($eventId, $competitionId);
        if (! $response) {
            return null;
        }
        $pages = (int) ($response['pageCount'] ?? 1);
        if ($pages < 1 || $pages > 100) {
            return null;
        }
        $items = $response['items'] ?? [];
        for ($page = 2; $page <= $pages; $page++) {
            $url = $this->buildUrl('core', 'plays', ['eventId' => $eventId, 'competitionId' => $competitionId]);
            $next = $this->getByRef($url.(str_contains($url, '?') ? '&' : '?').'page='.$page, false);
            if (! is_array($next['items'] ?? null) || (int) ($next['pageIndex'] ?? $page) !== $page
                || ($next['count'] ?? null) !== ($response['count'] ?? null)) {
                return null;
            }
            $items = [...$items, ...$next['items']];
        }

        return [...$response, 'items' => $items];
    }

    public function getLiveGame(string $eventId): ?array
    {
        return $this->get($this->buildUrl('site', 'summary', ['eventId' => $eventId]), false);
    }

    public function getTeamsPage(int $page = 1): ?array
    {
        $limit = $this->teamsLimit ?? 500;
        $url = "https://sports.core.api.espn.com/v2/sports/football/leagues/college-football/teams?limit={$limit}&page={$page}";

        return $this->getByRef($url, false);
    }
}
