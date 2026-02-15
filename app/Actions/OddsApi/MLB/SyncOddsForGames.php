<?php

namespace App\Actions\OddsApi\MLB;

use App\Models\MLB\Game;
use App\Services\OddsApi\OddsApiService;

class SyncOddsForGames
{
    protected string $sport = 'baseball_mlb';

    public function __construct(
        protected OddsApiService $oddsApiService
    ) {}

    public function execute(?int $daysAhead = 7): int
    {
        $oddsData = $this->oddsApiService->getMlbOdds();

        if (! $oddsData) {
            return 0;
        }

        $updated = 0;

        foreach ($oddsData as $event) {
            if (! isset($event['id'], $event['home_team'], $event['away_team'], $event['commence_time'])) {
                continue;
            }

            $game = $this->matchEvent($event);

            if (! $game) {
                continue;
            }

            $game->update([
                'odds_api_event_id' => $event['id'],
                'odds_data' => $this->oddsApiService->extractOddsData($event),
                'odds_updated_at' => now(),
            ]);

            $updated++;
        }

        return $updated;
    }

    protected function matchEvent(array $event): ?Game
    {
        $commenceTime = $event['commence_time'];
        $gameDate = date('Y-m-d', strtotime($commenceTime));

        $games = Game::query()
            ->with(['homeTeam', 'awayTeam'])
            ->whereDate('game_date', $gameDate)
            ->get();

        foreach ($games as $game) {
            // MLB teams have location and name fields (e.g., "New York" "Yankees")
            $homeNames = array_filter([
                $game->homeTeam->location ?? '',
                $game->homeTeam->name ?? '',
            ]);

            $awayNames = array_filter([
                $game->awayTeam->location ?? '',
                $game->awayTeam->name ?? '',
            ]);

            if ($this->oddsApiService->fuzzyMatchTeams(
                $event['home_team'],
                $event['away_team'],
                $homeNames,
                $awayNames,
                80.0,
                $this->sport
            )) {
                return $game;
            }
        }

        return null;
    }
}
