<?php

namespace App\Http\Controllers\Api\Sports;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

abstract class AbstractEloRatingController extends AbstractSportsApiController
{
    protected const ELO_RATING_MODEL = '';

    protected const TEAM_MODEL = '';

    protected const ELO_RATING_RESOURCE = '';

    protected function getEloRatingModel(): string
    {
        if (static::ELO_RATING_MODEL === '') {
            throw new \RuntimeException('ELO_RATING_MODEL must be defined on elo rating controller.');
        }

        return static::ELO_RATING_MODEL;
    }

    protected function getTeamModel(): string
    {
        if (static::TEAM_MODEL === '') {
            throw new \RuntimeException('TEAM_MODEL must be defined on elo rating controller.');
        }

        return static::TEAM_MODEL;
    }

    protected function getEloRatingResource(): string
    {
        if (static::ELO_RATING_RESOURCE === '') {
            throw new \RuntimeException('ELO_RATING_RESOURCE must be defined on elo rating controller.');
        }

        return static::ELO_RATING_RESOURCE;
    }

    /**
     * @return string[]
     */
    protected function getDefaultOrderColumns(): array
    {
        return ['date'];
    }

    /**
     * @return string[]
     */
    protected function getBySeasonOrderColumns(): array
    {
        return $this->getDefaultOrderColumns();
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $model = $this->getEloRatingModel();
        $resource = $this->getEloRatingResource();

        $query = $model::query()->with(['team']);
        foreach ($this->getDefaultOrderColumns() as $column) {
            $query->orderByDesc($column);
        }

        return $resource::collection($query->paginate($this->getPerPage($request)));
    }

    public function show($eloRating): JsonResource
    {
        $model = $this->getEloRatingModel();
        $resource = $this->getEloRatingResource();
        $eloRatingId = $this->requireNumericId($eloRating);

        $eloRating = $model::query()
            ->with(['team'])
            ->findOrFail($eloRatingId);

        return new $resource($eloRating);
    }

    public function byTeam($team, Request $request): AnonymousResourceCollection
    {
        $teamModel = $this->getTeamModel();
        $model = $this->getEloRatingModel();
        $resource = $this->getEloRatingResource();
        $teamId = $this->requireNumericId($team);

        $teamModel::query()->findOrFail($teamId);

        $query = $model::query()->where('team_id', $teamId);
        foreach ($this->getDefaultOrderColumns() as $column) {
            $query->orderByDesc($column);
        }

        return $resource::collection($query->paginate($this->getPerPage($request)));
    }

    public function bySeason($season, Request $request): AnonymousResourceCollection
    {
        $model = $this->getEloRatingModel();
        $resource = $this->getEloRatingResource();
        $seasonValue = $this->requireNumericId($season);

        $query = $model::query()
            ->with(['team'])
            ->where('season', $seasonValue);
        foreach ($this->getBySeasonOrderColumns() as $column) {
            $query->orderByDesc($column);
        }

        return $resource::collection($query->paginate($this->getPerPage($request)));
    }
}
