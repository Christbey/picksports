<?php

namespace App\Actions\ESPN\WCBB;

use App\Actions\ESPN\AbstractSyncGamesFromSchedule;
use App\DataTransferObjects\ESPN\GameData;
use App\Models\WCBB\Game;
use App\Models\WCBB\Team;
use App\Services\ESPN\WCBB\EspnService;
use App\Services\SportsAssetStorage;
use Illuminate\Database\Eloquent\Model;

class SyncGamesFromSchedule extends AbstractSyncGamesFromSchedule
{
    protected const GAME_MODEL_CLASS = Game::class;

    public function __construct(EspnService $espnService, protected ?SportsAssetStorage $sportsAssetStorage = null)
    {
        parent::__construct($espnService);
        $this->sportsAssetStorage ??= app(SportsAssetStorage::class);
    }

    protected function resolveTeams(GameData $dto, array $rawGame): array
    {
        [$homeCompetitor, $awayCompetitor] = $this->resolveCompetitors($rawGame);

        return [
            $this->resolveTeam($dto->homeTeamEspnId, $homeCompetitor),
            $this->resolveTeam($dto->awayTeamEspnId, $awayCompetitor),
        ];
    }

    /**
     * @param  array<string, mixed>  $competitor
     */
    private function resolveTeam(string $espnId, array $competitor): ?Team
    {
        if (! ctype_digit($espnId) || (int) $espnId <= 0) {
            return null;
        }

        $team = Team::query()->where('espn_id', $espnId)->first();
        if ($team) {
            return $team;
        }

        $school = (string) data_get($competitor, 'team.location', 'Unknown');
        $mascot = (string) data_get($competitor, 'team.name', 'Unknown');
        $abbreviation = trim((string) data_get($competitor, 'team.abbreviation', ''));
        if ($abbreviation === '' || strlen($abbreviation) > 10 || Team::query()->where('abbreviation', $abbreviation)->exists()) {
            $attempt = 0;
            do {
                $abbreviation = 'E'.substr(hash('sha256', $espnId.'|'.$attempt++), 0, 9);
            } while (Team::query()->where('abbreviation', $abbreviation)->exists());
        }

        return Team::query()->firstOrCreate(['espn_id' => $espnId], [
            'school' => $school,
            'mascot' => $mascot,
            'abbreviation' => $abbreviation,
            'logo_url' => $this->sportsAssetStorage->mirrorTeamLogo(
                data_get($competitor, 'team.logo'),
                'wcbb',
                $school.' '.$mascot.'-'.$espnId,
            ),
        ]);
    }

    protected function gameAttributes(GameData $dto, array $rawGame, Model $homeTeam, Model $awayTeam): array
    {
        $dateParts = GameData::extractDateParts($rawGame['date'] ?? null);

        return [
            'espn_event_id' => $dto->espnEventId,
            'espn_uid' => $rawGame['uid'] ?? null,
            'season' => $dto->season,
            'week' => $dto->week,
            'season_type' => $dto->seasonType,
            'game_date' => $dateParts['game_date'],
            'game_time' => $dateParts['game_time'],
            'name' => $dto->name,
            'short_name' => $dto->shortName,
            'home_team_id' => $homeTeam->id,
            'away_team_id' => $awayTeam->id,
            'home_score' => $dto->homeScore,
            'away_score' => $dto->awayScore,
            'home_linescores' => $dto->homeLinescores,
            'away_linescores' => $dto->awayLinescores,
            'status' => $dto->status,
            'period' => $dto->period,
            'game_clock' => $dto->gameClock,
            'venue_name' => $dto->venueName,
            'venue_city' => $dto->venueCity,
            'venue_state' => $dto->venueState,
            'neutral_site' => $dto->neutralSite,
            'broadcast_networks' => $dto->broadcastNetworks,
        ];
    }
}
