<?php

namespace App\Actions\OddsApi\MLB;

use App\Actions\OddsApi\AbstractSyncOddsForGames;
use App\Models\MLB\Game;
use Illuminate\Database\Eloquent\Builder;

class SyncOddsForGames extends AbstractSyncOddsForGames
{
    protected const SPORT_KEY = 'baseball_mlb';

    protected const PRESEASON_SPORT_KEY = 'baseball_mlb_preseason';

    protected const GAME_MODEL_CLASS = Game::class;

    protected const INCLUDE_DISPLAY_NAME_IN_TEAM_NAMES = false;

    protected function seasonTypeForOddsSportKey(string $oddsSportKey): ?int
    {
        if ($oddsSportKey === self::PRESEASON_SPORT_KEY) {
            return (int) config('mlb.season.types.spring_training', 1);
        }

        if ($oddsSportKey === self::SPORT_KEY) {
            return (int) config('mlb.season.types.regular', 2);
        }

        return null;
    }

    protected function localGamesQuery(string $oddsSportKey, ?int $daysAhead = null): Builder
    {
        $gameModel = $this->gameModelClass();
        $query = $gameModel::query();

        if ($daysAhead !== null) {
            $query->whereDate('game_date', '>=', now()->startOfDay()->toDateString())
                ->whereDate('game_date', '<=', now()->startOfDay()->addDays($daysAhead)->toDateString())
                ->whereIn('status', ['STATUS_SCHEDULED', 'STATUS_IN_PROGRESS', 'STATUS_HALFTIME']);
        }

        $seasonType = $this->seasonTypeForOddsSportKey($oddsSportKey);

        if ($seasonType === null) {
            return $query;
        }

        $query->where(function (Builder $seasonTypeQuery) use ($seasonType, $oddsSportKey): void {
            $seasonTypeQuery->where('season_type', (string) $seasonType)
                ->orWhere('season_type', $seasonType);

            foreach ($this->seasonTypeAliases($oddsSportKey) as $alias) {
                $seasonTypeQuery->orWhere('season_type', $alias);
            }
        });

        return $query;
    }

    /**
     * @return array<int, string>
     */
    private function seasonTypeAliases(string $oddsSportKey): array
    {
        if ($oddsSportKey === self::PRESEASON_SPORT_KEY) {
            return ['Preseason', 'Spring Training', 'SpringTraining'];
        }

        if ($oddsSportKey === self::SPORT_KEY) {
            return ['Regular Season', 'Regular'];
        }

        return [];
    }
}
