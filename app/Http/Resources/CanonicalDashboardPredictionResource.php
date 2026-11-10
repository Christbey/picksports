<?php

namespace App\Http\Resources;

use App\Http\Resources\Api\V2\CanonicalSportPredictionResource;
use App\Models\CanonicalPrediction;
use App\Models\CFB\Game as CfbGame;
use App\Services\Api\V2\CanonicalPredictionPresentationService;
use App\Services\Api\V2\PredictionWeatherContext;
use App\Services\Api\V2\SportContext;
use App\Services\CFB\Live\LiveBoardPresentation;
use App\Services\MLB\MlbRecommendationPresentation;
use App\Services\Predictions\BettingForecastSource;
use App\Services\Predictions\LivePredictionStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CanonicalDashboardPredictionResource extends JsonResource
{
    public function __construct(CanonicalPrediction $resource, private readonly SportContext $context, private readonly array $config, private readonly bool $compact = false)
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        $prediction = $this->resource;
        $run = (! $this->compact || $this->context->slug === 'mlb') ? $prediction->calculationRun : null;
        $snapshot = $run?->inputSnapshot;
        $render = fn (): array => $this->dashboardPayload($prediction);
        $readSnapshot = fn (): array => $snapshot ? $snapshot->withCachedJsonArrays($render) : $render();

        return $prediction->withCachedJsonArrays(
            fn (): array => $run ? $run->withCachedJsonArrays($readSnapshot) : $readSnapshot(),
        );
    }

    private function dashboardPayload(CanonicalPrediction $prediction): array
    {
        $sport = $this->context->slug;
        $game = $prediction->sportEvent->getRelation($sport.'Game');
        $isLive = in_array($game->status, $this->config['live_statuses'], true);
        $isFinal = in_array($game->status, $this->config['final_statuses'], true);
        $payload = $this->compact
            ? $this->compactPayload($prediction, $game)
            : (new CanonicalSportPredictionResource(
                $prediction,
                $this->context,
                app(CanonicalPredictionPresentationService::class)->forPrediction($prediction),
            ))->resolve();

        return array_merge($payload, [
            'sport' => strtoupper($sport),
            'predicted_spread' => $payload['predicted_spread'] === null ? null : -$payload['predicted_spread'],
            'season_type' => $game->season_type,
            'game' => $game->name,
            'game_time' => $prediction->sportEvent->starts_at?->toIso8601String(),
            'home_team' => $game->homeTeam?->abbreviation,
            'away_team' => $game->awayTeam?->abbreviation,
            'home_logo' => $game->homeTeam?->logo_url,
            'away_logo' => $game->awayTeam?->logo_url,
            'home_color' => $game->homeTeam?->color,
            'away_color' => $game->awayTeam?->color,
            'is_live' => $isLive,
            'is_final' => $isFinal,
            'home_score' => ($isLive || $isFinal) ? $game->home_score : null,
            'away_score' => ($isLive || $isFinal) ? $game->away_score : null,
            'period' => $isLive ? $game->period : null,
            'game_clock' => $isLive ? ($game->game_clock ?? $game->clock) : null,
            'inning' => $isLive ? $game->inning : null,
            'inning_state' => $isLive ? $game->inning_state : null,
        ]);
    }

    private function compactPayload(CanonicalPrediction $prediction, Model $game): array
    {
        $sport = $this->context->slug;
        $spread = $this->number($prediction->marketFor('spread', 'home')?->projected_line);
        $evaluation = $prediction->latestEvaluation;
        $live = in_array($game->status, ['STATUS_IN_PROGRESS', 'STATUS_HALFTIME', 'STATUS_END_PERIOD', 'STATUS_SUSPENDED', 'STATUS_DELAYED'], true)
            ? app(LivePredictionStore::class)->forPrediction($prediction, $sport)
            : LivePredictionStore::EMPTY_FIELDS;

        if ($sport === 'mlb') {
            $forecast = app(BettingForecastSource::class)->fromModel($prediction, $sport);
            $recommendation = app(CanonicalPredictionPresentationService::class)->mlbRecommendation($forecast);
        }

        return [
            'id' => $prediction->public_id,
            'game_id' => $game->getKey(),
            'status' => $game->status,
            'win_probability' => $this->number($prediction->marketFor('moneyline', 'home')?->probability),
            'predicted_spread' => $spread,
            'predicted_total' => $this->number($prediction->marketFor('total', 'combined')?->projected_line),
            'actual_total' => $this->number(data_get($evaluation?->actuals, 'total_points')),
            'winner_correct' => data_get($evaluation?->errors, 'winner_correct'),
            'updated_at' => $prediction->updated_at?->toIso8601String(),
            'recommendation' => $recommendation ?? null,
            'public_recommendation' => app(MlbRecommendationPresentation::class)->summary($recommendation ?? null),
            'weather_forecast' => $game instanceof CfbGame ? app(PredictionWeatherContext::class)->forPrediction($prediction, $game) : null,
            ...$live,
            'live_updated_at' => $live['live_updated_at']?->toIso8601String(),
            ...($game instanceof CfbGame ? app(LiveBoardPresentation::class)->forGame($game) : []),
        ];
    }

    private function number(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}
