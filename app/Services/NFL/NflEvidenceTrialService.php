<?php

namespace App\Services\NFL;

use App\Actions\NFL\GeneratePredictionFromHistoricalElo;
use App\Models\ModelArtifact;
use App\Models\PredictionFeatureSnapshot;
use App\Models\ShadowModelOutput;
use App\Services\ML\MlArtifactStorage;
use App\Services\ML\ModelArtifactRegistry;
use App\Services\Predictions\ModelRunRecorder;
use App\Services\Predictions\SnapshotProvenanceResolver;
use App\Support\NflReasonCodeCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use InvalidArgumentException;

/** Preregistered, additive shadow experiments; never fitted or promoted from leaderboard ranks. */
class NflEvidenceTrialService
{
    public const TYPE = 'nfl_evidence_trial';

    public function register(array $spec): ModelArtifact
    {
        $specificationHash = NflEvidenceCapture::fingerprint($spec, [], []);

        return Cache::lock('nfl:evidence:register:'.$specificationHash, 30)
            ->block(5, fn () => $this->registerLocked($spec, $specificationHash));
    }

    private function registerLocked(array $spec, string $specificationHash): ModelArtifact
    {
        if (array_diff(array_keys($spec), ['model_version', 'market', 'candidates', 'discovery_end', 'validation_end', 'name'])) {
            throw new InvalidArgumentException('Unknown trial specification fields. Validation gates cannot be overridden.');
        }
        $existing = ModelArtifact::where('model_type', self::TYPE)->whereHas('trainingRun', fn ($q) => $q->where('parameters->specification_hash', $specificationHash))->first();
        if ($existing) {
            $this->protocol($existing);

            return $existing;
        }
        $registered = CarbonImmutable::now();
        $discoveryEnd = CarbonImmutable::parse($spec['discovery_end'] ?? 'invalid');
        $validationEnd = CarbonImmutable::parse($spec['validation_end'] ?? 'invalid');
        $market = $spec['market'] ?? '';
        $candidates = $spec['candidates'] ?? [];
        $cap = $market === 'winner' ? .03 : 1.;
        if (! in_array($market, ['winner', 'spread', 'total'], true) || ! is_array($candidates) || count($candidates) < 1 || count($candidates) > 10
            || $discoveryEnd->lt($registered->addDays(7)) || $validationEnd->lt($discoveryEnd->addDays(8))
            || empty($spec['model_version']) || strlen($spec['model_version']) > 128) {
            throw new InvalidArgumentException('Specify a model, market, 1–10 explicit candidate offsets and future discovery/validation periods (at least 7 days each plus a 24-hour embargo).');
        }
        foreach ($candidates as $key => $offset) {
            if (! preg_match('/^(catalog_[1-9][0-9]*_(home|away)|source_(reason_code|risk_flag|matched_rule|pass_rule|validated_combo)_[a-zA-Z0-9_]+)$/', (string) $key) || ! is_numeric($offset) || ! is_finite((float) $offset) || abs((float) $offset) > $cap) {
                throw new InvalidArgumentException('Candidate keys must identify a frozen catalog or atomic source condition; offsets must be finite and within the market cap.');
            }
        }
        ksort($candidates);
        $id = (string) Str::uuid();
        $protocol = ['version' => 'nfl-evidence-trial-v1', 'name' => substr((string) ($spec['name'] ?? 'NFL evidence trial'), 0, 120), 'specification_hash' => $specificationHash, 'registered_at' => $registered->toIso8601String(),
            'model_version' => $spec['model_version'], 'market' => $market, 'candidates' => array_map(floatval(...), $candidates),
            'discovery_end' => $discoveryEnd->toIso8601String(), 'validation_start' => $discoveryEnd->addDay()->toIso8601String(),
            'validation_end' => $validationEnd->toIso8601String(), 'minimum_games_per_period' => 30,
            'minimum_validation_weeks' => 3, 'family_size' => 100, 'alpha' => .05, 'maximum_adjustment' => $cap,
            'source_config_hash' => app(ModelRunRecorder::class)->configHash('nfl'), 'code_hash' => $this->codeHash(), 'coefficient_policy' => 'explicit_preregistered_offsets_no_outcome_fitting',
            'comparison' => 'same_frozen_base_forecast_plus_only_the_preregistered_additive_adjustment',
            'promotion_allowed' => false];
        $path = storage_path('app/ml/nfl/evidence-trials/'.$id.'.json');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode($protocol, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        $run = app(ModelRunRecorder::class)->create('nfl', 'evidence_trial', 'nfl-evidence-trial-'.$id,
            NflEvidenceCapture::POLICY, NflEvidenceCapture::POLICY, parameters: $protocol, status: 'completed', completedAt: now());

        return app(ModelArtifactRegistry::class)->register($id, $run, $market, self::TYPE, 'nfl-evidence-trial-'.$id,
            NflEvidenceCapture::POLICY, hash('sha256', 'no_fitted_dataset'), $path, ['protocol_only' => true]);
    }

    public function recordShadows(PredictionFeatureSnapshot $snapshot): int
    {
        if ($snapshot->blend_version !== NflEvidenceCapture::POLICY || ! $this->safe($snapshot) || ! $snapshot->game_start_at->isFuture()) {
            return 0;
        }
        $created = 0;
        foreach (ModelArtifact::with('trainingRun')->where('sport', 'nfl')->where('model_type', self::TYPE)->where('status', 'challenger')->get() as $artifact) {
            try {
                $p = $this->protocol($artifact);
            } catch (\Throwable) {
                continue;
            }
            if ($p['model_version'] !== $snapshot->model_version || $snapshot->modelRun?->config_hash !== $p['source_config_hash'] || $snapshot->generated_at->lt(CarbonImmutable::parse($p['registered_at']))
                || $snapshot->generated_at->gt(CarbonImmutable::parse($p['validation_end'])) || ! hash_equals($p['code_hash'], $this->codeHash())) {
                continue;
            }
            $field = ['winner' => 'win_probability', 'spread' => 'predicted_spread', 'total' => 'predicted_total'][$p['market']];
            $base = $snapshot->outputs[$field] ?? null;
            if (! is_numeric($base) || ! is_finite((float) $base)) {
                continue;
            }
            $delta = 0.;
            $states = [];
            foreach ($p['candidates'] as $key => $offset) {
                $state = data_get($snapshot->features, 'evidence.conditions.'.$key.'.state', 'unavailable');
                $states[$key] = $state;
                $delta += $state === 'matched' ? $offset : 0.;
            }
            // Unknown candidate state is not equivalent to an absent condition.
            if (array_diff(array_values($states), ['matched', 'not_matched'])) {
                continue;
            }
            $delta = max(-$p['maximum_adjustment'], min($p['maximum_adjustment'], $delta));
            $base = round((float) $base, 6);
            $prediction = (float) $base + $delta;
            if ($p['market'] === 'winner') {
                $prediction = max(.01, min(.99, $prediction));
            }
            $prediction = round($prediction, 6);
            $ablations = [];
            foreach ($p['candidates'] as $key => $offset) {
                $without = 0.;
                foreach ($p['candidates'] as $other => $coefficient) {
                    $without += $other !== $key && $states[$other] === 'matched' ? $coefficient : 0.;
                }
                $value = $base + max(-$p['maximum_adjustment'], min($p['maximum_adjustment'], $without));
                $ablations[$key] = round($p['market'] === 'winner' ? max(.01, min(.99, $value)) : $value, 6);
            }
            $explanation = ['output_hash' => $this->outputHash($snapshot, $artifact, $base, $prediction, $ablations), 'candidate_ablations' => $ablations, 'evidence_trial' => true, 'protocol_hash' => $artifact->artifact_hash,
                'code_hash' => $p['code_hash'], 'feature_hash' => $snapshot->feature_hash, 'states' => $states,
                'calibrated' => false, 'bet_eligible' => false, 'public_output_changed' => false,
                'limitation' => 'Tests an additive residual proposal, not removal of an original model component.'];
            $shadow = ShadowModelOutput::firstOrCreate(['model_artifact_id' => $artifact->id,
                'prediction_feature_snapshot_id' => $snapshot->id, 'market_type' => $p['market']], [
                    'inference_run_id' => $snapshot->model_run_id, 'sport' => 'nfl', 'game_table' => 'nfl_games', 'game_id' => $snapshot->game_id,
                    'prediction_table' => 'nfl_predictions', 'prediction_id' => $snapshot->prediction_id,
                    'baseline_output' => $base, 'challenger_output' => $prediction, 'output_delta' => $prediction - $base,
                    'status' => 'shadow', 'explanation' => $explanation, 'generated_at' => $snapshot->generated_at]);
            $created += (int) $shadow->wasRecentlyCreated;
        }

        return $created;
    }

    public function evaluate(ModelArtifact $artifact): array
    {
        $p = $this->protocol($artifact);
        $periods = ['discovery' => [], 'validation' => []];
        $excluded = [];
        $seen = [];
        $query = ShadowModelOutput::with(['featureSnapshot', 'featureSnapshot.nflGame'])
            ->where('model_artifact_id', $artifact->id)->orderByDesc('generated_at')->orderByDesc('id');
        foreach ($query->get() as $shadow) {
            $s = $shadow->featureSnapshot;
            if (! $s || $s->blend_version !== NflEvidenceCapture::POLICY || ! $this->safe($s) || ($shadow->explanation['protocol_hash'] ?? null) !== $artifact->artifact_hash
                || ($shadow->explanation['code_hash'] ?? null) !== $p['code_hash']
                || ($shadow->explanation['feature_hash'] ?? null) !== $s->feature_hash
                || $shadow->generated_at->ne($s->generated_at)
                || $s->model_version !== $p['model_version']
                || $s->modelRun?->config_hash !== $p['source_config_hash']
                || abs((float) $shadow->output_delta) > $p['maximum_adjustment'] + 1e-6
                || abs((float) $shadow->baseline_output - round((float) ($s->outputs[['winner' => 'win_probability', 'spread' => 'predicted_spread', 'total' => 'predicted_total'][$p['market']]] ?? NAN), 6)) > 1e-6
                || ($shadow->explanation['output_hash'] ?? null) !== $this->outputHash($s, $artifact, (float) $shadow->baseline_output, (float) $shadow->challenger_output, $shadow->explanation['candidate_ablations'] ?? [])
                || $s->generated_at->lt(CarbonImmutable::parse($p['registered_at']))
                || $s->game_start_at->gt(CarbonImmutable::parse($p['validation_end']))) {
                $excluded['invalid_lineage_or_period'] = ($excluded['invalid_lineage_or_period'] ?? 0) + 1;

                continue;
            }
            if (isset($seen[$s->game_id])) {
                continue;
            }
            $seen[$s->game_id] = true;
            $g = $s->nflGame;
            if ($s->game_start_at->isFuture() || ! $g || $g->status !== 'STATUS_FINAL' || $g->home_score === null || $g->away_score === null) {
                $excluded['pending_actual'] = ($excluded['pending_actual'] ?? 0) + 1;

                continue;
            }
            $period = $s->game_start_at->lt(CarbonImmutable::parse($p['discovery_end'])) ? 'discovery'
                : ($s->game_start_at->gte(CarbonImmutable::parse($p['validation_start'])) ? 'validation' : null);
            if ($period === null) {
                $excluded['embargo'] = ($excluded['embargo'] ?? 0) + 1;

                continue;
            }
            if ($p['market'] === 'winner' && $g->home_score === $g->away_score) {
                $excluded['tie'] = ($excluded['tie'] ?? 0) + 1;

                continue;
            }
            $actual = $p['market'] === 'total' ? $g->home_score + $g->away_score
                : ($p['market'] === 'spread' ? $g->home_score - $g->away_score : (int) ($g->home_score > $g->away_score));
            $base = (float) $shadow->baseline_output;
            $test = (float) $shadow->challenger_output;
            $loss = fn ($v) => $p['market'] === 'winner' ? ($v - $actual) ** 2 : abs($v - $actual);
            $ablationLoss = [];
            foreach (($shadow->explanation['candidate_ablations'] ?? []) as $candidate => $value) {
                $ablationLoss[$candidate] = $loss((float) $value) - $loss($test);
            }
            $periods[$period][] = ['candidate_improvement' => $ablationLoss, 'game_id' => $g->id, 'week' => sprintf('%d:%02d', $g->season, $g->week),
                'cohort' => data_get($s->features, 'evidence.context.cohort', 'unknown'),
                'baseline_loss' => $loss($base), 'shadow_loss' => $loss($test), 'improvement' => $loss($base) - $loss($test),
                'baseline_log_loss' => $p['market'] === 'winner' ? -($actual * log(max(1e-6, $base)) + (1 - $actual) * log(max(1e-6, 1 - $base))) : null,
                'shadow_log_loss' => $p['market'] === 'winner' ? -($actual * log(max(1e-6, $test)) + (1 - $actual) * log(max(1e-6, 1 - $test))) : null];
        }
        $summaries = [];
        foreach ($periods as $name => $rows) {
            $summaries[$name] = $this->summary($rows, $p);
        }
        $contributions = [];
        foreach ($periods as $period => $rows) {
            foreach (array_keys($p['candidates']) as $candidate) {
                $values = array_values(array_filter(array_map(fn ($r) => $r['candidate_improvement'][$candidate] ?? null, $rows), is_numeric(...)));
                $contributions[$candidate][$period] = ['games' => count($values), 'mean_full_minus_ablated_improvement' => $values === [] ? null : array_sum($values) / count($values)];
            }
        }
        $checks = [
            'protocol_code_unchanged' => hash_equals($p['code_hash'], $this->codeHash()),
            'source_configuration_unchanged' => hash_equals($p['source_config_hash'], app(ModelRunRecorder::class)->configHash('nfl')),
            'validation_period_finished' => now()->gte(CarbonImmutable::parse($p['validation_end'])->addDay()),
            'discovery_sample' => $summaries['discovery']['games'] >= $p['minimum_games_per_period'],
            'validation_sample' => $summaries['validation']['games'] >= $p['minimum_games_per_period'],
            'validation_weeks' => $summaries['validation']['weeks'] >= $p['minimum_validation_weeks'],
            'discovery_improvement' => ($summaries['discovery']['mean_improvement'] ?? -1) > 0,
            'corrected_validation_improvement' => ($summaries['validation']['simultaneous_lower_bound'] ?? -1) > 0,
            'no_missing_actuals' => ($excluded['pending_actual'] ?? 0) === 0,
            'family_budget' => ModelArtifact::where('model_type', self::TYPE)->count() <= $p['family_size'],
        ];
        $cohorts = collect($periods['validation'])->groupBy('cohort')->map(fn ($r) => $this->summary($r->all(), $p))->all();
        $checks['subgroup_coverage'] = collect(['divisional', 'conference_non_divisional', 'interconference'])
            ->every(fn ($cohort) => ($cohorts[$cohort]['games'] ?? 0) >= 10 && ($cohorts[$cohort]['mean_improvement'] ?? -1) >= 0);

        $rolling = [];
        $validationWeeks = collect($periods['validation'])->pluck('week')->unique()->sort()->values();
        foreach ($validationWeeks as $week) {
            $prefix = array_values(array_filter($periods['validation'], fn ($r) => strcmp($r['week'], $week) <= 0));
            $rolling[] = ['through_week' => $week, ...$this->summary($prefix, $p)];
        }
        $status = match (true) {
            ! $checks['protocol_code_unchanged'] => 'code_changed',
            ! $checks['source_configuration_unchanged'] => 'configuration_changed',
            ! $checks['validation_period_finished'] => 'awaiting_confirmation',
            ! $checks['discovery_sample'] || ! $checks['validation_sample'] || ! $checks['validation_weeks'] => 'insufficient_evidence',
            ! $checks['discovery_improvement'] || ($summaries['validation']['mean_improvement'] ?? -1) <= 0 => 'no_improvement',
            ! $checks['subgroup_coverage'] => 'unstable_or_incomplete_subgroups',
            in_array(false, $checks, true) => 'inconclusive',
            default => 'confirmed_shadow_improvement',
        };

        return ['artifact_id' => $artifact->id, 'protocol' => $p, 'periods' => $summaries, 'cohorts' => $cohorts,
            'candidate_contributions' => $contributions, 'ablation_basis' => 'proposed_additive_components_only_same_frozen_inputs',
            'excluded' => $excluded, 'checks' => $checks, 'status' => $status, 'expanding_validation_windows' => $rolling,
            'promoted' => false, 'bet_eligible' => false, 'calibrated' => false,
            'uncertainty_method' => 'Week-cluster means; equal week weighting; bounded Hoeffding interval with Bonferroni family correction.',
            'limitation' => 'No automatic production promotion. Confirmed shadow results still require the existing model approval/calibration workflow.'];
    }

    public function protocol(ModelArtifact $artifact): array
    {
        $p = $artifact->trainingRun?->parameters;
        if ($artifact->model_type !== self::TYPE || ! is_array($p)) {
            throw new InvalidArgumentException('Not an evidence trial protocol.');
        }
        $path = $artifact->artifact_disk && $artifact->artifact_object_key
            ? app(MlArtifactStorage::class)->materialize($artifact->artifact_disk, $artifact->artifact_object_key, $artifact->artifact_hash)
            : $artifact->artifact_path;
        if (! is_file($path) || ! hash_equals($artifact->artifact_hash, hash_file('sha256', $path))
            || ! hash_equals(NflEvidenceCapture::fingerprint(json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR), [], []), NflEvidenceCapture::fingerprint($p, [], []))) {
            throw new InvalidArgumentException('Trial protocol integrity mismatch.');
        }

        return $p;
    }

    public function safe(PredictionFeatureSnapshot $s): bool
    {
        foreach (['win_probability', 'predicted_spread', 'predicted_total'] as $field) {
            if (! is_numeric($s->outputs[$field] ?? null) || ! is_finite((float) $s->outputs[$field])) {
                return false;
            }
        }
        if ($s->outputs['win_probability'] <= 0 || $s->outputs['win_probability'] >= 1) {
            return false;
        }
        $game = $s->nflGame;
        $kickoff = $game ? app(SnapshotProvenanceResolver::class)->gameStartAt($game) : null;

        return $kickoff !== null && $s->game_start_at?->eq($kickoff) && NflEvidenceCapture::isEvidence($s)
            && ($s->blend_version !== NflEvidenceCapture::NATIVE_POLICY || NflEvidenceCapture::hasNativeLineage($s)) && $s->pregame_safe && $s->availability_status === 'observed_pregame'
            && $s->generated_at && $s->game_start_at && $s->features_available_at && $s->generated_at->lt($s->game_start_at)
            && $s->features_available_at->lte($s->generated_at)
            && $s->generated_at->gte($s->game_start_at->toImmutable()->subDay())
            && hash_equals($s->feature_hash, NflEvidenceCapture::fingerprint($s->features, $s->outputs, $s->market_context));
    }

    private function summary(array $rows, array $p): array
    {
        $weeks = collect($rows)->groupBy('week')->map(fn ($r) => $r->avg('improvement'));
        $mean = $weeks->isEmpty() ? null : $weeks->avg();
        $width = $p['market'] === 'winner' ? 4 * $p['maximum_adjustment'] : 2 * $p['maximum_adjustment'];
        $radius = $weeks->isEmpty() ? null : $width * sqrt(log(2 * $p['family_size'] / $p['alpha']) / (2 * $weeks->count()));

        return ['games' => count($rows), 'weeks' => $weeks->count(), 'mean_improvement' => $mean,
            'simultaneous_lower_bound' => $mean === null ? null : $mean - $radius,
            'baseline_loss' => $rows === [] ? null : collect($rows)->avg('baseline_loss'),
            'shadow_loss' => $rows === [] ? null : collect($rows)->avg('shadow_loss'),
            'baseline_log_loss' => collect($rows)->avg('baseline_log_loss'), 'shadow_log_loss' => collect($rows)->avg('shadow_log_loss')];
    }

    private function outputHash(PredictionFeatureSnapshot $snapshot, ModelArtifact $artifact, float $base, float $prediction, array $ablations = []): string
    {
        return NflEvidenceCapture::fingerprint(['source_hash' => $snapshot->feature_hash, 'protocol_hash' => $artifact->artifact_hash],
            ['baseline' => round($base, 6), 'challenger' => round($prediction, 6), 'candidate_ablations' => $ablations], []);
    }

    private function codeHash(): string
    {
        $files = [__FILE__, (new \ReflectionClass(NflReasonCodeCatalog::class))->getFileName(), (new \ReflectionClass(NflSignalObservationMaterializer::class))->getFileName(), (new \ReflectionClass(NflEvidenceCapture::class))->getFileName(),
            (new \ReflectionClass(GeneratePredictionFromHistoricalElo::class))->getFileName(),
            (new \ReflectionClass(NflSignalGradingService::class))->getFileName()];
        foreach (File::files(app_path('Services/NFL/Matchups')) as $file) {
            if ($file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
        $hashes = [];
        foreach ($files as $file) {
            $hashes[basename($file)] = hash_file('sha256', $file);
        }
        ksort($hashes);

        return hash('sha256', json_encode($hashes, JSON_THROW_ON_ERROR));
    }
}
