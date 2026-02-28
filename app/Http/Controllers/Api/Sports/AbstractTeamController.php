<?php

namespace App\Http\Controllers\Api\Sports;

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

        $team = $teamModel::findOrFail($teamId);

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

        $team = $teamModel::findOrFail($teamId);
        $calculator = app($calculatorClass);

        $gameCount = $request->integer('games', config('trends.defaults.sample_size', 20));
        $gameCount = min(
            max($gameCount, config('trends.defaults.min_sample', 5)),
            config('trends.defaults.max_sample', 50)
        );

        $season = $request->integer('season') ?: null;
        $beforeDate = $request->string('before_date')->toString() ?: null;

        $user = $request->user();
        $userTier = $user?->subscriptionTier()?->slug ?? config('subscriptions.default_tier', 'free');

        $result = $calculator->execute($team, $gameCount, $season, $beforeDate, $userTier);

        return response()->json([
            'team_id' => $team->id,
            'team_abbreviation' => $team->abbreviation,
            'team_name' => $this->getTeamNameForResponse($team),
            'sample_size' => $gameCount,
            'user_tier' => $userTier,
            'trends' => $result['trends'],
            'locked_trends' => $result['locked'],
        ]);
    }
}
