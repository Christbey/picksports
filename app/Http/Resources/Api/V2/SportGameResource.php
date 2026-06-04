<?php

namespace App\Http\Resources\Api\V2;

use App\Services\Api\V2\SportContext;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\MissingValue;

class SportGameResource extends JsonResource
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
            'id' => $this->id,
            'sport' => $this->context->slug,
            'espn_id' => $this->espn_id ?? null,
            'season' => $this->season ?? null,
            'season_type' => $this->season_type ?? null,
            'week' => $this->week ?? null,
            'name' => $this->name ?? null,
            'short_name' => $this->short_name ?? null,
            'game_date' => $this->serializeDateValue($this->game_date ?? null),
            'game_time' => $this->serializeDateValue($this->game_time ?? null),
            'status' => $this->status ?? null,
            'home_team_id' => $this->home_team_id ?? null,
            'away_team_id' => $this->away_team_id ?? null,
            'home_score' => $this->home_score ?? null,
            'away_score' => $this->away_score ?? null,
            'home_team' => $this->teamPayload($this->whenLoaded('homeTeam')),
            'away_team' => $this->teamPayload($this->whenLoaded('awayTeam')),
            'has_prediction' => $this->relationLoaded('prediction') && $this->prediction !== null,
            'updated_at' => $this->serializeDateValue($this->updated_at ?? null),
        ];
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

    /**
     * @return array<string, mixed>|null
     */
    private function teamPayload(mixed $team): ?array
    {
        if (! $team || $team instanceof MissingValue) {
            return null;
        }

        return [
            'id' => $team->id,
            'espn_id' => $team->espn_id ?? null,
            'abbreviation' => $team->abbreviation ?? null,
            'display_name' => $team->display_name ?? $team->name ?? $team->school ?? null,
            'logo_url' => $team->logo_url ?? null,
        ];
    }
}
