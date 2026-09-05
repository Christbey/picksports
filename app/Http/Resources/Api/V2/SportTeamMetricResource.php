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
        private readonly ?array $preparedRecord = null,
    ) {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $metrics = $this->metrics();
        $record = $this->record($metrics);

        return [
            ...$metrics,
            'id' => $this->attribute('id'),
            'sport' => $this->context->slug,
            'team_id' => $this->attribute('team_id'),
            'season' => $this->attribute('season'),
            'season_type' => $this->attribute('season_type'),
            'wins' => $record['wins'],
            'losses' => $record['losses'],
            'games_played' => $record['games_played'],
            'record' => $record,
            'record_label' => $record['label'],
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
            ->mapWithKeys(fn (mixed $value, string $key): array => [$key => $this->metricAttribute($key)])
            ->filter(fn (mixed $value): bool => $value !== null)
            ->all();

        $this->aliasMetric($metrics, 'offensive_rating', 'offensive_efficiency');
        $this->aliasMetric($metrics, 'defensive_rating', 'defensive_efficiency');
        $this->aliasMetric($metrics, 'pace', 'tempo');

        return $metrics;
    }

    private function metricAttribute(string $key): mixed
    {
        $value = $this->resource instanceof Model ? $this->resource->getAttribute($key) : null;

        if (is_numeric($value)) {
            return str_contains((string) $value, '.')
                ? (float) $value
                : (int) $value;
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $metrics
     * @return array{wins: int|null, losses: int|null, games_played: int, label: string|null, source: string}
     */
    private function record(array $metrics): array
    {
        $wins = $this->nullableInt($metrics['wins'] ?? $this->attribute('wins'));
        $losses = $this->nullableInt($metrics['losses'] ?? $this->attribute('losses'));
        $source = 'metric';

        if ($this->preparedRecord !== null && ($wins === null || $losses === null || ($wins + $losses) === 0)) {
            $derived = $this->preparedRecord;

            if ($derived['games_played'] > 0) {
                $wins = $derived['wins'];
                $losses = $derived['losses'];
                $source = 'derived_games';
            }
        }

        $gamesPlayed = $wins !== null && $losses !== null
            ? $wins + $losses
            : ($this->nullableInt($metrics['games_played'] ?? $this->attribute('games_played')) ?? 0);

        return [
            'wins' => $wins,
            'losses' => $losses,
            'games_played' => $gamesPlayed,
            'label' => $wins !== null && $losses !== null ? "{$wins}-{$losses}" : null,
            'source' => $source,
        ];
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
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
