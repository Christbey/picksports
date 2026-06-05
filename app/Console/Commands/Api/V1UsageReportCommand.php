<?php

namespace App\Console\Commands\Api;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Symfony\Component\Finder\Finder;

class V1UsageReportCommand extends Command
{
    protected $signature = 'api:v1-usage-report
        {--path=* : Log file path(s) to inspect. Defaults to storage/logs/laravel*.log}
        {--limit=25 : Maximum number of grouped routes to display}
        {--json : Output machine-readable JSON}';

    protected $description = 'Summarize logged legacy product API v1 usage before route retirement.';

    public function handle(): int
    {
        $entries = $this->entries();
        $summary = $this->summarize($entries)
            ->sortByDesc('count')
            ->take(max((int) $this->option('limit'), 1))
            ->values();

        if ((bool) $this->option('json')) {
            $this->output->writeln(json_encode($summary->all(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        if ($summary->isEmpty()) {
            $this->info('No api.v1.usage entries found.');

            return self::SUCCESS;
        }

        $this->table(
            ['Method', 'Path', 'Route', 'Hits', 'Users', 'Latest'],
            $summary->map(fn (array $row): array => [
                $row['method'],
                $row['path'],
                $row['route_name'] ?? 'n/a',
                $row['count'],
                $row['unique_users'],
                $row['latest_at'] ?? 'n/a',
            ])->all()
        );

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function entries()
    {
        return collect($this->logPaths())
            ->flatMap(fn (string $path) => $this->parseFile($path))
            ->values();
    }

    /**
     * @return array<int, string>
     */
    private function logPaths(): array
    {
        $paths = (array) $this->option('path');

        if ($paths !== []) {
            return collect($paths)
                ->map(fn (string $path): string => str_starts_with($path, DIRECTORY_SEPARATOR) ? $path : base_path($path))
                ->map(fn (string $path): string => realpath($path) ?: $path)
                ->filter(fn (string $path): bool => is_file($path))
                ->values()
                ->all();
        }

        $directory = storage_path('logs');
        if (! is_dir($directory)) {
            return [];
        }

        return collect((new Finder)->files()->in($directory)->name('laravel*.log'))
            ->map(fn (\SplFileInfo $file): string => $file->getPathname())
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function parseFile(string $path): array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return [];
        }

        $entries = [];

        while (($line = fgets($handle)) !== false) {
            $entry = $this->parseLine($line);
            if ($entry !== null) {
                $entries[] = $entry;
            }
        }

        fclose($handle);

        return $entries;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function parseLine(string $line): ?array
    {
        if (! str_contains($line, 'api.v1.usage')) {
            return null;
        }

        if (! preg_match('/^\[(?<logged_at>[^\]]+)\].*api\.v1\.usage (?<context>\{.*\})\s*$/', trim($line), $matches)) {
            return null;
        }

        $context = json_decode($matches['context'], true);
        if (! is_array($context)) {
            return null;
        }

        return [
            'logged_at' => Carbon::parse($matches['logged_at'])->toIso8601String(),
            'method' => (string) ($context['method'] ?? 'UNKNOWN'),
            'path' => (string) ($context['path'] ?? 'unknown'),
            'route_name' => $context['route_name'] ?? null,
            'user_id' => $context['user_id'] ?? null,
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $entries
     * @return Collection<int, array<string, mixed>>
     */
    private function summarize($entries)
    {
        return $entries
            ->groupBy(fn (array $entry): string => implode('|', [
                $entry['method'],
                $entry['path'],
                $entry['route_name'] ?? '',
            ]))
            ->map(function ($group): array {
                $first = $group->first();

                return [
                    'method' => $first['method'],
                    'path' => $first['path'],
                    'route_name' => $first['route_name'],
                    'count' => $group->count(),
                    'unique_users' => $group
                        ->pluck('user_id')
                        ->filter(fn (mixed $userId): bool => $userId !== null)
                        ->unique()
                        ->count(),
                    'latest_at' => $group->max('logged_at'),
                ];
            });
    }
}
