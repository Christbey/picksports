<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BettingRecommendationResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $sportPrefix = $this->resolveSportPrefix();
        $playerRoute = "{$sportPrefix}.player.show";
        $playerUrl = app('router')->has($playerRoute)
            ? route($playerRoute, $this->resource['player']->id)
            : null;
        $playerName = $this->resource['player']->full_name
            ?? $this->resource['player']->display_name
            ?? $this->resource['player']->name;

        return [
            'id' => $this->resource['prop']->id,
            'player' => [
                'id' => $this->resource['player']->id,
                'name' => $playerName,
                'position' => $this->resource['player']->position,
                'team' => $this->resource['player']->team?->abbreviation ?? $this->resource['player']->team?->name,
                'headshot' => $this->resource['player']->headshot_url ?? $this->resource['player']->headshot ?? null,
                'url' => $playerUrl,
            ],
            'market' => $this->resource['market'],
            'line' => (float) $this->resource['line'],
            'recommendation' => $this->resource['recommendation'],
            'odds' => $this->resource['odds'],
            'confidence' => $this->resource['confidence'],
            'stats' => [
                'season_avg' => $this->resource['season_avg'],
                'recent_avg' => $this->resource['recent_avg'],
                'last5_avg' => $this->resource['last5_avg'],
                'vs_opponent_avg' => $this->resource['vs_opponent_avg'] ?? null,
                'home_away_avg' => $this->resource['home_away_avg'] ?? null,
                'hit_rate_vs_opponent' => $this->resource['hit_rate_vs_opponent'] ?? null,
                'times_covered_last5' => $this->resource['times_covered_last5'] ?? null,
                'times_covered_season' => $this->resource['times_covered_season'] ?? null,
                'cover_record' => $this->resource['cover_record'] ?? null,
                'consistency' => $this->resource['consistency'] ?? null,
            ],
            'streak' => $this->resource['streak'] ?? null,
            'edge' => $this->resource['edge'],
            'model_over_probability' => $this->resource['model_over_probability'] ?? null,
            'market_over_probability' => $this->resource['market_over_probability'] ?? null,
            'edge_probability' => $this->resource['edge_probability'] ?? null,
            'context' => $this->resource['context'] ?? null,
            'data_quality_score' => $this->resource['data_quality_score'] ?? null,
            'match_quality_score' => $this->resource['match_quality_score'] ?? null,
            'confidence_decomposition' => $this->resource['confidence_decomposition'] ?? null,
            'reasoning' => $this->resource['reasoning'],
            'narrative' => $this->resource['narrative'] ?? null,
            'actual_value' => $this->resource['prop']->actual_value !== null ? (float) $this->resource['prop']->actual_value : null,
            'hit_over' => $this->resource['prop']->hit_over,
            'graded_at' => $this->resource['prop']->graded_at?->toIso8601String(),
            'game' => [
                'id' => $this->resource['game']->id,
                'home_team' => $this->resource['game']->homeTeam?->name,
                'away_team' => $this->resource['game']->awayTeam?->name,
                'date' => $this->resource['game']->game_date,
                'time' => $this->resource['game']->game_time,
            ],
            'bookmaker' => $this->resource['prop']->bookmaker,
        ];
    }

    protected function resolveSportPrefix(): string
    {
        $modelClass = $this->resource['prop']::class;

        return match (true) {
            str_contains($modelClass, '\\NBA\\') => 'nba',
            str_contains($modelClass, '\\MLB\\') => 'mlb',
            str_contains($modelClass, '\\NFL\\') => 'nfl',
            str_contains($modelClass, '\\CBB\\') => 'cbb',
            default => 'nba',
        };
    }
}
