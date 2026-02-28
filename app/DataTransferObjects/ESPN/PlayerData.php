<?php

namespace App\DataTransferObjects\ESPN;

class PlayerData extends AbstractPlayerData
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
        public ?int $age,
        public ?int $experience,
        public ?string $college,
        public ?string $status,
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
            age: self::intOrNull($player['age'] ?? null),
            experience: self::intOrNull($player['experience']['years'] ?? null),
            college: $player['college']['name'] ?? null,
            status: $player['status']['type'] ?? null,
            headshotUrl: $common['headshotUrl'],
        );
    }

    protected function extraPlayerFields(): array
    {
        return [
            'age' => $this->age,
            'experience' => $this->experience,
            'college' => $this->college,
            'status' => $this->status,
        ];
    }
}
