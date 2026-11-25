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

    public function getPlays(string $eventId, string $competitionId): ?array
    {
        $response = parent::getPlays($eventId, $competitionId);
        if ($response === null || ! isset($response['items']) || ! is_array($response['items'])) {
            return null;
        }

        $count = (int) ($response['count'] ?? count($response['items']));
        $pageCount = (int) ($response['pageCount'] ?? 1);
        if ($pageCount < 1 || $pageCount > 10) {
            throw new \RuntimeException('Invalid CBB play pagination; preserving stored plays.');
        }
        $items = $response['items'];
        $url = $this->buildUrl('core', 'plays', compact('eventId', 'competitionId'));
        for ($page = 2; $page <= $pageCount; $page++) {
            $next = $this->get($url.'&page='.$page, false);
            if (! is_array($next) || ! is_array($next['items'] ?? null)
                || (int) ($next['pageIndex'] ?? 0) !== $page
                || (int) ($next['count'] ?? -1) !== $count) {
                throw new \RuntimeException('Incomplete CBB play pagination; preserving stored plays.');
            }
            $items = [...$items, ...$next['items']];
        }

        $ids = array_column($items, 'id');
        if (count($items) !== $count || count($ids) !== $count || count(array_unique($ids)) !== $count) {
            throw new \RuntimeException('Incomplete or duplicate CBB plays; preserving stored plays.');
        }
        $response['items'] = $items;

        return $response;
    }
}
