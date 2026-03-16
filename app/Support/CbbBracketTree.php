<?php

namespace App\Support;

use App\Models\CBB\Game;
use Illuminate\Support\Collection;

class CbbBracketTree
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

    private const ROUND_LABELS = [
        'first_four' => 'First Four',
        'round_of_64' => 'Round of 64',
        'round_of_32' => 'Round of 32',
        'sweet_16' => 'Sweet 16',
        'elite_8' => 'Elite 8',
        'final_four' => 'Final Four',
        'national_championship' => 'National Championship',
    ];

    public function build(Collection $games, array $picks = [], ?int $season = null): array
    {
        $season ??= $this->resolveSeason($games);
        $gamePayloads = $games
            ->map(fn (Game $game) => $this->mapGame($game))
            ->values();

        $firstFour = $this->buildFirstFourMatchups($gamePayloads);
        $firstFourById = collect($firstFour)->keyBy('id');
        $regions = $this->buildRegions($gamePayloads, $firstFour, $firstFourById, $picks, $season);
        $standalone = $this->buildStandaloneRounds($gamePayloads, $regions, $picks);

        return [
            'season' => $season,
            'scoring' => config('cbb_bracket.scoring'),
            'regions' => $regions,
            'standalone' => $standalone,
        ];
    }

    private function resolveSeason(Collection $games): int
    {
        $season = $games
            ->map(fn (Game $game) => (int) ($game->season ?: 0))
            ->first(fn (int $value) => $value > 0);

        return $season ?: (int) now()->year;
    }

    private function mapGame(Game $game): array
    {
        $homeTeam = $game->homeTeam;
        $awayTeam = $game->awayTeam;

        $homeName = trim(($homeTeam?->school ?? '').' '.($homeTeam?->mascot ?? '')) ?: ($game->home_team_display_name ?: 'TBD');
        $awayName = trim(($awayTeam?->school ?? '').' '.($awayTeam?->mascot ?? '')) ?: ($game->away_team_display_name ?: 'TBD');

        return [
            'id' => $game->id,
            'region' => $game->tournament_region ?: 'Unassigned',
            'roundKey' => $game->tournament_round ?: 'unassigned',
            'homeSeed' => $game->home_seed,
            'awaySeed' => $game->away_seed,
            'playInTargetSeed' => $game->play_in_target_seed,
            'status' => $game->status,
            'homeScore' => $game->home_score,
            'awayScore' => $game->away_score,
            'prediction' => $game->prediction
                ? [
                    'homeWinProbability' => (float) $game->prediction->win_probability,
                    'awayWinProbability' => round(1 - (float) $game->prediction->win_probability, 3),
                ]
                : null,
            'homeTeam' => [
                'id' => $homeTeam?->id,
                'name' => $homeName,
                'abbreviation' => $homeTeam?->abbreviation ?: ($game->home_team_abbreviation ?: 'TBD'),
                'logo' => $homeTeam?->logo_url,
            ],
            'awayTeam' => [
                'id' => $awayTeam?->id,
                'name' => $awayName,
                'abbreviation' => $awayTeam?->abbreviation ?: ($game->away_team_abbreviation ?: 'TBD'),
                'logo' => $awayTeam?->logo_url,
            ],
        ];
    }

    private function buildFirstFourMatchups(Collection $games): array
    {
        return $games
            ->where('roundKey', 'first_four')
            ->sortBy('id')
            ->map(fn (array $game) => [
                'id' => "game:{$game['id']}",
                'roundKey' => 'first_four',
                'label' => self::ROUND_LABELS['first_four'],
                'game' => $game,
                'participants' => [
                    ['participant' => $this->participantWithSeed($game['awayTeam'], $game['awaySeed'])],
                    ['participant' => $this->participantWithSeed($game['homeTeam'], $game['homeSeed'])],
                ],
            ])
            ->values()
            ->all();
    }

    private function buildRegions(
        Collection $games,
        array $firstFour,
        Collection $firstFourById,
        array $picks,
        int $season,
    ): array {
        $regions = [];

        foreach (self::REGION_NAMES as $regionName) {
            $regionGames = $games->where('region', $regionName)->groupBy('roundKey');
            $regionFirstFour = collect($firstFour)->where('game.region', $regionName)->values();
            $roundOf64Games = collect($regionGames->get('round_of_64', []));
            $roundOf64ByPair = $roundOf64Games
                ->filter(fn (array $game) => $game['homeSeed'] !== null && $game['awaySeed'] !== null)
                ->keyBy(fn (array $game) => min($game['homeSeed'], $game['awaySeed']).'-'.max($game['homeSeed'], $game['awaySeed']));

            $roundOf64Matchups = [];

            foreach (self::ROUND_OF_64_SEED_PAIRINGS as $index => [$highSeed, $lowSeed]) {
                $game = $roundOf64ByPair->get(min($highSeed, $lowSeed).'-'.max($highSeed, $lowSeed));
                $matchingFirstFour = $regionFirstFour->first(
                    fn (array $matchup) => in_array($matchup['game']['playInTargetSeed'], [$highSeed, $lowSeed], true)
                );

                $playInSeed = $matchingFirstFour['game']['playInTargetSeed'] ?? null;

                $roundOf64Matchups[] = [
                    'id' => $game ? "game:{$game['id']}" : "{$regionName}-round_of_64-{$index}",
                    'roundKey' => 'round_of_64',
                    'label' => self::ROUND_LABELS['round_of_64'],
                    'game' => $game,
                    'participants' => [
                        $this->slotForSeed($regionName, $highSeed, 'away', $game, $playInSeed, $matchingFirstFour, $season),
                        $this->slotForSeed($regionName, $lowSeed, 'home', $game, $playInSeed, $matchingFirstFour, $season),
                    ],
                ];
            }

            $roundOf32 = $this->buildVirtualRound($regionName, 'round_of_32', $roundOf64Matchups, $regionGames->get('round_of_32', collect())->all(), $picks, $firstFourById);
            $sweet16 = $this->buildVirtualRound($regionName, 'sweet_16', $roundOf32, $regionGames->get('sweet_16', collect())->all(), $picks, $firstFourById);
            $elite8 = $this->buildVirtualRound($regionName, 'elite_8', $sweet16, $regionGames->get('elite_8', collect())->all(), $picks, $firstFourById);

            $regions[] = [
                'id' => str($regionName)->slug()->toString(),
                'name' => $regionName,
                'rounds' => [
                    ['key' => 'round_of_64', 'label' => self::ROUND_LABELS['round_of_64'], 'matchups' => $roundOf64Matchups],
                    ['key' => 'round_of_32', 'label' => self::ROUND_LABELS['round_of_32'], 'matchups' => $roundOf32],
                    ['key' => 'sweet_16', 'label' => self::ROUND_LABELS['sweet_16'], 'matchups' => $sweet16],
                    ['key' => 'elite_8', 'label' => self::ROUND_LABELS['elite_8'], 'matchups' => $elite8],
                ],
            ];
        }

        return $regions;
    }

    private function buildStandaloneRounds(Collection $games, array $regions, array $picks): array
    {
        $regionChampion = function (string $regionName) use ($regions, $picks) {
            $region = collect($regions)->firstWhere('name', $regionName);
            $elite8 = collect($region['rounds'] ?? [])->firstWhere('key', 'elite_8');
            $matchup = $elite8['matchups'][0] ?? null;

            return $this->selectedParticipant($matchup, $picks, collect());
        };

        $finalFourGames = $games->where('roundKey', 'final_four')->sortBy('id')->values();
        $championshipGame = $games->where('roundKey', 'national_championship')->sortBy('id')->first();

        $finalFour = [
            [
                'id' => 'final-four-0',
                'roundKey' => 'final_four',
                'label' => self::ROUND_LABELS['final_four'],
                'game' => $finalFourGames[0] ?? null,
                'participants' => [
                    ['participant' => $regionChampion('East'), 'placeholderLabel' => 'East champion', 'placeholderAbbreviation' => 'E'],
                    ['participant' => $regionChampion('West'), 'placeholderLabel' => 'West champion', 'placeholderAbbreviation' => 'W'],
                ],
            ],
            [
                'id' => 'final-four-1',
                'roundKey' => 'final_four',
                'label' => self::ROUND_LABELS['final_four'],
                'game' => $finalFourGames[1] ?? null,
                'participants' => [
                    ['participant' => $regionChampion('South'), 'placeholderLabel' => 'South champion', 'placeholderAbbreviation' => 'S'],
                    ['participant' => $regionChampion('Midwest'), 'placeholderLabel' => 'Midwest champion', 'placeholderAbbreviation' => 'MW'],
                ],
            ],
        ];

        return [
            'final_four' => $finalFour,
            'national_championship' => [
                'id' => 'championship-0',
                'roundKey' => 'national_championship',
                'label' => self::ROUND_LABELS['national_championship'],
                'game' => $championshipGame,
                'participants' => [
                    ['participant' => $this->selectedParticipant($finalFour[0], $picks, collect()), 'placeholderLabel' => 'Semifinal winner', 'placeholderAbbreviation' => 'SF1'],
                    ['participant' => $this->selectedParticipant($finalFour[1], $picks, collect()), 'placeholderLabel' => 'Semifinal winner', 'placeholderAbbreviation' => 'SF2'],
                ],
            ],
        ];
    }

    private function buildVirtualRound(
        string $regionName,
        string $roundKey,
        array $previousMatchups,
        array $actualGames,
        array $picks,
        Collection $firstFourById,
    ): array {
        $matchupCount = count($actualGames) > 0 ? count($actualGames) : (int) ceil(count($previousMatchups) / 2);
        $matchups = [];

        for ($index = 0; $index < $matchupCount; $index++) {
            $leftWinner = $this->selectedParticipant($previousMatchups[$index * 2] ?? null, $picks, $firstFourById);
            $rightWinner = $this->selectedParticipant($previousMatchups[($index * 2) + 1] ?? null, $picks, $firstFourById);

            $matchups[] = [
                'id' => "{$regionName}-{$roundKey}-{$index}",
                'roundKey' => $roundKey,
                'label' => self::ROUND_LABELS[$roundKey] ?? str($roundKey)->headline()->toString(),
                'game' => $actualGames[$index] ?? null,
                'participants' => [
                    ['participant' => $leftWinner, 'placeholderLabel' => 'Advances here', 'placeholderAbbreviation' => 'TBD'],
                    ['participant' => $rightWinner, 'placeholderLabel' => 'Advances here', 'placeholderAbbreviation' => 'TBD'],
                ],
            ];
        }

        return $matchups;
    }

    private function selectedParticipant(?array $matchup, array $picks, Collection $firstFourById): ?array
    {
        if (! $matchup) {
            return null;
        }

        $selectedId = $picks[$matchup['id']] ?? null;
        if (! $selectedId) {
            return null;
        }

        foreach ($matchup['participants'] as $slot) {
            $participant = $this->resolveSlotParticipant($slot, $picks, $firstFourById);
            if (($participant['id'] ?? null) === $selectedId) {
                return $participant;
            }
        }

        return null;
    }

    private function resolveSlotParticipant(array $slot, array $picks, Collection $firstFourById): ?array
    {
        if ($slot['participant'] ?? null) {
            return $slot['participant'];
        }

        $sourceMatchupId = $slot['sourceMatchupId'] ?? null;
        if (! $sourceMatchupId) {
            return null;
        }

        return $this->selectedParticipant($firstFourById->get($sourceMatchupId), $picks, $firstFourById);
    }

    private function slotForSeed(
        string $regionName,
        int $seed,
        string $side,
        ?array $game,
        ?int $playInSeed,
        ?array $matchingFirstFour,
        int $season,
    ): array {
        if ($playInSeed === $seed && $matchingFirstFour) {
            return [
                'participant' => null,
                'sourceMatchupId' => $matchingFirstFour['id'],
                'placeholderLabel' => "Winner to {$matchingFirstFour['game']['region']} {$matchingFirstFour['game']['playInTargetSeed']}",
                'placeholderAbbreviation' => 'FF',
            ];
        }

        if (! $game) {
            return ['participant' => $this->fallbackParticipantForSeed($season, $regionName, $seed) ?? $this->seedPlaceholder($seed)];
        }

        $team = $side === 'home' ? $game['homeTeam'] : $game['awayTeam'];
        $teamSeed = $side === 'home' ? $game['homeSeed'] : $game['awaySeed'];
        if ($teamSeed === $seed) {
            return ['participant' => $this->participantWithSeed($team, $teamSeed)];
        }

        $oppositeTeam = $side === 'home' ? $game['awayTeam'] : $game['homeTeam'];
        $oppositeSeed = $side === 'home' ? $game['awaySeed'] : $game['homeSeed'];
        if ($oppositeSeed === $seed) {
            return ['participant' => $this->participantWithSeed($oppositeTeam, $oppositeSeed)];
        }

        return ['participant' => $this->fallbackParticipantForSeed($season, $regionName, $seed) ?? $this->seedPlaceholder($seed)];
    }

    private function fallbackParticipantForSeed(int $season, string $regionName, int $seed): ?array
    {
        $team = config("cbb_bracket.season_fallbacks.{$season}.{$regionName}.{$seed}");
        if (! is_array($team)) {
            return null;
        }

        return [
            'id' => "fallback:{$season}:{$regionName}:{$seed}",
            'name' => $team['name'],
            'abbreviation' => $team['abbreviation'],
            'logo' => null,
            'seed' => $seed,
        ];
    }

    private function participantWithSeed(array $team, ?int $seed): array
    {
        return [
            'id' => $team['id'] !== null ? "team:{$team['id']}" : "name:{$team['name']}",
            'name' => $team['name'],
            'abbreviation' => $team['abbreviation'],
            'logo' => $team['logo'] ?? null,
            'seed' => $seed,
        ];
    }

    private function seedPlaceholder(int $seed): array
    {
        return [
            'id' => "seed:{$seed}",
            'name' => 'TBD',
            'abbreviation' => 'TBD',
            'logo' => null,
            'seed' => $seed,
        ];
    }
}
