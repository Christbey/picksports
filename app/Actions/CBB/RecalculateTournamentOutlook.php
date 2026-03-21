<?php

namespace App\Actions\CBB;

use App\Models\CBB\Game;
use App\Models\CBB\Team;
use App\Models\CBB\TournamentForecast;
use App\Models\CBB\TournamentStateSnapshot;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

class RecalculateTournamentOutlook
{
    private const REGION_NAMES = ['East', 'West', 'South', 'Midwest'];

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

    /** @var array<string, float> */
    private array $probabilityCache = [];

    /** @var array<string, int|null> */
    private array $teamResolutionCache = [];

    public function __construct(
        private readonly GeneratePrediction $generatePrediction,
    ) {}

    public function execute(
        int $season,
        string $source = 'manual',
        ?int $triggerGameId = null,
        ?\DateTimeInterface $asOf = null,
    ): TournamentStateSnapshot {
        $asOf ??= now();
        $games = $this->tournamentGames($season);
        $field = $this->actualField($games, $season);
        $actualFieldByTeam = $field['teams'];
        $placeholderFieldRows = $field['placeholders'];

        $snapshot = TournamentStateSnapshot::query()->create([
            'season' => $season,
            'as_of' => $asOf,
            'source' => $source,
            'status' => 'running',
            'trigger_game_id' => $triggerGameId,
            'games_final_count' => $games->where('status', config('cbb.statuses.final'))->count(),
            'games_remaining_count' => $games->where('status', '!=', config('cbb.statuses.final'))->count(),
            'field_size' => count($actualFieldByTeam) + count($placeholderFieldRows),
            'metadata' => null,
        ]);

        try {
            $rows = $this->buildOutlookRows($games, $season, $actualFieldByTeam, $placeholderFieldRows, $snapshot);

            DB::transaction(function () use ($snapshot, $rows): void {
                TournamentForecast::query()
                    ->where('snapshot_id', $snapshot->id)
                    ->delete();

                TournamentForecast::query()->insert($rows);

                $snapshot->forceFill([
                    'status' => 'completed',
                ])->save();
            });
        } catch (Throwable $e) {
            $snapshot->forceFill([
                'status' => 'failed',
                'notes' => $e->getMessage(),
                'metadata' => ['exception' => $e::class],
            ])->save();

            throw $e;
        }

        return $snapshot->fresh(['forecasts.team']);
    }

    private function tournamentGames(int $season): Collection
    {
        return Game::query()
            ->with(['homeTeam', 'awayTeam', 'prediction'])
            ->where('season', $season)
            ->where('season_type', (int) config('cbb.season.types.postseason'))
            ->where('is_ncaa_tournament', true)
            ->whereIn('tournament_round', [
                'first_four',
                'round_of_64',
                'round_of_32',
                'sweet_16',
                'elite_8',
                'final_four',
                'national_championship',
            ])
            ->get();
    }

    /**
     * @param  array<int, array{seed:int|null,region:?string,round:?string,is_first_four:bool}>  $actualFieldByTeam
     * @param  array<int, array{placeholder_key:string,team_display_name:string,team_abbreviation:string,seed:int|null,region:?string,round:?string,is_first_four:bool}>  $placeholderFieldRows
     * @return array<int, array<string, mixed>>
     */
    private function buildOutlookRows(
        Collection $games,
        int $season,
        array $actualFieldByTeam,
        array $placeholderFieldRows,
        TournamentStateSnapshot $snapshot,
    ): array {
        $aliveTeamIds = array_diff(array_keys($actualFieldByTeam), array_keys($this->eliminatedByTeam($games)));
        $eliminatedByTeam = $this->eliminatedByTeam($games);
        $reachedRoundByTeam = $this->reachedRoundByTeam($games, $actualFieldByTeam);
        $regionDistributions = [];
        $roundProbabilities = [];

        foreach (array_keys($actualFieldByTeam) as $teamId) {
            $roundProbabilities[$teamId] = [
                'round_of_32_probability' => 0.0,
                'sweet_16_probability' => 0.0,
                'elite_8_probability' => 0.0,
                'final_four_probability' => 0.0,
                'title_game_probability' => 0.0,
                'champion_probability' => 0.0,
            ];
        }

        foreach (self::REGION_NAMES as $regionName) {
            $region = $this->regionState($games, $regionName, $season);
            $regionDistributions[$regionName] = $region;

            foreach ($region['round_of_32'] as $distribution) {
                foreach ($distribution as $teamId => $probability) {
                    $roundProbabilities[$teamId]['round_of_32_probability'] += $probability;
                }
            }
            foreach ($region['sweet_16'] as $distribution) {
                foreach ($distribution as $teamId => $probability) {
                    $roundProbabilities[$teamId]['sweet_16_probability'] += $probability;
                }
            }
            foreach ($region['elite_8'] as $distribution) {
                foreach ($distribution as $teamId => $probability) {
                    $roundProbabilities[$teamId]['elite_8_probability'] += $probability;
                }
            }
            foreach (($region['champion'] ?? []) as $teamId => $probability) {
                $roundProbabilities[$teamId]['final_four_probability'] += $probability;
            }
        }

        $finalFourGames = $games->where('tournament_round', 'final_four')->sortBy('id')->values();
        $semifinalOne = $this->playMatchup(
            $finalFourGames->get(0),
            $regionDistributions['East']['champion'] ?? [],
            $regionDistributions['West']['champion'] ?? [],
            $season,
        );
        $semifinalTwo = $this->playMatchup(
            $finalFourGames->get(1),
            $regionDistributions['South']['champion'] ?? [],
            $regionDistributions['Midwest']['champion'] ?? [],
            $season,
        );

        foreach ([$semifinalOne, $semifinalTwo] as $distribution) {
            foreach ($distribution as $teamId => $probability) {
                $roundProbabilities[$teamId]['title_game_probability'] += $probability;
            }
        }

        $championship = $this->playMatchup(
            $games->firstWhere('tournament_round', 'national_championship'),
            $semifinalOne,
            $semifinalTwo,
            $season,
        );

        foreach ($championship as $teamId => $probability) {
            $roundProbabilities[$teamId]['champion_probability'] += $probability;
        }

        $rows = [];
        foreach ($actualFieldByTeam as $teamId => $field) {
            $isAlive = in_array($teamId, $aliveTeamIds, true);

            $rows[] = [
                'snapshot_id' => $snapshot->id,
                'placeholder_key' => '',
                'season' => $season,
                'team_id' => $teamId,
                'as_of' => $snapshot->as_of,
                'mode' => 'live',
                'region' => $field['region'],
                'seed' => $field['seed'],
                'team_display_name' => null,
                'team_abbreviation' => null,
                'is_first_four' => $field['is_first_four'],
                'is_alive' => $isAlive,
                'is_eliminated' => ! $isAlive,
                'reached_round' => $reachedRoundByTeam[$teamId] ?? $field['round'],
                'eliminated_round' => $eliminatedByTeam[$teamId] ?? null,
                'selection_score' => 0,
                'projected_seed' => $field['seed'],
                'auto_bid' => false,
                'auto_bid_probability' => 0,
                'at_large_probability' => 0,
                'first_four_probability' => $field['is_first_four'] ? 1 : 0,
                'first_four_auto_probability' => 0,
                'first_four_at_large_probability' => 0,
                'bid_thief_probability' => 0,
                'tournament_make_probability' => 1,
                'round_of_32_probability' => round($isAlive ? (float) $roundProbabilities[$teamId]['round_of_32_probability'] : 0.0, 5),
                'sweet_16_probability' => round($isAlive ? (float) $roundProbabilities[$teamId]['sweet_16_probability'] : 0.0, 5),
                'elite_8_probability' => round($isAlive ? (float) $roundProbabilities[$teamId]['elite_8_probability'] : 0.0, 5),
                'final_four_probability' => round($isAlive ? (float) $roundProbabilities[$teamId]['final_four_probability'] : 0.0, 5),
                'title_game_probability' => round($isAlive ? (float) $roundProbabilities[$teamId]['title_game_probability'] : 0.0, 5),
                'champion_probability' => round($isAlive ? (float) $roundProbabilities[$teamId]['champion_probability'] : 0.0, 5),
                'games_final_count' => $snapshot->games_final_count,
                'simulated_field_appearances' => 0,
                'simulated_titles' => 0,
                'simulation_runs' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        foreach ($placeholderFieldRows as $field) {
            $rows[] = [
                'snapshot_id' => $snapshot->id,
                'placeholder_key' => $field['placeholder_key'],
                'season' => $season,
                'team_id' => null,
                'as_of' => $snapshot->as_of,
                'mode' => 'live',
                'region' => $field['region'],
                'seed' => $field['seed'],
                'team_display_name' => $field['team_display_name'],
                'team_abbreviation' => $field['team_abbreviation'],
                'is_first_four' => $field['is_first_four'],
                'is_alive' => true,
                'is_eliminated' => false,
                'reached_round' => $field['round'],
                'eliminated_round' => null,
                'selection_score' => 0,
                'projected_seed' => $field['seed'],
                'auto_bid' => false,
                'auto_bid_probability' => 0,
                'at_large_probability' => 0,
                'first_four_probability' => $field['is_first_four'] ? 1 : 0,
                'first_four_auto_probability' => 0,
                'first_four_at_large_probability' => 0,
                'bid_thief_probability' => 0,
                'tournament_make_probability' => 1,
                'round_of_32_probability' => 0,
                'sweet_16_probability' => 0,
                'elite_8_probability' => 0,
                'final_four_probability' => 0,
                'title_game_probability' => 0,
                'champion_probability' => 0,
                'games_final_count' => $snapshot->games_final_count,
                'simulated_field_appearances' => 0,
                'simulated_titles' => 0,
                'simulation_runs' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        return $rows;
    }

    /**
     * @return array{
     *   round_of_32: array<int, array<int, float>>,
     *   sweet_16: array<int, array<int, float>>,
     *   elite_8: array<int, array<int, float>>,
     *   champion: array<int, float>
     * }
     */
    private function regionState(Collection $games, string $regionName, int $season): array
    {
        $roundGames = [
            'round_of_32' => $games->where('tournament_region', $regionName)->where('tournament_round', 'round_of_32')->values(),
            'sweet_16' => $games->where('tournament_region', $regionName)->where('tournament_round', 'sweet_16')->values(),
            'elite_8' => $games->where('tournament_region', $regionName)->where('tournament_round', 'elite_8')->values(),
        ];

        $roundOf32 = [];
        foreach (self::ROUND_OF_64_SEED_PAIRINGS as [$highSeed, $lowSeed]) {
            $roundOf32[] = $this->roundOf64Distribution($games, $regionName, $highSeed, $lowSeed, $season);
        }

        $sweet16 = $this->advanceRound($roundOf32, $roundGames['round_of_32'], $season);
        $elite8 = $this->advanceRound($sweet16, $roundGames['sweet_16'], $season);
        $champion = $this->advanceRound($elite8, $roundGames['elite_8'], $season)[0] ?? [];

        return [
            'round_of_32' => $roundOf32,
            'sweet_16' => $sweet16,
            'elite_8' => $elite8,
            'champion' => $champion,
        ];
    }

    /**
     * @return array<int, float>
     */
    private function roundOf64Distribution(Collection $games, string $regionName, int $highSeed, int $lowSeed, int $season): array
    {
        $game = $this->findGameBySeeds($games, $regionName, 'round_of_64', $highSeed, $lowSeed);
        $left = $this->seedSlotDistribution($games, $regionName, $highSeed, $season);
        $right = $this->seedSlotDistribution($games, $regionName, $lowSeed, $season);

        return $this->playMatchup($game, $left, $right, $season);
    }

    /**
     * @param  array<int, array<int, float>>  $previousRound
     * @return array<int, array<int, float>>
     */
    private function advanceRound(array $previousRound, Collection $candidateGames, int $season): array
    {
        $advanced = [];
        $usedGameIds = [];
        $matchupCount = (int) ceil(count($previousRound) / 2);

        for ($index = 0; $index < $matchupCount; $index++) {
            $left = $previousRound[$index * 2] ?? [];
            $right = $previousRound[($index * 2) + 1] ?? [];
            $game = $this->matchActualGame($candidateGames, $left, $right, $usedGameIds);

            if ($game) {
                $usedGameIds[] = $game->id;
            }

            $advanced[] = $this->playMatchup($game, $left, $right, $season);
        }

        return $advanced;
    }

    /**
     * @return array<int, float>
     */
    private function seedSlotDistribution(Collection $games, string $regionName, int $seed, int $season): array
    {
        $firstFourGame = $this->findFirstFourGame($games, $regionName, $seed);
        if ($firstFourGame) {
            return $this->playMatchup(
                $firstFourGame,
                $this->teamDistribution($firstFourGame->home_team_id),
                $this->teamDistribution($firstFourGame->away_team_id),
                $season,
            );
        }

        return $this->teamDistribution($this->findTeamIdForRegionSeed($games, $regionName, $seed, $season));
    }

    /**
     * @param  array<int, float>  $left
     * @param  array<int, float>  $right
     * @return array<int, float>
     */
    private function playMatchup(?Game $game, array $left, array $right, int $season): array
    {
        if ($left === []) {
            return $right;
        }

        if ($right === []) {
            return $left;
        }

        if ($game && $game->status === config('cbb.statuses.final')) {
            $winnerId = $this->finalWinnerTeamId($game);

            return $winnerId !== null ? [$winnerId => 1.0] : [];
        }

        $distribution = [];
        foreach ($left as $leftTeamId => $leftProbability) {
            foreach ($right as $rightTeamId => $rightProbability) {
                $meetingProbability = $leftProbability * $rightProbability;
                $leftWinProbability = $this->matchupWinProbability($game, $leftTeamId, $rightTeamId, $season);

                $distribution[$leftTeamId] = ($distribution[$leftTeamId] ?? 0.0) + ($meetingProbability * $leftWinProbability);
                $distribution[$rightTeamId] = ($distribution[$rightTeamId] ?? 0.0) + ($meetingProbability * (1 - $leftWinProbability));
            }
        }

        return $distribution;
    }

    private function matchupWinProbability(?Game $game, int $leftTeamId, int $rightTeamId, int $season): float
    {
        if ($game && $game->prediction) {
            $homeId = (int) ($game->home_team_id ?? 0);
            $awayId = (int) ($game->away_team_id ?? 0);
            $homeWinProbability = (float) $game->prediction->win_probability;

            if ($homeId === $leftTeamId && $awayId === $rightTeamId) {
                return $homeWinProbability;
            }

            if ($homeId === $rightTeamId && $awayId === $leftTeamId) {
                return 1 - $homeWinProbability;
            }
        }

        return $this->syntheticWinProbability($leftTeamId, $rightTeamId, $season);
    }

    private function syntheticWinProbability(int $homeTeamId, int $awayTeamId, int $season): float
    {
        $cacheKey = "{$season}:{$homeTeamId}:{$awayTeamId}";
        if (array_key_exists($cacheKey, $this->probabilityCache)) {
            return $this->probabilityCache[$cacheKey];
        }

        $homeTeam = Team::query()->find($homeTeamId);
        $awayTeam = Team::query()->find($awayTeamId);

        if (! $homeTeam || ! $awayTeam) {
            return 0.5;
        }

        $game = new Game([
            'season' => $season,
            'status' => config('cbb.statuses.scheduled'),
            'game_date' => now()->toDateString(),
            'game_time' => '18:00:00',
            'home_team_id' => $homeTeamId,
            'away_team_id' => $awayTeamId,
        ]);
        $game->setRelation('homeTeam', $homeTeam);
        $game->setRelation('awayTeam', $awayTeam);

        try {
            $preview = $this->generatePrediction->preview($game);
        } catch (Throwable) {
            $preview = null;
        }

        $probability = (float) ($preview['win_probability'] ?? 0.5);
        $this->probabilityCache[$cacheKey] = $probability;

        return $probability;
    }

    /**
     * @return array{
     *   teams: array<int, array{seed:int|null,region:?string,round:?string,is_first_four:bool}>,
     *   placeholders: array<int, array{placeholder_key:string,team_display_name:string,team_abbreviation:string,seed:int|null,region:?string,round:?string,is_first_four:bool}>
     * }
     */
    private function actualField(Collection $games, int $season): array
    {
        $field = [];
        $placeholders = [];

        foreach ($games->whereIn('tournament_round', ['first_four', 'round_of_64']) as $game) {
            foreach ([
                [
                    'side' => 'home',
                    'team_id' => $game->home_team_id,
                    'seed' => $game->home_seed,
                    'display_name' => $game->home_team_display_name,
                    'abbreviation' => $game->home_team_abbreviation,
                ],
                [
                    'side' => 'away',
                    'team_id' => $game->away_team_id,
                    'seed' => $game->away_seed,
                    'display_name' => $game->away_team_display_name,
                    'abbreviation' => $game->away_team_abbreviation,
                ],
            ] as $participant) {
                $teamId = (int) ($participant['team_id'] ?? 0);
                $replacement = [
                    'seed' => $participant['seed'] !== null ? (int) $participant['seed'] : null,
                    'region' => $game->tournament_region,
                    'round' => $game->tournament_round,
                    'is_first_four' => $game->tournament_round === 'first_four',
                ];

                if ($teamId <= 0) {
                    $placeholder = $this->placeholderFromParticipant($season, $game, $participant, $replacement);
                    if ($placeholder !== null) {
                        $placeholders[$placeholder['placeholder_key']] = $placeholder;
                    }

                    continue;
                }

                $current = $field[$teamId] ?? null;

                if ($current === null || ($current['is_first_four'] && ! $replacement['is_first_four'])) {
                    $field[$teamId] = $replacement;
                }
            }
        }

        foreach ((array) config("cbb_bracket.season_fallbacks.{$season}", []) as $region => $seeds) {
            foreach ($seeds as $seed => $fallback) {
                $teamId = $this->resolveFallbackTeamId($fallback);
                if ($teamId !== null) {
                    if (isset($field[$teamId])) {
                        continue;
                    }

                    $field[$teamId] = [
                        'seed' => (int) $seed,
                        'region' => $region,
                        'round' => 'round_of_64',
                        'is_first_four' => false,
                    ];

                    continue;
                }

                $placeholderKey = "fallback:{$season}:{$region}:{$seed}";
                $placeholders[$placeholderKey] = [
                    'placeholder_key' => $placeholderKey,
                    'team_display_name' => trim((string) ($fallback['name'] ?? 'TBD')) ?: 'TBD',
                    'team_abbreviation' => trim((string) ($fallback['abbreviation'] ?? 'TBD')) ?: 'TBD',
                    'seed' => (int) $seed,
                    'region' => $region,
                    'round' => 'round_of_64',
                    'is_first_four' => false,
                ];
            }
        }

        return [
            'teams' => $field,
            'placeholders' => array_values($placeholders),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function eliminatedByTeam(Collection $games): array
    {
        $eliminated = [];

        foreach ($games as $game) {
            if ($game->status !== config('cbb.statuses.final')) {
                continue;
            }

            $homeTeamId = (int) ($game->home_team_id ?? 0);
            $awayTeamId = (int) ($game->away_team_id ?? 0);

            if ($homeTeamId <= 0 || $awayTeamId <= 0) {
                continue;
            }

            if (($game->home_score ?? null) > ($game->away_score ?? null)) {
                $eliminated[$awayTeamId] = (string) $game->tournament_round;
            } elseif (($game->away_score ?? null) > ($game->home_score ?? null)) {
                $eliminated[$homeTeamId] = (string) $game->tournament_round;
            }
        }

        return $eliminated;
    }

    /**
     * @param  array<int, array{seed:int|null,region:?string,round:?string,is_first_four:bool}>  $actualFieldByTeam
     * @return array<int, string>
     */
    private function reachedRoundByTeam(Collection $games, array $actualFieldByTeam): array
    {
        $order = [
            'first_four' => 1,
            'round_of_64' => 2,
            'round_of_32' => 3,
            'sweet_16' => 4,
            'elite_8' => 5,
            'final_four' => 6,
            'national_championship' => 7,
        ];
        $reached = [];

        foreach ($actualFieldByTeam as $teamId => $field) {
            $reached[$teamId] = (string) ($field['round'] ?? 'round_of_64');
        }

        foreach ($games as $game) {
            foreach ([(int) ($game->home_team_id ?? 0), (int) ($game->away_team_id ?? 0)] as $teamId) {
                if ($teamId <= 0) {
                    continue;
                }

                $current = $reached[$teamId] ?? 'round_of_64';
                $candidate = (string) ($game->tournament_round ?? $current);

                if (($order[$candidate] ?? 0) > ($order[$current] ?? 0)) {
                    $reached[$teamId] = $candidate;
                }
            }
        }

        return $reached;
    }

    private function findGameBySeeds(Collection $games, string $regionName, string $roundKey, int $seedA, int $seedB): ?Game
    {
        return $games
            ->where('tournament_region', $regionName)
            ->where('tournament_round', $roundKey)
            ->first(function (Game $game) use ($seedA, $seedB) {
                $homeSeed = (int) ($game->home_seed ?? 0);
                $awaySeed = (int) ($game->away_seed ?? 0);

                return [$homeSeed, $awaySeed] === [$seedA, $seedB]
                    || [$homeSeed, $awaySeed] === [$seedB, $seedA];
            });
    }

    private function findFirstFourGame(Collection $games, string $regionName, int $seed): ?Game
    {
        return $games
            ->where('tournament_region', $regionName)
            ->where('tournament_round', 'first_four')
            ->first(fn (Game $game) => (int) ($game->play_in_target_seed ?? 0) === $seed);
    }

    private function findTeamIdForRegionSeed(Collection $games, string $regionName, int $seed, int $season): ?int
    {
        $roundOf64Game = $games
            ->where('tournament_region', $regionName)
            ->where('tournament_round', 'round_of_64')
            ->first(function (Game $game) use ($seed) {
                return (int) ($game->home_seed ?? 0) === $seed || (int) ($game->away_seed ?? 0) === $seed;
            });

        if ($roundOf64Game) {
            if ((int) ($roundOf64Game->home_seed ?? 0) === $seed && $roundOf64Game->home_team_id) {
                return (int) $roundOf64Game->home_team_id;
            }

            if ((int) ($roundOf64Game->away_seed ?? 0) === $seed && $roundOf64Game->away_team_id) {
                return (int) $roundOf64Game->away_team_id;
            }
        }

        $fallback = config("cbb_bracket.season_fallbacks.{$season}.{$regionName}.{$seed}");

        return is_array($fallback) ? $this->resolveFallbackTeamId($fallback) : null;
    }

    /**
     * @param  array<int, float>  $left
     * @param  array<int, float>  $right
     * @param  array<int, int>  $usedGameIds
     */
    private function matchActualGame(Collection $candidateGames, array $left, array $right, array $usedGameIds): ?Game
    {
        if ($left === [] || $right === []) {
            return null;
        }

        $leftIds = array_keys($left);
        $rightIds = array_keys($right);

        return $candidateGames->first(function (Game $game) use ($leftIds, $rightIds, $usedGameIds) {
            if (in_array($game->id, $usedGameIds, true)) {
                return false;
            }

            $homeId = (int) ($game->home_team_id ?? 0);
            $awayId = (int) ($game->away_team_id ?? 0);

            if ($homeId <= 0 || $awayId <= 0) {
                return false;
            }

            return (in_array($homeId, $leftIds, true) && in_array($awayId, $rightIds, true))
                || (in_array($homeId, $rightIds, true) && in_array($awayId, $leftIds, true));
        });
    }

    /**
     * @return array<int, float>
     */
    private function teamDistribution(?int $teamId): array
    {
        return $teamId ? [$teamId => 1.0] : [];
    }

    private function finalWinnerTeamId(Game $game): ?int
    {
        $homeTeamId = (int) ($game->home_team_id ?? 0);
        $awayTeamId = (int) ($game->away_team_id ?? 0);

        if ($homeTeamId <= 0 || $awayTeamId <= 0) {
            return null;
        }

        if (($game->home_score ?? null) > ($game->away_score ?? null)) {
            return $homeTeamId;
        }

        if (($game->away_score ?? null) > ($game->home_score ?? null)) {
            return $awayTeamId;
        }

        return null;
    }

    /**
     * @param  array{side:string,team_id:mixed,seed:mixed,display_name:mixed,abbreviation:mixed}  $participant
     * @param  array{seed:int|null,region:?string,round:?string,is_first_four:bool}  $replacement
     * @return array{placeholder_key:string,team_display_name:string,team_abbreviation:string,seed:int|null,region:?string,round:?string,is_first_four:bool}|null
     */
    private function placeholderFromParticipant(int $season, Game $game, array $participant, array $replacement): ?array
    {
        $displayName = trim((string) ($participant['display_name'] ?? ''));
        $abbreviation = trim((string) ($participant['abbreviation'] ?? ''));

        if ($displayName === '' && $abbreviation === '') {
            return null;
        }

        $seedPart = $replacement['seed'] !== null ? (string) $replacement['seed'] : 'na';
        $sidePart = (string) ($participant['side'] ?? 'slot');
        $placeholderKey = "game-slot:{$season}:{$game->id}:{$sidePart}:{$seedPart}";

        return [
            'placeholder_key' => $placeholderKey,
            'team_display_name' => $displayName !== '' ? $displayName : ($abbreviation !== '' ? $abbreviation : 'TBD'),
            'team_abbreviation' => $abbreviation !== '' ? $abbreviation : 'TBD',
            'seed' => $replacement['seed'],
            'region' => $replacement['region'],
            'round' => $replacement['round'],
            'is_first_four' => $replacement['is_first_four'],
        ];
    }

    /**
     * @param  array{name?:mixed,abbreviation?:mixed}  $fallback
     */
    private function resolveFallbackTeamId(array $fallback): ?int
    {
        $cacheKey = sprintf('%s|%s', (string) ($fallback['abbreviation'] ?? ''), trim((string) ($fallback['name'] ?? '')));
        if (array_key_exists($cacheKey, $this->teamResolutionCache)) {
            return $this->teamResolutionCache[$cacheKey];
        }

        $team = null;
        $abbreviation = (string) ($fallback['abbreviation'] ?? '');
        $name = trim((string) ($fallback['name'] ?? ''));

        if ($abbreviation !== '') {
            $team = Team::query()->where('abbreviation', $abbreviation)->first();
        }

        if (! $team && $name !== '') {
            $team = Team::query()
                ->whereRaw("TRIM(CONCAT(school, ' ', mascot)) = ?", [$name])
                ->first();
        }

        $teamId = $team?->id ? (int) $team->id : null;
        $this->teamResolutionCache[$cacheKey] = $teamId;

        return $teamId;
    }
}
