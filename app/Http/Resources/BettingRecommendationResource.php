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
        $sport = strtolower($this->resource['prop']->sport);
        $sportPrefix = match ($sport) {
            'basketball_nba' => 'nba',
            'baseball_mlb' => 'mlb',
            'americanfootball_nfl' => 'nfl',
            'basketball_ncaab' => 'cbb',
            default => 'nba',
        };

        // Build player URL if route exists for that sport
        $playerUrl = null;
        if ($sportPrefix === 'nba') {
            $playerUrl = route('nba.player.show', $this->resource['player']->id);
        }
        // Add other sport routes as they become available
        // elseif ($sportPrefix === 'mlb') { $playerUrl = route('mlb.player.show', ...); }

        return [
            'id' => $this->resource['prop']->id,
            'player' => [
                'id' => $this->resource['player']->id,
                'name' => $this->resource['player']->full_name,
                'position' => $this->resource['player']->position,
                'team' => $this->resource['player']->team?->abbreviation ?? $this->resource['player']->team?->name,
                'headshot' => $this->resource['player']->headshot_url ?? null,
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
            ],
            'edge' => $this->resource['edge'],
            'reasoning' => $this->resource['reasoning'],
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
}
