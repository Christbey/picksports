<?php

namespace App\Http\Resources\Api\V2;

use App\Models\CanonicalPrediction;
use App\Models\CFB\Game;
use App\Services\Api\V2\CanonicalPredictionPresentationData;
use App\Services\Api\V2\SportContext;
use App\Services\CFB\Live\LiveBoardPresentation;
use App\Services\MLB\MlbRecommendationPresentation;
use App\Services\Predictions\LivePredictionStore;
use App\Support\Sports\GameDateTimePresenter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CanonicalSportPredictionResource extends JsonResource
{
    public function __construct(mixed $resource, private readonly SportContext $context, private readonly CanonicalPredictionPresentationData|\Closure $presentation)
    {
        parent::__construct($resource);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $prediction = $this->resource;
        $run = $prediction->calculationRun;
        $snapshot = $run?->inputSnapshot;
        $render = fn (): array => $this->predictionPayload($request);
        $readSnapshot = fn (): array => $snapshot ? $snapshot->withCachedJsonArrays($render) : $render();

        return $prediction->withCachedJsonArrays(
            fn (): array => $run ? $run->withCachedJsonArrays($readSnapshot) : $readSnapshot(),
        );
    }

    private function predictionPayload(Request $request): array
    {
        /** @var CanonicalPrediction $prediction */
        $prediction = $this->resource;
        $presentation = $this->presentation instanceof \Closure ? ($this->presentation)() : $this->presentation;
        $game = $this->gameModel($prediction);
        $homeMoneyline = $prediction->marketFor('moneyline', 'home');
        $awayMoneyline = $prediction->marketFor('moneyline', 'away');
        $homeSpread = $prediction->marketFor('spread', 'home');
        $total = $prediction->marketFor('total', 'combined');
        $homeProbability = $this->number($homeMoneyline?->probability);
        $awayProbability = $this->number($awayMoneyline?->probability);
        $confidence = $prediction->sport === 'cfb' ? null : $this->number($homeMoneyline?->confidence_score);
        $evaluation = $prediction->latestEvaluation;
        $confidenceContext = $presentation->confidenceContext;
        $valueSignal = $presentation->valueSignal;
        $live = app(LivePredictionStore::class)->forPrediction($prediction, $this->context->slug);
        if (! in_array($game?->status, ['STATUS_IN_PROGRESS', 'STATUS_HALFTIME', 'STATUS_END_PERIOD', 'STATUS_SUSPENDED', 'STATUS_DELAYED'], true)) {
            $live = LivePredictionStore::EMPTY_FIELDS;
        }

        $cfbInputs = $prediction->sport === 'cfb'
            ? $prediction->calculationRun?->inputSnapshot?->inlineInputs()
            : null;

        return [
            'id' => $prediction->public_id,
            'sport' => $prediction->sport,
            'game_id' => $game?->getKey(),
            'sport_event_id' => $prediction->sportEvent?->public_id,
            'revision' => $prediction->revision,
            'phase' => $prediction->phase,
            'publication_state' => $prediction->publication_state,
            'home_team_id' => $game?->home_team_id,
            'away_team_id' => $game?->away_team_id,
            'game' => $this->game($game),
            'status' => $game?->status,
            'pick' => $this->pick($game, $homeProbability),
            'projection' => [
                'home_win_probability' => $homeProbability,
                'away_win_probability' => $awayProbability,
                'predicted_spread' => $this->number($homeSpread?->projected_line),
                'predicted_total' => $this->number($total?->projected_line),
                'confidence_score' => $confidence,
                'confidence_context' => $confidenceContext,
            ],
            'home_win_probability' => $homeProbability,
            'away_win_probability' => $awayProbability,
            'win_probability' => $homeProbability,
            'predicted_spread' => $this->number($homeSpread?->projected_line),
            'predicted_total' => $this->number($total?->projected_line),
            'confidence_score' => $confidence,
            'confidence_level' => $confidenceContext['tier'],
            'confidence_context' => $confidenceContext,
            'evidence_windows' => $prediction->sport === 'cfb' ? [
                'home' => data_get($cfbInputs, 'home.evidence_windows'),
                'away' => data_get($cfbInputs, 'away.evidence_windows'),
            ] : null,
            'public_recommendation' => app(MlbRecommendationPresentation::class)->summary($presentation->recommendation),
            'model_bet_context' => [
                'model_home_spread' => $this->number($homeSpread?->projected_line),
                ...$presentation->modelBetContext,
            ],
            'weather_forecast' => $presentation->weatherForecast,
            'value_signal' => $valueSignal,
            ...($prediction->sport === 'cfb' ? [
                'comparison_forecasts' => array_intersect_key((array) data_get($prediction->output_metadata, 'comparison_forecasts', []),
                    array_flip(['version', 'status', 'validated', 'betting_approved', 'models', 'limitations'])),
                'market_evaluation' => data_get($evaluation?->market_comparison, 'cfb_comparison'),
                'forecast_coordination' => data_get($prediction->output_metadata, 'forecast_coordination'),
                'quarterback_adjustment' => data_get($prediction->output_metadata, 'quarterback_adjustment'),
                'raw_home_win_probability' => $homeProbability,
            ] : []),
            'market_aware_projection' => $presentation->marketAwareProjection,
            'recommendation' => $presentation->recommendation,
            'pro_signal_layer' => null,
            'nfl_board' => $presentation->nflBoard === null ? null : [
                ...$presentation->nflBoard,
                'can_retry_research' => (bool) $request->user()?->isAdmin()
                    && in_array($game?->status, ['STATUS_SCHEDULED', 'STATUS_DELAYED'], true)
                    && $prediction->sportEvent?->starts_at?->isFuture(),
            ],
            'period_insights' => [],
            'cfb_signal_context' => null,
            ...($prediction->sport === 'cfb' ? ['scoring_model' => $this->cfbScoringModel($prediction)] : []),
            'home_elo' => null,
            'away_elo' => null,
            'home_team_elo' => null,
            'away_team_elo' => null,
            'home_pitcher_elo' => null,
            'away_pitcher_elo' => null,
            'home_combined_elo' => null,
            'away_combined_elo' => null,
            'actual_spread' => $this->number(data_get($evaluation?->actuals, 'home_margin')),
            'actual_total' => $this->number(data_get($evaluation?->actuals, 'total_points')),
            'spread_error' => $this->number(data_get($evaluation?->errors, 'spread_absolute_error')),
            'total_error' => $this->number(data_get($evaluation?->errors, 'total_absolute_error')),
            'winner_correct' => data_get($evaluation?->errors, 'winner_correct'),
            ...($prediction->sport === 'cbb' ? [
                'pregame_market' => data_get($prediction->calculationRun?->inputSnapshot?->inputs, 'pregame_market'),
                'market_evaluation' => ['status' => 'tracking_only', ...(array) data_get($evaluation?->market_comparison, 'pregame_market', [])],
                'ats_pick_side' => data_get($evaluation?->market_comparison, 'pregame_market.spread.selection'),
                'ats_pick_line' => data_get($evaluation?->market_comparison, 'pregame_market.spread.selection_line'),
                'ats_pick_result' => data_get($evaluation?->market_comparison, 'pregame_market.spread.result'),
            ] : []),
            'total_pick_side' => $prediction->sport === 'cbb' ? data_get($evaluation?->market_comparison, 'pregame_market.total.selection') : null,
            'total_pick_line' => $prediction->sport === 'cbb' ? data_get($evaluation?->market_comparison, 'pregame_market.total.market_line') : null,
            'total_pick_result' => $prediction->sport === 'cbb' ? data_get($evaluation?->market_comparison, 'pregame_market.total.result') : null,
            'total_pick_edge' => $prediction->sport === 'cbb' ? data_get($evaluation?->market_comparison, 'pregame_market.total.edge_points') : null,
            'total_result' => $evaluation === null ? null : [
                'actual' => $this->number(data_get($evaluation->actuals, 'total_points')),
                'predicted' => $this->number(data_get($evaluation->errors, 'predicted_total')),
                'absolute_error' => $this->number(data_get($evaluation->errors, 'total_absolute_error')),
            ],
            'graded_at' => $evaluation?->evaluated_at?->toIso8601String(),
            'live_predicted_spread' => $live['live_predicted_spread'],
            'live_predicted_total' => $live['live_predicted_total'],
            'live_win_probability' => $live['live_win_probability'],
            'live_seconds_remaining' => $live['live_seconds_remaining'],
            'live_outs_remaining' => $live['live_outs_remaining'],
            'live_updated_at' => $live['live_updated_at']?->toIso8601String(),
            ...($game instanceof Game
                ? app(LiveBoardPresentation::class)->forGame($game) : []),
            'depth_chart_context' => null,
            'market_summary' => [
                'has_odds' => $valueSignal !== null,
                'markets' => $prediction->markets->pluck('market_type')->unique()->values()->all(),
                'odds_updated_at' => $game?->odds_updated_at?->toIso8601String(),
            ],
            'audit_context' => $this->auditContext($prediction),
            'generated_at' => $prediction->generated_at?->toIso8601String(),
            'published_at' => $prediction->published_at?->toIso8601String(),
            'created_at' => $prediction->created_at?->toIso8601String(),
            'updated_at' => $prediction->updated_at?->toIso8601String(),
        ];
    }

    private function cfbScoringModel(CanonicalPrediction $prediction): ?array
    {
        $model = data_get($prediction->output_metadata, 'scoring_challenger');
        if (! is_array($model) || ($model['state'] ?? '') === 'disabled') {
            return null;
        }
        $summary = $model['summary'] ?? [];

        return ['state' => $model['state'] ?? 'unavailable', 'promoted' => (bool) ($model['promoted'] ?? false),
            'home_points' => $this->number($summary['home_points'] ?? null),
            'away_points' => $this->number($summary['away_points'] ?? null),
            'total' => $this->number($summary['total'] ?? null), 'home_margin' => $this->number($summary['home_margin'] ?? null),
            'probability_status' => $summary['status'] ?? null,
            'fpi_weight' => $this->number($model['fpi_weight'] ?? null),
            'generated_at' => $prediction->generated_at?->toIso8601String(),
            'artifact_id' => $model['artifact_id'] ?? null,
            'recommendation_status' => 'requires_market_validation'];
    }

    /** @return array<string, mixed>|null */
    private function game(?Model $game): ?array
    {
        if ($game === null) {
            return null;
        }

        $dateTime = GameDateTimePresenter::forSport($this->context->slug, $game->game_date, $game->game_time);

        return [
            'id' => $game->getKey(),
            'espn_id' => $game->espn_id ?? $game->espn_event_id,
            'season' => $game->season,
            'season_type' => $game->season_type,
            'week' => $game->week,
            'name' => $game->name,
            'short_name' => $game->short_name,
            'game_date' => $dateTime['game_date'],
            'game_time' => $dateTime['game_time'],
            'status' => $game->status,
            'home_score' => $game->home_score,
            'away_score' => $game->away_score,
            'period' => $game->period,
            'clock' => $game->game_clock ?? $game->clock,
            'home_linescores' => null,
            'away_linescores' => null,
            'inning' => null,
            'inning_half' => null,
            'balls' => null,
            'strikes' => null,
            'outs' => null,
            'home_team_id' => $game->home_team_id,
            'away_team_id' => $game->away_team_id,
            'home_team' => $this->team($game->homeTeam),
            'away_team' => $this->team($game->awayTeam),
        ];
    }

    /** @return array<string, mixed> */
    private function pick(?Model $game, ?float $homeProbability): array
    {
        $side = $homeProbability === null ? null : ($homeProbability >= 0.5 ? 'home' : 'away');
        $team = $side === null ? null : $game?->getRelation("{$side}Team");

        return [
            'side' => $side,
            'team_id' => $team?->getKey(),
            'team_abbreviation' => $team?->abbreviation,
            'label' => $team?->abbreviation ?? $team?->display_name,
        ];
    }

    /** @return array<string, mixed>|null */
    private function team(?Model $team): ?array
    {
        if ($team === null) {
            return null;
        }

        return [
            'id' => $team->getKey(),
            'abbreviation' => $team->abbreviation,
            'display_name' => $team->display_name ?: trim(implode(' ', array_filter([
                $team->location ?: $team->school,
                $team->name ?: $team->mascot,
            ]))),
            'logo_url' => $team->logo_url,
        ];
    }

    /** @return array<string, mixed> */
    private function auditContext(CanonicalPrediction $prediction): array
    {
        $run = $prediction->calculationRun;

        return [
            'prediction_id' => $prediction->public_id,
            'prediction_revision' => $prediction->revision,
            'output_hash' => $prediction->output_hash,
            'calculation_run_id' => $run?->getKey(),
            'calculation_release_id' => $run?->release?->public_id,
            'calculation_release_version' => $run?->release?->semantic_version,
            'input_snapshot_id' => $run?->inputSnapshot?->public_id,
            'input_snapshot_hash' => $run?->inputSnapshot?->content_hash,
            'evaluation_id' => $prediction->latestEvaluation?->getKey(),
            'evaluation_revision' => $prediction->latestEvaluation?->evaluation_revision,
            'scoring_version' => $prediction->latestEvaluation?->scoring_version,
        ];
    }

    private function number(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private function gameModel(CanonicalPrediction $prediction): ?Model
    {
        $event = $prediction->sportEvent;
        $relation = $this->context->slug.'Game';

        if ($event === null || ! $event->relationLoaded($relation)) {
            return null;
        }

        $game = $event->getRelation($relation);

        return $game instanceof Model ? $game : null;
    }
}
