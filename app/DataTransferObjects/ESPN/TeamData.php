<?php

namespace App\DataTransferObjects\ESPN;

class TeamData extends AbstractTeamData
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

    protected static function fromCommonAndRaw(array $common, array $team): static
    {
        return new static(
            espnId: $common['espnId'],
            abbreviation: $common['abbreviation'],
            location: $team['location'] ?? '',
            name: $team['name'] ?? '',
            conference: $common['conference'],
            division: $common['division'],
            color: $common['color'],
            logoUrl: $common['logoUrl'],
        );
    }

    protected function extraTeamFields(): array
    {
        return [
            'location' => $this->location,
            'name' => $this->name,
        ];
    }
}
