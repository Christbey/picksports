<?php

namespace App\Actions\ESPN\CFB;

use App\DataTransferObjects\ESPN\GameData;
use App\Models\CFB\Game;
use App\Services\ESPN\CFB\EspnService;
use App\Services\Sports\SportEventIdentitySynchronizer;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class SyncGameMetadata
{
    public function __construct(private readonly EspnService $service) {}

    public function execute(Game $game): bool
    {
        $payload = $this->service->getGame((string) $game->espn_event_id);
        $competition = data_get($payload, 'header.competitions.0', []);
        if (! is_array($payload) || (string) data_get($payload, 'header.id') !== (string) $game->espn_event_id
            || (int) data_get($payload, 'header.season.year') !== (int) $game->season) {
            throw new RuntimeException('CFB summary identity or season could not be verified.');
        }
        $date = $competition['date'] ?? null;
        $status = (string) data_get($competition, 'status.type.name');
        if (! $date || ($competition['dateValid'] ?? true) === false || ($competition['timeValid'] ?? true) === false
            || preg_match('/\b(?:TBD|TBA)\b/i', (string) data_get($competition, 'status.type.detail').' '.(string) data_get($competition, 'status.type.shortDetail'))
            || ! in_array($status, ['STATUS_SCHEDULED', 'STATUS_PRE_GAME', 'STATUS_DELAYED'], true)) {
            throw new RuntimeException('CFB pregame kickoff is unconfirmed or the game has already started.');
        }
        $parts = GameData::extractDateParts($date);
        $venue = data_get($payload, 'gameInfo.venue', $competition['venue'] ?? []);
        $updates = [...$parts];
        foreach (['venue_name' => $venue['fullName'] ?? null, 'venue_city' => data_get($venue, 'address.city'),
            'venue_state' => data_get($venue, 'address.state')] as $key => $value) {
            if (filled($value)) {
                $updates[$key] = $value;
            }
        }
        if (array_key_exists('neutralSite', $competition)) {
            $updates['neutral_site'] = (bool) $competition['neutralSite'];
        }

        return DB::transaction(function () use ($game, $updates, $payload, $competition): bool {
            $locked = Game::query()->lockForUpdate()->findOrFail($game->id);
            if ((string) $locked->espn_event_id !== (string) data_get($payload, 'header.id')
                || (int) $locked->season !== (int) data_get($payload, 'header.season.year')
                || ! in_array($locked->status, ['STATUS_SCHEDULED', 'STATUS_PRE_GAME', 'STATUS_DELAYED'], true)) {
                throw new RuntimeException('CFB game left pregame while its metadata was fetched.');
            }
            foreach ($competition['competitors'] ?? [] as $competitor) {
                $team = match ($competitor['homeAway'] ?? '') {
                    'home' => $locked->homeTeam,
                    'away' => $locked->awayTeam,
                    default => null,
                };
                if ($team?->espn_id && data_get($competitor, 'team.id') !== null
                    && (string) $team->espn_id !== (string) data_get($competitor, 'team.id')) {
                    throw new RuntimeException('CFB summary team identity disagrees with the stored matchup.');
                }
            }
            $locked->fill($updates)->saveQuietly();
            // A successful provider verification must refresh source age even when values are unchanged.
            $locked->touchQuietly();
            app(SportEventIdentitySynchronizer::class)->sync('cfb', $locked);

            return true;
        });
    }
}
