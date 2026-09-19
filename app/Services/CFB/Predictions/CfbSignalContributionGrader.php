<?php

namespace App\Services\CFB\Predictions;

use App\Application\Predictions\Data\CalculationReleaseData;
use App\Application\Predictions\Data\EventInputSnapshotData;
use App\Models\CanonicalPrediction;
use App\Services\CFB\Signals\CfbFootballSignalEvidence;
use Carbon\CarbonImmutable;

/** Paired counterfactuals on frozen inputs; never refits weights or rewrites predictions. */
class CfbSignalContributionGrader
{
    public const VERSION = 'cfb-signal-ablation-1';

    public function evaluate(CanonicalPrediction $prediction, CarbonImmutable $kickoff, ?float $actualMargin, ?float $actualTotal, ?float $homeLine = null, ?float $totalLine = null): array
    {
        $prediction->loadMissing(['markets', 'calculationRun.release', 'calculationRun.inputSnapshot']);
        $run = $prediction->calculationRun;
        $snapshot = $run?->inputSnapshot;
        if (! $snapshot || $snapshot->pregame_safety_status !== 'verified' || ! $snapshot->captured_at
            || $snapshot->captured_at->gte($kickoff) || ! $prediction->generated_at || $prediction->generated_at->gte($kickoff)
            || ($snapshot->latest_source_available_at && $snapshot->latest_source_available_at->gt($snapshot->captured_at))) {
            return ['excluded' => 'no_verified_pregame_prediction'];
        }
        if (data_get($run->diagnostics, 'spread_baseline') !== 'fpi_points') {
            return ['excluded' => 'unsupported_fallback_baseline'];
        }
        $release = CalculationReleaseData::fromModel($run->release);
        $inputs = $snapshot->inputs;
        $configuration = $release->configuration;
        $calculate = function (array $i, array $c) use ($snapshot, $release): array {
            if ($c !== $release->configuration && isset($i['football_signal_evidence'])) {
                // A paired ablation holds the already fitted corrections fixed while changing one base term.
                $i['football_signal_evidence']['baseline_hash'] = CfbFootballSignalEvidence::baselineHash($c);
            }
            $r = new CalculationReleaseData($release->publicId, $release->sport, $release->phase, $release->calculatorName,
                $release->releaseType, $release->semanticVersion, $release->codeRevision, $release->configurationHash,
                $release->inputSchemaVersion, $c);
            $out = app(CfbCalculator::class)->calculate(new EventInputSnapshotData($snapshot->schema_version, $i, $snapshot->captured_at, $snapshot->cutoff_at), $r);
            $markets = collect($out->markets);

            return ['spread' => -$markets->first(fn ($m) => $m->marketType === 'spread')->projectedLine,
                'total' => $markets->first(fn ($m) => $m->marketType === 'total')->projectedLine];
        };
        $full = $calculate($inputs, $configuration);
        $storedSpread = $prediction->markets->first(fn ($m) => $m->market_type === 'spread' && $m->selection === 'home');
        $storedTotal = $prediction->markets->first(fn ($m) => $m->market_type === 'total');
        if (! is_numeric($storedSpread?->projected_line) || ! is_numeric($storedTotal?->projected_line)
            || abs($full['spread'] + (float) $storedSpread->projected_line) > .001
            || abs($full['total'] - (float) $storedTotal->projected_line) > .001) {
            return ['excluded' => 'stored_prediction_replay_mismatch'];
        }
        $signals = [
            'fpi_gap' => [], 'home_field' => ['elo.home_field_advantage'],
            'recent_form' => ['context.recent_spread_weight', 'context.recent_total_weight'],
            'turnovers' => ['context.turnover_spread_weight'],
            'rest_travel_fatigue' => ['context.fatigue_spread_weight', 'context.fatigue_total_weight'],
            'injury_rating' => ['context.injury_rating_spread_weight'],
            'injury_records' => ['injuries.out_spread_penalty', 'injuries.questionable_spread_penalty', 'injuries.out_total_penalty', 'injuries.questionable_total_penalty'],
            'offensive_scoring' => [], 'defensive_scoring' => [],
        ];
        $rows = [];
        foreach ($signals as $signal => $paths) {
            $i = $inputs;
            $c = $configuration;
            foreach ($paths as $path) {
                data_set($c, $path, 0);
            }
            if ($signal === 'fpi_gap') {
                data_set($i, 'home.metrics.fpi', data_get($i, 'away.metrics.fpi'));
            }
            if (in_array($signal, ['offensive_scoring', 'defensive_scoring'], true)) {
                $field = $signal === 'offensive_scoring' ? 'points_per_game' : 'points_allowed_per_game';
                foreach (['home', 'away'] as $side) {
                    foreach (['metrics', 'prior_metrics'] as $period) {
                        if (is_numeric(data_get($i, "$side.$period.$field"))) {
                            data_set($i, "$side.$period.$field", data_get($c, 'total.default_team_points', 28));
                        }
                    }
                }
            }
            $without = $calculate($i, $c);
            foreach (['spread' => $actualMargin, 'total' => $actualTotal] as $market => $actual) {
                $delta = round($full[$market] - $without[$market], 3);
                $lift = $actual === null ? null : round(abs($without[$market] - $actual) - abs($full[$market] - $actual), 3);
                $line = $market === 'spread' ? $homeLine : $totalLine;
                $settle = static function (float $projection) use ($market, $line, $actual): ?string {
                    if ($line === null || $actual === null) {
                        return null;
                    }
                    $edge = $market === 'spread' ? $projection + $line : $projection - $line;
                    $result = $market === 'spread' ? $actual + $line : $actual - $line;

                    return abs($edge) < .00001 ? 'no_pick' : (abs($result) < .00001 ? 'push' : ($edge * $result > 0 ? 'win' : 'loss'));
                };
                $rows[] = ['signal' => $signal, 'market' => $market, 'full_projection' => $full[$market],
                    'without_signal' => $without[$market], 'contribution_points' => $delta,
                    'active' => abs($delta) >= .001, 'actual' => $actual,
                    'absolute_error_reduction' => $lift,
                    'grade' => $actual === null ? 'pending' : ($lift > 0 ? 'helped' : ($lift < 0 ? 'hurt' : 'neutral')),
                    'full_market_result' => $settle($full[$market]), 'without_market_result' => $settle($without[$market])];
            }
        }

        return ['version' => self::VERSION, 'prediction_id' => $prediction->id, 'snapshot_id' => $snapshot->id,
            'release' => $release->semanticVersion, 'replay_matched' => true,
            'signal_groups_evaluated' => count($signals), 'active_spread_signals' => count(array_filter($rows, fn ($r) => $r['market'] === 'spread' && $r['active'])),
            'active_total_signals' => count(array_filter($rows, fn ($r) => $r['market'] === 'total' && $r['active'])),
            'feature_roles' => [
                'elo' => data_get($run->diagnostics, 'feature_coverage.elo.role'),
                'eligibility_only' => data_get($run->diagnostics, 'feature_coverage.eligibility_only', []),
                'not_directly_applied' => data_get($run->diagnostics, 'feature_coverage.not_directly_applied', []),
                'personnel' => data_get($run->diagnostics, 'feature_coverage.personnel.role'),
            ], 'signals' => $rows];
    }
}
