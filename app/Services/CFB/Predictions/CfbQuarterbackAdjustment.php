<?php

namespace App\Services\CFB\Predictions;

use App\Application\Predictions\Data\EventInputSnapshotData;
use App\Application\Predictions\Data\PredictionMarketOutput;
use App\Application\Predictions\Data\PredictionOutput;

class CfbQuarterbackAdjustment
{
    /** A team-scoring delta: (shrunk replacement EPA - existing QB mixture EPA) × observed workload. */
    public function estimate(array $evidence, array $policy): array
    {
        $result = ['status' => $evidence['status'] ?? 'unavailable', 'applied' => false,
            'starter_player_id' => $evidence['starter_player_id'] ?? null,
            'replacement_player_id' => $evidence['replacement_player_id'] ?? null,
            'starter_name' => $evidence['starter_name'] ?? null, 'replacement_name' => $evidence['replacement_name'] ?? null,
            'identity_basis' => $evidence['identity_basis'] ?? null, 'absence_status' => $evidence['absence_status'] ?? null,
            'source_available_at' => $evidence['source_available_at'] ?? null,
            'samples' => collect($evidence['profiles'] ?? [])->map(fn ($p) => collect($p)->only([
                'player_id', 'plays', 'weighted_plays', 'games', 'passing_identity_coverage']))->all(),
            'rejected_game_ids' => $evidence['rejected_game_ids'] ?? [],
            'offense_points_adjustment' => null, 'validation_status' => 'provisional_not_prospectively_validated'];
        if (($evidence['version'] ?? null) !== CfbQuarterbackEvidence::VERSION || ($evidence['status'] ?? null) !== 'captured') {
            return $result;
        }
        $profiles = collect($evidence['profiles'] ?? [])->keyBy('player_id');
        $starter = $profiles->get($result['starter_player_id']);
        $replacement = $profiles->get($result['replacement_player_id']);
        $baseline = $evidence['baseline'] ?? [];
        foreach ([$starter, $replacement] as $profile) {
            if (! $profile || ($profile['weighted_plays'] ?? 0) < $policy['minimum_plays']
                || count($profile['games'] ?? []) < $policy['minimum_games']
                || ($profile['passing_identity_coverage'] ?? 0) < $policy['minimum_identity_coverage']
                || ! is_numeric($profile['epa_per_play'] ?? null) || ! is_finite((float) $profile['epa_per_play'])) {
                return [...$result, 'status' => 'insufficient_play_evidence'];
            }
        }
        if (count($baseline['games'] ?? []) < $policy['minimum_games']
            || ($baseline['current_game_coverage'] ?? 0) < $policy['minimum_current_game_coverage']
            || ! is_numeric($baseline['epa_per_play'] ?? null) || ! is_finite((float) $baseline['epa_per_play'])
            || ! is_numeric($baseline['plays_per_game'] ?? null) || $baseline['plays_per_game'] <= 0) {
            return [...$result, 'status' => 'insufficient_baseline'];
        }
        $reliability = $replacement['weighted_plays'] / ($replacement['weighted_plays'] + $policy['prior_plays']);
        $delta = ($replacement['epa_per_play'] - $baseline['epa_per_play']) * $reliability
            * min($policy['maximum_plays_per_game'], $baseline['plays_per_game']);
        $cap = $policy['maximum_team_points'];

        return [...$result, 'status' => 'provisional_replacement_estimate', 'applied' => true,
            'offense_points_adjustment' => round(max(-$cap, min($cap, $delta)), 4),
            'uncapped_points_adjustment' => round($delta, 4), 'reliability' => round($reliability, 4),
            'baseline_epa_per_play' => $baseline['epa_per_play'], 'replacement_epa_per_play' => $replacement['epa_per_play'],
            'baseline_includes_observed_backup_usage' => true, 'confirmed_starter' => false];
    }

    /** Remove the replaced generic charge before any team-model blending. Frozen source rows stay intact. */
    public function baselineSnapshot(EventInputSnapshotData $snapshot, array $estimates): EventInputSnapshotData
    {
        $inputs = $snapshot->inputs;
        foreach ($estimates as $side => $estimate) {
            if (! $estimate['applied']) {
                continue;
            }
            $inputs[$side]['injuries'] = array_values(array_filter($inputs[$side]['injuries'] ?? [],
                fn ($i) => (int) $i['player_id'] !== (int) $estimate['starter_player_id']));
            $inputs[$side]['metrics']['injury_adjusted_team_rating'] = null;
        }

        return new EventInputSnapshotData($snapshot->schemaVersion, $inputs, $snapshot->capturedAt, $snapshot->cutoffAt,
            $snapshot->latestSourceAvailableAt, $snapshot->sourceTimestamps, $snapshot->pregameSafetyStatus, $snapshot->objectUri, $snapshot->metadata);
    }

    /** Apply once after all model blends so the personnel delta is neither erased nor diluted. */
    public function apply(PredictionOutput $output, array $estimates, float $probabilityScale): PredictionOutput
    {
        $markets = collect($output->markets);
        $margin = -(float) $markets->first(fn ($m) => $m->marketType === 'spread' && $m->selection === 'home')->projectedLine;
        $total = (float) $markets->first(fn ($m) => $m->marketType === 'total')->projectedLine;
        $deltas = [];
        foreach (['home', 'away'] as $side) {
            $requested = $estimates[$side]['applied'] ? $estimates[$side]['offense_points_adjustment'] : 0.0;
            $teamPoints = ($total + ($side === 'home' ? $margin : -$margin)) / 2;
            $deltas[$side] = max(-max(0, $teamPoints), $requested);
        }
        $newMargin = round($margin + $deltas['home'] - $deltas['away'], 4);
        $newTotal = round($total + $deltas['home'] + $deltas['away'], 4);
        $p = round(1 / (1 + exp(-$newMargin / $probabilityScale)), 6);
        $applied = collect($estimates)->contains('applied', true);
        $result = $applied ? array_map(fn ($m) => match ($m->marketType) {
            'spread' => new PredictionMarketOutput('spread', $m->selection, projectedLine: -$newMargin),
            'total' => new PredictionMarketOutput('total', $m->selection, projectedLine: $newTotal),
            'moneyline' => new PredictionMarketOutput('moneyline', $m->selection, probability: $m->selection === 'home' ? $p : round(1 - $p, 6)),
            default => $m,
        }, $output->markets) : $output->markets;
        $evidence = ['home' => $estimates['home'], 'away' => $estimates['away'], 'applied_team_points' => $deltas,
            'pre_adjustment_margin' => $margin, 'pre_adjustment_total' => $total,
            'generic_qb_penalty_removed' => $applied, 'application_stage' => 'after_forecast_coordination'];

        return new PredictionOutput($result, [...$output->metadata, 'home_margin' => $newMargin,
            'quarterback_adjustment' => $evidence], [...$output->diagnostics, 'home_margin' => $newMargin,
                'projected_total' => $newTotal, 'quarterback_adjustment' => $evidence], $output->generatedAt);
    }
}
