<?php

namespace App\Services\Api\V2;

use App\Services\Api\V2\Concerns\BuildsSportQueries;
use App\Services\Trends\TrendSignalScorer;
use App\Support\SportsViewCache;
use App\Support\UserTierResolver;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

class SportTeamTrendQuery
{
    use BuildsSportQueries;

    public function __construct(
        private readonly SportsViewCache $sportsViewCache,
        private readonly UserTierResolver $tierResolver,
        private readonly TrendSignalScorer $signalScorer,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function get(
        SportContext $context,
        int $teamId,
        array $filters = [],
        ?Authenticatable $user = null,
    ): array {
        $teamModel = $this->teamModel($context);
        $calculatorClass = $this->calculatorClass($context);
        $season = isset($filters['season']) ? (int) $filters['season'] : null;
        $seasonType = isset($filters['season_type']) ? (string) $filters['season_type'] : null;
        $beforeDate = isset($filters['before_date']) ? (string) $filters['before_date'] : null;
        $gamesParam = isset($filters['games']) ? strtolower(trim((string) $filters['games'])) : '';
        $userTier = $this->tierResolver->resolveTierSlug($user);

        $cacheKey = $this->sportsViewCache->contextHash([
            'contract' => 'sports.teams.trends.show',
            'sport' => $context->slug,
            'team_id' => $teamId,
            'season' => $season,
            'season_type' => $seasonType,
            'before_date' => $beforeDate,
            'games' => $gamesParam !== '' ? $gamesParam : (int) config('trends.defaults.sample_size', 20),
            'tier' => $userTier,
        ]);

        return $this->sportsViewCache->remember(
            segment: 'team_trends',
            key: $cacheKey,
            ttlSeconds: (int) config('sports_view_cache.ttl.team_trends_seconds', 120),
            resolver: function () use ($teamModel, $calculatorClass, $context, $teamId, $season, $seasonType, $beforeDate, $gamesParam, $userTier): array {
                /** @var Model $team */
                $team = $teamModel::query()->findOrFail($teamId);
                $calculator = app($calculatorClass);
                $isSeasonSample = in_array($gamesParam, ['season', 'all'], true);

                if ($isSeasonSample && method_exists($calculator, 'countAvailableGames')) {
                    $gameCount = max(1, (int) $calculator->countAvailableGames($team, $season, $seasonType, $beforeDate));
                } else {
                    $requestedGames = $gamesParam !== '' && ctype_digit($gamesParam)
                        ? (int) $gamesParam
                        : (int) config('trends.defaults.sample_size', 20);
                    $gameCount = min(
                        max($requestedGames, (int) config('trends.defaults.min_sample', 5)),
                        (int) config('trends.defaults.max_sample', 50)
                    );
                }

                $result = $calculator->execute($team, $gameCount, $season, $seasonType, $beforeDate, $userTier);
                $trends = (array) ($result['trends'] ?? []);
                $scoredSignals = $this->signalScorer->score($context->slug, $trends, $gameCount);

                return [
                    'team_id' => $team->getKey(),
                    'team_abbreviation' => $team->getAttribute('abbreviation'),
                    'team_name' => $team->getAttribute('display_name')
                        ?? $team->getAttribute('name')
                        ?? $team->getAttribute('school'),
                    'sample_size' => $gameCount,
                    'user_tier' => $userTier,
                    'trends' => $trends,
                    'scored_signals' => $scoredSignals,
                    'trend_signal_summary' => $this->signalScorer->summarize($scoredSignals),
                    'locked_trends' => (array) ($result['locked'] ?? []),
                ];
            },
        );
    }

    /**
     * @return class-string<Model>
     */
    private function teamModel(SportContext $context): string
    {
        return $this->requireModel($context, 'team', 'Team trends');
    }

    /**
     * @return class-string
     */
    private function calculatorClass(SportContext $context): string
    {
        $calculatorClass = "App\\Actions\\{$context->namespace}\\CalculateTeamTrends";

        if (! class_exists($calculatorClass)) {
            abort(404, "Team trends are not available for {$context->slug}.");
        }

        return $calculatorClass;
    }
}
