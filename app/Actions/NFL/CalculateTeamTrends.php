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

class CalculateTeamTrends extends AbstractCalculateTeamTrends
{
    protected const SPORT_KEY = 'nfl';

    protected const GAME_MODEL = Game::class;

    protected const DEFAULT_GAME_COUNT = 16;

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
