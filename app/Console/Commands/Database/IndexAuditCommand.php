<?php

namespace App\Console\Commands\Database;

use App\Services\Database\IndexAuditService;
use Illuminate\Console\Command;
use Throwable;

class IndexAuditCommand extends Command
{
    protected $signature = 'db:index-audit
        {--connection= : MySQL connection to inspect}
        {--table=* : Limit the audit to named tables}
        {--json : Output machine-readable JSON}';

    protected $description = 'Report duplicate, left-prefix, and low-cardinality index candidates without changing schema';

    public function handle(IndexAuditService $audit): int
    {
        try {
            $findings = $audit->inspect(
                $this->option('connection') ?: null,
                array_values(array_filter(array_map('strval', (array) $this->option('table')))),
            );
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line(json_encode([
                'advisory_only' => true,
                'findings' => $findings,
            ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->table(['Table', 'Index', 'Candidate', 'Covered by', 'Columns'], array_map(
            fn (array $finding): array => [
                $finding['table'],
                $finding['index'],
                $finding['kind'],
                $finding['covered_by'] ?? '-',
                implode(', ', $finding['columns']),
            ],
            $findings,
        ));
        $this->warn('Advisory only: capture real query plans and production latency before any contract migration removes an index.');

        return self::SUCCESS;
    }
}
