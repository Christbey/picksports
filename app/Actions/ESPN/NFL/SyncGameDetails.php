<?php

namespace App\Actions\ESPN\NFL;

use App\Actions\ESPN\AbstractStandardSyncGameDetails;
use App\Actions\GradePlayerProps;
use App\Models\NFL\Game;
use App\Support\NflGameStateGuard;
use App\Support\SportsViewCache;
use Illuminate\Database\Eloquent\Model;

class SyncGameDetails extends AbstractStandardSyncGameDetails
{
    protected const GAME_MODEL_CLASS = Game::class;

    protected function includeGameUpdatedFlag(): bool
    {
        return true;
    }

    protected function updateGame(array $gameData, Model $game): bool
    {
        $competition = data_get($gameData, 'header.competitions.0', []);
        $venue = data_get($gameData, 'gameInfo.venue', $competition['venue'] ?? []);
        $competitors = collect($competition['competitors'] ?? []);
        $home = $competitors->firstWhere('homeAway', 'home');
        $away = $competitors->firstWhere('homeAway', 'away');
        $homeName = data_get($home, 'team.displayName');
        $awayName = data_get($away, 'team.displayName');
        $homeAbbreviation = data_get($home, 'team.abbreviation');
        $awayAbbreviation = data_get($away, 'team.abbreviation');
        $updates = [
            'venue_name' => $venue['fullName'] ?? null,
            'venue_city' => data_get($venue, 'address.city'),
            'venue_state' => data_get($venue, 'address.state'),
            'name' => data_get($gameData, 'header.name') ?? ($homeName && $awayName ? "{$awayName} at {$homeName}" : null),
            'short_name' => data_get($gameData, 'header.shortName') ?? ($homeAbbreviation && $awayAbbreviation ? "{$awayAbbreviation} @ {$homeAbbreviation}" : null),
        ];
        // An omitted neutralSite flag must not relocate an international game.
        if (array_key_exists('neutralSite', $competition)) {
            $updates['neutral_site'] = (bool) $competition['neutralSite'];
        }
        $game->fill(NflGameStateGuard::preserve($game, $updates));
        if (! $game->isDirty()) {
            return false;
        }

        return $game->save();
    }

    public function execute(string $eventId): array
    {
        $result = parent::execute($eventId);
        $game = Game::where('espn_event_id', $eventId)->first();
        if ($game?->status === 'STATUS_FINAL' && $result['player_stats'] > 0) {
            $grading = app(GradePlayerProps::class)->executeForGame('americanfootball_nfl', $game->id);
            if ($grading['graded'] > 0) {
                app(SportsViewCache::class)->bustSegment(SportsViewCache::SEGMENT_PLAYER_PROPS_PAGE);
            }
        }

        return $result;
    }
}
