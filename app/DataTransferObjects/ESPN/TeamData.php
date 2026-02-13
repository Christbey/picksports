<?php

namespace App\DataTransferObjects\ESPN;

class TeamData
{
    public function __construct(
        public string $espnId,
        public string $abbreviation,
        public string $location,
        public string $name,
        public ?string $conference,
        public ?string $division,
        public ?string $color,
        public ?string $logoUrl,
    ) {}

    public static function fromEspnResponse(array $team): self
    {
        return new self(
            espnId: (string) $team['id'],
            abbreviation: $team['abbreviation'] ?? '',
            location: $team['location'] ?? '',
            name: $team['name'] ?? '',
            conference: $team['conference']['name'] ?? null,
            division: $team['division']['name'] ?? null,
            color: $team['color'] ?? null,
            logoUrl: $team['logos'][0]['href'] ?? null,
        );
    }

    public function toArray(): array
    {
        return [
            'espn_id' => $this->espnId,
            'abbreviation' => $this->abbreviation,
            'location' => $this->location,
            'name' => $this->name,
            'conference' => $this->conference,
            'division' => $this->division,
            'color' => $this->color,
            'logo_url' => $this->logoUrl,
        ];
    }
}
