<?php

namespace App\Actions\ESPN\MLB;

use App\Actions\ESPN\AbstractSyncGameDetails;
use App\Models\MLB\Game;
use Illuminate\Database\Eloquent\Model;

class SyncGameDetails extends AbstractSyncGameDetails
{
    protected const GAME_MODEL_CLASS = \App\Models\MLB\Game::class;

    protected function includeGameUpdatedFlag(): bool
    {
        return true;
    }

    /**
     * @param  array<string, mixed>  $gameData
     */
    protected function updateGame(array $gameData, Model $game): bool
    {
        if (! $game instanceof Game) {
            return false;
        }

        $competition = $gameData['header']['competitions'][0] ?? null;

        if (! $competition) {
            return true;
        }

        $updateData = [];

        if (isset($competition['status']['type']['name'])) {
            $updateData['status'] = $competition['status']['type']['name'];
        }

        $homeCompetitor = null;
        $awayCompetitor = null;

        foreach ($competition['competitors'] ?? [] as $competitor) {
            if ($competitor['homeAway'] === 'home') {
                $homeCompetitor = $competitor;
            } elseif ($competitor['homeAway'] === 'away') {
                $awayCompetitor = $competitor;
            }
        }

        if ($homeCompetitor && isset($homeCompetitor['score'])) {
            $updateData['home_score'] = $homeCompetitor['score'];
        }

        if ($awayCompetitor && isset($awayCompetitor['score'])) {
            $updateData['away_score'] = $awayCompetitor['score'];
        }

        if ($homeCompetitor && isset($homeCompetitor['linescores']) && is_array($homeCompetitor['linescores'])) {
            $homeLinescores = array_map(fn ($inning) => $inning['displayValue'] ?? '0', $homeCompetitor['linescores']);
            $updateData['home_linescores'] = json_encode($homeLinescores);
        }

        if ($awayCompetitor && isset($awayCompetitor['linescores']) && is_array($awayCompetitor['linescores'])) {
            $awayLinescores = array_map(fn ($inning) => $inning['displayValue'] ?? '0', $awayCompetitor['linescores']);
            $updateData['away_linescores'] = json_encode($awayLinescores);
        }

        if ($homeCompetitor) {
            if (isset($homeCompetitor['hits'])) {
                $updateData['home_hits'] = $homeCompetitor['hits'];
            }
            if (isset($homeCompetitor['errors'])) {
                $updateData['home_errors'] = $homeCompetitor['errors'];
            }
        }

        if ($awayCompetitor) {
            if (isset($awayCompetitor['hits'])) {
                $updateData['away_hits'] = $awayCompetitor['hits'];
            }
            if (isset($awayCompetitor['errors'])) {
                $updateData['away_errors'] = $awayCompetitor['errors'];
            }
        }

        if (! empty($updateData)) {
            $game->update($updateData);
        }

        return true;
    }
}
