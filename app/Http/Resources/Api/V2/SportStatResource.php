<?php

namespace App\Http\Resources\Api\V2;

use App\Services\Api\V2\SportContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SportStatResource extends JsonResource
{
    public function __construct(
        mixed $resource,
        private readonly SportContext $context,
        private readonly string $type,
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
            'type' => $this->type,
            'game_id' => $this->attribute('game_id'),
            'team_id' => $this->attribute('team_id'),
            'player_id' => $this->attribute('player_id'),
            'stat_type' => $this->attribute('stat_type') ?? $this->attribute('team_type'),
            'team_type' => $this->attribute('team_type'),
            'season' => $this->gameAttribute('season'),
            'season_type' => $this->gameAttribute('season_type'),
            'game_date' => $this->serializeDateValue($this->gameAttribute('game_date')),
            'game' => $this->game(),
            'team' => $this->team(),
            'player' => $this->player(),
            'stats' => $this->stats(),
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
            'season' => $game->getAttribute('season'),
            'season_type' => $game->getAttribute('season_type'),
            'week' => $game->getAttribute('week'),
            'game_date' => $this->serializeDateValue($game->getAttribute('game_date')),
            'status' => $game->getAttribute('status'),
            'home_team_id' => $game->getAttribute('home_team_id'),
            'away_team_id' => $game->getAttribute('away_team_id'),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function team(): ?array
    {
        $team = $this->relation('team');

        if (! $team) {
            return null;
        }

        return [
            'id' => $team->getAttribute('id'),
            'abbreviation' => $team->getAttribute('abbreviation'),
            'display_name' => $team->getAttribute('display_name')
                ?? trim((string) ($team->getAttribute('location') ?? $team->getAttribute('school')).' '.(string) ($team->getAttribute('name') ?? $team->getAttribute('mascot'))),
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
     * @return array<string, mixed>
     */
    private function stats(): array
    {
        if (! $this->resource instanceof Model) {
            return [];
        }

        return collect($this->resource->getAttributes())
            ->except([
                'id',
                'game_id',
                'team_id',
                'player_id',
                'stat_type',
                'team_type',
                'created_at',
                'updated_at',
            ])
            ->filter(fn (mixed $value): bool => $value !== null)
            ->all();
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
