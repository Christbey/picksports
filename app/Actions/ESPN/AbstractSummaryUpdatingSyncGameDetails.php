<?php

namespace App\Actions\ESPN;

use App\Actions\ESPN\Concerns\UpdatesGameFromSummary;
use Illuminate\Database\Eloquent\Model;

abstract class AbstractSummaryUpdatingSyncGameDetails extends AbstractSyncGameDetails
{
    use UpdatesGameFromSummary;

    protected function includeGameUpdatedFlag(): bool
    {
        return true;
    }

    /**
     * @param  array<string, mixed>  $gameData
     */
    protected function updateGame(array $gameData, Model $game): bool
    {
        return $this->updateGameFromSummary($gameData, $game);
    }
}
