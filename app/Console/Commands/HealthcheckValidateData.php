<?php

namespace App\Console\Commands;

use App\Actions\Validation\SportValidator;
use App\Models\Healthcheck;
use App\Support\SportCatalog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class HealthcheckValidateData extends Command
{
    protected $signature = 'healthcheck:validate-data {--sport= : Specific sport to validate (mlb, nba, nfl, cbb, cfb, wcbb, wnba)}';

    protected $description = 'Run deep data validation checks across sports';

    public function __construct(private readonly SportValidator $sportValidator)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->info('Running data validation checks...');

        $sports = $this->option('sport') ? [$this->option('sport')] : SportCatalog::ALL;

        foreach ($sports as $sport) {
            $this->line("Validating {$sport}...");

            $results = $this->sportValidator->validate($sport);

            if ($results === []) {
                $this->recordCheck(
                    $sport,
                    'validation_configuration',
                    'warning',
                    'No validation profile or checks configured for this sport.',
                    []
                );

                continue;
            }

            foreach ($results as $result) {
                $this->recordCheck(
                    $sport,
                    (string) $result['check_type'],
                    (string) $result['status'],
                    (string) $result['message'],
                    (array) ($result['metadata'] ?? [])
                );
            }
        }

        return $this->displayResults();
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
        $this->info('Validation Summary:');

        $results = Healthcheck::query()
            ->where('checked_at', '>=', now()->subMinutes(10))
            ->where('check_type', 'like', 'validation_%')
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
            ->where('checked_at', '>=', now()->subMinutes(10))
            ->where('check_type', 'like', 'validation_%')
            ->where('status', 'failing')
            ->count();

        return $failing > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
