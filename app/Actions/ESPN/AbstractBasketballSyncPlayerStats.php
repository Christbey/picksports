<?php

namespace App\Actions\ESPN;

use Illuminate\Database\Eloquent\Model;

abstract class AbstractBasketballSyncPlayerStats
{
    protected const TEAM_MODEL_CLASS = Model::class;

    protected const PLAYER_MODEL_CLASS = Model::class;

    protected const PLAYER_STAT_MODEL_CLASS = Model::class;

    protected const SKIP_DNP_OR_EMPTY_STATS = false;

    public function execute(array $gameData, Model $game): int
    {
        if (! isset($gameData['boxscore']['players'])) {
            return 0;
        }

        $playerStatModel = $this->playerStatModelClass();
        $playerStatModel::query()->where('game_id', $game->id)->delete();

        $synced = 0;
        $teamModel = $this->teamModelClass();
        $playerModel = $this->playerModelClass();

        foreach ($gameData['boxscore']['players'] as $teamData) {
            $teamEspnId = $teamData['team']['id'] ?? null;

            if (! $teamEspnId) {
                continue;
            }

            $team = $teamModel::query()->where('espn_id', $teamEspnId)->first();

            if (! $team) {
                continue;
            }

            if (! isset($teamData['statistics'][0]['athletes'])) {
                continue;
            }

            $athletes = $teamData['statistics'][0]['athletes'];

            foreach ($athletes as $athleteData) {
                $playerEspnId = $athleteData['athlete']['id'] ?? null;

                if (! $playerEspnId) {
                    continue;
                }

                $player = $playerModel::query()->where('espn_id', $playerEspnId)->first();

                if (! $player || $this->shouldSkipAthlete($athleteData)) {
                    continue;
                }

                $stats = $athleteData['stats'] ?? [];

                [$fgMade, $fgAttempted] = $this->parseMadeAttempt($stats[2] ?? null);
                [$threeMade, $threeAttempted] = $this->parseMadeAttempt($stats[3] ?? null);
                [$ftMade, $ftAttempted] = $this->parseMadeAttempt($stats[4] ?? null);

                $playerStatModel::create([
                    'player_id' => $player->id,
                    'game_id' => $game->id,
                    'team_id' => $team->id,
                    'minutes_played' => $stats[0] ?? null,
                    'points' => isset($stats[1]) ? (int) $stats[1] : 0,
                    'field_goals_made' => $fgMade,
                    'field_goals_attempted' => $fgAttempted,
                    'three_point_made' => $threeMade,
                    'three_point_attempted' => $threeAttempted,
                    'free_throws_made' => $ftMade,
                    'free_throws_attempted' => $ftAttempted,
                    'rebounds_total' => isset($stats[5]) ? (int) $stats[5] : 0,
                    'assists' => isset($stats[6]) ? (int) $stats[6] : 0,
                    'turnovers' => isset($stats[7]) ? (int) $stats[7] : 0,
                    'steals' => isset($stats[8]) ? (int) $stats[8] : 0,
                    'blocks' => isset($stats[9]) ? (int) $stats[9] : 0,
                    'rebounds_offensive' => isset($stats[10]) ? (int) $stats[10] : 0,
                    'rebounds_defensive' => isset($stats[11]) ? (int) $stats[11] : 0,
                    'fouls' => isset($stats[12]) ? (int) $stats[12] : 0,
                ]);

                $synced++;
            }
        }

        return $synced;
    }

    protected function shouldSkipAthlete(array $athleteData): bool
    {
        if (static::SKIP_DNP_OR_EMPTY_STATS) {
            return ($athleteData['didNotPlay'] ?? false) || empty($athleteData['stats']);
        }

        return false;
    }

    /**
     * @return array{0:int,1:int}
     */
    protected function parseMadeAttempt(?string $value): array
    {
        if (! $value) {
            return [0, 0];
        }

        $parts = explode('-', $value);

        return [
            isset($parts[0]) ? (int) $parts[0] : 0,
            isset($parts[1]) ? (int) $parts[1] : 0,
        ];
    }

    /**
     * @return class-string<Model>
     */
    protected function teamModelClass(): string
    {
        if (static::TEAM_MODEL_CLASS === Model::class) {
            throw new \RuntimeException('TEAM_MODEL_CLASS must be defined.');
        }

        return static::TEAM_MODEL_CLASS;
    }

    /**
     * @return class-string<Model>
     */
    protected function playerModelClass(): string
    {
        if (static::PLAYER_MODEL_CLASS === Model::class) {
            throw new \RuntimeException('PLAYER_MODEL_CLASS must be defined.');
        }

        return static::PLAYER_MODEL_CLASS;
    }

    /**
     * @return class-string<Model>
     */
    protected function playerStatModelClass(): string
    {
        if (static::PLAYER_STAT_MODEL_CLASS === Model::class) {
            throw new \RuntimeException('PLAYER_STAT_MODEL_CLASS must be defined.');
        }

        return static::PLAYER_STAT_MODEL_CLASS;
    }
}
