<?php

namespace App\Services\CBB;

use App\Models\CBB\EloRating;
use App\Models\CBB\Team;
use App\Models\ModelRun;
use App\Services\Predictions\ModelRunRecorder;
use App\Support\BasketballSeason;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class CbbSeasonInitializer
{
    public function initialize(int $season): int
    {
        if ($season !== BasketballSeason::forDate() || $this->alreadyInitialized($season)) {
            return 0;
        }

        return Cache::lock('cbb-season-initialization:'.$season, 300)->block(10, function () use ($season): int {
            return DB::transaction(function () use ($season): int {
                if ($this->alreadyInitialized($season)) {
                    return 0;
                }
                $mean = (float) config('cbb.elo.default', 1500);
                $regression = max(0.0, min(1.0, (float) config('cbb.elo.offseason_regression_factor', 0.3)));
                $teams = Team::query()->lockForUpdate()->get();
                foreach ($teams as $team) {
                    $prior = EloRating::query()->where('team_id', $team->id)->where('season', $season - 1)
                        ->orderByDesc('season')->orderByDesc('date')->orderByDesc('id')->value('elo_rating');
                    $baseline = is_numeric($prior) ? (float) $prior : $mean;
                    $team->update(['elo_rating' => round($baseline * (1 - $regression) + $mean * $regression)]);
                }
                app(ModelRunRecorder::class)->create(
                    sport: 'cbb', runType: 'season_initialization', modelVersion: 'cbb-season-v2',
                    featureVersion: 'core-v1', blendVersion: 'season-baseline-v1',
                    parameters: ['season' => $season, 'regression_factor' => $regression],
                    metadata: ['teams_initialized' => $teams->count(), 'new_team_baseline' => $mean],
                    status: 'completed', completedAt: now(),
                );

                return $teams->count();
            });
        });
    }

    private function alreadyInitialized(int $season): bool
    {
        return EloRating::query()->where('season', $season)->exists()
            || ModelRun::query()->where('sport', 'cbb')->where('run_type', 'season_initialization')
                ->where('status', 'completed')->where('parameters->season', $season)->exists();
    }
}
