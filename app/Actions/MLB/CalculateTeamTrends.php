<?php

namespace App\Actions\MLB;

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
use App\Models\MLB\Team;
use Illuminate\Support\Collection;

class CalculateTeamTrends
{
    /**
     * Baseball-appropriate collectors (excludes quarters, halves, and drives).
     *
     * @var array<int, class-string>
     */
    protected array $collectors = [
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

    /**
     * @return array{trends: array<string, array<int, string>>, locked: array<string, string>}
     */
    public function execute(Team $team, int $gameCount = 20, ?int $season = null, ?string $beforeDate = null, string $userTier = 'free'): array
    {
        $games = $this->fetchRecentGames($team, $gameCount, $season, $beforeDate);

        if ($games->isEmpty()) {
            return ['trends' => [], 'locked' => []];
        }

        $trends = [];
        $locked = [];
        $enabledCollectors = config('trends.collectors', []);
        $tierRequirements = config('trends.tier_requirements', []);
        $tierLevels = config('trends.tier_levels', []);
        $userTierLevel = $tierLevels[$userTier] ?? 0;

        foreach ($this->collectors as $collectorClass) {
            $collector = app($collectorClass);
            $key = $collector->key();

            if (! ($enabledCollectors[$key] ?? true)) {
                continue;
            }

            $requiredTier = $tierRequirements[$key] ?? 'free';
            $requiredLevel = $tierLevels[$requiredTier] ?? 0;

            if ($userTierLevel < $requiredLevel) {
                $locked[$key] = $requiredTier;

                continue;
            }

            $collector->setContext('mlb', $team, $games);
            $messages = $collector->collect();

            if (! empty($messages)) {
                $trends[$key] = $messages;
            }
        }

        return ['trends' => $trends, 'locked' => $locked];
    }

    protected function fetchRecentGames(Team $team, int $count, ?int $season = null, ?string $beforeDate = null): Collection
    {
        return Game::query()
            ->where('status', 'STATUS_FINAL')
            ->where(fn ($q) => $q
                ->where('home_team_id', $team->id)
                ->orWhere('away_team_id', $team->id))
            ->when($season, fn ($q) => $q->where('season', $season))
            ->when($beforeDate, fn ($q) => $q->where('game_date', '<', $beforeDate))
            ->with(['homeTeam', 'awayTeam', 'teamStats'])
            ->orderByDesc('game_date')
            ->limit($count)
            ->get();
    }
}
