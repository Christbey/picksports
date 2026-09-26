<?php

namespace App\Services\CFB\Scoring;

use App\Application\Predictions\Data\EventInputSnapshotData;
use App\Application\Predictions\Data\PredictionMarketOutput;
use App\Application\Predictions\Data\PredictionOutput;
use App\Models\CFB\Game;
use App\Models\CFB\GameDataCheck;
use App\Services\CFB\Signals\CfbFootballSignalCatalog;
use App\Services\CFB\Signals\CfbFootballSignalJointModel;
use Carbon\CarbonImmutable;

/** Freeze the challenger result with original inputs; never load a newer artifact on replay. */
class CfbScoringChallenger
{
    public function capture(Game $game, EventInputSnapshotData $snapshot, array $inputs, array $configuration): array
    {
        $id = $configuration['artifact_id'] ?? null;
        if (! $id) {
            return ['state' => 'disabled'];
        }
        try {
            $loaded = app(CfbScoringArtifact::class)->load($id, $snapshot->capturedAt, allowResearchShadow: true);
            $artifact = $loaded['payload'];
            $catalog = CfbFootballSignalCatalog::all();
            $missing = [];
            foreach ($catalog as $key => $rule) {
                foreach (['home', 'away'] as $side) {
                    if (CfbFootballSignalCatalog::evaluate($rule, CfbFootballSignalCatalog::features($inputs, $side, 'observed_only')) === null) {
                        $missing[$key] = true;
                    } else {
                        $missing[$key] ??= false;
                    }
                }
            }
            $evidence = ['home_id' => (string) $game->home_team_id, 'away_id' => (string) $game->away_team_id,
                'kickoff' => $snapshot->cutoffAt->toIso8601String(), 'season' => (int) $game->season, 'neutral' => (bool) $game->neutral_site,
                'fpi' => ['home' => data_get($inputs, 'home.metrics.fpi'), 'away' => data_get($inputs, 'away.metrics.fpi')],
                'signals' => CfbFootballSignalJointModel::features($inputs, ['catalog' => $catalog, 'feature_policy' => 'observed_only']), 'signal_missing' => $missing];
            // A corrected accepted training revision requires retraining; do not silently use stale ratings.
            $latest = GameDataCheck::whereIn('game_id', $artifact['training_ids'])->whereNotNull('accepted_at')->max('accepted_at');
            if ($latest && CarbonImmutable::parse($latest)->gt(CarbonImmutable::parse($artifact['max_source_available_at']))) {
                return ['state' => 'stale', 'reason' => 'accepted_history_changed', 'artifact_id' => $id];
            }
            $seed = (int) hexdec(substr(hash('sha256', json_encode($evidence).$loaded['record']->artifact_hash), 0, 7));
            $distribution = app(CfbPossessionScorer::class)->simulate($artifact, $evidence, 50000, $seed);

            $reference = null;
            try {
                $reference = app(CfbPossessionScorer::class)->simulate($artifact, $evidence, 50000, $seed + 101, true);
            } catch (\DomainException) { /* Missing original FPI leaves the paired comparison unavailable. */
            }

            return ['state' => 'ready', 'historical_evaluation_mode' => $artifact['evidence_mode'], 'benchmark_scores' => $reference?->scores, 'artifact_id' => $id, 'artifact_hash' => $loaded['record']->artifact_hash, 'artifact_available_at' => $loaded['record']->created_at->toIso8601String(),
                'seed' => $seed, 'inputs' => $evidence, 'summary' => $distribution->summary(), 'scores' => $distribution->scores,
                'signal_diagnostics' => $artifact['signal_diagnostics'] ?? [],
                'promoted' => $loaded['record']->status === 'promoted' && data_get($loaded['record']->promotion_decision, 'forecast_allowed') === true];
        } catch (\Throwable $error) {
            report($error);

            return ['state' => 'unavailable', 'artifact_id' => $id, 'reason' => $error->getMessage()];
        }
    }

    public function apply(PredictionOutput $baseline, array $frozen): PredictionOutput
    {
        $metadata = [...$baseline->metadata, 'scoring_challenger' => array_diff_key($frozen, ['scores' => true, 'benchmark_scores' => true, 'inputs' => true, 'signal_diagnostics' => true])];
        if (($frozen['state'] ?? '') !== 'ready' || ! ($frozen['promoted'] ?? false)) {
            return new PredictionOutput($baseline->markets, $metadata, $baseline->diagnostics, $baseline->generatedAt);
        }
        $s = $frozen['summary'];
        $p = max(.000001, min(.999999, $s['home_win_probability']));

        return new PredictionOutput([
            new PredictionMarketOutput('spread', 'home', projectedLine: -$s['home_margin']),
            new PredictionMarketOutput('total', 'combined', projectedLine: $s['total']),
            new PredictionMarketOutput('team_total', 'home', projectedLine: $s['home_points']),
            new PredictionMarketOutput('team_total', 'away', projectedLine: $s['away_points']),
            new PredictionMarketOutput('moneyline', 'home', probability: $p), new PredictionMarketOutput('moneyline', 'away', probability: 1 - $p)],
            [...$metadata, 'home_margin' => $s['home_margin'], 'input_quality' => [...($baseline->metadata['input_quality'] ?? []), 'qualified' => false, 'risk_flags' => [...($baseline->metadata['input_quality']['risk_flags'] ?? []), 'challenger_market_validation_required']]],
            [...$baseline->diagnostics, 'spread_baseline' => 'opponent_adjusted_possessions', 'projected_total' => $s['total']], $baseline->generatedAt);
    }
}
