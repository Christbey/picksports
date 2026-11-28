<?php

namespace App\Services\NFL;

use App\Models\NFL\Game;
use App\Models\PredictionFeatureSnapshot;
use App\Services\Predictions\SnapshotProvenanceResolver;
use Carbon\CarbonImmutable;

class NflCalibrationReport
{
    public function build(int $season, string $version, CarbonImmutable $asOf): array
    {
        $games = Game::query()->with('sportEvent')->where('season', $season)
            ->whereIn('season_type', ['2', 'regular'])->get()->keyBy('id');
        $snapshots = PredictionFeatureSnapshot::query()->where('sport', 'nfl')
            ->where('prediction_table', 'nfl_predictions')->where('model_version', $version)
            ->whereIn('game_id', $games->keys())->where('generated_at', '<=', $asOf)
            ->orderByDesc('generated_at')->orderByDesc('id')->get();
        $seen = [];
        $rows = [];
        $excluded = [];
        $pending = 0;
        foreach ($snapshots as $snapshot) {
            $game = $games->get($snapshot->game_id);
            $kickoff = app(SnapshotProvenanceResolver::class)->gameStartAt($game);
            if (! $snapshot->pregame_safe || $snapshot->availability_status !== 'observed_pregame'
                || $kickoff === null || $snapshot->game_start_at === null
                || ! $snapshot->game_start_at->equalTo($kickoff)
                || $snapshot->generated_at === null || $snapshot->features_available_at === null
                || $snapshot->generated_at->gte($kickoff) || $snapshot->features_available_at->gte($kickoff)
                || $snapshot->features_available_at->gt($snapshot->generated_at)) {
                $excluded['invalid_provenance'] = ($excluded['invalid_provenance'] ?? 0) + 1;

                continue;
            }
            if (isset($seen[$game->id])) {
                $excluded['duplicate_game'] = ($excluded['duplicate_game'] ?? 0) + 1;

                continue;
            }
            $seen[$game->id] = true;
            // Historical cutoffs cannot establish when a subsequently final score became known.
            if ($game->status !== 'STATUS_FINAL' || $game->home_score === null || $game->away_score === null
                || $kickoff->gte($asOf) || $game->updated_at === null || $game->updated_at->gt($asOf)) {
                $pending++;

                continue;
            }
            $rows[] = ['snapshot_id' => $snapshot->id, 'game_id' => $game->id,
                'outputs' => $snapshot->outputs ?? [], 'home_score' => (float) $game->home_score,
                'away_score' => (float) $game->away_score];
        }

        return ['generated_at' => now()->toIso8601String(), 'as_of' => $asOf->toIso8601String(),
            'season' => $season, 'model_version' => $version, 'source' => 'observed_pregame_snapshots',
            'snapshot_count' => $snapshots->count(), 'distinct_valid_games' => count($seen),
            'pending_games' => $pending, 'excluded_snapshots' => $excluded,
            ...$this->summarize($rows),
            'limitations' => ['Descriptive prospective scoring; no held-out calibrator comparison is performed.',
                'Market baselines and betting returns are unavailable in this report.',
                'Scores use current final records; historical cutoffs conservatively exclude later-updated games.'],
            'calibrated' => false, 'bet_eligible' => false];
    }

    /** @param list<array> $rows */
    public function summarize(array $rows): array
    {
        $brier = $logLoss = [];
        $spreadErrors = $totalErrors = [];
        $bins = [];
        $ties = 0;
        foreach ($rows as $row) {
            $outputs = $row['outputs'];
            $margin = $row['home_score'] - $row['away_score'];
            $total = $row['home_score'] + $row['away_score'];
            $p = $outputs['win_probability'] ?? null;
            if ($margin === 0.0) {
                $ties++;
            } elseif (is_numeric($p) && is_finite((float) $p) && $p > 0 && $p < 1) {
                $p = (float) $p;
                $y = $margin > 0 ? 1 : 0;
                $brier[] = ($p - $y) ** 2;
                $logLoss[] = -($y * log($p) + (1 - $y) * log(1 - $p));
                $bucket = min(9, (int) floor($p * 10));
                $bins[$bucket][] = ['p' => $p, 'y' => $y];
            }
            foreach (['predicted_spread' => $margin, 'predicted_total' => $total] as $field => $actual) {
                $value = $outputs[$field] ?? null;
                if (is_numeric($value) && is_finite((float) $value)) {
                    if ($field === 'predicted_spread') {
                        $spreadErrors[] = (float) $value - $actual;
                    } else {
                        $totalErrors[] = (float) $value - $actual;
                    }
                }
            }
        }
        ksort($bins);
        $reliability = [];
        foreach ($bins as $bucket => $values) {
            $reliability[] = ['lower' => $bucket / 10, 'upper' => ($bucket + 1) / 10,
                'sample' => count($values), 'mean_probability' => $this->mean(array_column($values, 'p')),
                'home_win_rate' => $this->mean(array_column($values, 'y'))];
        }
        $winMinimum = (int) config('nfl.predictions.adaptive_win_probability_calibration.min_bucket_sample', 30);
        $pointMinimum = (int) config('nfl.predictions.adaptive_point_calibration.min_sample', 48);

        return ['completed_games' => count($rows), 'evidence_ids' => array_map(fn ($r) => [
            'game_id' => $r['game_id'], 'snapshot_id' => $r['snapshot_id']], $rows),
            'moneyline' => ['sample' => count($brier), 'ties_excluded' => $ties,
                'brier' => $this->mean($brier), 'log_loss' => $this->mean($logLoss),
                'constant_50_brier' => count($brier) ? 0.25 : null, 'reliability_bins' => $reliability,
                'additional_games_for_configured_minimum' => max(0, $winMinimum - count($brier)),
                'validation_status' => 'not_validated'],
            'spread' => $this->pointSummary($spreadErrors, $pointMinimum),
            'total' => $this->pointSummary($totalErrors, $pointMinimum),
            'simulations' => ['validation_status' => 'not_evaluated', 'calibrated' => false],
        ];
    }

    private function pointSummary(array $errors, int $minimum): array
    {
        return ['sample' => count($errors), 'mean_prediction_minus_actual' => $this->mean($errors),
            'mae' => $this->mean(array_map(abs(...), $errors)),
            'additional_games_for_configured_minimum' => max(0, $minimum - count($errors)),
            'probability_validation_status' => 'unavailable', 'calibrated' => false];
    }

    private function mean(array $values): ?float
    {
        return $values === [] ? null : round(array_sum($values) / count($values), 6);
    }
}
