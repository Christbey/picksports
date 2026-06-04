<?php

namespace App\Http\Resources\Api\V2;

use App\Services\Api\V2\SportContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SportFuturesOddResource extends JsonResource
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
            'season' => $this->attribute('season'),
            'odds_api_sport_key' => $this->attribute('odds_api_sport_key'),
            'event_id' => $this->attribute('event_id'),
            'event_name' => $this->attribute('event_name'),
            'commence_time' => $this->serializeDateValue($this->attribute('commence_time')),
            'bookmaker' => $this->attribute('bookmaker'),
            'market_key' => $this->attribute('market_key'),
            'market_last_update' => $this->serializeDateValue($this->attribute('market_last_update')),
            'outcome' => [
                'name' => $this->attribute('outcome_name'),
                'description' => $this->attribute('outcome_description'),
                'point' => $this->floatAttribute('outcome_point'),
                'price' => $this->attribute('price'),
                'implied_probability' => $this->floatAttribute('implied_probability'),
            ],
            'entity' => $this->entity(),
            'fetched_at' => $this->serializeDateValue($this->attribute('fetched_at')),
            'created_at' => $this->serializeDateValue($this->attribute('created_at')),
            'updated_at' => $this->serializeDateValue($this->attribute('updated_at')),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function entity(): ?array
    {
        foreach ([
            'nbaTeam' => 'team',
            'mlbTeam' => 'team',
            'nflTeam' => 'team',
            'cbbTeam' => 'team',
            'wcbbTeam' => 'team',
            'nflPlayer' => 'player',
        ] as $relation => $type) {
            $entity = $this->relation($relation);

            if (! $entity) {
                continue;
            }

            return [
                'type' => $type,
                'id' => $entity->getAttribute('id'),
                'team_id' => $entity->getAttribute('team_id'),
                'abbreviation' => $entity->getAttribute('abbreviation'),
                'display_name' => $entity->getAttribute('display_name')
                    ?? $entity->getAttribute('full_name')
                    ?? trim((string) ($entity->getAttribute('location') ?? $entity->getAttribute('school')).' '.(string) ($entity->getAttribute('name') ?? $entity->getAttribute('mascot'))),
                'position' => $entity->getAttribute('position'),
            ];
        }

        return null;
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
