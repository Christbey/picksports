<?php

namespace App\Actions\ESPN\NFL;

use App\Models\NFL\Game;
use App\Models\NFL\Team;
use App\Services\ESPN\NFL\EspnService;
use App\Services\Sports\SportEventIdentitySynchronizer;
use App\Support\NflGameStateGuard;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class SyncGameMetadata
{
    public function __construct(private readonly EspnService $service) {}

    public function execute(Game $game): bool
    {
        $connection = $game->getConnection();
        $name = $connection->getName();
        if (! $game->exists || $game->isDirty() || $game->getTable() !== (new Game)->getTable()
            || $game->getKeyName() !== (new Game)->getKeyName() || $game->getKey() !== $game->getRawOriginal($game->getKeyName())
            || ! is_string($name) || $name === '') {
            throw new RuntimeException('NFL metadata requires a clean persisted game identity.');
        }
        $context = ['connection' => $connection, 'pdo' => $connection->getPdo(), 'name' => $name,
            'input' => $game, 'id' => $game->getKey(), 'identity' => $this->identity($game)];
        $this->assertConnection($context);
        $initial = $this->currentGame($context, false);
        if ($this->identity($initial) !== $context['identity']) {
            throw new RuntimeException('NFL metadata game identity is stale before provider IO.');
        }
        $teams = $this->teamReferences($initial, false);
        $payload = $this->service->getGame((string) $initial->espn_event_id);
        $this->assertConnection($context);
        if (! is_array($payload) || (string) data_get($payload, 'header.id') !== (string) $initial->espn_event_id) {
            throw new RuntimeException('NFL game summary was unavailable.');
        }
        $this->assertSummaryOwner($payload, $initial, $teams);

        $changed = false;
        $current = null;
        $event = app(SportEventIdentitySynchronizer::class)->mutateAndSync('nfl', $initial, function (Game $locked) use ($context, $payload, $teams, &$changed, &$current): void {
            $this->assertConnection($context);
            $current = $locked;
            if ($this->identity($current) !== $context['identity'] || $this->teamReferences($current, true) !== $teams) {
                throw new RuntimeException('NFL metadata game or provider team binding changed during IO.');
            }
            $changed = $this->apply($payload, $current);
            if (! filled($current->venue_name) || ! filled($current->venue_city)) {
                throw new RuntimeException('NFL game summary did not provide usable venue metadata.');
            }
            if ($this->identity($this->currentGame($context, true)) !== $context['identity']) {
                throw new RuntimeException('NFL metadata persistence changed its game owner.');
            }
            $this->assertConnection($context);

        });
        $this->assertConnection($context);
        if (! $current instanceof Game) {
            throw new RuntimeException('NFL metadata coordinator did not mutate its owned game.');
        }
        $game->setRawAttributes([...$game->getAttributes(), ...$current->getAttributes(), 'sport_event_id' => $event->getKey()], true);
        $game->setRelation('sportEvent', $event);
        $game->unsetRelation('homeTeam')->unsetRelation('awayTeam');

        return $changed;
    }

    public function apply(array $gameData, Game $game): bool
    {
        $expectedIdentity = $this->identity($game);
        $teamReferences = $this->teamReferences($game, true);
        $this->assertSummaryOwner($gameData, $game, $teamReferences);
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
        if (array_key_exists('neutralSite', $competition) && $competition['neutralSite'] !== null) {
            $updates['neutral_site'] = $competition['neutralSite'];
        }
        $game->fill(NflGameStateGuard::preserve($game, $updates));

        if (! $game->isDirty()) {
            return false;
        }
        $desired = $game->getDirty();
        $connection = $game->getConnection();
        $pdo = $connection->getPdo();
        $id = $game->getKey();
        if ($game->save() !== true) {
            throw new RuntimeException('NFL metadata persistence was cancelled.');
        }

        if (DB::connection($connection->getName()) !== $connection || $game->getConnection() !== $connection
            || $connection->getRawPdo() !== $pdo) {
            throw new RuntimeException('NFL metadata persistence connection changed.');
        }
        $stored = (new Game)->setConnection($connection->getName())->newQuery()->useWritePdo()->whereKey($id)
            ->lockForUpdate()->first(array_values(array_unique([...array_keys($expectedIdentity), ...array_keys($desired)])));
        if ($stored === null || $this->identity($stored) !== $expectedIdentity || $this->teamReferences($stored, true) !== $teamReferences) {
            throw new RuntimeException('NFL metadata persisted owner changed.');
        }
        foreach ($desired as $field => $value) {
            $actual = $stored->getRawOriginal($field);
            $matches = $field === 'neutral_site'
                ? in_array($actual, [0, 1, '0', '1', false, true], true) && (int) $actual === (int) $value
                : $actual === $value;
            if (! $matches) {
                throw new RuntimeException('NFL metadata differs from its staged requested fields.');
            }
        }

        return true;
    }

    private function identity(Game $game): array
    {
        $identity = [];
        foreach (['id', 'espn_event_id', 'espn_uid', 'odds_api_event_id', 'nflverse_game_id', 'sport_event_id', 'home_team_id', 'away_team_id', 'season',
            'season_type', 'game_date', 'game_time', 'status'] as $field) {
            $identity[$field] = $game->getRawOriginal($field);
        }

        return $identity;
    }

    private function currentGame(array $context, bool $lock): Game
    {
        $query = (new Game)->setConnection($context['name'])->newQuery()->useWritePdo()->whereKey($context['id']);
        if ($query->getQuery()->getConnection() !== $context['connection']) {
            throw new RuntimeException('NFL metadata query resolved another connection.');
        }
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->firstOrFail(['id', 'espn_event_id', 'espn_uid', 'odds_api_event_id', 'nflverse_game_id', 'sport_event_id', 'home_team_id', 'away_team_id', 'season',
            'season_type', 'game_date', 'game_time', 'status', 'name', 'short_name', 'venue_name', 'venue_city', 'venue_state', 'neutral_site']);
    }

    private function assertConnection(array $context): void
    {
        if (DB::connection($context['name']) !== $context['connection']
            || $context['input']->getConnection() !== $context['connection']
            || $context['connection']->getRawPdo() !== $context['pdo']
            || $context['input']->getKey() !== $context['id']
            || $context['input']->getTable() !== (new Game)->getTable()
            || $context['input']->getKeyName() !== (new Game)->getKeyName()) {
            throw new RuntimeException('NFL metadata connection or caller identity changed during IO.');
        }
    }

    /** Exactly the two stored participants, never a provider-supplied fallback team. */
    private function teamReferences(Game $game, bool $lock): array
    {
        $query = (new Team)->setConnection($game->getConnection()->getName())->newQuery()->useWritePdo()
            ->whereKey([$game->home_team_id, $game->away_team_id])->orderBy('id');
        if ($query->getQuery()->getConnection() !== $game->getConnection()) {
            throw new RuntimeException('NFL metadata team query resolved another connection.');
        }
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->get(['id', 'espn_id'])->mapWithKeys(fn (Team $team) => [(string) $team->id => (string) $team->espn_id])->all();
    }

    private function assertSummaryOwner(array $payload, Game $game, array $teams): void
    {
        $neutral = data_get($payload, 'header.competitions.0.neutralSite');
        if ($neutral !== null && ! is_bool($neutral)) {
            throw new RuntimeException('NFL neutral-site metadata requires an observed boolean value.');
        }
        $id = data_get($payload, 'header.id');
        $season = data_get($payload, 'header.season.year');
        if (($id !== null && (string) $id !== (string) $game->espn_event_id)
            || ($season !== null && (string) $season !== (string) $game->season)) {
            throw new RuntimeException('NFL summary event or season disagrees with its stored owner.');
        }
        foreach ((array) data_get($payload, 'header.competitions.0.competitors', []) as $competitor) {
            $reference = data_get($competitor, 'team.id');
            if ($reference === null) {
                continue; // Existing partial venue summaries need not invent missing team IDs.
            }
            $teamId = match ($competitor['homeAway'] ?? null) {
                'home' => $game->home_team_id,
                'away' => $game->away_team_id,
                default => null,
            };
            if ($teamId === null || ! isset($teams[$teamId]) || (string) $reference !== $teams[$teamId]) {
                throw new RuntimeException('NFL summary team identity disagrees with its stored matchup.');
            }
        }
    }
}
