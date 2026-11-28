<?php

namespace App\Services\NFL;

use App\Actions\GradePlayerProps;
use App\Models\NFL\Game;
use App\Models\NFL\PlayerProp;
use App\Models\NFL\PlayerStat;
use App\Models\PredictionEvaluation;
use App\Models\PredictionFeatureSnapshot;
use Carbon\CarbonImmutable;

/** Read-only, deidentified extraction. Never repairs labels or changes snapshots. */
class NflPropProspectiveDataset
{
    public function build(int $season): array
    {
        $policy = NflPropProbabilityExperiment::POLICY;
        $games = Game::with('sportEvent')->where('season', $season)->whereIn('season_type', ['2', 'regular'])->get()->keyBy('id');
        $snapshots = PredictionFeatureSnapshot::where('sport', 'nfl')->where('blend_version', $policy)
            ->whereIn('game_id', $games->keys())->orderBy('generated_at')->orderBy('id')->get();
        $evaluations = PredictionEvaluation::where('sport', 'nfl')->where('prediction_table', 'prediction_feature_snapshots')
            ->where('blend_version', $policy)->whereIn('prediction_id', $snapshots->pluck('id'))->get()->groupBy('prediction_id');
        $stats = PlayerStat::whereIn('game_id', $snapshots->pluck('game_id')->unique())->get()->groupBy(fn ($s) => $s->game_id.':'.$s->player_id);
        $reader = new class extends GradePlayerProps
        {
            public function actual(PlayerProp $prop): ?float
            {
                return $this->getNflActualValue($prop);
            }
        };
        $rows = $counts = $pending = $seen = [];
        foreach ($snapshots as $snapshot) {
            $game = $games->get($snapshot->game_id);
            $reason = $this->invalidReason($snapshot, $game?->sportEvent?->starts_at?->toIso8601String());
            $market = (string) ($snapshot->market_context['market'] ?? 'missing');
            if ($reason !== null) {
                $counts['excluded'][$reason] = ($counts['excluded'][$reason] ?? 0) + 1;

                continue;
            }
            $labels = $evaluations->get($snapshot->id, collect());
            $key = $snapshot->game_id.':'.($snapshot->model_metadata['player_id'] ?? 'missing');
            $matches = $stats->get($key, collect());
            $stat = $matches->count() === 1 ? $matches->first() : null;
            $actual = null;
            $actualReason = match (true) {
                $game->status !== 'STATUS_FINAL' => 'game_not_final',
                $matches->isEmpty() => 'missing_player_stat',
                $matches->count() > 1 => 'ambiguous_player_stats',
                (int) $stat->team_id !== (int) ($snapshot->model_metadata['team_id'] ?? 0) => 'team_mismatch',
                default => null,
            };
            if ($actualReason === null) {
                $actual = $reader->actual(new PlayerProp(['game_id' => $game->id, 'player_id' => $stat->player_id, 'market' => $market]));
                $actualReason = $actual === null ? 'missing_market_actual_or_participation' : null;
            }
            if ($labels->isEmpty()) {
                $reason = $actualReason ?? 'actual_available_not_evaluated';
                $pending[$market][$reason] = ($pending[$market][$reason] ?? 0) + 1;

                continue;
            }
            $label = $labels->first();
            $reason = match (true) {
                $labels->count() !== 1 => 'ambiguous_label_revisions',
                $actualReason !== null => 'graded_'.$actualReason,
                ! is_numeric($label->actuals['value'] ?? null) => 'invalid_label',
                (float) $label->actuals['value'] !== $actual => 'current_stat_differs_from_label',
                (int) ($label->actuals['stat_id'] ?? 0) !== (int) $stat->id => 'label_stat_identity_mismatch',
                $label->feature_version !== $snapshot->feature_version || $label->model_version !== $snapshot->model_version || (int) $label->game_id !== (int) $game->id => 'label_version_or_game_mismatch',
                ! $label->evaluated_at || $label->evaluated_at->lte($snapshot->game_start_at) || $label->evaluated_at->isFuture() => 'invalid_label_availability',
                default => null,
            };
            if ($reason !== null) {
                $counts['excluded'][$reason] = ($counts['excluded'][$reason] ?? 0) + 1;

                continue;
            }
            $identity = $key.':'.$market.':'.$snapshot->feature_version;
            if (isset($seen[$identity])) {
                $counts['excluded']['duplicate_version_outcome'] = ($counts['excluded']['duplicate_version_outcome'] ?? 0) + 1;

                continue;
            }
            $seen[$identity] = true;
            $rows[] = [
                'snapshot_id' => $snapshot->id,
                'outcome_key' => hash('sha256', $key.':'.$market),
                'game_group' => hash('sha256', (string) $game->id),
                'market' => $market, 'code_version' => $snapshot->feature_version, 'model_version' => $snapshot->model_version,
                'week' => (int) $game->week, 'kickoff_at' => $snapshot->game_start_at->toIso8601String(),
                'captured_at' => $snapshot->generated_at->toIso8601String(), 'labels_available_at' => $label->evaluated_at->toIso8601String(),
                'probability' => (float) $snapshot->outputs['over_probability'], 'push_probability' => (float) $snapshot->outputs['push_probability'],
                'line' => (float) $snapshot->market_context['line'], 'actual' => $actual,
                'over_price' => $snapshot->market_context['over_price'] ?? null, 'under_price' => $snapshot->market_context['under_price'] ?? null,
            ];
        }

        return ['format' => 'nfl-prop-prospective-dataset-v1', 'season' => $season, 'checked_at' => now()->toIso8601String(),
            'captured' => $snapshots->count(), 'exclusions' => $counts['excluded'] ?? [], 'pending' => $pending, 'rows' => $rows,
            'production_promotion' => false];
    }

    public function invalidReason(PredictionFeatureSnapshot $s, ?string $canonicalKickoff): ?string
    {
        try {
            if (($s->features['fingerprint_version'] ?? null) !== 'round8-v1') {
                return 'fingerprint_version';
            }
            if (! hash_equals((string) $s->feature_hash, NflPropProbabilityExperiment::fingerprint($s->features, $s->outputs, $s->market_context))) {
                return 'integrity_hash';
            }
            if (! $canonicalKickoff || ! $s->game_start_at || ! isset($s->features['kickoff_at'])
                || ! $s->game_start_at->eq(CarbonImmutable::parse($canonicalKickoff))
                || ! $s->game_start_at->eq(CarbonImmutable::parse($s->features['kickoff_at']))) {
                return 'canonical_kickoff';
            }
            if (! $s->pregame_safe || ! $s->generated_at || ! $s->features_available_at
                || $s->generated_at->gte($s->game_start_at) || $s->features_available_at->gt($s->generated_at)) {
                return 'pregame_timing';
            }
            $quote = CarbonImmutable::parse($s->market_context['quote_observed_at'] ?? throw new \InvalidArgumentException);
            if ($quote->gt($s->generated_at) || $quote->gte($s->game_start_at) || $quote->lt($s->game_start_at->toImmutable()->subHours(36))) {
                return 'quote_timing';
            }
            foreach (['over_probability', 'push_probability'] as $field) {
                if (! is_numeric($s->outputs[$field] ?? null) || ! is_finite((float) $s->outputs[$field]) || $s->outputs[$field] < 0 || $s->outputs[$field] > 1) {
                    return 'invalid_probability';
                }
            }
            if (! is_numeric($s->market_context['line'] ?? null) || ! is_finite((float) $s->market_context['line'])
                || empty($s->model_metadata['player_id']) || empty($s->model_metadata['team_id'])) {
                return 'invalid_market_or_identity';
            }

            return null;
        } catch (\Throwable) {
            return 'malformed_snapshot';
        }
    }
}
