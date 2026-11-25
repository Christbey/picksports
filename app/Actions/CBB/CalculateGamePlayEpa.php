<?php

namespace App\Actions\CBB;

use App\Models\CBB\Game;
use App\Models\CBB\Play;
use App\Services\NBA\TrueEpaCalculator;

class CalculateGamePlayEpa
{
    public function __construct(private TrueEpaCalculator $calculator) {}

    public function execute(Game $game): int
    {
        return $game->getConnection()->transaction(function () use ($game): int {
            Game::query()->whereKey($game->id)->lockForUpdate()->firstOrFail();
            $plays = Play::query()->where('game_id', $game->id)->orderBy('sequence_number')->orderBy('id')->get();
            $results = $this->calculator->calculateForGame($plays, (int) $game->home_team_id, (int) $game->away_team_id, (int) $game->season, 'cbb');
            $timestamp = now()->toDateTimeString();
            $rows = [];
            $eligible = 0;
            foreach ($plays as $play) {
                $result = $results[$play->id];
                $eligible += $result['eligible'] ? 1 : 0;
                $rows[] = [...$play->getAttributes(),
                    'is_epa_eligible' => $result['eligible'],
                    'expected_points_before' => $result['ep_before'],
                    'expected_points_after' => $result['ep_after'],
                    'true_epa' => $result['epa'],
                    'epa_calculated_at' => $timestamp,
                    'updated_at' => $timestamp,
                ];
            }
            if ($eligible === 0) {
                throw new \RuntimeException('No attributable EPA plays after repair.');
            }
            foreach (array_chunk($rows, 250) as $chunk) {
                Play::query()->upsert($chunk, ['id'], ['is_epa_eligible', 'expected_points_before', 'expected_points_after', 'true_epa', 'epa_calculated_at', 'updated_at']);
            }

            return $eligible;
        });
    }
}
