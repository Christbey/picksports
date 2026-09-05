<?php

namespace App\Application\Sports\ReadModels;

final readonly class TeamSummary
{
    public function __construct(
        public int|string $id,
        public ?string $espnId,
        public ?string $abbreviation,
        public ?string $location,
        public ?string $name,
        public ?string $nickname,
        public ?string $displayName,
        public ?string $shortDisplayName,
        public ?string $conference,
        public ?string $league,
        public ?string $division,
        public ?string $color,
        public ?string $alternateColor,
        public ?string $logoUrl,
    ) {}
}
