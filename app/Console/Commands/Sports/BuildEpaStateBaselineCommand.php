<?php

namespace App\Console\Commands\Sports;

use App\Services\Epa\StateBaselineService;
use App\Services\NBA\TrueEpaCalculator as BasketballTrueEpaCalculator;
use App\Services\NFL\TrueEpaCalculator as NflTrueEpaCalculator;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class BuildEpaStateBaselineCommand extends Command
{
    protected $signature = 'sports:build-epa-state-baseline
        {sport : One of nfl,nba,cbb,wcbb}
        {--season= : Target season to write baseline for}
        {--from-season= : Source season used to train baseline}
        {--min-samples= : Minimum samples required per state key}
        {--limit-games=0 : Limit number of source games for fast iteration}
        {--dry-run : Compute and report without persisting}';

    protected $description = 'Build out-of-sample EPA expected-points state baselines by sport and season';

    /**
     * @var array<string,array{game_model:class-string<Model>,play_model:class-string<Model>}>
     */
    private const SPORT_MODELS = [
        'nba' => ['game_model' => \App\Models\NBA\Game::class, 'play_model' => \App\Models\NBA\Play::class],
        'cbb' => ['game_model' => \App\Models\CBB\Game::class, 'play_model' => \App\Models\CBB\Play::class],
        'wcbb' => ['game_model' => \App\Models\WCBB\Game::class, 'play_model' => \App\Models\WCBB\Play::class],
        'nfl' => ['game_model' => \App\Models\NFL\Game::class, 'play_model' => \App\Models\NFL\Play::class],
    ];

    public function handle(
        StateBaselineService $baselineService,
        BasketballTrueEpaCalculator $basketballCalculator,
        NflTrueEpaCalculator $nflCalculator
    ): int {
        $sport = strtolower((string) $this->argument('sport'));
        if (! isset(self::SPORT_MODELS[$sport])) {
            $this->error("Unsupported sport '{$sport}'. Allowed: ".implode(', ', array_keys(self::SPORT_MODELS)));

            return self::FAILURE;
        }

        $fromSeason = (int) ($this->option('from-season') ?: date('Y') - 1);
        $targetSeason = (int) ($this->option('season') ?: ($fromSeason + 1));
        $minSamples = max(1, (int) ($this->option('min-samples') ?: config('epa.state_baseline.min_sample_size', 25)));
        $limitGames = max(0, (int) $this->option('limit-games'));
        $dryRun = (bool) $this->option('dry-run');

        $models = self::SPORT_MODELS[$sport];
        $gameModel = $models['game_model'];
        $playModel = $models['play_model'];

        $gameQuery = $gameModel::query()
            ->where('season', $fromSeason)
            ->where('status', 'STATUS_FINAL')
            ->orderByDesc('game_date');

        if ($limitGames > 0) {
            $gameQuery->limit($limitGames);
        }

        $games = $gameQuery->get(['id']);
        if ($games->isEmpty()) {
            $this->warn('No source games found for baseline build.');

            return self::SUCCESS;
        }

        $this->info("Building {$sport} EPA state baseline from season {$fromSeason} -> target {$targetSeason}");
        $this->line('Source games: '.$games->count());
        $bar = $this->output->createProgressBar($games->count());
        $bar->start();

        $stateBuckets = [];

        foreach ($games as $game) {
            $plays = $playModel::query()
                ->where('game_id', (int) $game->id)
                ->where('is_epa_eligible', true)
                ->whereNotNull('possession_team_id')
                ->whereNotNull('expected_points_before')
                ->orderBy('sequence_number')
                ->orderBy('id')
                ->get([
                    'id',
                    'period',
                    'clock',
                    'play_type',
                    'play_text',
                    'down',
                    'distance',
                    'yards_to_endzone',
                    'home_score',
                    'away_score',
                    'possession_team_id',
                    'expected_points_before',
                ]);

            if ($plays->isEmpty()) {
                $bar->advance();
                continue;
            }

            if ($sport === 'nfl') {
                foreach ($plays as $play) {
                    $stateKey = $nflCalculator->stateKeyForPlay($play);
                    $this->pushStateSample($stateBuckets, $stateKey, (float) $play->expected_points_before);
                }

                $bar->advance();
                continue;
            }

            [$halfMode, $periodDurationSeconds] = $basketballCalculator->derivePeriodContext($plays);
            foreach ($plays as $play) {
                $stateKey = $basketballCalculator->stateKeyForPlay($play, $halfMode, $periodDurationSeconds);
                $this->pushStateSample($stateBuckets, $stateKey, (float) $play->expected_points_before);
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        if ($stateBuckets === []) {
            $this->warn('No eligible state samples found.');

            return self::SUCCESS;
        }

        $rows = [];
        $dropped = 0;
        foreach ($stateBuckets as $stateKey => $bucket) {
            $count = (int) $bucket['count'];
            if ($count < $minSamples) {
                $dropped++;
                continue;
            }

            $rows[] = [
                'state_key' => $stateKey,
                'expected_points' => $bucket['sum'] / $count,
                'sample_size' => $count,
            ];
        }

        usort($rows, fn (array $a, array $b): int => $b['sample_size'] <=> $a['sample_size']);

        $this->line('Distinct states (raw): '.count($stateBuckets));
        $this->line("Distinct states retained (min_samples={$minSamples}): ".count($rows));
        $this->line('Dropped for low samples: '.$dropped);

        $previewRows = [];
        foreach (array_slice($rows, 0, 15) as $row) {
            $previewRows[] = [
                $row['state_key'],
                number_format($row['expected_points'], 4),
                $row['sample_size'],
            ];
        }
        $this->table(['State Key', 'EP', 'Samples'], $previewRows);

        if ($dryRun) {
            $this->info('Dry run complete. No baseline rows persisted.');

            return self::SUCCESS;
        }

        $baselineService->replaceSeasonBaseline($sport, $targetSeason, $fromSeason, $rows);
        $this->info("Saved baseline rows for {$sport} season {$targetSeason}: ".count($rows));

        return self::SUCCESS;
    }

    /**
     * @param  array<string,array{sum:float,count:int}>  $stateBuckets
     */
    private function pushStateSample(array &$stateBuckets, string $stateKey, float $expectedPointsBefore): void
    {
        if (! isset($stateBuckets[$stateKey])) {
            $stateBuckets[$stateKey] = ['sum' => 0.0, 'count' => 0];
        }

        $stateBuckets[$stateKey]['sum'] += $expectedPointsBefore;
        $stateBuckets[$stateKey]['count']++;
    }
}
