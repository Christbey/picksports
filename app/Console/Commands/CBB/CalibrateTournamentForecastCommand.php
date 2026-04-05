<?php

namespace App\Console\Commands\CBB;

use App\Actions\CBB\GenerateTournamentForecast;
use App\Services\TournamentForecast\CbbTournamentForecastTuningStore;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use RuntimeException;

class CalibrateTournamentForecastCommand extends Command
{
    protected $signature = 'cbb:calibrate-tournament-forecast
        {--season= : Season to tune (defaults to cbb.season.default)}
        {--simulations=1200 : Simulation runs per candidate evaluation}
        {--repeats=3 : Repeat count per candidate (different random seeds)}
        {--full : Use expanded parameter grid}
        {--save : Persist the best tuned params to application_settings}';

    protected $description = 'Tune CBB tournament forecast parameters using current-season simulation stability';

    public function handle(
        GenerateTournamentForecast $forecastAction,
        CbbTournamentForecastTuningStore $tuningStore
    ): int {
        $season = (int) ($this->option('season') ?? config('cbb.season.default'));
        $simulationRuns = max(250, (int) $this->option('simulations'));
        $repeats = max(2, min(7, (int) $this->option('repeats')));
        $full = (bool) $this->option('full');
        $save = (bool) $this->option('save');

        $baseConfig = (array) config('cbb.tournament_forecast', []);
        $fieldSize = max(2, (int) ($baseConfig['field_size'] ?? 68));
        $candidateConfigs = $this->candidateConfigGrid($full, $baseConfig);

        $this->info("Calibrating CBB tournament forecast parameters for {$season}...");
        $this->line("Candidates: {$candidateConfigs->count()}");
        $this->line("Simulations per run: {$simulationRuns}");
        $this->line("Repeats per candidate: {$repeats}");
        $this->newLine();

        $progress = $this->output->createProgressBar($candidateConfigs->count());
        $progress->start();

        $scored = [];

        foreach ($candidateConfigs as $candidate) {
            $seeds = $this->seedSeries($season, $candidate['idx'], $repeats);
            $runs = collect();

            foreach ($seeds as $seed) {
                $overrides = $candidate['overrides'];
                $overrides['random_seed'] = $seed;

                $result = $forecastAction->simulateForBacktest($season, $simulationRuns, $overrides);
                if ($result->isEmpty()) {
                    $this->newLine();
                    $this->error('No qualifying team metrics found. Run cbb:calculate-team-metrics first.');

                    return self::FAILURE;
                }

                $runs->push($result);
            }

            $metrics = $this->evaluateCandidateRuns($runs, $fieldSize);

            $scored[] = [
                'idx' => $candidate['idx'],
                'label' => $candidate['label'],
                'overrides' => $candidate['overrides'],
                'score' => $metrics['score'],
                'stability_score' => $metrics['stability_score'],
                'cutline_score' => $metrics['cutline_score'],
                'concentration_score' => $metrics['concentration_score'],
            ];

            $progress->advance();
        }

        $progress->finish();
        $this->newLine(2);

        usort($scored, fn (array $a, array $b) => $a['score'] <=> $b['score']);

        $this->info('Top Candidate Parameter Sets (lower score is better)');
        $this->table(
            ['Rank', 'Profile', 'Bubble', 'AL Noise', 'Conf Upset', 'Score', 'Stability', 'Cutline', 'Concentration'],
            collect(array_slice($scored, 0, 10))->values()->map(function (array $row, int $rank) {
                return [
                    $rank + 1,
                    $row['label'],
                    number_format((float) $row['overrides']['bubble_steepness'], 2),
                    number_format((float) $row['overrides']['at_large_noise_stddev'], 2),
                    number_format((float) $row['overrides']['conference_tournament_upset_factor'], 2),
                    number_format((float) $row['score'], 4),
                    number_format((float) $row['stability_score'], 4),
                    number_format((float) $row['cutline_score'], 4),
                    number_format((float) $row['concentration_score'], 4),
                ];
            })->all()
        );

        $best = $scored[0];
        $this->newLine();
        $this->info('Best parameter set');
        $this->line("Profile: {$best['label']}");
        $this->line('Composite score: '.number_format((float) $best['score'], 4));
        $this->line('Overrides: '.json_encode($best['overrides'], JSON_UNESCAPED_SLASHES));

        if ($save) {
            try {
                $tuningStore->setForSeason($season, $best['overrides']);
            } catch (RuntimeException $exception) {
                $this->newLine();
                $this->error('Failed to save tuned params: '.$exception->getMessage());

                return self::FAILURE;
            }

            $this->newLine();
            $this->info("Saved tuned params for season {$season} to ".CbbTournamentForecastTuningStore::SETTINGS_KEY.'.');
        } else {
            $this->newLine();
            $this->warn('Dry run only. Use --save to persist tuned parameters.');
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $baseConfig
     * @return Collection<int, array{idx: int, label: string, overrides: array<string, mixed>}>
     */
    private function candidateConfigGrid(bool $full, array $baseConfig): Collection
    {
        $bubbleValues = $full ? [2.1, 2.4, 2.7, 3.0] : [2.2, 2.6, 3.0];
        $noiseValues = $full ? [0.20, 0.30, 0.40] : [0.25, 0.35];
        $upsetValues = $full ? [0.30, 0.40, 0.50, 0.60] : [0.35, 0.50];
        $profiles = $this->weightProfiles($baseConfig, $full);

        $grid = collect();
        $idx = 0;

        foreach ($profiles as $label => $profile) {
            foreach ($bubbleValues as $bubble) {
                foreach ($noiseValues as $noise) {
                    foreach ($upsetValues as $upset) {
                        $grid->push([
                            'idx' => $idx++,
                            'label' => $label,
                            'overrides' => [
                                'bubble_steepness' => $bubble,
                                'at_large_noise_stddev' => $noise,
                                'conference_tournament_upset_factor' => $upset,
                                'selection_weights' => $profile['selection_weights'],
                                'champion_weights' => $profile['champion_weights'],
                            ],
                        ]);
                    }
                }
            }
        }

        return $grid;
    }

    /**
     * @param  array<string, mixed>  $baseConfig
     * @return array<string, array{selection_weights: array<string, float>, champion_weights: array<string, float>}>
     */
    private function weightProfiles(array $baseConfig, bool $full): array
    {
        $selectionDefaults = (array) ($baseConfig['selection_weights'] ?? []);
        $championDefaults = (array) ($baseConfig['champion_weights'] ?? []);

        $profiles = [
            'Balanced' => [
                'selection_weights' => $selectionDefaults,
                'champion_weights' => $championDefaults,
            ],
            'ResumeHeavy' => [
                'selection_weights' => [
                    'adj_net_rating' => 0.24,
                    'rolling_net_rating' => 0.16,
                    'strength_of_schedule' => 0.26,
                    'elo_rating' => 0.12,
                    'win_pct' => 0.22,
                ],
                'champion_weights' => [
                    'elo_rating' => 0.42,
                    'adj_net_rating' => 0.24,
                    'rolling_net_rating' => 0.12,
                    'win_pct' => 0.12,
                    'strength_of_schedule' => 0.10,
                ],
            ],
            'PowerHeavy' => [
                'selection_weights' => [
                    'adj_net_rating' => 0.35,
                    'rolling_net_rating' => 0.24,
                    'strength_of_schedule' => 0.18,
                    'elo_rating' => 0.15,
                    'win_pct' => 0.08,
                ],
                'champion_weights' => [
                    'elo_rating' => 0.48,
                    'adj_net_rating' => 0.28,
                    'rolling_net_rating' => 0.12,
                    'win_pct' => 0.06,
                    'strength_of_schedule' => 0.06,
                ],
            ],
        ];

        if ($full) {
            $profiles['EloLean'] = [
                'selection_weights' => [
                    'adj_net_rating' => 0.27,
                    'rolling_net_rating' => 0.18,
                    'strength_of_schedule' => 0.15,
                    'elo_rating' => 0.30,
                    'win_pct' => 0.10,
                ],
                'champion_weights' => [
                    'elo_rating' => 0.55,
                    'adj_net_rating' => 0.22,
                    'rolling_net_rating' => 0.10,
                    'win_pct' => 0.08,
                    'strength_of_schedule' => 0.05,
                ],
            ];
        }

        return $profiles;
    }

    /**
     * @param  Collection<int, Collection<int, array<string, mixed>>>  $runs
     * @return array{score: float, stability_score: float, cutline_score: float, concentration_score: float}
     */
    private function evaluateCandidateRuns(Collection $runs, int $fieldSize): array
    {
        $teamRunRows = [];
        $topChampionProbabilities = [];
        $cutlineProbabilities = [];
        $bubbleGapValues = [];

        foreach ($runs as $run) {
            $sortedByMake = $run->sortByDesc('tournament_make_probability')->values();
            $cutlineIdx = max(0, min($sortedByMake->count() - 1, $fieldSize - 1));
            $aboveIdx = max(0, $cutlineIdx - 3);
            $belowIdx = min($sortedByMake->count() - 1, $cutlineIdx + 3);

            $cutlineProbabilities[] = (float) ($sortedByMake[$cutlineIdx]['tournament_make_probability'] ?? 0.0);
            $bubbleGapValues[] = (float) ($sortedByMake[$aboveIdx]['tournament_make_probability'] ?? 0.0)
                - (float) ($sortedByMake[$belowIdx]['tournament_make_probability'] ?? 0.0);

            $topChampionProbabilities[] = (float) $run->max('champion_probability');

            foreach ($run as $row) {
                $teamId = (int) ($row['team_id'] ?? 0);
                $teamRunRows[$teamId][] = [
                    'make' => (float) ($row['tournament_make_probability'] ?? 0.0),
                    'champion' => (float) ($row['champion_probability'] ?? 0.0),
                ];
            }
        }

        $stabilityAccumulator = 0.0;
        $stabilitySampleCount = 0;

        foreach ($teamRunRows as $rows) {
            $makeValues = array_column($rows, 'make');
            $championValues = array_column($rows, 'champion');
            $stabilityAccumulator += $this->stdDev($makeValues);
            $stabilityAccumulator += $this->stdDev($championValues) * 0.8;
            $stabilitySampleCount += 2;
        }

        $stabilityScore = $stabilitySampleCount > 0
            ? $stabilityAccumulator / $stabilitySampleCount
            : 1.0;

        $avgCutline = $this->average($cutlineProbabilities);
        $avgBubbleGap = $this->average($bubbleGapValues);
        $cutlineScore = abs($avgCutline - 0.55) + abs($avgBubbleGap - 0.12);

        $avgTopChampion = $this->average($topChampionProbabilities);
        $concentrationScore = abs($avgTopChampion - 0.11);

        $score = ($stabilityScore * 0.60)
            + ($cutlineScore * 0.25)
            + ($concentrationScore * 0.15);

        return [
            'score' => $score,
            'stability_score' => $stabilityScore,
            'cutline_score' => $cutlineScore,
            'concentration_score' => $concentrationScore,
        ];
    }

    /**
     * @return array<int, int>
     */
    private function seedSeries(int $season, int $candidateIndex, int $repeats): array
    {
        $base = ($season * 1000) + ($candidateIndex * 31);
        $seeds = [];

        for ($i = 0; $i < $repeats; $i++) {
            $seeds[] = $base + ($i * 137);
        }

        return $seeds;
    }

    /**
     * @param  array<int, float>  $values
     */
    private function average(array $values): float
    {
        if ($values === []) {
            return 0.0;
        }

        return array_sum($values) / count($values);
    }

    /**
     * @param  array<int, float>  $values
     */
    private function stdDev(array $values): float
    {
        if (count($values) <= 1) {
            return 0.0;
        }

        $mean = $this->average($values);
        $sum = 0.0;
        foreach ($values as $value) {
            $sum += ($value - $mean) ** 2;
        }

        return sqrt($sum / count($values));
    }
}
