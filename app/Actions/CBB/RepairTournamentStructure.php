<?php

namespace App\Actions\CBB;

use App\Models\CBB\Game;
use App\Models\CBB\Team;
use Illuminate\Support\Collection;

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
        $games = Game::query()
            ->where('season', $season)
            ->where('season_type', (int) config('cbb.season.types.postseason'))
            ->where('is_ncaa_tournament', true)
            ->orderBy('id')
            ->get();

        $teams = Team::query()
            ->select(['id', 'espn_id', 'school', 'mascot', 'abbreviation', 'logo_url'])
            ->get();

        $createdOrUpdated = 0;

        foreach (self::REGIONS as $region) {
            foreach (self::ROUND_OF_64_SEED_PAIRINGS as [$highSeed, $lowSeed]) {
                $placeholderEventId = $this->placeholderEventId($season, $region, $highSeed, $lowSeed);
                $pairGames = $this->gamesForPair($games, $region, $highSeed, $lowSeed);
                $realGame = $pairGames->first(fn (Game $game) => ! $this->isPlaceholderGame($game));
                $placeholderGame = $pairGames->first(fn (Game $game) => $this->isPlaceholderGame($game));

                if ($realGame) {
                    if ($placeholderGame) {
                        $placeholderGame->delete();
                    }

                    continue;
                }

                $attributes = $this->buildPlaceholderAttributes($games, $teams, $season, $region, $highSeed, $lowSeed);
                Game::query()->updateOrCreate(['espn_event_id' => $placeholderEventId], $attributes);
                $createdOrUpdated++;
            }
        }

        return $createdOrUpdated;
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
