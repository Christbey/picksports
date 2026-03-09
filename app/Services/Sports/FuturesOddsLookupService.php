<?php

namespace App\Services\Sports;

use App\Models\Sports\FuturesOdd;

class FuturesOddsLookupService
{
    /**
     * @var array<string, string>
     */
    protected const TEAM_FOREIGN_KEY_BY_SPORT = [
        'nba' => 'nba_team_id',
        'mlb' => 'mlb_team_id',
        'nfl' => 'nfl_team_id',
        'cbb' => 'cbb_team_id',
        'wcbb' => 'wcbb_team_id',
    ];

    /**
     * @return array<int, array{bookmaker:string,market_key:string,price:?int,implied_probability:?float,fetched_at:?string,odds_api_sport_key:string}>
     */
    public function byTeamForSeason(string $sport, int $season): array
    {
        $teamForeignKey = self::TEAM_FOREIGN_KEY_BY_SPORT[$sport] ?? null;
        if ($teamForeignKey === null) {
            return [];
        }

        $rows = FuturesOdd::query()
            ->where('sport', $sport)
            ->where('season', $season)
            ->whereNotNull($teamForeignKey)
            ->orderByDesc('fetched_at')
            ->orderByDesc('id')
            ->get([
                $teamForeignKey,
                'bookmaker',
                'market_key',
                'price',
                'implied_probability',
                'fetched_at',
                'odds_api_sport_key',
            ]);

        $byTeam = [];

        foreach ($rows as $row) {
            $teamId = (int) ($row->{$teamForeignKey} ?? 0);
            if ($teamId <= 0 || isset($byTeam[$teamId])) {
                continue;
            }

            $byTeam[$teamId] = [
                'bookmaker' => (string) $row->bookmaker,
                'market_key' => (string) $row->market_key,
                'price' => $row->price !== null ? (int) $row->price : null,
                'implied_probability' => $row->implied_probability !== null ? (float) $row->implied_probability : null,
                'fetched_at' => $row->fetched_at?->toIso8601String(),
                'odds_api_sport_key' => (string) $row->odds_api_sport_key,
            ];
        }

        return $byTeam;
    }
}
