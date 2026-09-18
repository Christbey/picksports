<?php

namespace App\Actions\NFL;

use App\Actions\Trends\AbstractCalculateTeamTrends;
use App\Actions\Trends\Collectors\AdvancedTrendCollector;
use App\Actions\Trends\Collectors\ClutchPerformanceTrendCollector;
use App\Actions\Trends\Collectors\ConferenceTrendCollector;
use App\Actions\Trends\Collectors\DefensivePerformanceTrendCollector;
use App\Actions\Trends\Collectors\DriveEfficiencyTrendCollector;
use App\Actions\Trends\Collectors\FirstScoreTrendCollector;
use App\Actions\Trends\Collectors\HalfTrendCollector;
use App\Actions\Trends\Collectors\MarginTrendCollector;
use App\Actions\Trends\Collectors\MomentumTrendCollector;
use App\Actions\Trends\Collectors\OffensiveEfficiencyTrendCollector;
use App\Actions\Trends\Collectors\OpponentStrengthTrendCollector;
use App\Actions\Trends\Collectors\QuarterTrendCollector;
use App\Actions\Trends\Collectors\RestScheduleTrendCollector;
use App\Actions\Trends\Collectors\ScoringPatternTrendCollector;
use App\Actions\Trends\Collectors\ScoringTrendCollector;
use App\Actions\Trends\Collectors\SituationalTrendCollector;
use App\Actions\Trends\Collectors\StreakTrendCollector;
use App\Actions\Trends\Collectors\TimeBasedTrendCollector;
use App\Actions\Trends\Collectors\TotalsTrendCollector;
use App\Models\NFL\Game;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class CalculateTeamTrends extends AbstractCalculateTeamTrends
{
    protected const SPORT_KEY = 'nfl';

    protected const GAME_MODEL = Game::class;

    protected const DEFAULT_GAME_COUNT = 16;

    public function evidenceGames(object $team, int $season, string $beforeDate): Collection
    {
        return $this->baseGamesQuery($team, null, '2', $beforeDate)
            ->whereBetween('season', [$season - 3, $season])
            ->whereNotNull('home_score')->whereNotNull('away_score')
            ->with($this->gameRelations())
            ->orderByDesc('game_date')->orderByDesc('game_time')->orderByDesc('id')->get();
    }

    protected function baseGamesQuery(
        object $team,
        ?int $season = null,
        ?string $seasonType = null,
        ?string $beforeDate = null
    ): Builder {
        // An omitted phase must never silently mix exhibition and regular games.
        return parent::baseGamesQuery($team, $season, $seasonType ?? '2', $beforeDate);
    }

    /**
     * @var array<int, class-string>
     */
    protected const COLLECTORS = [
        ScoringTrendCollector::class,
        QuarterTrendCollector::class,
        HalfTrendCollector::class,
        MarginTrendCollector::class,
        TotalsTrendCollector::class,
        FirstScoreTrendCollector::class,
        SituationalTrendCollector::class,
        StreakTrendCollector::class,
        AdvancedTrendCollector::class,
        TimeBasedTrendCollector::class,
        RestScheduleTrendCollector::class,
        OpponentStrengthTrendCollector::class,
        ConferenceTrendCollector::class,
        ScoringPatternTrendCollector::class,
        DriveEfficiencyTrendCollector::class,
        OffensiveEfficiencyTrendCollector::class,
        DefensivePerformanceTrendCollector::class,
        MomentumTrendCollector::class,
        ClutchPerformanceTrendCollector::class,
    ];
}
