<?php

namespace App\Http\Resources\Api\V2;

use App\Services\Api\V2\SportContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SportPredictionResource extends JsonResource
{
    public function __construct(
        mixed $resource,
        private readonly SportContext $context,
    ) {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->attribute('id'),
            'sport' => $this->context->slug,
            'game_id' => $this->attribute('game_id'),
            'game' => $this->game(),
            'status' => $this->gameAttribute('status'),
            'pick' => $this->pick(),
            'projection' => $this->projection(),
            'market_summary' => $this->marketSummary(),
            'created_at' => $this->serializeDateValue($this->attribute('created_at')),
            'updated_at' => $this->serializeDateValue($this->attribute('updated_at')),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function game(): ?array
    {
        $game = $this->relation('game');

        if (! $game) {
            return null;
        }

        return [
            'id' => $game->getAttribute('id'),
            'espn_id' => $game->getAttribute('espn_id'),
            'season' => $game->getAttribute('season'),
            'season_type' => $game->getAttribute('season_type'),
            'week' => $game->getAttribute('week'),
            'name' => $game->getAttribute('name'),
            'short_name' => $game->getAttribute('short_name'),
            'game_date' => $this->serializeDateValue($game->getAttribute('game_date')),
            'game_time' => $this->serializeDateValue($game->getAttribute('game_time')),
            'status' => $game->getAttribute('status'),
            'home_team_id' => $game->getAttribute('home_team_id'),
            'away_team_id' => $game->getAttribute('away_team_id'),
            'home_team' => $this->team($game, 'homeTeam'),
            'away_team' => $this->team($game, 'awayTeam'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function pick(): array
    {
        $winProbability = $this->floatAttribute('win_probability');
        $game = $this->relation('game');
        $side = $winProbability === null ? null : ($winProbability >= 0.5 ? 'home' : 'away');
        $team = $game && $side ? $this->teamModel($game, "{$side}Team") : null;

        return [
            'side' => $side,
            'team_id' => $team?->getAttribute('id') ?? ($game?->getAttribute("{$side}_team_id")),
            'team_abbreviation' => $team?->getAttribute('abbreviation'),
            'label' => $team?->getAttribute('abbreviation') ?? $team?->getAttribute('display_name'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function projection(): array
    {
        $homeWinProbability = $this->floatAttribute('win_probability');

        return [
            'home_win_probability' => $homeWinProbability,
            'away_win_probability' => $homeWinProbability === null ? null : round(1 - $homeWinProbability, 3),
            'predicted_spread' => $this->floatAttribute('predicted_spread'),
            'predicted_total' => $this->floatAttribute('predicted_total'),
            'confidence_score' => $this->floatAttribute('confidence_score'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function marketSummary(): array
    {
        $hasSpread = $this->attribute('vegas_spread') !== null;

        return [
            'has_odds' => $hasSpread,
            'markets' => $hasSpread ? ['spread'] : [],
            'odds_updated_at' => null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function team(Model $game, string $relation): ?array
    {
        $team = $this->teamModel($game, $relation);

        if (! $team) {
            return null;
        }

        return [
            'id' => $team->getAttribute('id'),
            'abbreviation' => $team->getAttribute('abbreviation'),
            'display_name' => $team->getAttribute('display_name')
                ?? trim((string) ($team->getAttribute('location') ?? $team->getAttribute('school')).' '.(string) ($team->getAttribute('name') ?? $team->getAttribute('mascot'))),
            'logo_url' => $team->getAttribute('logo_url'),
        ];
    }

    private function teamModel(Model $game, string $relation): ?Model
    {
        if (! $game->relationLoaded($relation)) {
            return null;
        }

        $team = $game->getRelation($relation);

        return $team instanceof Model ? $team : null;
    }

    private function relation(string $relation): ?Model
    {
        if (! $this->resource instanceof Model || ! $this->resource->relationLoaded($relation)) {
            return null;
        }

        $related = $this->resource->getRelation($relation);

        return $related instanceof Model ? $related : null;
    }

    private function gameAttribute(string $key): mixed
    {
        return $this->relation('game')?->getAttribute($key);
    }

    private function attribute(string $key): mixed
    {
        if (! $this->resource instanceof Model) {
            return null;
        }

        return array_key_exists($key, $this->resource->getAttributes())
            ? $this->resource->getAttribute($key)
            : null;
    }

    private function floatAttribute(string $key): ?float
    {
        $value = $this->attribute($key);

        return $value === null ? null : (float) $value;
    }

    private function serializeDateValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_object($value) && method_exists($value, 'toIso8601String')) {
            return $value->toIso8601String();
        }

        return (string) $value;
    }
}
