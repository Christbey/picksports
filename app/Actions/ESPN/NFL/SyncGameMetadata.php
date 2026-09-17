<?php

namespace App\Actions\ESPN\NFL;

use App\Models\NFL\Game;
use App\Services\ESPN\NFL\EspnService;
use App\Support\NflGameStateGuard;
use RuntimeException;

class SyncGameMetadata
{
    public function __construct(private readonly EspnService $service) {}

    public function execute(Game $game): bool
    {
        $payload = $this->service->getGame((string) $game->espn_event_id);
        if (! is_array($payload) || (string) data_get($payload, 'header.id') !== (string) $game->espn_event_id) {
            throw new RuntimeException('NFL game summary was unavailable.');
        }

        $changed = $this->apply($payload, $game);
        if (! filled($game->venue_name) || ! filled($game->venue_city)) {
            throw new RuntimeException('NFL game summary did not provide usable venue metadata.');
        }

        return $changed;
    }

    public function apply(array $gameData, Game $game): bool
    {
        $competition = data_get($gameData, 'header.competitions.0', []);
        $venue = data_get($gameData, 'gameInfo.venue', $competition['venue'] ?? []);
        $competitors = collect($competition['competitors'] ?? []);
        $home = $competitors->firstWhere('homeAway', 'home');
        $away = $competitors->firstWhere('homeAway', 'away');
        $homeName = data_get($home, 'team.displayName');
        $awayName = data_get($away, 'team.displayName');
        $homeAbbreviation = data_get($home, 'team.abbreviation');
        $awayAbbreviation = data_get($away, 'team.abbreviation');
        $updates = [
            'venue_name' => $venue['fullName'] ?? null,
            'venue_city' => data_get($venue, 'address.city'),
            'venue_state' => data_get($venue, 'address.state'),
            'name' => data_get($gameData, 'header.name') ?? ($homeName && $awayName ? "{$awayName} at {$homeName}" : null),
            'short_name' => data_get($gameData, 'header.shortName') ?? ($homeAbbreviation && $awayAbbreviation ? "{$awayAbbreviation} @ {$homeAbbreviation}" : null),
        ];
        if (array_key_exists('neutralSite', $competition)) {
            $updates['neutral_site'] = (bool) $competition['neutralSite'];
        }
        $game->fill(NflGameStateGuard::preserve($game, $updates));

        return $game->isDirty() && $game->save();
    }
}
