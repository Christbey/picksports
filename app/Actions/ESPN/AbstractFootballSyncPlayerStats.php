<?php

namespace App\Actions\ESPN;

use Illuminate\Database\Eloquent\Model;

abstract class AbstractFootballSyncPlayerStats
{
    protected const TEAM_MODEL_CLASS = Model::class;

    protected const PLAYER_MODEL_CLASS = Model::class;

    protected const PLAYER_STAT_MODEL_CLASS = Model::class;

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

            if (! $team || ! isset($teamData['statistics'])) {
                continue;
            }

            foreach ($teamData['statistics'] as $statCategory) {
                $categoryName = strtolower((string) ($statCategory['name'] ?? ''));
                $athletes = $statCategory['athletes'] ?? [];
                $labels = $statCategory['labels'] ?? [];

                foreach ($athletes as $athleteData) {
                    $playerEspnId = $athleteData['athlete']['id'] ?? null;

                    if (! $playerEspnId) {
                        continue;
                    }

                    $player = $playerModel::query()->where('espn_id', $playerEspnId)->first();

                    if (! $player) {
                        continue;
                    }

                    $playerStat = $playerStatModel::query()
                        ->where('player_id', $player->id)
                        ->where('game_id', $game->id)
                        ->first();

                    if (! $playerStat) {
                        $playerStat = $playerStatModel::create([
                            'player_id' => $player->id,
                            'game_id' => $game->id,
                            'team_id' => $team->id,
                        ]);
                    }

                    $mappedStats = $this->mapLabeledStats($labels, $athleteData['stats'] ?? []);
                    $updates = $this->parseCategoryUpdates($categoryName, $mappedStats);

                    if (! empty($updates)) {
                        $playerStat->update($updates);
                    }

                    $synced++;
                }
            }
        }

        return $synced;
    }

    protected function mapLabeledStats(array $labels, array $stats): array
    {
        $mapped = [];

        foreach ($labels as $index => $label) {
            if (isset($stats[$index])) {
                $mapped[$label] = $stats[$index];
            }
        }

        return $mapped;
    }

    protected function parsePassingStats(array $stats): array
    {
        $updates = [];

        if (isset($stats['C/ATT'])) {
            $parts = explode('/', (string) $stats['C/ATT']);
            if (count($parts) === 2) {
                $updates[$this->passingCompletionsField()] = (int) $parts[0];
                $updates[$this->passingAttemptsField()] = (int) $parts[1];
            }
        }

        $updates[$this->passingYardsField()] = isset($stats['YDS']) ? (int) $stats['YDS'] : null;
        $updates[$this->passingTouchdownsField()] = isset($stats['TD']) ? (int) $stats['TD'] : null;
        $updates[$this->interceptionsField()] = isset($stats['INT']) ? (int) $stats['INT'] : null;

        return array_filter($updates, fn ($value) => $value !== null);
    }

    protected function parseRushingStats(array $stats): array
    {
        $updates = [];

        $updates[$this->rushingAttemptsField()] = isset($stats['CAR']) || isset($stats['ATT'])
            ? (int) ($stats['CAR'] ?? $stats['ATT'] ?? 0)
            : null;
        $updates[$this->rushingYardsField()] = isset($stats['YDS']) ? (int) $stats['YDS'] : null;
        $updates[$this->rushingTouchdownsField()] = isset($stats['TD']) ? (int) $stats['TD'] : null;

        return array_filter($updates, fn ($value) => $value !== null);
    }

    protected function parseReceivingStats(array $stats): array
    {
        $updates = [];

        $updates[$this->receptionsField()] = isset($stats['REC']) ? (int) $stats['REC'] : null;
        $updates[$this->receivingYardsField()] = isset($stats['YDS']) ? (int) $stats['YDS'] : null;
        $updates[$this->receivingTouchdownsField()] = isset($stats['TD']) ? (int) $stats['TD'] : null;
        $updates[$this->receivingTargetsField()] = isset($stats['TAR']) || isset($stats['TGTS'])
            ? (int) ($stats['TAR'] ?? $stats['TGTS'] ?? 0)
            : null;

        return array_filter($updates, fn ($value) => $value !== null);
    }

    protected function passingCompletionsField(): string
    {
        return 'completions';
    }

    protected function passingAttemptsField(): string
    {
        return 'attempts';
    }

    protected function passingYardsField(): string
    {
        return 'passing_yards';
    }

    protected function passingTouchdownsField(): string
    {
        return 'passing_touchdowns';
    }

    protected function interceptionsField(): string
    {
        return 'interceptions';
    }

    protected function rushingAttemptsField(): string
    {
        return 'carries';
    }

    protected function rushingYardsField(): string
    {
        return 'rushing_yards';
    }

    protected function rushingTouchdownsField(): string
    {
        return 'rushing_touchdowns';
    }

    protected function receptionsField(): string
    {
        return 'receptions';
    }

    protected function receivingYardsField(): string
    {
        return 'receiving_yards';
    }

    protected function receivingTouchdownsField(): string
    {
        return 'receiving_touchdowns';
    }

    protected function receivingTargetsField(): string
    {
        return 'targets';
    }

    protected function parseCategoryUpdates(string $category, array $mappedStats): array
    {
        return match ($category) {
            'passing' => $this->parsePassingStats($mappedStats),
            'rushing' => $this->parseRushingStats($mappedStats),
            'receiving' => $this->parseReceivingStats($mappedStats),
            default => [],
        };
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
