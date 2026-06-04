<?php

namespace App\Http\Resources\Api\V2;

use App\Services\Api\V2\SportContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SportPlayerResource extends JsonResource
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
            'team_id' => $this->attribute('team_id'),
            'espn_id' => $this->attribute('espn_id'),
            'first_name' => $this->attribute('first_name'),
            'last_name' => $this->attribute('last_name'),
            'full_name' => $this->fullName(),
            'display_name' => $this->attribute('display_name') ?? $this->fullName(),
            'jersey_number' => $this->attribute('jersey_number') ?? $this->attribute('jersey'),
            'position' => $this->attribute('position'),
            'height' => $this->attribute('height'),
            'weight' => $this->attribute('weight'),
            'age' => $this->attribute('age'),
            'experience' => $this->attribute('experience'),
            'year' => $this->attribute('year'),
            'college' => $this->attribute('college'),
            'hometown' => $this->attribute('hometown'),
            'status' => $this->attribute('status'),
            'batting_hand' => $this->attribute('batting_hand'),
            'throwing_hand' => $this->attribute('throwing_hand'),
            'headshot_url' => $this->attribute('headshot_url') ?? $this->attribute('headshot'),
            'team' => $this->team(),
            'created_at' => $this->serializeDateValue($this->attribute('created_at')),
            'updated_at' => $this->serializeDateValue($this->attribute('updated_at')),
        ];
    }

    private function fullName(): ?string
    {
        $fullName = $this->attribute('full_name') ?? $this->attribute('name');

        if ($fullName !== null && trim((string) $fullName) !== '') {
            return (string) $fullName;
        }

        $parts = array_filter([
            $this->attribute('first_name'),
            $this->attribute('last_name'),
        ]);

        return $parts === [] ? null : implode(' ', $parts);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function team(): ?array
    {
        if (! $this->resource instanceof Model || ! $this->resource->relationLoaded('team')) {
            return null;
        }

        $team = $this->resource->getRelation('team');

        if (! $team instanceof Model) {
            return null;
        }

        return [
            'id' => $team->getAttribute('id'),
            'abbreviation' => $team->getAttribute('abbreviation'),
            'location' => $team->getAttribute('location') ?? $team->getAttribute('school'),
            'name' => $team->getAttribute('name') ?? $team->getAttribute('mascot'),
            'display_name' => $team->getAttribute('display_name'),
        ];
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
