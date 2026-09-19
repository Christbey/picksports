<?php

namespace App\Services\CFB\Ratings;

use App\Models\CFB\Game;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class ResultRatingEvidence
{
    public function __construct(private ResultRatingModel $model) {}

    public function forGame(Game $game, CarbonImmutable $capturedAt, CarbonImmutable $cutoffAt): ?array
    {
        if ($capturedAt->gte($cutoffAt)) {
            return null;
        }
        $captured = $capturedAt->setTimezone(config('app.timezone'));
        // Exclude the whole kickoff date so same-day or in-progress scores can never leak in.
        $rows = Game::query()->whereBetween('season', [$game->season - 1, $game->season])
            ->where('id', '!=', $game->id)->where('status', 'STATUS_FINAL')->whereNotNull('home_score')->whereNotNull('away_score')
            ->whereDate('game_date', '<', $cutoffAt->utc()->toDateString())
            ->where('created_at', '<=', $captured)->where('updated_at', '<=', $captured)
            ->orderBy('id')->get(['id', 'season', 'home_team_id', 'away_team_id', 'home_score', 'away_score', 'neutral_site', 'updated_at'])
            ->map(fn ($g) => [...$g->only(['id', 'season', 'home_team_id', 'away_team_id', 'home_score', 'away_score', 'neutral_site']),
                'observed_at' => $g->updated_at->toIso8601String()])->all();
        if ($rows === []) {
            return null;
        }
        $hash = hash('sha256', json_encode([ResultRatingModel::VERSION, $game->season, $rows], JSON_THROW_ON_ERROR));
        $run = DB::table('cfb_result_rating_runs')->where('input_hash', $hash)->first();
        if (! $run) {
            $model = $this->model->fit($rows, $game->season);
            DB::table('cfb_result_rating_runs')->insertOrIgnore([
                'season' => $game->season, 'version' => ResultRatingModel::VERSION, 'input_hash' => $hash,
                'source_rows' => json_encode($rows, JSON_THROW_ON_ERROR), 'model' => json_encode($model, JSON_THROW_ON_ERROR),
                'source_available_at' => collect($rows)->max('observed_at') ? CarbonImmutable::parse(collect($rows)->max('observed_at'))->setTimezone(config('app.timezone')) : $captured,
                'created_at' => now(),
            ]);
            $run = DB::table('cfb_result_rating_runs')->where('input_hash', $hash)->first();
        }
        $model = json_decode($run->model, true, flags: JSON_THROW_ON_ERROR);
        $prediction = $this->model->predict($model, $game->home_team_id, $game->away_team_id, (bool) $game->neutral_site);
        if (! $prediction) {
            return null;
        }

        return [...$prediction, 'run_id' => (int) $run->id, 'input_hash' => $hash, 'status' => 'observed_result_rating',
            'source_available_at' => CarbonImmutable::parse($run->source_available_at, config('app.timezone'))->toIso8601String(),
            'home_advantage' => $model['home_advantage'], 'training_games' => $model['games'],
            'minimum_team_games' => min($prediction['home']['current_games'] + $prediction['home']['prior_games'], $prediction['away']['current_games'] + $prediction['away']['prior_games']),
            'limitations' => ['Low samples are shrunk toward the fitted network mean; they are not treated as full-season evidence.',
                'This independent result model has not been calibrated to betting probabilities.']];
    }
}
