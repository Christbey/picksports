<?php

namespace App\Actions\ESPN\WCBB;

use App\Actions\ESPN\AbstractSyncGamesFromScoreboard;
use App\Actions\WCBB\UpdateLivePrediction;
use Illuminate\Database\Eloquent\Model;

class SyncGamesFromScoreboard extends AbstractSyncGamesFromScoreboard
{
    protected const GAME_MODEL_CLASS = \App\Models\WCBB\Game::class;

    protected const TEAM_MODEL_CLASS = \App\Models\WCBB\Team::class;

    protected const UPDATE_LIVE_PREDICTION_ACTION_CLASS = UpdateLivePrediction::class;
    protected const SYNC_ORPHANED_IN_PROGRESS_GAMES = true;

    protected function shouldAutoCreateMissingTeams(): bool
    {
        return true;
    }

    protected function createMissingTeamFromEventData(array $eventData, string $homeAway, string $espnTeamId): ?Model
    {
        $teamModel = $this->teamModelClass();
        $teamData = collect($eventData['competitions'][0]['competitors'] ?? [])
            ->firstWhere('homeAway', $homeAway);

        if (! is_array($teamData) || ! isset($teamData['team'])) {
            return null;
        }

        $rawTeam = $teamData['team'];

        return $teamModel::query()->create([
            'espn_id' => $espnTeamId,
            'school' => $rawTeam['location'] ?? 'Unknown',
            'mascot' => $rawTeam['name'] ?? 'Unknown',
            'abbreviation' => $rawTeam['abbreviation'] ?? 'UNK',
            'logo_url' => $rawTeam['logo'] ?? null,
        ]);
    }
}
