<?php

namespace App\Actions\WNBA;

use App\Actions\Trends\AbstractCalculateTeamTrends;
use App\Actions\Trends\Collectors\AdvancedTrendCollector;
use App\Actions\Trends\Collectors\ClutchPerformanceTrendCollector;
use App\Actions\Trends\Collectors\ConferenceTrendCollector;
use App\Actions\Trends\Collectors\DefensivePerformanceTrendCollector;
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
use App\Models\WNBA\Game;

class CalculateTeamTrends extends AbstractCalculateTeamTrends
{
    protected const SPORT_KEY = 'wnba';

    protected const GAME_MODEL = Game::class;

    /**
     * Basketball-appropriate collectors (includes quarters, excludes drive efficiency).
     *
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
        OffensiveEfficiencyTrendCollector::class,
        DefensivePerformanceTrendCollector::class,
        MomentumTrendCollector::class,
        ClutchPerformanceTrendCollector::class,
    ];
}

