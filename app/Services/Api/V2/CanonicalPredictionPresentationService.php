<?php

namespace App\Services\Api\V2;

use App\Models\CanonicalPrediction;
use App\Models\CFB\Game as CfbGame;
use App\Services\CFB\Predictions\CfbCanonicalSpreadValueSignalService;
use App\Services\CFB\Predictions\CfbPredictionInputQuality;

class CanonicalPredictionPresentationService
{
    public function __construct(private readonly CfbCanonicalSpreadValueSignalService $cfbValueSignals) {}

    public function forPrediction(CanonicalPrediction $prediction): CanonicalPredictionPresentationData
    {
        $market = $prediction->markets->first(fn ($market): bool => $market->market_type === 'moneyline' && $market->selection === 'home');
        $confidence = is_numeric($market?->confidence_score) ? (float) $market->confidence_score : null;
        $game = $prediction->sport === 'cfb' ? $prediction->sportEvent?->cfbGame : null;

        return new CanonicalPredictionPresentationData(
            confidenceContext: $this->confidenceContext($prediction, $confidence),
            valueSignal: $game instanceof CfbGame ? $this->cfbValueSignals->forPrediction($prediction, $game) : null,
        );
    }

    /** @return array<string, mixed> */
    private function confidenceContext(CanonicalPrediction $prediction, ?float $confidence): array
    {
        $level = $this->confidenceLevel($confidence);
        if ($prediction->sport === 'cfb') {
            $quality = CfbPredictionInputQuality::assess((array) ($prediction->calculationRun?->inputSnapshot?->inputs ?? []));
            if (! $quality['qualified']) {
                return [
                    'label' => 'Incomplete inputs', 'tier' => 'unavailable', 'model_level' => $level,
                    'reason_codes' => $quality['risk_flags'], 'sample_games' => min($quality['sample_games']),
                    'team_sample_games' => $quality['sample_games'],
                    'probability_status' => $quality['probability_status'],
                ];
            }
        }

        return [
            'label' => ucfirst($level),
            'tier' => $level,
            'model_level' => $level,
            'reason_codes' => array_values((array) data_get($prediction->output_metadata, 'reason_codes', [])),
            'sample_games' => isset($quality) ? min($quality['sample_games']) : null,
            ...($prediction->sport === 'cfb' ? ['probability_status' => 'uncalibrated_model_estimate'] : []),
        ];
    }

    private function confidenceLevel(?float $confidence): string
    {
        return match (true) {
            $confidence === null => 'unavailable',
            $confidence >= 75 => 'high',
            $confidence >= 60 => 'medium',
            default => 'low',
        };
    }
}
