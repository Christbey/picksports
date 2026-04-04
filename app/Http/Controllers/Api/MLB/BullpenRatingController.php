<?php

namespace App\Http\Controllers\Api\MLB;

use App\Http\Controllers\Api\Sports\AbstractSportsApiController;
use App\Http\Resources\MLB\BullpenRatingResource;
use App\Models\MLB\BullpenRating;
use Illuminate\Http\Request;

class BullpenRatingController extends AbstractSportsApiController
{
    public function index(Request $request)
    {
        $tierContext = $this->resolveTierContext('getTeamMetricsLimit');
        $tierMetadata = $tierContext['metadata'];
        $tierLimit = $tierContext['limit'];
        $season = (int) ($request->integer('season') ?: date('Y'));
        $seasonType = (string) ($request->query('season_type') ?: (string) config('mlb.season.types.regular', 2));
        $asOfDate = $request->string('as_of_date')->toString() ?: null;

        $query = BullpenRating::query()
            ->with('team')
            ->where('season', $season)
            ->where('season_type', $seasonType);

        if ($asOfDate !== null) {
            $query->whereDate('as_of_date', $asOfDate);
        } else {
            $query->whereExists(function ($subquery) use ($season, $seasonType) {
                $subquery->selectRaw('1')
                    ->from('mlb_bullpen_ratings as latest')
                    ->whereColumn('latest.team_id', 'mlb_bullpen_ratings.team_id')
                    ->where('latest.season', $season)
                    ->where('latest.season_type', $seasonType)
                    ->whereColumn('latest.as_of_date', 'mlb_bullpen_ratings.as_of_date')
                    ->whereRaw(
                        'latest.as_of_date = (select max(inner_latest.as_of_date) from mlb_bullpen_ratings as inner_latest where inner_latest.team_id = latest.team_id and inner_latest.season = ? and inner_latest.season_type = ?)',
                        [$season, $seasonType]
                    );
            });
        }

        $query->orderByRaw('CASE WHEN rating_rank IS NULL THEN 1 ELSE 0 END')
            ->orderBy('rating_rank')
            ->orderByDesc('rating_score');

        if ($tierLimit !== null) {
            $query->limit($tierLimit);
        }

        return BullpenRatingResource::collection($query->get())->additional([
            'tier_limit' => $tierMetadata['tier_limit'],
            'tier_name' => $tierMetadata['tier_name'],
        ]);
    }

    public function byTeam($team, Request $request)
    {
        $teamId = $this->requireNumericId($team);
        $season = (int) ($request->integer('season') ?: date('Y'));
        $seasonType = (string) ($request->query('season_type') ?: (string) config('mlb.season.types.regular', 2));

        $query = BullpenRating::query()
            ->with('team')
            ->where('team_id', $teamId)
            ->where('season', $season)
            ->where('season_type', $seasonType)
            ->orderByDesc('as_of_date')
            ->orderByDesc('id');

        if ($request->boolean('latest', false)) {
            $rating = $query->firstOrFail();

            return BullpenRatingResource::make($rating);
        }

        return BullpenRatingResource::collection(
            $query->paginate($this->getPerPage($request))
        );
    }
}
