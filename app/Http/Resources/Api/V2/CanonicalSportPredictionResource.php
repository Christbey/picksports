<?php

namespace App\Http\Resources\Api\V2;

use App\Models\CanonicalPrediction;
use App\Models\PredictionMarket;
use App\Services\Api\V2\SportContext;
use App\Support\Sports\GameDateTimePresenter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CanonicalSportPredictionResource extends JsonResource
{
    public function __construct(mixed $resource, private readonly SportContext $context)
    {
        parent::__construct($resource);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var CanonicalPrediction $prediction */
        $prediction = $this->resource;
        $game = $this->gameModel($prediction);
        $homeMoneyline = $this->market($prediction, 'moneyline', 'home');
        $awayMoneyline = $this->market($prediction, 'moneyline', 'away');
        $homeSpread = $this->market($prediction, 'spread', 'home');
        $total = $this->market($prediction, 'total', 'combined');
        $homeProbability = $this->number($homeMoneyline?->probability);
        $awayProbability = $this->number($awayMoneyline?->probability);
        $confidence = $this->number($homeMoneyline?->confidence_score);
        $evaluation = $prediction->latestEvaluation;
        $confidenceContext = $this->confidenceContext($prediction, $confidence);

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
            'confidence_level' => $this->confidenceLevel($confidence),
            'confidence_context' => $confidenceContext,
            'public_recommendation' => null,
            'value_signal' => null,
            'market_aware_projection' => null,
            'recommendation' => null,
            'pro_signal_layer' => null,
            'period_insights' => [],
            'cfb_signal_context' => null,
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
            'total_pick_side' => null,
            'total_pick_line' => null,
            'total_pick_result' => null,
            'total_pick_edge' => null,
            'total_result' => $evaluation === null ? null : [
                'actual' => $this->number(data_get($evaluation->actuals, 'total_points')),
                'predicted' => $this->number(data_get($evaluation->errors, 'predicted_total')),
                'absolute_error' => $this->number(data_get($evaluation->errors, 'total_absolute_error')),
            ],
            'graded_at' => $evaluation?->evaluated_at?->toIso8601String(),
            'live_predicted_spread' => null,
            'live_predicted_total' => null,
            'live_win_probability' => null,
            'live_seconds_remaining' => null,
            'live_outs_remaining' => null,
            'live_updated_at' => null,
            'depth_chart_context' => null,
            'market_summary' => [
                'has_odds' => false,
                'markets' => $prediction->markets->pluck('market_type')->unique()->values()->all(),
                'odds_updated_at' => null,
            ],
            'audit_context' => $this->auditContext($prediction),
            'generated_at' => $prediction->generated_at?->toIso8601String(),
            'published_at' => $prediction->published_at?->toIso8601String(),
            'created_at' => $prediction->created_at?->toIso8601String(),
            'updated_at' => $prediction->updated_at?->toIso8601String(),
        ];
    }

    private function market(CanonicalPrediction $prediction, string $type, string $selection): ?PredictionMarket
    {
        return $prediction->markets->first(
            fn (PredictionMarket $market): bool => $market->market_type === $type
                && $market->selection === $selection,
        );
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

    /** @return array{label:string,tier:string,model_level:string,reason_codes:array<int,string>,sample_games:null} */
    private function confidenceContext(CanonicalPrediction $prediction, ?float $confidence): array
    {
        $level = $this->confidenceLevel($confidence);

        return [
            'label' => ucfirst($level),
            'tier' => $level,
            'model_level' => $level,
            'reason_codes' => array_values((array) data_get($prediction->output_metadata, 'reason_codes', [])),
            'sample_games' => null,
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
