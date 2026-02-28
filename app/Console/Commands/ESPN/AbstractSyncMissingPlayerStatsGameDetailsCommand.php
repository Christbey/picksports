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
            ->whereDoesntHave('playerStats')
            ->orderBy('game_date', 'asc')
            ->get();
    }

    /**
     * @return class-string<Model>
     */
    protected function gameModelClass(): string
    {
        return $this->requiredJobClass(static::GAME_MODEL_CLASS, 'GAME_MODEL_CLASS');
    }
}
