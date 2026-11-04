<?php

namespace App\Actions\ESPN\WNBA;

use App\Actions\ESPN\AbstractSummaryUpdatingSyncGameDetails;
use App\Models\WNBA\Game;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

class SyncGameDetails extends AbstractSummaryUpdatingSyncGameDetails
{
    protected const GAME_MODEL_CLASS = Game::class;

    protected function updateGame(array $gameData, Model $game): bool
    {
        $competition = data_get($gameData, 'header.competitions.0', []);
        $competitors = collect($competition['competitors'] ?? []);
        $home = $competitors->firstWhere('homeAway', 'home');
        $away = $competitors->firstWhere('homeAway', 'away');
        if ((string) data_get($gameData, 'header.id') !== (string) $game->espn_event_id
            || $home === null || $away === null
            || (string) data_get($home, 'team.id') !== (string) $game->homeTeam?->espn_id
            || (string) data_get($away, 'team.id') !== (string) $game->awayTeam?->espn_id) {
            throw new RuntimeException('WNBA summary identity is unavailable or mismatched.');
        }
        $status = data_get($competition, 'status.type.name');
        if (! in_array($status, ['STATUS_SCHEDULED', 'STATUS_DELAYED', 'STATUS_IN_PROGRESS', 'STATUS_END_PERIOD', 'STATUS_HALFTIME', 'STATUS_SUSPENDED', 'STATUS_FINAL', 'STATUS_CANCELED', 'STATUS_POSTPONED'], true)) {
            throw new RuntimeException('WNBA summary status is unavailable.');
        }
        if ($status === 'STATUS_FINAL') {
            $scores = [data_get($home, 'score'), data_get($away, 'score')];
            $valid = data_get($competition, 'status.type.completed') === true;
            foreach ($scores as $score) {
                $valid = $valid && is_numeric($score) && is_finite((float) $score) && (float) $score >= 0 && floor((float) $score) === (float) $score;
            }
            if (! $valid || (float) $scores[0] === (float) $scores[1]) {
                throw new RuntimeException('WNBA final scores are unverified; retain the existing game hold.');
            }
        } elseif (in_array($game->status, ['STATUS_FINAL', 'STATUS_CANCELED', 'STATUS_POSTPONED'], true)) {
            return false;
        }

        return parent::updateGame($gameData, $game);
    }
}
