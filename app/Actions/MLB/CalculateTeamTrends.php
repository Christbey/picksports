<?php

namespace App\Actions\MLB;

use App\Actions\Trends\AbstractCalculateTeamTrends;
use App\Actions\Trends\Collectors\AdvancedTrendCollector;
use App\Actions\Trends\Collectors\ClutchPerformanceTrendCollector;
use App\Actions\Trends\Collectors\ConferenceTrendCollector;
use App\Actions\Trends\Collectors\DefensivePerformanceTrendCollector;
use App\Actions\Trends\Collectors\FirstScoreTrendCollector;
use App\Actions\Trends\Collectors\MarginTrendCollector;
use App\Actions\Trends\Collectors\MomentumTrendCollector;
use App\Actions\Trends\Collectors\OffensiveEfficiencyTrendCollector;
use App\Actions\Trends\Collectors\OpponentStrengthTrendCollector;
use App\Actions\Trends\Collectors\RestScheduleTrendCollector;
use App\Actions\Trends\Collectors\ScoringPatternTrendCollector;
use App\Actions\Trends\Collectors\ScoringTrendCollector;
use App\Actions\Trends\Collectors\SituationalTrendCollector;
use App\Actions\Trends\Collectors\StreakTrendCollector;
use App\Actions\Trends\Collectors\TimeBasedTrendCollector;
use App\Actions\Trends\Collectors\TotalsTrendCollector;
use App\Models\MLB\Game;
use Illuminate\Database\Eloquent\Builder;

class CalculateTeamTrends extends AbstractCalculateTeamTrends
{
    protected const SPORT_KEY = 'mlb';

    protected const GAME_MODEL = Game::class;

    protected const GAME_RELATIONS = ['homeTeam', 'awayTeam', 'teamStats', 'prediction'];

    protected const USES_ANALYTICS_SEASON_TYPES = false;

    /**
     * Baseball-appropriate collectors (excludes quarters, halves, and drives).
     *
     * @var array<int, class-string>
     */
    protected const COLLECTORS = [
        ScoringTrendCollector::class,
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

    protected function baseGamesQuery(
        object $team,
        ?int $season = null,
        ?string $seasonType = null,
        ?string $beforeDate = null
    ): Builder {
        return parent::baseGamesQuery($team, $season, $seasonType, $beforeDate)
            ->where(function (Builder $query): void {
                $query->where(function (Builder $scores): void {
                    $scores->whereNotNull('home_score')->whereNotNull('away_score');
                })->orWhere(function (Builder $stats): void {
                    $stats->whereHas('teamStats', fn (Builder $home): Builder => $home
                        ->whereColumn('mlb_team_stats.team_id', 'mlb_games.home_team_id')
                        ->whereNotNull('runs'))
                        ->whereHas('teamStats', fn (Builder $away): Builder => $away
                            ->whereColumn('mlb_team_stats.team_id', 'mlb_games.away_team_id')
                            ->whereNotNull('runs'));
                });
            });
    }
}
