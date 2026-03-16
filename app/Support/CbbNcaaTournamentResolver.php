<?php

namespace App\Support;

use App\Models\CBB\Game;

class CbbNcaaTournamentResolver
{
    /**
     * ESPN tournament ids observed for the NCAA men's basketball championship bracket.
     *
     * @var array<int>
     */
    protected const NCAA_TOURNAMENT_IDS = [22];

    /**
     * @param  array<string, mixed>  $eventData
     * @return array{
     *   is_ncaa_tournament: bool,
     *   tournament_id: ?int,
     *   tournament_note: ?string,
     *   tournament_round: ?string,
     *   tournament_region: ?string,
     *   home_seed: ?int,
     *   away_seed: ?int,
     *   play_in_target_seed: ?int
     * }
     */
    public function resolveFromEspnEvent(array $eventData): array
    {
        $competition = data_get($eventData, 'competitions.0')
            ?? data_get($eventData, 'header.competitions.0', []);
        $homeCompetitor = collect(data_get($competition, 'competitors', []))->firstWhere('homeAway', 'home') ?? [];
        $awayCompetitor = collect(data_get($competition, 'competitors', []))->firstWhere('homeAway', 'away') ?? [];
        $tournamentId = $this->intOrNull(data_get($competition, 'tournamentId'));
        $note = $this->firstString([
            data_get($eventData, 'header.gameNote'),
            data_get($competition, 'notes.0.headline'),
            data_get($eventData, 'gameNote'),
        ]);

        $searchText = $this->buildSearchText([
            $note,
            data_get($eventData, 'headline'),
            data_get($eventData, 'header.competitions.0.status.type.detail'),
            data_get($eventData, 'name'),
            data_get($eventData, 'shortName'),
            ...collect(data_get($eventData, 'links', []))->pluck('text')->all(),
            ...collect(data_get($eventData, 'header.links', []))->pluck('text')->all(),
        ]);

        $isNcaaTournament = $this->isNcaaTournamentEvent(
            seasonType: (int) (data_get($eventData, 'season.type')
                ?? data_get($eventData, 'header.season.type')
                ?? 2),
            tournamentId: $tournamentId,
            searchText: $searchText,
        );
        $homeSeed = $this->resolveCompetitorSeed($homeCompetitor);
        $awaySeed = $this->resolveCompetitorSeed($awayCompetitor);
        $round = $isNcaaTournament ? $this->resolveRound($searchText) : null;

        return [
            'is_ncaa_tournament' => $isNcaaTournament,
            'tournament_id' => $tournamentId,
            'tournament_note' => $note,
            'tournament_round' => $round,
            'tournament_region' => $isNcaaTournament ? $this->resolveRegion($searchText) : null,
            'home_seed' => $isNcaaTournament ? $homeSeed : null,
            'away_seed' => $isNcaaTournament ? $awaySeed : null,
            'play_in_target_seed' => $isNcaaTournament ? $this->resolvePlayInTargetSeed($round, $homeSeed, $awaySeed) : null,
        ];
    }

    /**
     * @return array{
     *   is_ncaa_tournament: bool,
     *   tournament_id: ?int,
     *   tournament_note: ?string,
     *   tournament_round: ?string,
     *   tournament_region: ?string,
     *   home_seed: ?int,
     *   away_seed: ?int,
     *   play_in_target_seed: ?int
     * }
     */
    public function resolveFromStoredGame(Game $game): array
    {
        $searchText = $this->buildSearchText([
            $game->tournament_note,
            $game->name,
            $game->short_name,
            $game->venue_name,
        ]);

        $isNcaaTournament = $this->isNcaaTournamentEvent(
            seasonType: (int) $game->season_type,
            tournamentId: $game->tournament_id,
            searchText: $searchText,
        );

        $round = $isNcaaTournament ? $this->resolveRound($searchText) : null;

        return [
            'is_ncaa_tournament' => $isNcaaTournament,
            'tournament_id' => $game->tournament_id,
            'tournament_note' => $game->tournament_note,
            'tournament_round' => $round,
            'tournament_region' => $isNcaaTournament ? $this->resolveRegion($searchText) : null,
            'home_seed' => $isNcaaTournament ? $this->intOrNull($game->home_seed) : null,
            'away_seed' => $isNcaaTournament ? $this->intOrNull($game->away_seed) : null,
            'play_in_target_seed' => $isNcaaTournament
                ? $this->resolvePlayInTargetSeed($round, $this->intOrNull($game->home_seed), $this->intOrNull($game->away_seed))
                : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $competitor
     */
    protected function resolveCompetitorSeed(array $competitor): ?int
    {
        return $this->intOrNull(
            $competitor['rank']
            ?? data_get($competitor, 'seed')
            ?? data_get($competitor, 'tournamentSeed')
            ?? data_get($competitor, 'team.rank')
        );
    }

    protected function resolvePlayInTargetSeed(?string $round, ?int $homeSeed, ?int $awaySeed): ?int
    {
        if ($round !== 'first_four') {
            return null;
        }

        if ($homeSeed !== null && $awaySeed !== null && $homeSeed === $awaySeed) {
            return $homeSeed;
        }

        return $homeSeed ?? $awaySeed;
    }

    protected function isNcaaTournamentEvent(int $seasonType, ?int $tournamentId, string $searchText): bool
    {
        if ($seasonType !== $this->postseasonSeasonType()) {
            return false;
        }

        if ($tournamentId !== null && in_array($tournamentId, self::NCAA_TOURNAMENT_IDS, true)) {
            return true;
        }

        return str_contains($searchText, 'ncaa men\'s basketball championship')
            || str_contains($searchText, 'march madness')
            || str_contains($searchText, 'tournament challenge')
            || (
                str_contains($searchText, 'region')
                && (
                    str_contains($searchText, 'first four')
                    || str_contains($searchText, '1st round')
                    || str_contains($searchText, '2nd round')
                    || str_contains($searchText, 'sweet 16')
                    || str_contains($searchText, 'elite 8')
                    || str_contains($searchText, 'final four')
                    || str_contains($searchText, 'national championship')
                )
            );
    }

    protected function resolveRound(string $searchText): ?string
    {
        if ($searchText === '') {
            return null;
        }

        if (str_contains($searchText, 'first four')) {
            return 'first_four';
        }

        if (str_contains($searchText, '1st round') || str_contains($searchText, 'first round')) {
            return 'round_of_64';
        }

        if (str_contains($searchText, '2nd round') || str_contains($searchText, 'second round')) {
            return 'round_of_32';
        }

        if (str_contains($searchText, 'sweet 16')) {
            return 'sweet_16';
        }

        if (str_contains($searchText, 'elite 8') || str_contains($searchText, 'elite eight')) {
            return 'elite_8';
        }

        if (str_contains($searchText, 'final four') || str_contains($searchText, 'semifinal')) {
            return 'final_four';
        }

        if (str_contains($searchText, 'national championship') || str_contains($searchText, 'championship game')) {
            return 'national_championship';
        }

        return null;
    }

    protected function resolveRegion(string $searchText): ?string
    {
        if (preg_match('/\b(east|west|south|midwest)\s+region\b/i', $searchText, $matches) === 1) {
            return ucfirst(strtolower($matches[1]));
        }

        return null;
    }

    /**
     * @param  array<int, mixed>  $parts
     */
    protected function buildSearchText(array $parts): string
    {
        $text = implode(' ', array_filter(array_map(
            fn (mixed $value) => is_scalar($value) ? (string) $value : null,
            $parts,
        )));
        $text = mb_strtolower($text);

        return preg_replace('/\s+/', ' ', $text) ?? '';
    }

    /**
     * @param  array<int, mixed>  $values
     */
    protected function firstString(array $values): ?string
    {
        foreach ($values as $value) {
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    protected function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    protected function postseasonSeasonType(): int
    {
        try {
            return (int) config('cbb.season.types.postseason', 3);
        } catch (\Throwable) {
            return 3;
        }
    }
}
