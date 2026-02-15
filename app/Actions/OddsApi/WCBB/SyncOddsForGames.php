<?php

namespace App\Actions\OddsApi\WCBB;

use App\Models\WCBB\Game;
use App\Services\OddsApi\OddsApiService;

class SyncOddsForGames
{
    protected string $sport = 'basketball_wncaab';

    public function __construct(
        protected OddsApiService $oddsApiService
    ) {}

    public function execute(?int $daysAhead = 7): int
    {
        $oddsData = $this->oddsApiService->getWcbbOdds();

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
            // WCBB teams use school, mascot, and abbreviation (similar to CBB)
            $homeNames = array_filter([
                $game->homeTeam->school ?? '',
                $game->homeTeam->mascot ?? '',
                $game->homeTeam->abbreviation ?? '',
            ]);

            $awayNames = array_filter([
                $game->awayTeam->school ?? '',
                $game->awayTeam->mascot ?? '',
                $game->awayTeam->abbreviation ?? '',
            ]);

            if ($this->oddsApiService->fuzzyMatchTeams(
                $event['home_team'],
                $event['away_team'],
                $homeNames,
                $awayNames,
                85.0,
                $this->sport
            )) {
                return $game;
            }
        }

        return null;
    }
}
