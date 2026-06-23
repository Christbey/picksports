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
        $prop = $this->resource['prop'];
        $player = $this->resource['player'] ?? null;
        $game = $this->resource['game'];
        $sportPrefix = $this->resolveSportPrefix();
        $playerRoute = "{$sportPrefix}.player.show";
        $playerId = data_get($player, 'id') ?? $prop->player_id;
        $playerUrl = $playerId !== null && app('router')->has($playerRoute)
            ? route($playerRoute, $playerId)
            : null;
        $playerName = data_get($player, 'full_name')
            ?? data_get($player, 'display_name')
            ?? data_get($player, 'name')
            ?? $prop->player_name
            ?? 'Unknown Player';

        return [
            'id' => $prop->id,
            'player' => [
                'id' => $playerId,
                'name' => $playerName,
                'position' => data_get($player, 'position'),
                'team' => data_get($player, 'team.abbreviation') ?? data_get($player, 'team.name'),
                'headshot' => data_get($player, 'headshot_url') ?? data_get($player, 'headshot'),
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
            'actual_value' => $prop->actual_value !== null ? (float) $prop->actual_value : null,
            'hit_over' => $prop->hit_over,
            'graded_at' => $prop->graded_at?->toIso8601String(),
            'game' => [
                'id' => $game->id,
                'home_team' => $game->homeTeam?->name,
                'away_team' => $game->awayTeam?->name,
                'date' => $game->game_date,
                'time' => $game->game_time,
            ],
            'bookmaker' => $prop->bookmaker,
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
