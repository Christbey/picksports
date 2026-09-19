<?php

namespace App\Services\CFB\Predictions;

use App\Actions\CFB\CalculateElo;
use Carbon\CarbonImmutable;

/** Fixed-coefficient diagnostics on frozen pregame evidence; never fits on held-out outcomes. */
class CfbFrozenBaselineComparison
{
    public function evaluate(array $inputs, CarbonImmutable $capturedAt, CarbonImmutable $kickoff, float $actualMargin): array
    {
        if ($capturedAt->gte($kickoff)) {
            return ['excluded' => 'snapshot_not_pregame'];
        }
        $asOf = $capturedAt;
        $safeEvidence = static function (mixed $observed) use ($asOf): bool {
            if (! is_string($observed) || $observed === '') {
                return false;
            }
            try {
                return CarbonImmutable::parse($observed)->lte($asOf);
            } catch (\Throwable) {
                return false;
            }
        };
        $hfa = data_get($inputs, 'event.neutral_site', false) ? 0.0 : 2.2;
        $fpiAvailable = $eloAvailable = true;
        foreach (['home', 'away'] as $side) {
            $fpiAvailable = $fpiAvailable && is_numeric(data_get($inputs, $side.'.metrics.fpi'))
                && data_get($inputs, $side.'.rating_evidence.source') === 'cfbd_fpi'
                && data_get($inputs, $side.'.rating_evidence.units') === 'points_above_average'
                && (int) data_get($inputs, $side.'.rating_evidence.season') === (int) data_get($inputs, 'event.season')
                && $safeEvidence(data_get($inputs, $side.'.rating_evidence.observed_at'));
            $eloAvailable = $eloAvailable && is_numeric(data_get($inputs, $side.'.elo'))
                && data_get($inputs, $side.'.elo_evidence.qualified') === true
                && data_get($inputs, $side.'.elo_evidence.model_version') === CalculateElo::MODEL_VERSION
                && $safeEvidence(data_get($inputs, $side.'.elo_evidence.observed_at'));
        }
        $margins = [];
        if ($fpiAvailable) {
            $margins['fpi'] = $inputs['home']['metrics']['fpi'] - $inputs['away']['metrics']['fpi'] + $hfa;
        }
        if ($eloAvailable) {
            $margins['corrected_elo'] = ($inputs['home']['elo'] - $inputs['away']['elo']) / 25 + $hfa;
        }
        if ($eloAvailable && $fpiAvailable) {
            $margins['fixed_half_blend'] = ($margins['fpi'] + $margins['corrected_elo']) / 2;
        }

        return ['margins' => $margins, 'errors' => array_map(fn ($margin) => $margin - $actualMargin, $margins),
            'paired' => $eloAvailable && $fpiAvailable,
            'unavailable' => array_values(array_filter([$fpiAvailable ? null : 'fpi_missing_contemporaneous_provenance',
                $eloAvailable ? null : 'corrected_elo_missing_contemporaneous_provenance']))];
    }

    public function summarize(array $errors): array
    {
        return ['n' => count($errors), 'mae' => $errors ? round(array_sum(array_map('abs', $errors)) / count($errors), 4) : null,
            'bias' => $errors ? round(array_sum($errors) / count($errors), 4) : null];
    }
}
