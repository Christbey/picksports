<?php

namespace App\Http\Controllers\Api\Sports;

use App\Services\Trends\TrendSignalScorer;
use App\Support\SportsViewCache;
use App\Support\UserTierResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

abstract class AbstractTeamController extends AbstractSportsApiController
{
    protected const TEAM_MODEL = '';

    protected const TEAM_RESOURCE = '';

    protected const TRENDS_CALCULATOR = null;

    protected const ORDER_BY_COLUMN = 'display_name';

    protected function getTeamModel(): string
    {
        if (static::TEAM_MODEL === '') {
            throw new \RuntimeException('TEAM_MODEL must be defined on team controller.');
        }

        return static::TEAM_MODEL;
    }

    protected function getTeamResource(): string
    {
        if (static::TEAM_RESOURCE === '') {
            throw new \RuntimeException('TEAM_RESOURCE must be defined on team controller.');
        }

        return static::TEAM_RESOURCE;
    }

    protected function getTrendsCalculator(): ?string
    {
        return static::TRENDS_CALCULATOR;
    }

    protected function getOrderByColumn(): string
    {
        return static::ORDER_BY_COLUMN;
    }

    /**
     * @return array<int, string>
     */
    protected function additionalTeamRelations(): array
    {
        return [];
    }

    /**
     * Get the team name field(s) for the response
     */
    protected function getTeamNameForResponse(Model $team): string
    {
        return $team->display_name ?? $team->name ?? $team->school;
    }

    /**
     * Display a listing of teams
     */
    public function index(): AnonymousResourceCollection
    {
        $teamModel = $this->getTeamModel();
        $resourceClass = $this->getTeamResource();

        $teams = $teamModel::query()
            ->with($this->additionalTeamRelations())
            ->orderBy($this->getOrderByColumn())
            ->paginate(15);

        return $resourceClass::collection($teams);
    }

    /**
     * Display the specified team
     */
    public function show($team): JsonResource
    {
        $teamModel = $this->getTeamModel();
        $resourceClass = $this->getTeamResource();
        $teamId = $this->requireNumericId($team);

        $team = $teamModel::query()
            ->with($this->additionalTeamRelations())
            ->findOrFail($teamId);

        return new $resourceClass($team);
    }

    /**
     * Calculate team trends based on recent games
     */
    public function trends($team, Request $request): JsonResponse
    {
        $teamModel = $this->getTeamModel();
        $calculatorClass = $this->getTrendsCalculator();
        $teamId = $this->requireNumericId($team);

        if (! $calculatorClass) {
            abort(404, 'Trends not available for this sport');
        }

        $season = $request->integer('season') ?: null;
        $seasonType = $request->query('season_type');
        $seasonType = is_string($seasonType) && trim($seasonType) !== ''
            ? trim($seasonType)
            : null;
        $beforeDate = $request->string('before_date')->toString() ?: null;
        $gamesParam = strtolower(trim((string) $request->query('games', '')));
        $userTier = app(UserTierResolver::class)->resolveTierSlug($request->user());

        /** @var SportsViewCache $sportsViewCache */
        $sportsViewCache = app(SportsViewCache::class);
        $cacheKey = $sportsViewCache->contextHash([
            'controller' => static::class,
            'team_id' => $teamId,
            'season' => $season,
            'season_type' => $seasonType,
            'before_date' => $beforeDate,
            'games' => $gamesParam !== '' ? $gamesParam : $request->integer('games', config('trends.defaults.sample_size', 20)),
            'tier' => $userTier,
        ]);

        $payload = $sportsViewCache->remember(
            segment: 'team_trends',
            key: $cacheKey,
            ttlSeconds: (int) config('sports_view_cache.ttl.team_trends_seconds', 120),
            resolver: function () use ($teamModel, $teamId, $calculatorClass, $season, $seasonType, $beforeDate, $gamesParam, $userTier, $request): array {
                $team = $teamModel::findOrFail($teamId);
                $calculator = app($calculatorClass);

                $isSeasonSample = in_array($gamesParam, ['season', 'all'], true);
                if ($isSeasonSample && method_exists($calculator, 'countAvailableGames')) {
                    $gameCount = max(1, (int) $calculator->countAvailableGames($team, $season, $seasonType, $beforeDate));
                } else {
                    $gameCount = $request->integer('games', config('trends.defaults.sample_size', 20));
                    $gameCount = min(
                        max($gameCount, config('trends.defaults.min_sample', 5)),
                        config('trends.defaults.max_sample', 50)
                    );
                }

                $result = $calculator->execute($team, $gameCount, $season, $seasonType, $beforeDate, $userTier);
                $scorer = app(TrendSignalScorer::class);
                $scoredSignals = $scorer->score($this->trendSportKey(), $result['trends'], $gameCount);

                return [
                    'team_id' => $team->id,
                    'team_abbreviation' => $team->abbreviation,
                    'team_name' => $this->getTeamNameForResponse($team),
                    'sample_size' => $gameCount,
                    'user_tier' => $userTier,
                    'trends' => $result['trends'],
                    'scored_signals' => $scoredSignals,
                    'trend_signal_summary' => $scorer->summarize($scoredSignals),
                    'locked_trends' => $result['locked'],
                ];
            },
        );

        return response()->json($payload);
    }

    protected function trendSportKey(): string
    {
        $parts = explode('\\', static::class);
        $apiIndex = array_search('Api', $parts, true);

        if ($apiIndex !== false && isset($parts[$apiIndex + 1])) {
            return strtolower($parts[$apiIndex + 1]);
        }

        return strtolower(class_basename(static::class));
    }
}
