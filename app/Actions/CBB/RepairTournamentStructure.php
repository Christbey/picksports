<?php

namespace App\Actions\CBB;

use App\Models\CBB\Game;
use App\Models\CBB\Team;
use Illuminate\Database\Connection;
use Illuminate\Support\Collection;
use RuntimeException;

class RepairTournamentStructure
{
    private const REGIONS = ['East', 'West', 'South', 'Midwest'];

    private const ROUND_OF_64_SEED_PAIRINGS = [
        [1, 16],
        [8, 9],
        [5, 12],
        [4, 13],
        [6, 11],
        [3, 14],
        [7, 10],
        [2, 15],
    ];

    public function execute(int $season): int
    {
        $connection = (new Game)->getConnection();
        if ($connection !== (new Team)->getConnection()) {
            throw new RuntimeException('Tournament repair requires one game and team connection.');
        }

        return $connection->transaction(fn (): int => $this->repairSeason($season, $connection));
    }

    private function repairSeason(int $season, Connection $connection): int
    {
        $games = Game::query()
            ->with(['homeTeam', 'awayTeam'])
            ->where('season', $season)
            ->where('season_type', (int) config('cbb.season.types.postseason'))
            ->where('is_ncaa_tournament', true)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $teams = Team::query()
            ->select(['id', 'espn_id', 'school', 'mascot', 'abbreviation', 'logo_url'])
            ->get();

        $plans = [];
        foreach (self::REGIONS as $region) {
            foreach (self::ROUND_OF_64_SEED_PAIRINGS as [$highSeed, $lowSeed]) {
                $placeholderEventId = $this->placeholderEventId($season, $region, $highSeed, $lowSeed);
                $pairGames = $this->gamesForPair($games, $region, $highSeed, $lowSeed);
                $realGame = $pairGames->first(fn (Game $game) => ! $this->isPlaceholderGame($game));
                $placeholderGame = $pairGames->first(fn (Game $game) => $this->isPlaceholderGame($game));

                if ($realGame) {
                    if ($placeholderGame) {
                        $this->assertDeletable($connection, $placeholderGame);
                    }
                    $plans[] = ['kind' => 'real', 'id' => $realGame->id,
                        'expected' => array_intersect_key($realGame->getRawOriginal(), array_flip([
                            'espn_event_id', 'sport_event_id', 'season', 'season_type', 'tournament_round',
                            'tournament_region', 'home_seed', 'away_seed', 'home_team_id', 'away_team_id',
                            'status', 'home_score', 'away_score',
                        ])), 'obsolete' => $placeholderGame];

                    continue;
                }

                $attributes = $this->buildPlaceholderAttributes($games, $teams, $season, $region, $highSeed, $lowSeed);
                // Detach date objects before any model observer can run.
                $attributes = (new Game($attributes))->getAttributes();
                $current = Game::query()->where('espn_event_id', $placeholderEventId)->lockForUpdate()->first();
                if ($current !== null && $current->getRawOriginal('espn_event_id') !== $placeholderEventId) {
                    throw new RuntimeException('Tournament placeholder key has an ambiguous current owner.');
                }
                $plans[] = ['kind' => 'write', 'row' => $current, 'id' => $current?->getRawOriginal('id'),
                    'attributes' => $attributes, 'key' => $placeholderEventId,
                    'expected' => ['espn_event_id' => $placeholderEventId, ...$attributes,
                        'sport_event_id' => $current?->getRawOriginal('sport_event_id')]];
            }
        }

        $createdOrUpdated = 0;
        $headers = [];
        $deleted = [];
        foreach ($plans as $plan) {
            if ($plan['kind'] === 'real') {
                $this->assertSlot($connection, $plan['id'], $plan['expected']);
                if ($plan['obsolete'] !== null) {
                    $obsolete = $plan['obsolete'];
                    $id = $obsolete->id;
                    $key = $obsolete->getRawOriginal('espn_event_id');
                    $this->assertDeletable($connection, $obsolete);
                    if ($obsolete->delete() !== true || $connection->table('cbb_games')->useWritePdo()->where('id', $id)->exists()) {
                        throw new RuntimeException('Obsolete tournament placeholder deletion was not persisted.');
                    }
                    $deleted[] = ['id' => $id, 'key' => $key];
                }
                $headers[] = ['id' => $plan['id'], 'expected' => $plan['expected']];

                continue;
            }
            $row = $plan['row'] ?? new Game(['espn_event_id' => $plan['key']]);
            $row->fill($plan['attributes']);
            if ($row->getConnection() !== $connection || $row->save() !== true || ! $row->exists
                || $row->getConnection() !== $connection || $row->getTable() !== 'cbb_games'
                || ($plan['id'] !== null && (string) $row->id !== (string) $plan['id'])) {
                throw new RuntimeException('Tournament placeholder save was not persisted.');
            }
            $this->assertSlot($connection, $row->id, $plan['expected']);
            $headers[] = ['id' => $row->id, 'expected' => $plan['expected']];
            $createdOrUpdated++;
        }
        // At most 32 intended slots; a later observer cannot erase an earlier receipt.
        foreach ($headers as $header) {
            $this->assertSlot($connection, $header['id'], $header['expected']);
        }
        foreach ($deleted as $row) {
            if ($connection->table('cbb_games')->useWritePdo()->where('id', $row['id'])
                ->orWhere('espn_event_id', $row['key'])->exists()) {
                throw new RuntimeException('Obsolete tournament placeholder reappeared during repair.');
            }
        }

        return $createdOrUpdated;
    }

    private function assertSlot(Connection $connection, int|string $id, array $expected): void
    {
        $query = $connection->table('cbb_games')->useWritePdo()->where('id', $id)->lockForUpdate()->select('id');
        foreach ($expected as $field => $value) {
            $column = $connection->getQueryGrammar()->wrap($field);
            if ($value === null) {
                $query->selectRaw("CASE WHEN {$column} IS NULL THEN NULL ELSE '__not_null__' END AS {$column}");
            } else {
                $query->selectRaw("CASE WHEN LENGTH({$column}) <= ? THEN {$column} ELSE NULL END AS {$column}", [max(32, strlen((string) $value) + 1)]);
            }
        }
        $current = $query->first();
        if ($current === null) {
            throw new RuntimeException('An intended tournament slot disappeared.');
        }
        foreach ($expected as $field => $value) {
            $actual = $current->{$field};
            if ($field === 'game_date' && $value !== null) {
                $date = substr((string) $value, 0, 10);
                $matches = in_array($actual, [$date, $date.' 00:00:00'], true);
            } else {
                $matches = $value === null ? $actual === null : $actual !== null && (string) $actual === (string) $value;
            }
            if (! $matches) {
                throw new RuntimeException('Tournament slot differs from its requested identity or values.');
            }
        }
    }

    private function assertDeletable(Connection $connection, Game $game): void
    {
        if ($game->getConnection() !== $connection || $game->getRawOriginal('sport_event_id') !== null) {
            throw new RuntimeException('Linked tournament identity must be retained.');
        }
        $this->assertSlot($connection, $game->id, array_intersect_key($game->getRawOriginal(), array_flip([
            'espn_event_id', 'sport_event_id', 'season', 'season_type', 'is_ncaa_tournament',
            'tournament_round', 'tournament_region', 'home_seed', 'away_seed',
        ])));
        foreach (['cbb_plays', 'cbb_player_stats', 'cbb_team_stats', 'cbb_predictions', 'cbb_player_props', 'cbb_elo_ratings'] as $table) {
            if ($connection->table($table)->useWritePdo()->where('game_id', $game->id)->exists()) {
                throw new RuntimeException('Tournament placeholder has retained game history.');
            }
        }
        if ($connection->table('cbb_tournament_state_snapshots')->useWritePdo()->where('trigger_game_id', $game->id)->exists()) {
            throw new RuntimeException('Tournament placeholder has retained tournament history.');
        }
        foreach (['game_odds_snapshots', 'bet_decisions'] as $table) {
            if ($connection->table($table)->useWritePdo()->where('sport', 'cbb')->where('game_table', 'cbb_games')->where('game_id', $game->id)->exists()) {
                throw new RuntimeException('Tournament placeholder has retained typed game evidence.');
            }
        }
        if ($connection->table('prediction_feature_snapshots')->useWritePdo()->where('sport', 'cbb')
            ->where('prediction_table', 'cbb_predictions')->where('game_id', $game->id)->exists()) {
            throw new RuntimeException('Tournament placeholder has retained prediction evidence.');
        }
        if ($connection->getSchemaBuilder()->hasTable('player_props')
            && $connection->table('player_props')->useWritePdo()->whereIn('gameable_type', array_unique([Game::class, $game->getMorphClass()]))
                ->where('gameable_id', $game->id)->exists()) {
            throw new RuntimeException('Tournament placeholder has retained legacy prop evidence.');
        }
    }

    private function buildPlaceholderAttributes(
        Collection $games,
        Collection $teams,
        int $season,
        string $region,
        int $highSeed,
        int $lowSeed,
    ): array {
        $template = $this->regionTemplate($games, $region);
        $home = $this->resolveParticipant($games, $teams, $season, $region, $highSeed);
        $away = $this->resolveParticipant($games, $teams, $season, $region, $lowSeed);

        return [
            'espn_uid' => $this->placeholderUid($season, $region, $highSeed, $lowSeed),
            'season' => $season,
            'week' => (int) ($template?->week ?? 1),
            'season_type' => (int) config('cbb.season.types.postseason'),
            'game_date' => $template?->game_date ?? now()->setDate($season, 3, 18)->toDateString(),
            'game_time' => $template?->game_time ?? '23:00:00',
            'name' => "{$away['display_name']} at {$home['display_name']}",
            'short_name' => "{$away['abbreviation']} @ {$home['abbreviation']}",
            'home_team_id' => $home['team_id'],
            'away_team_id' => $away['team_id'],
            'home_team_display_name' => $home['team_id'] ? null : $home['display_name'],
            'away_team_display_name' => $away['team_id'] ? null : $away['display_name'],
            'home_team_abbreviation' => $home['team_id'] ? null : $home['abbreviation'],
            'away_team_abbreviation' => $away['team_id'] ? null : $away['abbreviation'],
            'status' => config('cbb.statuses.scheduled'),
            'period' => null,
            'game_clock' => null,
            'home_score' => null,
            'away_score' => null,
            'home_linescores' => null,
            'away_linescores' => null,
            'is_ncaa_tournament' => true,
            'tournament_note' => 'System-generated placeholder to complete NCAA tournament structure.',
            'tournament_id' => null,
            'tournament_round' => 'round_of_64',
            'tournament_region' => $region,
            'home_seed' => $highSeed,
            'away_seed' => $lowSeed,
            'play_in_target_seed' => null,
            'venue_name' => $template?->venue_name,
            'venue_city' => $template?->venue_city,
            'venue_state' => $template?->venue_state,
            'broadcast_networks' => null,
        ];
    }

    private function resolveParticipant(
        Collection $games,
        Collection $teams,
        int $season,
        string $region,
        int $seed,
    ): array {
        $firstFourGame = $games
            ->where('tournament_round', 'first_four')
            ->where('tournament_region', $region)
            ->first(fn (Game $game) => (int) ($game->play_in_target_seed ?? 0) === $seed);

        if ($firstFourGame) {
            return $this->resolveFirstFourParticipant($firstFourGame);
        }

        $fallback = config("cbb_bracket.season_fallbacks.{$season}.{$region}.{$seed}");
        if (! is_array($fallback)) {
            return [
                'team_id' => null,
                'display_name' => "({$seed}) TBD",
                'abbreviation' => 'TBD',
            ];
        }

        $team = $this->findTeamForFallback($teams, (string) ($fallback['name'] ?? ''), (string) ($fallback['abbreviation'] ?? ''));

        if ($team) {
            return [
                'team_id' => (int) $team->id,
                'display_name' => $this->teamDisplayName($team),
                'abbreviation' => (string) $team->abbreviation,
            ];
        }

        return [
            'team_id' => null,
            'display_name' => (string) ($fallback['name'] ?? "({$seed}) TBD"),
            'abbreviation' => (string) ($fallback['abbreviation'] ?? 'TBD'),
        ];
    }

    private function resolveFirstFourParticipant(Game $game): array
    {
        if ($game->status === config('cbb.statuses.final')) {
            $winner = $this->winningParticipant($game);
            if ($winner !== null) {
                return $winner;
            }
        }

        $away = $this->gameParticipant($game, 'away');
        $home = $this->gameParticipant($game, 'home');

        return [
            'team_id' => null,
            'display_name' => "Winner of {$away['display_name']} / {$home['display_name']}",
            'abbreviation' => 'WFF',
        ];
    }

    private function winningParticipant(Game $game): ?array
    {
        if ($game->home_score === null || $game->away_score === null || $game->home_score === $game->away_score) {
            return null;
        }

        return $game->home_score > $game->away_score
            ? $this->gameParticipant($game, 'home')
            : $this->gameParticipant($game, 'away');
    }

    private function gameParticipant(Game $game, string $side): array
    {
        $team = $side === 'home' ? $game->homeTeam : $game->awayTeam;
        $display = $side === 'home' ? $game->home_team_display_name : $game->away_team_display_name;
        $abbr = $side === 'home' ? $game->home_team_abbreviation : $game->away_team_abbreviation;

        if ($team) {
            return [
                'team_id' => (int) $team->id,
                'display_name' => $this->teamDisplayName($team),
                'abbreviation' => (string) $team->abbreviation,
            ];
        }

        return [
            'team_id' => null,
            'display_name' => $display ?: 'TBD',
            'abbreviation' => $abbr ?: 'TBD',
        ];
    }

    private function findTeamForFallback(Collection $teams, string $name, string $abbreviation): ?Team
    {
        $normalizedTarget = $this->normalize($name);

        return $teams->first(function (Team $team) use ($normalizedTarget, $abbreviation) {
            $fullName = $this->normalize($this->teamDisplayName($team));

            return ($abbreviation !== '' && strcasecmp((string) $team->abbreviation, $abbreviation) === 0)
                || $fullName === $normalizedTarget;
        });
    }

    private function teamDisplayName(Team $team): string
    {
        return trim($team->school.' '.$team->mascot);
    }

    private function normalize(string $value): string
    {
        return preg_replace('/[^a-z0-9]+/', '', strtolower($value)) ?? '';
    }

    private function regionTemplate(Collection $games, string $region): ?Game
    {
        return $games
            ->where('tournament_region', $region)
            ->where('tournament_round', 'round_of_64')
            ->sortBy(['game_date', 'game_time', 'id'])
            ->first();
    }

    private function gamesForPair(Collection $games, string $region, int $seedA, int $seedB): Collection
    {
        return $games
            ->where('tournament_region', $region)
            ->where('tournament_round', 'round_of_64')
            ->filter(function (Game $game) use ($seedA, $seedB) {
                $homeSeed = (int) ($game->home_seed ?? 0);
                $awaySeed = (int) ($game->away_seed ?? 0);

                return [$homeSeed, $awaySeed] === [$seedA, $seedB]
                    || [$homeSeed, $awaySeed] === [$seedB, $seedA];
            })
            ->values();
    }

    private function isPlaceholderGame(Game $game): bool
    {
        return str_starts_with((string) $game->espn_event_id, 'placeholder:');
    }

    private function placeholderEventId(int $season, string $region, int $highSeed, int $lowSeed): string
    {
        return sprintf('placeholder:%d:%s:%d-%d', $season, strtolower($region), $highSeed, $lowSeed);
    }

    private function placeholderUid(int $season, string $region, int $highSeed, int $lowSeed): string
    {
        return sprintf('placeholder:cbb:%d:%s:%d-%d', $season, strtolower($region), $highSeed, $lowSeed);
    }
}
