<?php

namespace App\Console\Commands\ESPN;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

abstract class AbstractSyncMissingPlayerStatsGameDetailsCommand extends AbstractSyncGameDetailsCommand
{
    protected const REQUIRES_FINAL_STATUS = false;

    protected const GAME_MODEL_CLASS = '';

    protected function requiresFinalStatus(): bool
    {
        return static::REQUIRES_FINAL_STATUS;
    }

    protected function pendingGames(): Collection
    {
        $gameModel = $this->gameModelClass();

        return $gameModel::query()
            ->when($this->requiresFinalStatus(), fn ($query) => $query->where('status', 'STATUS_FINAL'))
            ->whereNotNull('espn_event_id')
            ->when($this->lookbackDays() !== null, fn ($query) => $query->whereDate('game_date', '>=', now()->copy()->subDays($this->lookbackDays())->toDateString()))
            ->when(! $this->option('refresh-existing'), fn ($query) => $query->where(fn ($query) => $this->whereMissingDetails($query, $gameModel)))
            ->orderBy('game_date', $this->option('latest') ? 'desc' : 'asc')
            ->get();
    }

    protected function whereMissingDetails($query, string $gameModel): void
    {
        $query->whereDoesntHave('playerStats');

        foreach (['teamStats', 'plays'] as $relation) {
            if (method_exists($gameModel, $relation)) {
                $query->orWhereDoesntHave($relation);
            }
        }

        if ($this->includesMissingFinalScores()) {
            $query->orWhere(function ($scoreQuery) {
                $scoreQuery
                    ->where('status', 'STATUS_FINAL')
                    ->where(function ($missingScoreQuery) {
                        $missingScoreQuery
                            ->whereNull('home_score')
                            ->orWhereNull('away_score');
                    });
            });
        }

        if ($this->includesMissingLineScores()) {
            $query->orWhere(function ($lineScoreQuery) {
                $lineScoreQuery
                    ->where('status', 'STATUS_FINAL')
                    ->where(function ($missingLineScoreQuery) {
                        $missingLineScoreQuery
                            ->whereNull('home_linescores')
                            ->orWhereNull('away_linescores');
                    });
            });
        }
    }

    protected function includesMissingFinalScores(): bool
    {
        return false;
    }

    protected function includesMissingLineScores(): bool
    {
        return false;
    }

    protected function lookbackDays(): ?int
    {
        $value = $this->option('lookback-days');

        if ($value === null || $value === '') {
            return null;
        }

        return max(1, (int) $value);
    }

    /**
     * @return class-string<Model>
     */
    protected function gameModelClass(): string
    {
        return $this->requiredJobClass(static::GAME_MODEL_CLASS, 'GAME_MODEL_CLASS');
    }
}
