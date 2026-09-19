<?php

namespace App\Services\CFB\Predictions;

use App\Application\Predictions\Data\CalculationReleaseData;
use App\Application\Predictions\Data\EventInputSnapshotData;
use App\Models\CalculationRelease;
use App\Models\CanonicalPrediction;
use App\Services\Predictions\CanonicalPayloadHasher;

/** Actual frozen predictions only; mutable table reconstructions are never relabeled as frozen evidence. */
class CfbFrozenMoneylineCalibrationDataset
{
    public function rows(string $releaseVersion): array
    {
        $rows = $excluded = $hashes = $seen = [];
        $target = CalculationRelease::query()->where('sport', 'cfb')->where('phase', 'pregame')
            ->where('semantic_version', $releaseVersion)->first();
        if (! $target) {
            return ['rows' => [], 'excluded' => ['target_release_not_found' => 1], 'configuration_hashes' => []];
        }
        $predictions = CanonicalPrediction::query()->where('sport', 'cfb')->where('phase', 'pregame')
            ->whereIn('publication_state', ['published', 'superseded'])
            ->whereHas('sportEvent.cfbGame', fn ($q) => $q->where('status', 'STATUS_FINAL'))
            ->with(['markets', 'calculationRun.release', 'calculationRun.inputSnapshot', 'sportEvent.cfbGame'])
            ->orderByDesc('generated_at')->orderByDesc('id')->lazy(50);
        foreach ($predictions as $prediction) {
            $event = $prediction->sportEvent;
            $game = $event?->cfbGame;
            $snapshot = $prediction->calculationRun?->inputSnapshot;
            if (! $game || isset($seen[$game->id])) {
                continue;
            }
            $kickoff = $event->starts_at;
            $probability = $prediction->markets->first(fn ($m) => $m->market_type === 'moneyline' && $m->selection === 'home')?->probability;
            $safe = $kickoff && $snapshot && $snapshot->pregame_safety_status === 'verified'
                && $snapshot->captured_at && $snapshot->captured_at->lt($kickoff)
                && $snapshot->cutoff_at && $snapshot->captured_at->lte($snapshot->cutoff_at) && $snapshot->cutoff_at->lte($kickoff)
                && $snapshot->created_at && $snapshot->created_at->lt($kickoff)
                && $prediction->created_at && $prediction->created_at->lt($kickoff)
                && $prediction->generated_at && $prediction->generated_at->lt($kickoff)
                && $prediction->published_at && $prediction->published_at->lt($kickoff)
                && $snapshot->captured_at->lte($prediction->generated_at)
                && (! $snapshot->latest_source_available_at || $snapshot->latest_source_available_at->lte($snapshot->captured_at));
            if (! $safe || ! CfbPredictionInputQuality::assess($snapshot->inputs)['qualified']) {
                $excluded['unverified_or_unqualified_frozen_prediction'] = ($excluded['unverified_or_unqualified_frozen_prediction'] ?? 0) + 1;

                continue;
            }
            if (! is_numeric($probability) || ! is_finite((float) $probability) || $probability <= 0 || $probability >= 1
                || ! is_numeric($game->home_score) || ! is_numeric($game->away_score)
                || $game->home_score < 0 || $game->away_score < 0 || $game->home_score == $game->away_score) {
                $excluded['invalid_probability_or_final_result'] = ($excluded['invalid_probability_or_final_result'] ?? 0) + 1;

                continue;
            }
            if (! $this->matchesRelease($prediction, $target)) {
                $excluded['current_release_output_incompatible'] = ($excluded['current_release_output_incompatible'] ?? 0) + 1;

                continue;
            }
            $seen[$game->id] = true;
            $hashes[] = $target->configuration_hash;
            $rows[] = ['game_id' => $game->id, 'prediction_id' => $prediction->id, 'snapshot_id' => $snapshot->id,
                'source_release_version' => $prediction->calculationRun->release->semantic_version,
                'compatibility_basis' => $prediction->calculationRun->release->semantic_version === $releaseVersion ? 'exact_release' : 'exact_frozen_output_replay',
                'kickoff' => $kickoff->utc()->toIso8601String(), 'season' => (int) $event->season, 'pregame_safe' => true,
                'feature_model_win_probability' => (float) $probability,
                'target_home_win' => $game->home_score > $game->away_score ? 1 : 0,
                'model_margin' => ($line = $prediction->markets->first(fn ($m) => $m->market_type === 'spread' && $m->selection === 'home')?->projected_line) !== null ? -(float) $line : null,
                'actual_margin' => (float) $game->home_score - (float) $game->away_score];
        }

        return ['rows' => $rows, 'excluded' => $excluded, 'configuration_hashes' => array_values(array_unique($hashes))];
    }

    public function matchesRelease(CanonicalPrediction $prediction, CalculationRelease $target): bool
    {
        $source = $prediction->calculationRun?->release;
        if (! $source) {
            return false;
        }
        if ($source->semantic_version === $target->semantic_version) {
            return $source->configuration_hash === $target->configuration_hash;
        }
        // Release changes affecting only other input domains must not erase valid
        // observations. Require the exact canonical output hash on original frozen inputs.
        // Never substitute current metrics, new learned evidence, or final scores.
        $snapshot = $prediction->calculationRun->inputSnapshot;
        if (! $snapshot || $snapshot->schema_version !== $target->input_schema_version) {
            return false;
        }
        try {
            $input = new EventInputSnapshotData($snapshot->schema_version, $snapshot->inputs,
                $snapshot->captured_at, $snapshot->cutoff_at, $snapshot->latest_source_available_at,
                $snapshot->source_timestamps ?? [], $snapshot->pregame_safety_status);
            $release = new CalculationReleaseData('calibration-compatibility', 'cfb', 'pregame',
                $target->calculator_name, $target->release_type, $target->semantic_version,
                $target->code_revision, $target->configuration_hash, $target->input_schema_version, $target->configuration);
            $replayed = app(CfbCalculator::class)->calculate($input, $release);

            return is_string($prediction->output_hash)
                && hash_equals($prediction->output_hash, app(CanonicalPayloadHasher::class)->hash($replayed->hashablePayload()));
        } catch (\Throwable) {
            return false;
        }
    }
}
