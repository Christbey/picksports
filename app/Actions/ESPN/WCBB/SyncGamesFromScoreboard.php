<?php

namespace App\Actions\ESPN\WCBB;

use App\Actions\ESPN\AbstractSyncGamesFromScoreboard;
use App\Actions\WCBB\UpdateLivePrediction;
use App\Models\WCBB\Game;
use App\Models\WCBB\Team;
use App\Services\ESPN\WCBB\EspnService;
use App\Services\SportsAssetStorage;
use Illuminate\Database\Eloquent\Model;

class SyncGamesFromScoreboard extends AbstractSyncGamesFromScoreboard
{
    protected const GAME_MODEL_CLASS = Game::class;

    protected const TEAM_MODEL_CLASS = Team::class;

    protected const UPDATE_LIVE_PREDICTION_ACTION_CLASS = UpdateLivePrediction::class;

    protected const SYNC_ORPHANED_IN_PROGRESS_GAMES = true;

    public function __construct(
        EspnService $espnService,
        ?object $updateLivePrediction = null,
        protected ?SportsAssetStorage $sportsAssetStorage = null,
    ) {
        $this->sportsAssetStorage ??= app(SportsAssetStorage::class);

        parent::__construct($espnService, $updateLivePrediction);
    }

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
            'logo_url' => $this->sportsAssetStorage->mirrorTeamLogo(
                $rawTeam['logo'] ?? null,
                'wcbb',
                (($rawTeam['location'] ?? 'Unknown').' '.($rawTeam['name'] ?? 'Unknown')).'-'.$espnTeamId
            ),
        ]);
    }
}
