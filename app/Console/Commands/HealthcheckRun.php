<?php

namespace App\Console\Commands;

use App\Models\Healthcheck;
use App\Services\Validation\HeartbeatHealthService;
use App\Support\HealthcheckOutcome;
use App\Support\SportCatalog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class HealthcheckRun extends Command
{
    protected $signature = 'healthcheck:run
        {--sport= : Specific sport to check}
        {--include-validation : Reserved for future deep data validation checks}
        {--report-only : Record unhealthy findings without failing successful execution}';

    protected $description = 'Run heartbeat-first healthchecks to verify command pipelines are running';

    public function handle(HeartbeatHealthService $service): int
    {
        $sport = $this->option('sport');
        if ($sport !== null && ! in_array($sport, SportCatalog::ALL, true)) {
            $this->error('Invalid --sport value. Supported sports: '.implode(', ', SportCatalog::ALL).'.');

            return self::FAILURE;
        }
        $this->info('Running heartbeat healthchecks...');
        if ($this->option('include-validation')) {
            $this->warn('Data validation checks are not enabled yet. Running heartbeat checks only.');
        }
        try {
            $checks = $service->evaluate($sport ? [$sport] : SportCatalog::ALL);
            DB::transaction(function () use ($checks): void {
                foreach ($checks as $check) {
                    Healthcheck::query()->create($check);
                }
            });
            foreach ($checks as $check) {
                $this->line("  [{$check['sport']}:{$check['check_type']}] {$check['status']}: {$check['message']}");
            }
            $counts = collect($checks)->countBy('status');
            $this->info('Healthcheck Summary:');
            foreach ($counts as $status => $count) {
                $this->line("{$status}: {$count} checks");
            }
            $status = $counts->get('failing', 0) > 0 ? 'failing' : ($counts->get('warning', 0) > 0 ? 'warning' : 'passing');

            return (new HealthcheckOutcome($status))->exitCode((bool) $this->option('report-only'));
        } catch (Throwable $error) {
            report($error);
            $this->error('Unable to complete heartbeat healthchecks.');

            return self::FAILURE;
        }
    }
}
