<?php

namespace App\Http\Resources\Api\V2;

use App\Services\Api\V2\SportContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SportTeamMetricResource extends JsonResource
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
            ...$this->metrics(),
            'id' => $this->attribute('id'),
            'sport' => $this->context->slug,
            'team_id' => $this->attribute('team_id'),
            'season' => $this->attribute('season'),
            'season_type' => $this->attribute('season_type'),
            'team' => $this->team(),
            'calculation_date' => $this->serializeDateValue($this->attribute('calculation_date')),
            'created_at' => $this->serializeDateValue($this->attribute('created_at')),
            'updated_at' => $this->serializeDateValue($this->attribute('updated_at')),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function metrics(): array
    {
        if (! $this->resource instanceof Model) {
            return [];
        }

        $metrics = collect($this->resource->getAttributes())
            ->except([
                'id',
                'team_id',
                'season',
                'season_type',
                'calculation_date',
                'created_at',
                'updated_at',
            ])
            ->filter(fn (mixed $value): bool => $value !== null)
            ->all();

        $this->aliasMetric($metrics, 'offensive_rating', 'offensive_efficiency');
        $this->aliasMetric($metrics, 'defensive_rating', 'defensive_efficiency');
        $this->aliasMetric($metrics, 'pace', 'tempo');

        return $metrics;
    }

    /**
     * @param  array<string, mixed>  $metrics
     */
    private function aliasMetric(array &$metrics, string $alias, string $source): void
    {
        if (! array_key_exists($alias, $metrics) && array_key_exists($source, $metrics)) {
            $metrics[$alias] = $metrics[$source];
        }
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
