<?php

namespace App\Actions\ESPN\WCBB;

use App\Actions\ESPN\AbstractSyncGamesFromSchedule;
use App\DataTransferObjects\ESPN\GameData;
use App\Models\WCBB\Team;
use Illuminate\Database\Eloquent\Model;

class SyncGamesFromSchedule extends AbstractSyncGamesFromSchedule
{
    protected const GAME_MODEL_CLASS = \App\Models\WCBB\Game::class;

    protected function resolveTeams(GameData $dto, array $rawGame): array
    {
        $homeTeam = Team::query()->where('espn_id', $dto->homeTeamEspnId)->first();
        if (! $homeTeam) {
            $homeTeam = Team::create([
                'espn_id' => $dto->homeTeamEspnId,
                'school' => $rawGame['competitions'][0]['competitors'][0]['team']['location'] ?? 'Unknown',
                'mascot' => $rawGame['competitions'][0]['competitors'][0]['team']['name'] ?? 'Unknown',
                'abbreviation' => $rawGame['competitions'][0]['competitors'][0]['team']['abbreviation'] ?? 'UNK',
                'logo_url' => $rawGame['competitions'][0]['competitors'][0]['team']['logo'] ?? null,
            ]);
        }

        $awayTeam = Team::query()->where('espn_id', $dto->awayTeamEspnId)->first();
        if (! $awayTeam) {
            $awayTeam = Team::create([
                'espn_id' => $dto->awayTeamEspnId,
                'school' => $rawGame['competitions'][0]['competitors'][1]['team']['location'] ?? 'Unknown',
                'mascot' => $rawGame['competitions'][0]['competitors'][1]['team']['name'] ?? 'Unknown',
                'abbreviation' => $rawGame['competitions'][0]['competitors'][1]['team']['abbreviation'] ?? 'UNK',
                'logo_url' => $rawGame['competitions'][0]['competitors'][1]['team']['logo'] ?? null,
            ]);
        }

        return [$homeTeam, $awayTeam];
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
            'broadcast_networks' => $dto->broadcastNetworks,
        ];
    }
}
