<?php

namespace App\Actions\ESPN;

use App\Actions\ESPN\Concerns\ValidatesBoxscoreReplacement;
use DomainException;
use Illuminate\Database\Eloquent\Model;

abstract class AbstractFootballSyncTeamStats
{
    use ValidatesBoxscoreReplacement;

    protected const TEAM_MODEL_CLASS = Model::class;

    protected const TEAM_STAT_MODEL_CLASS = Model::class;

    public function execute(array $gameData, Model $game): int
    {
        return $game->getConnection()->transaction(function () use ($gameData, $game): int {
            $game = $game->newQuery()->whereKey($game->getKey())->lockForUpdate()->firstOrFail();
            try {
                return $this->replaceTeamStats($gameData, $game);
            } catch (DomainException) {
                return 0;
            }
        });
    }

    protected function replaceTeamStats(array $gameData, Model $game): int
    {
        $sections = $gameData['boxscore']['teams'] ?? null;
        if (! is_array($sections) || $sections === []) {
            return 0;
        }

        $teamStatModel = $this->teamStatModelClass();

        $synced = 0;
        $teamModel = $this->teamModelClass();
        $teams = $this->replacementTeams($sections, $game, $teamModel, $teamStatModel);
        if ($teams === null) {
            return 0;
        }
        $rows = [];

        foreach ($sections as $teamData) {
            $team = $teams->get($teamData['team']['id']);
            if (! is_array($teamData['statistics'] ?? null) || $teamData['statistics'] === []) {
                return 0;
            }

            $stats = $this->parseTeamStats($teamData['statistics'] ?? []);

            $attributes = [
                'team_id' => $team->id,
                'game_id' => $game->id,
                ...$this->baseAttributes($stats),
                ...$this->sportSpecificAttributes($stats),
            ];

            $attributes = $this->additionalTeamAttributes($attributes, $teamData, $game, $gameData);

            if (collect(array_diff_key($attributes, array_flip(['team_id', 'game_id'])))->every(fn ($value): bool => $value === null)) {
                return 0;
            }

            $teamType = $this->resolveTeamType($team, $game, $teamData);
            if ($teamType !== null) {
                $attributes['team_type'] = $teamType;
            }

            $rows[] = $attributes;

            $synced++;
        }

        if (! $this->preservesObservedFields($teamStatModel, $game, $rows, ['team_id'])) {
            return 0;
        }
        $this->clearExisting($game);
        foreach ($rows as $attributes) {
            $this->storeStat($attributes);
        }

        return $synced;
    }

    protected function additionalTeamAttributes(array $attributes, array $teamData, Model $game, array $gameData): array
    {
        return $attributes;
    }

    protected function clearExisting(Model $game): void
    {
        $model = $this->teamStatModelClass();
        $model::where('game_id', $game->id)->delete();
    }

    protected function storeStat(array $attributes): void
    {
        $model = $this->teamStatModelClass();
        $model::create($attributes);
    }

    protected function baseAttributes(array $stats): array
    {
        return [
            'total_yards' => $stats['totalYards'] ?? null,
            'passing_yards' => $stats['passingYards'] ?? null,
            'rushing_yards' => $stats['rushingYards'] ?? null,
            'first_downs' => $stats['firstDowns'] ?? null,
            'third_down_conversions' => $stats['thirdDownConversions'] ?? null,
            'third_down_attempts' => $stats['thirdDownAttempts'] ?? null,
            'fourth_down_conversions' => $stats['fourthDownConversions'] ?? null,
            'fourth_down_attempts' => $stats['fourthDownAttempts'] ?? null,
            'penalties' => $stats['penalties'] ?? null,
            'penalty_yards' => $stats['penaltyYards'] ?? null,
        ];
    }

    protected function resolveTeamType(Model $team, Model $game, array $teamData): ?string
    {
        return null;
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
    protected function teamStatModelClass(): string
    {
        if (static::TEAM_STAT_MODEL_CLASS === Model::class) {
            throw new \RuntimeException('TEAM_STAT_MODEL_CLASS must be defined.');
        }

        return static::TEAM_STAT_MODEL_CLASS;
    }

    abstract protected function parseTeamStats(array $statistics): array;

    abstract protected function sportSpecificAttributes(array $stats): array;
}
