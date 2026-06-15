<?php

namespace App\Http\Resources\Api\V2;

use App\Services\Api\V2\SportContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SportPlayerPropResource extends JsonResource
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
            'player_id' => $this->attribute('player_id'),
            'player_name' => $this->attribute('player_name'),
            'market' => $this->attribute('market'),
            'bookmaker' => $this->attribute('bookmaker'),
            'line' => $this->floatAttribute('line'),
            'over_price' => $this->attribute('over_price'),
            'under_price' => $this->attribute('under_price'),
            'prices' => [
                'over' => $this->attribute('over_price'),
                'under' => $this->attribute('under_price'),
            ],
            'recommendation' => [
                'side' => $this->attribute('recommended_side'),
                'confidence_score' => $this->attribute('confidence_score'),
                'predicted_over_probability' => $this->floatAttribute('predicted_over_probability'),
                'market_over_probability' => $this->floatAttribute('market_over_probability'),
                'edge_probability' => $this->floatAttribute('edge_probability'),
                'data_quality_score' => $this->attribute('data_quality_score'),
                'match_quality_score' => $this->attribute('match_quality_score'),
                'context_adjustment_factor' => $this->floatAttribute('context_adjustment_factor'),
                'signal_quality' => $this->signalQuality(),
            ],
            'grading' => [
                'actual_value' => $this->floatAttribute('actual_value'),
                'hit_over' => $this->attribute('hit_over'),
                'error' => $this->floatAttribute('error'),
                'graded_at' => $this->serializeDateValue($this->attribute('graded_at')),
            ],
            'player' => $this->player(),
            'game' => $this->game(),
            'fetched_at' => $this->serializeDateValue($this->attribute('fetched_at')),
            'created_at' => $this->serializeDateValue($this->attribute('created_at')),
            'updated_at' => $this->serializeDateValue($this->attribute('updated_at')),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function player(): ?array
    {
        $player = $this->relation('player');

        if (! $player) {
            return null;
        }

        return [
            'id' => $player->getAttribute('id'),
            'team_id' => $player->getAttribute('team_id'),
            'display_name' => $player->getAttribute('display_name')
                ?? $player->getAttribute('full_name')
                ?? trim((string) $player->getAttribute('first_name').' '.(string) $player->getAttribute('last_name')),
            'position' => $player->getAttribute('position'),
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
            'season' => $game->getAttribute('season'),
            'game_date' => $this->serializeDateValue($game->getAttribute('game_date')),
            'status' => $game->getAttribute('status'),
            'home_team_id' => $game->getAttribute('home_team_id'),
            'away_team_id' => $game->getAttribute('away_team_id'),
            'home_team' => $this->team($game, 'homeTeam'),
            'away_team' => $this->team($game, 'awayTeam'),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function team(Model $game, string $relation): ?array
    {
        if (! $game->relationLoaded($relation)) {
            return null;
        }

        $team = $game->getRelation($relation);

        if (! $team instanceof Model) {
            return null;
        }

        return [
            'id' => $team->getAttribute('id'),
            'abbreviation' => $team->getAttribute('abbreviation'),
            'display_name' => $team->getAttribute('display_name')
                ?? trim((string) ($team->getAttribute('location') ?? $team->getAttribute('school')).' '.(string) ($team->getAttribute('name') ?? $team->getAttribute('mascot'))),
        ];
    }

    private function relation(string $relation): ?Model
    {
        if (! $this->resource instanceof Model || ! $this->resource->relationLoaded($relation)) {
            return null;
        }

        $related = $this->resource->getRelation($relation);

        return $related instanceof Model ? $related : null;
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

    /**
     * @return array<string, mixed>|null
     */
    private function signalQuality(): ?array
    {
        $decomposition = $this->attribute('confidence_decomposition');
        if (! is_array($decomposition)) {
            return null;
        }

        $quality = $decomposition['signal_quality'] ?? null;

        return is_array($quality) ? $quality : null;
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
