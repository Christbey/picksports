<?php

namespace App\Services\Predictions;

use App\Application\Sports\ReadModels\PredictionReviewInput;
use App\Models\CanonicalPrediction;
use App\Models\MLB\Game;
use App\Services\Api\V2\SportContextResolver;
use App\Services\BettingRecommendations\GameBettingRecommendationService;
use App\Services\NFL\Predictions\NflRecommendationInputBuilder;
use App\Services\Sports\SportsDateWindowService;
use Illuminate\Database\Eloquent\Model;

/** Foundation for the new review path; existing daily review callers are unchanged. */
class PredictionReviewInputBuilder
{
    public function __construct(
        private readonly PredictionDeliverySource $delivery,
        private readonly SportContextResolver $sports,
        private readonly GameBettingRecommendationService $recommendations,
        private readonly SportsAiPredictionPayloadBuilder $legacyPayloads,
        private readonly SportsDateWindowService $dates,
    ) {}

    public function forGame(Model $game, string $sport): ?PredictionReviewInput
    {
        $sport = strtolower(trim($sport));
        $context = $this->sports->find($sport);
        if ($context === null || ! $game instanceof $context->models['game']) {
            throw new \InvalidArgumentException('Review sport and game identity must match.');
        }

        $selected = $this->delivery->forGame($game, $sport);
        if ($selected === null) {
            return null;
        }

        $source = $selected->source;
        $canonical = $source instanceof CanonicalPrediction;
        $startsAt = $game instanceof Game ? $this->dates->gameStart($game) : ($canonical ? $source->sportEvent?->starts_at
            : $this->dates->gameStart($game));
        $snapshot = $canonical ? $source->calculationRun?->inputSnapshot : null;
        $recommendationContext = $sport === 'nfl' && $snapshot !== null
            ? data_get($snapshot->inputs, 'recommendation_context') : null;
        // Retain frozen recommendation evidence separately from the primary forecast.
        // Unsupported versions cannot silently become current recommendation inputs.
        $recommendationContext = is_array($recommendationContext)
            && ($recommendationContext['version'] ?? null) === NflRecommendationInputBuilder::VERSION
                ? $recommendationContext : null;

        $contract = [
            'schema_version' => 'prediction_review_decision_v1',
            'authority' => 'existing_public_bet_decisions',
            'classification' => 'pass',
            'recommendation' => 'pass',
            'selection' => null,
            'eligible_markets' => [],
            'reason_codes' => [],
            'requirements' => ['forecast_identity', 'existing_deterministic_eligibility', 'stored_priced_quote'],
        ];

        if ($canonical) {
            // Reuse the exact-revision public approval reader, including its quote,
            // tracking-only, withdrawal, phase and freshness restrictions.
            $markets = $this->recommendations->forPrediction($selected);
            $contract['eligible_markets'] = $markets;
            if ($markets !== []) {
                $contract['classification'] = 'bet';
                $contract['recommendation'] = $markets[0]['type'];
                $contract['selection'] = $markets[0];
            } else {
                $contract['reason_codes'][] = 'no_existing_public_approval';
            }
        } elseif ($sport === 'nfl') {
            // Preserve the existing NFL calculation, thresholds and context handling.
            // This contract is a compatibility ceiling, not a new public approval.
            $contract = $this->legacyPayloads->build($sport, $source)['decision_contract'];
        } else {
            // Other legacy policies must receive explicit adapters; AI output alone
            // must not manufacture eligibility in this new, unactivated path.
            $contract['authority'] = 'unsupported_legacy_decision_policy';
            $contract['reason_codes'][] = 'legacy_decision_contract_not_supported';
        }

        return new PredictionReviewInput(
            identity: [
                'sport' => $sport,
                'source' => $canonical ? 'canonical' : 'legacy',
                'prediction_table' => $source->getTable(),
                'prediction_id' => $source->getKey(),
                'public_id' => $canonical ? $source->public_id : null,
                'revision' => $canonical ? $source->revision : null,
                'phase' => $canonical ? $source->phase : 'pregame',
                'sport_event_id' => $game->sport_event_id,
                'game_table' => $game->getTable(),
                'game_id' => $game->getKey(),
                'season' => $game->season,
                'season_type' => $game->season_type,
                'starts_at' => $startsAt?->copy()->utc()->toIso8601String(),
                'business_date' => $startsAt?->copy()->setTimezone($this->dates->timezone())->toDateString(),
                'calculation_run_id' => $canonical ? $source->calculation_run_id : null,
                'input_snapshot_id' => $snapshot?->getKey(),
                'input_hash' => $snapshot?->content_hash,
            ],
            projection: [
                'predicted_spread' => $selected->predictedSpread,
                'spread_convention' => $canonical ? 'positive_home_margin' : 'legacy_stored',
                'source_home_spread' => $canonical
                    ? $this->number($source->markets->first(fn ($market) => $market->market_type === 'spread' && $market->selection === 'home')?->projected_line)
                    : null,
                'predicted_total' => $selected->predictedTotal,
                'home_win_probability' => $selected->homeWinProbability,
                'confidence_score' => $this->number($selected->confidenceScore),
                'model_version' => $source->model_version,
                'feature_version' => $source->feature_version,
                'blend_version' => $source->blend_version,
            ],
            recommendationContext: $recommendationContext,
            decisionContract: $contract,
        );
    }

    private function number(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}
