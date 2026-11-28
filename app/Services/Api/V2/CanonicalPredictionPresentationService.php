<?php

namespace App\Services\Api\V2;

use App\Application\Sports\ReadModels\BettingForecast;
use App\Models\CanonicalPrediction;
use App\Models\CFB\Game as CfbGame;
use App\Models\NFL\Game as NflGame;
use App\Models\WCBB\Game as WcbbGame;
use App\Services\BettingRecommendations\GameBettingRecommendationService;
use App\Services\CFB\Live\LiveBoardPresentation;
use App\Services\CFB\Predictions\CfbCanonicalSpreadValueSignalService;
use App\Services\CFB\Predictions\CfbPredictionInputQuality;
use App\Services\MLB\MlbMarketAwareProjectionService;
use App\Services\MLB\MlbPredictionRecommendationService;
use App\Services\MLB\MlbRecommendationPresentation;
use App\Services\Predictions\BettingForecastSource;
use App\Services\Predictions\LivePredictionStore;
use App\Services\Predictions\PredictionDeliverySource;
use App\Services\WCBB\Predictions\WcbbSpreadTracking;

class CanonicalPredictionPresentationService
{
    public function __construct(private readonly CfbCanonicalSpreadValueSignalService $cfbValueSignals) {}

    public function forPrediction(CanonicalPrediction $prediction, ?array $nflBoard = null): CanonicalPredictionPresentationData
    {
        $run = $prediction->calculationRun;
        $snapshot = $run?->inputSnapshot;
        $prepare = fn (): CanonicalPredictionPresentationData => $this->preparePrediction($prediction, $nflBoard);
        $readSnapshot = fn (): CanonicalPredictionPresentationData => $snapshot ? $snapshot->withCachedJsonArrays($prepare) : $prepare();

        return $prediction->withCachedJsonArrays(
            fn (): CanonicalPredictionPresentationData => $run ? $run->withCachedJsonArrays($readSnapshot) : $readSnapshot(),
        );
    }

    private function preparePrediction(CanonicalPrediction $prediction, ?array $nflBoard): CanonicalPredictionPresentationData
    {
        $market = $prediction->marketFor('moneyline', 'home');
        $confidence = is_numeric($market?->confidence_score) ? (float) $market->confidence_score : null;
        $game = $prediction->sportEvent?->getRelation($prediction->sport.'Game');

        $mlbForecast = $prediction->sport === 'mlb'
            ? app(BettingForecastSource::class)->fromModel($prediction, 'mlb') : null;

        if ($nflBoard !== null) {
            $context = app(PredictionDeliverySource::class)->context($prediction, 'nfl');
            if (app(GameBettingRecommendationService::class)->forPrediction($context) === []) {
                $nflBoard['research']['decision'] = 'hold';
                $nflBoard['research']['reasons'] = array_values(array_unique([...(array) ($nflBoard['research']['reasons'] ?? []), 'canonical_approval_required']));
            }
        }

        return new CanonicalPredictionPresentationData(
            nflBoard: $nflBoard,
            evidenceWindows: $prediction->sport === 'cfb' ? [
                'home' => data_get($prediction->calculationRun?->inputSnapshot?->inlineInputs(), 'home.evidence_windows'),
                'away' => data_get($prediction->calculationRun?->inputSnapshot?->inlineInputs(), 'away.evidence_windows'),
            ] : null,
            pregameMarket: $prediction->sport === 'cbb'
                ? data_get($prediction->calculationRun?->inputSnapshot?->inputs, 'pregame_market') : null,
            liveFields: app(LivePredictionStore::class)->forPrediction($prediction, $prediction->sport),
            liveBoard: $game instanceof CfbGame ? app(LiveBoardPresentation::class)->forGame($game) : [],
            recommendation: $prediction->sport === 'mlb'
                ? $this->mlbRecommendation($mlbForecast) : null,
            marketAwareProjection: $prediction->sport === 'mlb'
                ? app(MlbMarketAwareProjectionService::class)->forPrediction($mlbForecast) : null,
            confidenceContext: $this->confidenceContext($prediction, $confidence),
            valueSignal: match (true) {
                $game instanceof CfbGame => $this->cfbValueSignals->forPrediction($prediction, $game),
                $game instanceof WcbbGame => app(WcbbSpreadTracking::class)->signal($prediction, $game),
                default => null,
            },
            modelBetContext: app(PredictionModelBetContext::class)->forPrediction($prediction, $game),
            weatherForecast: $game instanceof CfbGame || $game instanceof NflGame ? app(PredictionWeatherContext::class)->forPrediction($prediction, $game) : null,
        );
    }

    public function forDashboardPrediction(CanonicalPrediction $prediction, bool $compact = false): CanonicalPredictionPresentationData
    {
        if (! $compact) {
            return $this->forPrediction($prediction);
        }

        $game = $prediction->sportEvent?->getRelation($prediction->sport.'Game');
        $prepare = fn (): CanonicalPredictionPresentationData => new CanonicalPredictionPresentationData(
            confidenceContext: [],
            valueSignal: null,
            recommendation: $prediction->sport === 'mlb'
                ? $this->mlbRecommendation(app(BettingForecastSource::class)->fromModel($prediction, 'mlb')) : null,
            weatherForecast: $game instanceof CfbGame || $game instanceof NflGame ? app(PredictionWeatherContext::class)->forPrediction($prediction, $game) : null,
            liveFields: app(LivePredictionStore::class)->forPrediction($prediction, $prediction->sport),
            liveBoard: $game instanceof CfbGame ? app(LiveBoardPresentation::class)->forGame($game) : [],
        );
        $run = $prediction->sport === 'mlb' ? $prediction->calculationRun : null;
        $snapshot = $run?->inputSnapshot;
        $readSnapshot = fn (): CanonicalPredictionPresentationData => $snapshot ? $snapshot->withCachedJsonArrays($prepare) : $prepare();

        return $prediction->withCachedJsonArrays(
            fn (): CanonicalPredictionPresentationData => $run ? $run->withCachedJsonArrays($readSnapshot) : $readSnapshot(),
        );
    }

    /** @return array<string, mixed> */
    public function mlbRecommendation(BettingForecast $forecast): array
    {
        return app(MlbRecommendationPresentation::class)->sanitize(app(MlbPredictionRecommendationService::class)->forPrediction($forecast));
    }

    /** @return array<string, mixed> */
    private function confidenceContext(CanonicalPrediction $prediction, ?float $confidence): array
    {
        $level = $this->confidenceLevel($confidence);
        if ($prediction->sport === 'cfb') {
            $quality = CfbPredictionInputQuality::assess((array) ($prediction->calculationRun?->inputSnapshot?->inputs ?? []));
            if ($quality['qualified']) {
                return ['label' => 'Uncalibrated win estimate', 'tier' => 'unavailable', 'model_level' => 'unavailable',
                    'reason_codes' => array_values((array) data_get($prediction->output_metadata, 'reason_codes', [])),
                    'sample_games' => min($quality['sample_games']), 'team_sample_games' => $quality['sample_games'],
                    'probability_status' => 'uncalibrated_model_estimate', 'calibrated' => false];
            }
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
