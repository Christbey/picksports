<?php

namespace App\Http\Resources\Api\V2;

use App\Services\Api\V2\SportContext;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SportTeamResource extends JsonResource
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
            'abbreviation' => $this->abbreviation ?? null,
            'location' => $this->location ?? $this->school ?? null,
            'name' => $this->name ?? $this->mascot ?? null,
            'nickname' => $this->nickname ?? null,
            'display_name' => $this->display_name ?? $this->displayName(),
            'short_display_name' => $this->short_display_name ?? $this->abbreviation ?? null,
            'conference' => $this->conference ?? null,
            'league' => $this->league ?? null,
            'division' => $this->division ?? null,
            'color' => $this->color ?? null,
            'alternate_color' => $this->alternate_color ?? null,
            'logo_url' => $this->logo_url ?? null,
            'created_at' => $this->serializeDateValue($this->created_at ?? null),
            'updated_at' => $this->serializeDateValue($this->updated_at ?? null),
        ];
    }

    private function displayName(): ?string
    {
        $parts = array_filter([
            $this->location ?? $this->school ?? null,
            $this->name ?? $this->nickname ?? $this->mascot ?? null,
        ]);

        return $parts === [] ? null : implode(' ', $parts);
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
