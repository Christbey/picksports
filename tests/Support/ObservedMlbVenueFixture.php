<?php

use App\Actions\ESPN\MLB\Concerns\ResolvesMlbGameDateParts;
use App\Models\MLB\Team;

function mlbSyntheticVenueDateParts(?string $city, ?string $state): array
{
    $resolver = new class
    {
        use ResolvesMlbGameDateParts;

        public function resolve(array $raw): array
        {
            return $this->resolveMlbGameDateParts($raw);
        }
    };

    return ['home_team_id' => Team::factory()->create()->id, 'away_team_id' => Team::factory()->create()->id] + $resolver->resolve([
        'date' => '2026-07-02T06:30:00Z',
        'competitions' => [['venue' => ['address' => ['city' => $city, 'state' => $state]]]],
    ]);
}
