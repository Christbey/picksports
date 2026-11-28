<?php

namespace App\Console\Commands;

use App\Services\ProviderData\Refresh\ProviderRefreshCoordinator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class ExpireProviderRefreshAttempts extends Command
{
    protected $signature = 'provider:expire-refresh-attempts {ids* : One to 100 explicit expired attempt IDs} {--apply : Record terminal expiry; default is dry run} {--connection= : Explicit configured write connection}';

    protected $description = 'Inspect or expire only explicitly selected abandoned provider refresh attempts without acquiring data';

    public function handle(ProviderRefreshCoordinator $coordinator): int
    {
        $raw = $this->argument('ids');
        if ($raw === [] || count($raw) > 100 || count(array_unique($raw)) !== count($raw)
            || collect($raw)->contains(fn ($id) => filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false)) {
            $this->error('Select one to 100 unique positive attempt IDs.');

            return self::FAILURE;
        }
        try {
            $results = $coordinator->expire(DB::connection($this->option('connection')), array_map(intval(...), $raw), (bool) $this->option('apply'));
            $this->table(['Attempt', 'Eligible', 'Applied', 'Outcome'], array_map(fn ($row) => [
                $row['attempt_id'], $row['eligible'] ? 'yes' : 'no', $row['applied'] ? 'yes' : 'no', $row['outcome'] ?? 'running',
            ], $results));

            return self::SUCCESS;
        } catch (Throwable $error) {
            $this->error('Refresh expiry refused; verify selected IDs, scope and reviewed write target.');

            return self::FAILURE;
        }
    }
}
