<?php

namespace App\DataTransferObjects\ESPN;

class CollegeTeamData extends AbstractTeamData
{
    public function __construct(
        public string $espnId,
        public string $abbreviation,
        public string $school,
        public string $mascot,
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
            school: $team['school'] ?? $team['location'] ?? '',
            mascot: $team['mascot'] ?? $team['name'] ?? '',
            conference: $common['conference'],
            division: $common['division'],
            color: $common['color'],
            logoUrl: $common['logoUrl'],
        );
    }

    protected function extraTeamFields(): array
    {
        return [
            'school' => $this->school,
            'mascot' => $this->mascot,
        ];
    }
}
