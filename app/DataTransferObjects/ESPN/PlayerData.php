<?php

namespace App\DataTransferObjects\ESPN;

class PlayerData
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

    public static function fromEspnResponse(array $player): self
    {
        return new self(
            espnId: (string) $player['id'],
            firstName: $player['firstName'] ?? '',
            lastName: $player['lastName'] ?? '',
            fullName: $player['fullName'] ?? $player['displayName'] ?? '',
            jerseyNumber: isset($player['jersey']) ? (string) $player['jersey'] : null,
            position: $player['position']['abbreviation'] ?? null,
            height: $player['height'] ?? null,
            weight: isset($player['weight']) ? (int) $player['weight'] : null,
            age: isset($player['age']) ? (int) $player['age'] : null,
            experience: isset($player['experience']['years']) ? (int) $player['experience']['years'] : null,
            college: $player['college']['name'] ?? null,
            status: $player['status']['type'] ?? null,
            headshotUrl: $player['headshot']['href'] ?? null,
        );
    }

    public function toArray(): array
    {
        return [
            'espn_id' => $this->espnId,
            'first_name' => $this->firstName,
            'last_name' => $this->lastName,
            'full_name' => $this->fullName,
            'jersey_number' => $this->jerseyNumber,
            'position' => $this->position,
            'height' => $this->height,
            'weight' => $this->weight,
            'age' => $this->age,
            'experience' => $this->experience,
            'college' => $this->college,
            'status' => $this->status,
            'headshot_url' => $this->headshotUrl,
        ];
    }
}
