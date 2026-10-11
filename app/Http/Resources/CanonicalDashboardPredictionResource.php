<?php

namespace App\Http\Resources;

use App\Http\Resources\Api\V2\CanonicalSportPredictionResource;
use App\Models\CanonicalPrediction;
use App\Services\Api\V2\CanonicalPredictionPresentationService;
use App\Services\Api\V2\SportContext;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CanonicalDashboardPredictionResource extends JsonResource
{
    public function __construct(CanonicalPrediction $resource, private readonly SportContext $context, private readonly array $config)
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        $prediction = $this->resource;
        $payload = (new CanonicalSportPredictionResource(
            $prediction,
            $this->context,
            app(CanonicalPredictionPresentationService::class)->forPrediction($prediction),
        ))->resolve();
        $game = $prediction->sportEvent->getRelation($this->context->slug.'Game');
        $isLive = in_array($game->status, $this->config['live_statuses'], true);
        $isFinal = in_array($game->status, $this->config['final_statuses'], true);

        return array_merge($payload, [
            'sport' => strtoupper($this->context->slug),
            'predicted_spread' => $payload['predicted_spread'] === null ? null : -$payload['predicted_spread'],
            'season_type' => $game->season_type,
            'game' => $game->name,
            'game_time' => $prediction->sportEvent->starts_at?->toIso8601String(),
            'home_team' => $game->homeTeam?->abbreviation,
            'away_team' => $game->awayTeam?->abbreviation,
            'home_logo' => $game->homeTeam?->logo_url,
            'away_logo' => $game->awayTeam?->logo_url,
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
}
