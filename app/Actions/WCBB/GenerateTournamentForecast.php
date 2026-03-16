<?php

namespace App\Actions\WCBB;

use App\Models\WCBB\TeamMetric;
use App\Models\WCBB\TournamentForecast;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class GenerateTournamentForecast
{
    public function execute(int|string|null $season = null, ?int $simulationRuns = null): Collection
    {
        $season = (int) ($season ?? config('wcbb.season.default'));
        $config = config('wcbb.tournament_forecast');

        $fieldSize = max(2, (int) ($config['field_size'] ?? 68));
        $autoBids = max(0, (int) ($config['auto_bids'] ?? 31));
        $simulationRuns = max(100, (int) ($simulationRuns ?? ($config['simulations'] ?? 5000)));

        if (($randomSeed = $config['random_seed'] ?? null) !== null) {
            mt_srand((int) $randomSeed);
        }

        $teamPool = $this->buildTeamPool($season);
        if ($teamPool->isEmpty()) {
            return collect();
        }

        $teamPool = $this->attachSelectionScores($teamPool, (array) ($config['selection_weights'] ?? []))
            ->sortByDesc('selection_score')
            ->values();

        $fieldSize = min($fieldSize, $teamPool->count());
        $autoBidConferences = $this->selectAutoBidConferences($teamPool, $autoBids);

        $simulation = $this->simulateTournamentOutcomes(
            $teamPool,
            $autoBidConferences,
            $fieldSize,
            $simulationRuns,
            $config
        );

        $teamPool = $teamPool->values()->map(function (array $team, int $index) use (
            $simulationRuns,
            $simulation
        ) {
            $teamId = $team['team_id'];
            $fieldAppearances = (int) ($simulation['field_appearances'][$teamId] ?? 0);
            $seedSamples = (int) ($simulation['seed_line_samples'][$teamId] ?? 0);
            $seedLineSum = (float) ($simulation['seed_line_sum'][$teamId] ?? 0.0);
            $averageSeedLine = $seedSamples > 0 ? $seedLineSum / $seedSamples : null;
            $makeProbability = $fieldAppearances / $simulationRuns;
            $autoBidProbability = ((int) ($simulation['auto_bid_counts'][$teamId] ?? 0)) / $simulationRuns;

            $team['selection_rank'] = $index + 1;
            $team['auto_bid'] = $autoBidProbability >= 0.5;
            $team['auto_bid_probability'] = $autoBidProbability;
            $team['at_large_probability'] = ((int) ($simulation['at_large_counts'][$teamId] ?? 0)) / $simulationRuns;
            $team['first_four_probability'] = ((int) ($simulation['first_four_counts'][$teamId] ?? 0)) / $simulationRuns;
            $team['first_four_auto_probability'] = ((int) ($simulation['first_four_auto_counts'][$teamId] ?? 0)) / $simulationRuns;
            $team['first_four_at_large_probability'] = ((int) ($simulation['first_four_at_large_counts'][$teamId] ?? 0)) / $simulationRuns;
            $team['bid_thief_probability'] = ((int) ($simulation['bid_thief_counts'][$teamId] ?? 0)) / $simulationRuns;
            $team['tournament_make_probability'] = $makeProbability;
            $team['projected_seed'] = $makeProbability >= 0.5 && $averageSeedLine !== null
                ? (int) round($this->clamp($averageSeedLine, 1, 16))
                : null;

            return $team;
        });

        $payload = $teamPool->map(function (array $team) use ($season, $simulationRuns, $simulation) {
            $teamId = $team['team_id'];

            return [
                'team_id' => $teamId,
                'season' => $season,
                'selection_score' => round($team['selection_score'], 4),
                'projected_seed' => $team['projected_seed'],
                'auto_bid' => $team['auto_bid'],
                'auto_bid_probability' => round((float) $team['auto_bid_probability'], 5),
                'at_large_probability' => round((float) $team['at_large_probability'], 5),
                'first_four_probability' => round((float) $team['first_four_probability'], 5),
                'first_four_auto_probability' => round((float) $team['first_four_auto_probability'], 5),
                'first_four_at_large_probability' => round((float) $team['first_four_at_large_probability'], 5),
                'bid_thief_probability' => round((float) $team['bid_thief_probability'], 5),
                'tournament_make_probability' => round((float) $team['tournament_make_probability'], 5),
                'champion_probability' => round(($simulation['champion_probability'][$teamId] ?? 0.0), 5),
                'final_four_probability' => round(($simulation['final_four_probability'][$teamId] ?? 0.0), 5),
                'title_game_probability' => round(($simulation['title_game_probability'][$teamId] ?? 0.0), 5),
                'simulated_field_appearances' => (int) ($simulation['field_appearances'][$teamId] ?? 0),
                'simulated_titles' => (int) ($simulation['champion_counts'][$teamId] ?? 0),
                'simulation_runs' => $simulationRuns,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        })->values()->all();

        TournamentForecast::query()
            ->where('season', $season)
            ->whereNotIn('team_id', $teamPool->pluck('team_id')->all())
            ->delete();

        TournamentForecast::query()->upsert(
            $payload,
            ['team_id', 'season'],
            [
                'selection_score',
                'projected_seed',
                'auto_bid',
                'auto_bid_probability',
                'at_large_probability',
                'first_four_probability',
                'first_four_auto_probability',
                'first_four_at_large_probability',
                'bid_thief_probability',
                'tournament_make_probability',
                'champion_probability',
                'final_four_probability',
                'title_game_probability',
                'simulated_field_appearances',
                'simulated_titles',
                'simulation_runs',
                'updated_at',
            ]
        );

        return TournamentForecast::query()
            ->with('team')
            ->where('season', $season)
            ->orderByDesc('champion_probability')
            ->orderByDesc('tournament_make_probability')
            ->get();
    }

    private function buildTeamPool(int $season): Collection
    {
        $metrics = TeamMetric::query()
            ->with('team')
            ->where('season', $season)
            ->where('meets_minimum', true)
            ->get();

        if ($metrics->isEmpty()) {
            return collect();
        }

        $records = collect(DB::select(
            "SELECT team_id,
                SUM(CASE WHEN won = 1 THEN 1 ELSE 0 END) AS wins,
                SUM(CASE WHEN won = 0 THEN 1 ELSE 0 END) AS losses
            FROM (
                SELECT home_team_id AS team_id, CASE WHEN home_score > away_score THEN 1 ELSE 0 END AS won
                FROM wcbb_games
                WHERE status = 'STATUS_FINAL' AND season = ?
                UNION ALL
                SELECT away_team_id AS team_id, CASE WHEN away_score > home_score THEN 1 ELSE 0 END AS won
                FROM wcbb_games
                WHERE status = 'STATUS_FINAL' AND season = ?
            ) results
            GROUP BY team_id",
            [$season, $season]
        ))->keyBy('team_id');

        $defaultElo = (float) config('wcbb.elo.default', 1500);

        return $metrics
            ->map(function (TeamMetric $metric) use ($records, $defaultElo) {
                if (! $metric->team) {
                    return null;
                }

                $record = $records->get($metric->team_id);
                $wins = (int) ($record->wins ?? 0);
                $losses = (int) ($record->losses ?? 0);
                $gamesPlayed = max($wins + $losses, (int) ($metric->games_played ?? 0));

                $adjNet = (float) ($metric->adj_net_rating ?? $metric->net_rating ?? 0);
                $rollingNet = (float) ($metric->rolling_net_rating ?? $metric->net_rating ?? 0);
                $sos = (float) ($metric->strength_of_schedule ?? 1500);
                $elo = (float) ($metric->team->elo_rating ?? $defaultElo);
                $winPct = $gamesPlayed > 0 ? $wins / $gamesPlayed : 0.5;
                $conference = trim((string) ($metric->team->conference ?? '')) ?: 'Independent';

                return [
                    'team_id' => (int) $metric->team_id,
                    'conference' => $conference,
                    'wins' => $wins,
                    'losses' => $losses,
                    'games_played' => $gamesPlayed,
                    'win_pct' => $winPct,
                    'elo_rating' => $elo,
                    'adj_net_rating' => $adjNet,
                    'rolling_net_rating' => $rollingNet,
                    'strength_of_schedule' => $sos,
                    'power_conference' => $this->isPowerConference($conference),
                    'power_rating' => 0.0,
                    'selection_score' => 0.0,
                    'tournament_make_probability' => 0.0,
                ];
            })
            ->filter()
            ->values();
    }

    private function attachSelectionScores(Collection $teams, array $weights): Collection
    {
        $keys = ['adj_net_rating', 'rolling_net_rating', 'strength_of_schedule', 'elo_rating', 'win_pct'];
        $normalizedWeights = $this->normalizeWeights($weights, $keys);

        $zScoreMap = [];
        foreach ($keys as $key) {
            $zScoreMap[$key] = $this->zScores($teams->pluck($key, 'team_id')->all());
        }

        $teams = $teams->map(function (array $team) use ($normalizedWeights, $zScoreMap) {
            $teamId = $team['team_id'];
            $score = 0.0;

            foreach ($normalizedWeights as $metricKey => $weight) {
                $score += ($zScoreMap[$metricKey][$teamId] ?? 0.0) * $weight;
            }

            $team['selection_score'] = $score;

            return $team;
        });

        $teams = $this->applySelectionContextAdjustments($teams);

        $powerWeights = (array) config('wcbb.tournament_forecast.champion_weights', []);
        $normalizedPowerWeights = $this->normalizeWeights($powerWeights, $keys);

        return $teams->map(function (array $team) use ($normalizedPowerWeights) {
            $team['power_rating'] = $this->powerRating($team, $normalizedPowerWeights);
            $team['power_rating'] += (float) ($team['conference_strength_bonus'] ?? 0.0)
                * (float) config('wcbb.tournament_forecast.champion_conference_strength_weight', 0.12);
            $team['power_rating'] += ((bool) ($team['power_conference'] ?? false))
                ? (float) config('wcbb.tournament_forecast.champion_power_conference_bonus', 0.08)
                : 0.0;

            return $team;
        });
    }

    private function applySelectionContextAdjustments(Collection $teams): Collection
    {
        if ($teams->isEmpty()) {
            return $teams;
        }

        $conferenceStrengthTopTeams = max(1, (int) config('wcbb.tournament_forecast.conference_strength_top_teams', 3));
        $fullConfidenceGames = max(1, (int) config('wcbb.tournament_forecast.selection_full_confidence_games', 20));
        $conferenceStrengthWeight = (float) config('wcbb.tournament_forecast.selection_conference_strength_weight', 0.35);
        $powerConferenceBonus = (float) config('wcbb.tournament_forecast.selection_power_conference_bonus', 0.45);
        $resumeConfidencePenalty = max(0.0, (float) config('wcbb.tournament_forecast.selection_resume_confidence_penalty', 0.30));

        $conferenceStrengths = $teams
            ->groupBy('conference')
            ->map(function (Collection $conferenceTeams) use ($conferenceStrengthTopTeams): float {
                return (float) $conferenceTeams
                    ->sortByDesc('selection_score')
                    ->take($conferenceStrengthTopTeams)
                    ->avg('selection_score');
            });

        $conferenceStrengthZScores = $this->zScores($conferenceStrengths->all());

        return $teams->map(function (array $team) use (
            $conferenceStrengthZScores,
            $conferenceStrengthWeight,
            $powerConferenceBonus,
            $resumeConfidencePenalty,
            $fullConfidenceGames
        ) {
            $conference = (string) ($team['conference'] ?? 'Independent');
            $conferenceStrengthBonus = ($conferenceStrengthZScores[$conference] ?? 0.0) * $conferenceStrengthWeight;
            $confidence = min(1.0, max(0.0, ((int) ($team['games_played'] ?? 0)) / $fullConfidenceGames));
            $confidencePenalty = (1.0 - $confidence) * $resumeConfidencePenalty;
            $powerBonus = ((bool) ($team['power_conference'] ?? false)) ? $powerConferenceBonus : 0.0;

            $team['conference_strength_bonus'] = $conferenceStrengthBonus;
            $team['resume_confidence'] = $confidence;
            $team['selection_score'] = (float) $team['selection_score'] + $conferenceStrengthBonus + $powerBonus - $confidencePenalty;

            return $team;
        });
    }

    /**
     * @return array<int, string>
     */
    private function selectAutoBidConferences(Collection $teams, int $autoBids): array
    {
        if ($autoBids <= 0) {
            return [];
        }

        return $teams
            ->groupBy('conference')
            ->map(function (Collection $conferenceTeams, string $conference): array {
                $favorite = $conferenceTeams->sortByDesc('selection_score')->first();

                return [
                    'conference' => $conference,
                    'favorite_score' => (float) ($favorite['selection_score'] ?? 0),
                ];
            })
            ->sortByDesc('favorite_score')
            ->take($autoBids)
            ->pluck('conference')
            ->values()
            ->all();
    }

    /**
     * @return array{
     *   champion_counts: array<int, int>,
     *   auto_bid_counts: array<int, int>,
     *   at_large_counts: array<int, int>,
     *   first_four_counts: array<int, int>,
     *   first_four_auto_counts: array<int, int>,
     *   first_four_at_large_counts: array<int, int>,
     *   bid_thief_counts: array<int, int>,
     *   champion_probability: array<int, float>,
     *   final_four_probability: array<int, float>,
     *   title_game_probability: array<int, float>,
     *   field_appearances: array<int, int>,
     *   seed_line_sum: array<int, float>,
     *   seed_line_samples: array<int, int>
     * }
     */
    private function simulateTournamentOutcomes(
        Collection $teams,
        array $autoBidConferences,
        int $fieldSize,
        int $simulationRuns,
        array $config
    ): array {
        $autoBidCount = min(count($autoBidConferences), $fieldSize);
        $atLargeSpots = max(0, $fieldSize - $autoBidCount);
        $teamIds = $teams->pluck('team_id')->all();
        $championCounts = array_fill_keys($teamIds, 0);
        $autoBidCounts = array_fill_keys($teamIds, 0);
        $atLargeCounts = array_fill_keys($teamIds, 0);
        $firstFourCounts = array_fill_keys($teamIds, 0);
        $firstFourAutoCounts = array_fill_keys($teamIds, 0);
        $firstFourAtLargeCounts = array_fill_keys($teamIds, 0);
        $bidThiefCounts = array_fill_keys($teamIds, 0);
        $finalFourCounts = array_fill_keys($teamIds, 0);
        $titleGameCounts = array_fill_keys($teamIds, 0);
        $fieldAppearances = array_fill_keys($teamIds, 0);
        $seedLineSum = array_fill_keys($teamIds, 0.0);
        $seedLineSamples = array_fill_keys($teamIds, 0);

        for ($run = 0; $run < $simulationRuns; $run++) {
            $autoBidTeams = $this->simulateConferenceAutoBids($teams, $autoBidConferences, $config);
            $autoBidTeamIds = $autoBidTeams->pluck('team_id')->all();
            $scoredTeams = $this->scoreAtLargeCandidates($teams, $config);
            $atLargeTeams = $this->selectAtLargeTeams(
                $scoredTeams->reject(fn (array $team) => in_array($team['team_id'], $autoBidTeamIds, true))->values(),
                $atLargeSpots,
            );

            foreach ($autoBidTeamIds as $autoBidTeamId) {
                if (isset($autoBidCounts[$autoBidTeamId])) {
                    $autoBidCounts[$autoBidTeamId]++;
                }
            }

            $atLargeTeamIds = $atLargeTeams->pluck('team_id')->all();
            foreach ($atLargeTeamIds as $atLargeTeamId) {
                if (isset($atLargeCounts[$atLargeTeamId])) {
                    $atLargeCounts[$atLargeTeamId]++;
                }
            }

            $field = $autoBidTeams
                ->map(fn (array $team) => array_merge($team, ['selection_type' => 'auto']))
                ->values()
                ->concat(
                    $atLargeTeams->map(fn (array $team) => array_merge($team, ['selection_type' => 'at_large']))
                )
                ->sortByDesc('selection_score')
                ->values()
                ->map(function (array $team, int $index) {
                    $overallRank = $index + 1;
                    $team['overall_seed_rank'] = $overallRank;
                    $team['seed_line'] = $this->overallRankToSeedLine($overallRank);

                    return $team;
                });

            $this->countBidThieves($autoBidTeams, $atLargeTeams, $scoredTeams, $bidThiefCounts);

            if ($field->count() < 4) {
                continue;
            }

            foreach ($field as $entry) {
                $fieldAppearances[$entry['team_id']]++;
                $seedLineSum[$entry['team_id']] += $entry['seed_line'];
                $seedLineSamples[$entry['team_id']]++;
            }

            $firstFour = $this->firstFourParticipants($field, $config);
            foreach ($firstFour['all'] as $teamId) {
                if (isset($firstFourCounts[$teamId])) {
                    $firstFourCounts[$teamId]++;
                }
            }
            foreach ($firstFour['auto'] as $teamId) {
                if (isset($firstFourAutoCounts[$teamId])) {
                    $firstFourAutoCounts[$teamId]++;
                }
            }
            foreach ($firstFour['at_large'] as $teamId) {
                if (isset($firstFourAtLargeCounts[$teamId])) {
                    $firstFourAtLargeCounts[$teamId]++;
                }
            }

            $entrants = $this->buildMainBracketEntrants($field, $config, $firstFour);

            while (count($entrants) > 1) {
                $currentCount = count($entrants);

                if ($currentCount === 4) {
                    foreach ($entrants as $entry) {
                        $finalFourCounts[$entry['team_id']]++;
                    }
                }

                if ($currentCount === 2) {
                    foreach ($entrants as $entry) {
                        $titleGameCounts[$entry['team_id']]++;
                    }
                }

                $entrants = $this->playRound($entrants);
            }

            $championId = $entrants[0]['team_id'];
            $championCounts[$championId]++;
        }

        $championProbability = [];
        $finalFourProbability = [];
        $titleGameProbability = [];

        foreach ($teamIds as $teamId) {
            $championProbability[$teamId] = $championCounts[$teamId] / $simulationRuns;
            $finalFourProbability[$teamId] = $finalFourCounts[$teamId] / $simulationRuns;
            $titleGameProbability[$teamId] = $titleGameCounts[$teamId] / $simulationRuns;
        }

        return [
            'champion_counts' => $championCounts,
            'auto_bid_counts' => $autoBidCounts,
            'at_large_counts' => $atLargeCounts,
            'first_four_counts' => $firstFourCounts,
            'first_four_auto_counts' => $firstFourAutoCounts,
            'first_four_at_large_counts' => $firstFourAtLargeCounts,
            'bid_thief_counts' => $bidThiefCounts,
            'champion_probability' => $championProbability,
            'final_four_probability' => $finalFourProbability,
            'title_game_probability' => $titleGameProbability,
            'field_appearances' => $fieldAppearances,
            'seed_line_sum' => $seedLineSum,
            'seed_line_samples' => $seedLineSamples,
        ];
    }

    /**
     * @param  array<int, string>  $autoBidConferences
     */
    private function simulateConferenceAutoBids(
        Collection $teams,
        array $autoBidConferences,
        array $config
    ): Collection {
        if ($autoBidConferences === []) {
            return collect();
        }

        $selectedConferenceSet = array_flip($autoBidConferences);
        $upsetFactor = max(0.0, (float) ($config['conference_tournament_upset_factor'] ?? 0.45));

        return $teams
            ->filter(fn (array $team) => isset($selectedConferenceSet[$team['conference']]))
            ->groupBy('conference')
            ->map(function (Collection $conferenceTeams) use ($upsetFactor) {
                $weights = $conferenceTeams->mapWithKeys(function (array $team) use ($upsetFactor) {
                    $zScore = (float) ($team['selection_score'] ?? 0);
                    $weight = exp($zScore / max(0.1, 1.0 + $upsetFactor));

                    return [$team['team_id'] => $weight];
                })->all();

                return $this->weightedPick($conferenceTeams, $weights)
                    ?? $conferenceTeams->sortByDesc('selection_score')->first();
            })
            ->values();
    }

    private function scoreAtLargeCandidates(Collection $teams, array $config): Collection
    {
        if ($teams->isEmpty()) {
            return collect();
        }

        $noiseStdDev = max(0.0, (float) ($config['at_large_noise_stddev'] ?? 0.35));

        return $teams->map(function (array $team) use ($noiseStdDev) {
            $noise = $noiseStdDev > 0 ? $this->randomGaussian() * $noiseStdDev : 0.0;
            $team['at_large_score'] = (float) $team['selection_score'] + $noise;

            return $team;
        });
    }

    private function selectAtLargeTeams(Collection $candidates, int $spots): Collection
    {
        if ($spots <= 0 || $candidates->isEmpty()) {
            return collect();
        }

        return $candidates
            ->sortByDesc('at_large_score')
            ->take($spots)
            ->values();
    }

    /**
     * @param  array{all: array<int, int>, auto: array<int, int>, at_large: array<int, int>}  $firstFour
     */
    private function buildMainBracketEntrants(Collection $field, array $config, array $firstFour): array
    {
        $entrants = $field->values()->all();
        $enableFirstFour = (bool) ($config['enable_first_four'] ?? true);

        if ($enableFirstFour && count($entrants) === 68) {
            $atLargePlayInSet = array_flip($firstFour['at_large']);
            $autoPlayInSet = array_flip($firstFour['auto']);
            $atLargePlayIn = array_values(array_filter($entrants, fn (array $team) => isset($atLargePlayInSet[$team['team_id']])));
            $autoPlayIn = array_values(array_filter($entrants, fn (array $team) => isset($autoPlayInSet[$team['team_id']])));

            $playInParticipants = array_merge($atLargePlayIn, $autoPlayIn);
            $playInSet = array_flip(array_map(fn (array $team) => $team['team_id'], $playInParticipants));
            $lockedEntrants = array_values(array_filter($entrants, fn (array $team) => ! isset($playInSet[$team['team_id']])));

            $playInWinners = [];
            foreach ([$atLargePlayIn, $autoPlayIn] as $group) {
                if (count($group) < 4) {
                    continue;
                }

                $playInWinners[] = $this->playGame($group[0], $group[3]);
                $playInWinners[] = $this->playGame($group[1], $group[2]);
            }

            $entrants = array_merge($lockedEntrants, $playInWinners);
        }

        if (count($entrants) !== 64) {
            $entrants = $this->normalizeBracketEntrants($entrants);
        }

        usort($entrants, fn (array $a, array $b) => (($a['overall_seed_rank'] ?? 999) <=> ($b['overall_seed_rank'] ?? 999)));

        return $entrants;
    }

    /**
     * @return array{all: array<int, int>, auto: array<int, int>, at_large: array<int, int>}
     */
    private function firstFourParticipants(Collection $field, array $config): array
    {
        $enableFirstFour = (bool) ($config['enable_first_four'] ?? true);
        if (! $enableFirstFour || $field->count() !== 68) {
            return ['all' => [], 'auto' => [], 'at_large' => []];
        }

        $atLargePlayIn = $field->where('selection_type', 'at_large')
            ->sortByDesc('overall_seed_rank')
            ->take(4)
            ->pluck('team_id')
            ->values()
            ->all();

        $autoPlayIn = $field->where('selection_type', 'auto')
            ->sortByDesc('overall_seed_rank')
            ->take(4)
            ->pluck('team_id')
            ->values()
            ->all();

        return [
            'all' => array_values(array_unique(array_merge($atLargePlayIn, $autoPlayIn))),
            'auto' => $autoPlayIn,
            'at_large' => $atLargePlayIn,
        ];
    }

    /**
     * @param  array<int, int>  $bidThiefCounts
     */
    private function countBidThieves(
        Collection $autoBidTeams,
        Collection $atLargeTeams,
        Collection $scoredTeams,
        array &$bidThiefCounts
    ): void {
        if ($autoBidTeams->isEmpty() || $atLargeTeams->isEmpty()) {
            return;
        }

        $atLargeCutScore = (float) $atLargeTeams->sortByDesc('at_large_score')->last()['at_large_score'];
        foreach ($autoBidTeams as $autoBidTeam) {
            $teamId = (int) $autoBidTeam['team_id'];
            $team = $scoredTeams->firstWhere('team_id', $teamId);
            if (! $team) {
                continue;
            }

            if (((float) $team['at_large_score']) < $atLargeCutScore && isset($bidThiefCounts[$teamId])) {
                $bidThiefCounts[$teamId]++;
            }
        }
    }

    private function weightedPick(Collection $pool, array $weights): ?array
    {
        if ($pool->isEmpty()) {
            return null;
        }

        $totalWeight = 0.0;
        foreach ($pool as $team) {
            $totalWeight += max(0.0, (float) ($weights[$team['team_id']] ?? 0.0));
        }

        if ($totalWeight <= 0.0) {
            return $pool->sortByDesc('selection_score')->first();
        }

        $target = $this->randomFloat() * $totalWeight;
        $running = 0.0;

        foreach ($pool as $team) {
            $running += max(0.0, (float) ($weights[$team['team_id']] ?? 0.0));
            if ($running >= $target) {
                return $team;
            }
        }

        return $pool->last();
    }

    /**
     * @param  array<int, array<string, float|int|string|null>>  $entrants
     * @return array<int, array<string, float|int|string|null>>
     */
    private function playRound(array $entrants): array
    {
        usort($entrants, function (array $a, array $b): int {
            $seedA = (int) ($a['overall_seed_rank'] ?? PHP_INT_MAX);
            $seedB = (int) ($b['overall_seed_rank'] ?? PHP_INT_MAX);

            if ($seedA !== $seedB) {
                return $seedA <=> $seedB;
            }

            return $b['power_rating'] <=> $a['power_rating'];
        });
        $winners = [];
        $left = 0;
        $right = count($entrants) - 1;

        while ($left < $right) {
            $teamA = $entrants[$left];
            $teamB = $entrants[$right];
            $winners[] = $this->playGame($teamA, $teamB);
            $left++;
            $right--;
        }

        if ($left === $right) {
            // Odd-sized rounds get a bye for the best remaining team.
            $winners[] = $entrants[$left];
        }

        return $winners;
    }

    /**
     * For non-power-of-two fields (e.g., 68 teams), play preliminary games
     * between the lowest-rated teams to reach a clean bracket size.
     *
     * @param  array<int, array<string, float|int|string|null>>  $entrants
     * @return array<int, array<string, float|int|string|null>>
     */
    private function normalizeBracketEntrants(array $entrants): array
    {
        $count = count($entrants);
        if ($count <= 1) {
            return $entrants;
        }

        $target = 1;
        while (($target * 2) <= $count) {
            $target *= 2;
        }

        if ($count === $target) {
            return $entrants;
        }

        usort($entrants, fn (array $a, array $b) => ($b['power_rating'] <=> $a['power_rating']));

        $playInTeamsCount = ($count - $target) * 2;
        $protectedTeamsCount = $count - $playInTeamsCount;

        $protected = array_slice($entrants, 0, $protectedTeamsCount);
        $playInTeams = array_slice($entrants, $protectedTeamsCount);

        $playInWinners = [];
        for ($i = 0; $i < count($playInTeams); $i += 2) {
            $playInWinners[] = $this->playGame($playInTeams[$i], $playInTeams[$i + 1]);
        }

        return array_merge($protected, $playInWinners);
    }

    /**
     * @param  array<string, float|int|string|null>  $teamA
     * @param  array<string, float|int|string|null>  $teamB
     * @return array<string, float|int|string|null>
     */
    private function playGame(array $teamA, array $teamB): array
    {
        $ratingA = (float) ($teamA['power_rating'] ?? 1500);
        $ratingB = (float) ($teamB['power_rating'] ?? 1500);
        $winProbabilityA = 1.0 / (1.0 + pow(10, (($ratingB - $ratingA) / 400)));

        return $this->randomFloat() <= $winProbabilityA ? $teamA : $teamB;
    }

    private function powerRating(array $team, array $weights): float
    {
        $elo = (float) ($team['elo_rating'] ?? 1500);
        $adjNet = (float) ($team['adj_net_rating'] ?? 0);
        $rollingNet = (float) ($team['rolling_net_rating'] ?? 0);
        $winPct = (float) ($team['win_pct'] ?? 0.5);
        $sos = (float) ($team['strength_of_schedule'] ?? 1500);

        return ($weights['elo_rating'] * $elo)
            + ($weights['adj_net_rating'] * (1500 + ($adjNet * 12)))
            + ($weights['rolling_net_rating'] * (1500 + ($rollingNet * 10)))
            + ($weights['win_pct'] * (1200 + ($winPct * 400)))
            + ($weights['strength_of_schedule'] * $sos);
    }

    /**
     * @param  array<string, float|int>  $weights
     * @param  array<int, string>  $keys
     * @return array<string, float>
     */
    private function normalizeWeights(array $weights, array $keys): array
    {
        $normalized = [];
        $total = 0.0;

        foreach ($keys as $key) {
            $value = max(0.0, (float) ($weights[$key] ?? 0.0));
            $normalized[$key] = $value;
            $total += $value;
        }

        if ($total <= 0.0) {
            $equal = 1.0 / count($keys);

            return array_fill_keys($keys, $equal);
        }

        foreach ($keys as $key) {
            $normalized[$key] /= $total;
        }

        return $normalized;
    }

    /**
     * @param  array<int, float|int>  $valuesByTeamId
     * @return array<int, float>
     */
    private function zScores(array $valuesByTeamId): array
    {
        if ($valuesByTeamId === []) {
            return [];
        }

        $values = array_map('floatval', array_values($valuesByTeamId));
        $mean = array_sum($values) / count($values);

        $sumSquaredDiff = 0.0;
        foreach ($values as $value) {
            $sumSquaredDiff += ($value - $mean) ** 2;
        }

        $stdDev = sqrt($sumSquaredDiff / max(1, count($values)));
        if ($stdDev <= 0.00001) {
            return array_map(fn () => 0.0, $valuesByTeamId);
        }

        $zScores = [];
        foreach ($valuesByTeamId as $teamId => $value) {
            $zScores[$teamId] = ((float) $value - $mean) / $stdDev;
        }

        return $zScores;
    }

    private function randomFloat(): float
    {
        return mt_rand() / mt_getrandmax();
    }

    private function randomGaussian(): float
    {
        $u1 = max(1e-9, $this->randomFloat());
        $u2 = max(1e-9, $this->randomFloat());

        return sqrt(-2.0 * log($u1)) * cos(2.0 * M_PI * $u2);
    }

    private function overallRankToSeedLine(int $rank): int
    {
        if ($rank <= 0) {
            return 16;
        }

        if ($rank <= 40) {
            return (int) ceil($rank / 4);
        }

        if ($rank <= 46) {
            return 11;
        }

        if ($rank <= 50) {
            return 12;
        }

        if ($rank <= 54) {
            return 13;
        }

        if ($rank <= 58) {
            return 14;
        }

        if ($rank <= 62) {
            return 15;
        }

        return 16;
    }

    private function clamp(float $value, float $min, float $max): float
    {
        return max($min, min($max, $value));
    }

    private function isPowerConference(string $conference): bool
    {
        $normalizedConference = $this->normalizeConferenceName($conference);
        $configured = array_map(
            fn ($value) => $this->normalizeConferenceName((string) $value),
            (array) config('wcbb.teams.power_conferences', [])
        );

        return in_array($normalizedConference, $configured, true);
    }

    private function normalizeConferenceName(string $conference): string
    {
        return strtolower(trim($conference));
    }
}
