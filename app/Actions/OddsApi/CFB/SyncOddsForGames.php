<?php

namespace App\Actions\OddsApi\CFB;

use App\Actions\OddsApi\AbstractSyncOddsForGames;
use App\Models\CFB\Game;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class SyncOddsForGames extends AbstractSyncOddsForGames
{
    protected const SPORT_KEY = 'americanfootball_ncaaf';

    protected const GAME_MODEL_CLASS = Game::class;

    protected const MATCH_THRESHOLD = 85.0;

    protected function matchEvent(array $event, string $oddsSportKey, ?int $daysAhead = null): ?Model
    {
        $event['home_team'] = $this->normalizeSchoolLabel((string) $event['home_team']);
        $event['away_team'] = $this->normalizeSchoolLabel((string) $event['away_team']);

        return parent::matchEvent($event, $oddsSportKey, $daysAhead);
    }

    protected function homeTeamNames(Model $game): array
    {
        return $this->schoolIdentityTeamNames($game->homeTeam);
    }

    protected function awayTeamNames(Model $game): array
    {
        return $this->schoolIdentityTeamNames($game->awayTeam);
    }

    /**
     * @return list<string>
     */
    private function schoolIdentityTeamNames(mixed $team): array
    {
        $school = $this->normalizeSchoolLabel((string) ($team->school ?? ''));

        if ($school === '') {
            return [];
        }

        return array_values(array_unique([
            $school,
            $this->normalizeSchoolLabel($school.' '.($team->mascot ?? '')),
        ]));
    }

    private function normalizeSchoolLabel(string $name): string
    {
        return Str::of($name)->ascii()->lower()
            ->replace(["'", '’'], '')
            ->replaceMatches('/\bappalachian state\b/', 'app state')
            ->replaceMatches('/\bumass\b/', 'massachusetts')
            ->squish()->toString();
    }
}
