<?php

namespace App\Console\Commands;

use App\Models\CommandHeartbeat;
use App\Models\Healthcheck;
use App\Support\SportCatalog;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class HealthcheckRun extends Command
{
    protected $signature = 'healthcheck:run
        {--sport= : Specific sport to check (mlb, nba, nfl, cbb, cfb, wcbb, wnba)}
        {--include-validation : Reserved for future deep data validation checks}';

    protected $description = 'Run heartbeat-first healthchecks to verify command pipelines are running';

    public function handle(): int
    {
        $this->info('Running heartbeat healthchecks...');

        if ((bool) $this->option('include-validation')) {
            $this->warn('Data validation checks are not enabled yet. Running heartbeat checks only.');
        }

        $sports = $this->option('sport') ? [(string) $this->option('sport')] : SportCatalog::ALL;

        foreach ($sports as $sport) {
            $this->line("Checking {$sport} heartbeat...");

            $this->checkSyncHeartbeat($sport);
            $this->checkLiveScoreboardHeartbeat($sport);
            $this->checkPredictionPipelineHeartbeat($sport);
            $this->checkModelPipelineHeartbeat($sport);
            $this->checkOddsHeartbeat($sport);
        }

        return $this->displayResults();
    }

    protected function checkSyncHeartbeat(string $sport): void
    {
        $inSeason = $this->isActiveSeason($sport);

        $patterns = [
            "espn:sync-{$sport}-current%",
            "espn:sync-{$sport}-games-scoreboard%",
            "espn:sync-{$sport}-games%",
        ];

        $thresholds = $inSeason
            ? ['warning' => 180, 'failing' => 360]
            : ['warning' => 1440, 'failing' => 4320];

        $this->evaluateCommandFreshness(
            sport: $sport,
            checkType: 'heartbeat_sync',
            commandPatterns: $patterns,
            warningAfterMinutes: $thresholds['warning'],
            failingAfterMinutes: $thresholds['failing'],
            label: 'sync pipeline',
            context: ['in_season' => $inSeason]
        );
    }

    protected function checkLiveScoreboardHeartbeat(string $sport): void
    {
        $inSeason = $this->isActiveSeason($sport);

        if (! $inSeason) {
            $this->recordCheck(
                $sport,
                'heartbeat_live_scoreboard',
                'passing',
                'Off-season. Live scoreboard heartbeat is not strictly required.',
                ['in_season' => false]
            );

            return;
        }

        $gameHours = $this->gameHoursForSport($sport);
        $currentHour = (int) now()->format('H');
        $isDuringGameHours = $gameHours['end'] < $gameHours['start']
            ? ($currentHour >= $gameHours['start'] || $currentHour < $gameHours['end'])
            : ($currentHour >= $gameHours['start'] && $currentHour < $gameHours['end']);

        $warningThreshold = $isDuringGameHours ? 15 : 120;
        $failingThreshold = $isDuringGameHours ? 30 : 240;

        $this->evaluateCommandFreshness(
            sport: $sport,
            checkType: 'heartbeat_live_scoreboard',
            commandPatterns: ["espn:sync-{$sport}-games-scoreboard%"],
            warningAfterMinutes: $warningThreshold,
            failingAfterMinutes: $failingThreshold,
            label: 'live scoreboard sync',
            context: [
                'in_season' => true,
                'is_during_game_hours' => $isDuringGameHours,
                'game_hours' => $gameHours,
                'current_hour' => $currentHour,
            ]
        );
    }

    protected function checkPredictionPipelineHeartbeat(string $sport): void
    {
        if (! in_array($sport, SportCatalog::STALE_PREDICTIONS, true)) {
            $this->recordCheck(
                $sport,
                'heartbeat_prediction_pipeline',
                'passing',
                'Prediction pipeline heartbeat is not applicable for this sport.',
                ['applicable' => false]
            );

            return;
        }

        $inSeason = $this->isActiveSeason($sport);
        $thresholds = $inSeason
            ? ['warning' => 1440, 'failing' => 2880] // 24h / 48h
            : ['warning' => 4320, 'failing' => 10080]; // 3d / 7d

        $this->evaluateCommandFreshness(
            sport: $sport,
            checkType: 'heartbeat_prediction_pipeline',
            commandPatterns: ["{$sport}:generate-predictions%", "{$sport}:grade-predictions%"],
            warningAfterMinutes: $thresholds['warning'],
            failingAfterMinutes: $thresholds['failing'],
            label: 'prediction pipeline',
            context: ['in_season' => $inSeason, 'applicable' => true]
        );
    }

    protected function checkModelPipelineHeartbeat(string $sport): void
    {
        $patterns = ["{$sport}:calculate-elo%"];

        if (in_array($sport, SportCatalog::TEAM_METRICS, true)) {
            $patterns[] = "{$sport}:calculate-team-metrics%";
        }

        $inSeason = $this->isActiveSeason($sport);
        $thresholds = $inSeason
            ? ['warning' => 1440, 'failing' => 4320] // 24h / 72h
            : ['warning' => 4320, 'failing' => 10080]; // 3d / 7d

        $this->evaluateCommandFreshness(
            sport: $sport,
            checkType: 'heartbeat_model_pipeline',
            commandPatterns: $patterns,
            warningAfterMinutes: $thresholds['warning'],
            failingAfterMinutes: $thresholds['failing'],
            label: 'model pipeline',
            context: ['in_season' => $inSeason]
        );
    }

    protected function checkOddsHeartbeat(string $sport): void
    {
        $inSeason = $this->isActiveSeason($sport);
        $thresholds = $inSeason
            ? ['warning' => 480, 'failing' => 720] // 8h / 12h
            : ['warning' => 1440, 'failing' => 4320]; // 1d / 3d

        $this->evaluateCommandFreshness(
            sport: $sport,
            checkType: 'heartbeat_odds',
            commandPatterns: ["{$sport}:sync-odds%"],
            warningAfterMinutes: $thresholds['warning'],
            failingAfterMinutes: $thresholds['failing'],
            label: 'odds sync',
            context: ['in_season' => $inSeason]
        );
    }

    /**
     * @param  array<int, string>  $commandPatterns
     * @param  array<string, mixed>  $context
     */
    protected function evaluateCommandFreshness(
        string $sport,
        string $checkType,
        array $commandPatterns,
        int $warningAfterMinutes,
        int $failingAfterMinutes,
        string $label,
        array $context = []
    ): void {
        $latestSuccess = $this->latestHeartbeat($sport, $commandPatterns, 'success');
        $latestFailure = $this->latestHeartbeat($sport, $commandPatterns, 'failure');

        if (! $latestSuccess) {
            $failureSuffix = $latestFailure
                ? " Last failure: {$latestFailure->ran_at?->toDateTimeString()}."
                : '';

            $this->recordCheck(
                $sport,
                $checkType,
                'failing',
                "No successful {$label} heartbeat recorded.{$failureSuffix}",
                array_merge($context, [
                    'command_patterns' => $commandPatterns,
                    'last_success_at' => null,
                    'last_failure_at' => $latestFailure?->ran_at?->toDateTimeString(),
                    'last_failure_error' => $latestFailure?->error,
                    'warning_after_minutes' => $warningAfterMinutes,
                    'failing_after_minutes' => $failingAfterMinutes,
                ])
            );

            return;
        }

        $ageMinutes = now()->diffInMinutes($latestSuccess->ran_at);

        $status = 'passing';
        if ($ageMinutes > $failingAfterMinutes) {
            $status = 'failing';
        } elseif ($ageMinutes > $warningAfterMinutes) {
            $status = 'warning';
        }

        $message = match ($status) {
            'passing' => ucfirst($label)." heartbeat is healthy. Last success {$ageMinutes} min ago.",
            'warning' => ucfirst($label)." heartbeat is stale. Last success {$ageMinutes} min ago.",
            'failing' => ucfirst($label)." heartbeat is overdue. Last success {$ageMinutes} min ago.",
            default => ucfirst($label)." heartbeat status unknown.",
        };

        $this->recordCheck(
            $sport,
            $checkType,
            $status,
            $message,
            array_merge($context, [
                'command_patterns' => $commandPatterns,
                'last_success_at' => $latestSuccess->ran_at?->toDateTimeString(),
                'age_minutes' => $ageMinutes,
                'last_failure_at' => $latestFailure?->ran_at?->toDateTimeString(),
                'last_failure_error' => $latestFailure?->error,
                'warning_after_minutes' => $warningAfterMinutes,
                'failing_after_minutes' => $failingAfterMinutes,
            ])
        );
    }

    /**
     * @param  array<int, string>  $commandPatterns
     */
    protected function latestHeartbeat(string $sport, array $commandPatterns, string $status): ?CommandHeartbeat
    {
        return CommandHeartbeat::query()
            ->where('sport', $sport)
            ->where('status', $status)
            ->where(function (Builder $query) use ($commandPatterns) {
                foreach ($commandPatterns as $index => $pattern) {
                    if ($index === 0) {
                        $query->where('command', 'like', $pattern);
                    } else {
                        $query->orWhere('command', 'like', $pattern);
                    }
                }
            })
            ->latest('ran_at')
            ->first();
    }

    protected function isActiveSeason(string $sport): bool
    {
        return match ($sport) {
            'mlb' => now()->month >= 3 && now()->month <= 10,
            'nba' => now()->month >= 10 || now()->month <= 6,
            'nfl' => now()->month >= 9 || now()->month <= 2,
            'cbb', 'wcbb' => now()->month >= 11 || now()->month <= 4,
            'cfb' => now()->month >= 8 || now()->month <= 1,
            'wnba' => now()->month >= 5 && now()->month <= 9,
            default => true,
        };
    }

    /**
     * @return array{start:int,end:int}
     */
    protected function gameHoursForSport(string $sport): array
    {
        return match ($sport) {
            'nba' => ['start' => 18, 'end' => 3],
            'cbb', 'wcbb', 'cfb' => ['start' => 12, 'end' => 1],
            'mlb' => ['start' => 13, 'end' => 4],
            'wnba' => ['start' => 19, 'end' => 23],
            'nfl' => ['start' => 17, 'end' => 2],
            default => ['start' => 12, 'end' => 2],
        };
    }

    protected function recordCheck(string $sport, string $checkType, string $status, string $message, array $metadata = []): void
    {
        Healthcheck::create([
            'sport' => $sport,
            'check_type' => $checkType,
            'status' => $status,
            'message' => $message,
            'metadata' => $metadata,
            'checked_at' => now(),
        ]);

        $color = match ($status) {
            'passing' => 'green',
            'warning' => 'yellow',
            'failing' => 'red',
            default => 'white',
        };

        $this->line("  [{$checkType}] <fg={$color}>{$status}</>: {$message}");
    }

    protected function displayResults(): int
    {
        $this->newLine();
        $this->info('Healthcheck Summary:');

        $results = Healthcheck::query()
            ->where('checked_at', '>=', now()->subMinutes(5))
            ->select('status', DB::raw('count(*) as count'))
            ->groupBy('status')
            ->get();

        foreach ($results as $result) {
            $color = match ($result->status) {
                'passing' => 'green',
                'warning' => 'yellow',
                'failing' => 'red',
                default => 'white',
            };

            $this->line("<fg={$color}>{$result->status}: {$result->count} checks</>");
        }

        $failing = Healthcheck::query()
            ->where('checked_at', '>=', now()->subMinutes(5))
            ->where('status', 'failing')
            ->count();

        return $failing > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
