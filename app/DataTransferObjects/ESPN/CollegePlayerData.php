<?php

namespace App\DataTransferObjects\ESPN;

class CollegePlayerData extends AbstractPlayerData
{
    public function __construct(
        public string $espnId,
        public string $firstName,
        public string $lastName,
        public string $fullName,
        public ?string $jerseyNumber,
        public ?string $position,
        public ?string $height,
        public ?int $weight,
        public ?string $year,
        public ?string $hometown,
        public ?string $headshotUrl,
    ) {}

    protected static function fromCommonAndRaw(array $common, array $player): static
    {
        return new static(
            espnId: $common['espnId'],
            firstName: $common['firstName'],
            lastName: $common['lastName'],
            fullName: $common['fullName'],
            jerseyNumber: $common['jerseyNumber'],
            position: $common['position'],
            height: $common['height'],
            weight: $common['weight'],
            year: $player['year'] ?? $player['class'] ?? null,
            hometown: $player['hometown'] ?? null,
            headshotUrl: $common['headshotUrl'],
        );
    }

    protected function extraPlayerFields(): array
    {
        return [
            'year' => $this->year,
            'hometown' => $this->hometown,
        ];
    }
}
