<?php

namespace App\Http\Controllers\Api\Sports;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

abstract class AbstractTeamController extends Controller
{
    /**
     * Get the Team model class for this sport
     */
    abstract protected function getTeamModel(): string;

    /**
     * Get the TeamResource class for this sport
     */
    abstract protected function getTeamResource(): string;

    /**
     * Get the CalculateTeamTrends action class for this sport (optional)
     */
    protected function getTrendsCalculator(): ?string
    {
        return null;
    }

    /**
     * Get the default order by column for team listing
     */
    protected function getOrderByColumn(): string
    {
        return 'display_name';
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
    public function show(int $team): JsonResource
    {
        $teamModel = $this->getTeamModel();
        $resourceClass = $this->getTeamResource();

        $team = $teamModel::findOrFail($team);

        return new $resourceClass($team);
    }

    /**
     * Calculate team trends based on recent games
     */
    public function trends(int $team, Request $request): JsonResponse
    {
        $teamModel = $this->getTeamModel();
        $calculatorClass = $this->getTrendsCalculator();

        if (! $calculatorClass) {
            abort(404, 'Trends not available for this sport');
        }

        $team = $teamModel::findOrFail($team);
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
