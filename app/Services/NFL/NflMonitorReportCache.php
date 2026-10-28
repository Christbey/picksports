<?php

namespace App\Services\NFL;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/** HTTP reads last-good snapshots; only the scheduled command builds analytics. */
class NflMonitorReportCache
{
    private const PREFIX = 'nfl:monitor:reports:v1:';

    public function scope(?string $artifact, array $filters): array
    {
        $filters = array_filter(array_intersect_key($filters, array_flip(['from_season', 'to_season', 'model_version', 'validation_from_week'])), fn ($v) => $v !== null && $v !== '');
        foreach (['from_season', 'to_season', 'validation_from_week'] as $key) {
            if (isset($filters[$key])) {
                $filters[$key] = (int) $filters[$key];
            }
        }
        ksort($filters);

        return ['artifact' => $artifact ?: null, 'filters' => $filters];
    }

    private function key(array $scope): string
    {
        return self::PREFIX.hash('sha256', json_encode($scope, JSON_THROW_ON_ERROR));
    }

    public function read(?string $artifact, array $filters): array
    {
        $scope = $this->scope($artifact, $filters);
        $key = $this->key($scope);
        $saved = Cache::get($key);
        $this->register($scope);
        $generated = $saved['generated_at'] ?? null;
        $failed = Cache::has($key.':retry-after');

        return ['payload' => $saved['payload'] ?? null, 'status' => [
            'state' => $generated ? (Carbon::parse($generated)->lt(now()->subMinutes(30)) ? 'stale' : 'ready') : 'pending',
            'generated_at' => $generated, 'scope' => $scope,
            'message' => $failed ? 'The last background refresh failed. Saved results are retained while the scheduler retries.' : ($generated ? 'Showing the last successful background report. Refresh checks for a newer saved result.' : 'Preparing this report in the background. Check again after the next scheduled refresh.'),
        ]];
    }

    private function register(array $scope): void
    {
        $key = $this->key($scope);
        // No request waits for a busy lock and no request dispatches a sync queue job.
        Cache::lock(self::PREFIX.'registry-lock', 5)->get(function () use ($key, $scope): void {
            $registry = Cache::get(self::PREFIX.'scopes', []);
            unset($registry[$key]);
            $registry = [$key => ['scope' => $scope, 'requested_at' => now()->timestamp]] + $registry;
            uasort($registry, fn ($a, $b) => $b['requested_at'] <=> $a['requested_at']);
            foreach (array_slice($registry, 16, null, true) as $oldKey => $old) {
                if ($oldKey !== $this->key($this->scope(null, []))) {
                    Cache::forget($oldKey);
                }
            }
            Cache::forever(self::PREFIX.'scopes', array_slice($registry, 0, 16, true));
        });
    }

    public function legacy(): array
    {
        return Cache::get(self::PREFIX.'legacy', ['grades' => [], 'generated_at' => null]);
    }

    public function refresh(int $limit = 3, bool $force = true): array
    {
        $result = Cache::lock(self::PREFIX.'build-lock', 900)->get(function () use ($limit, $force): array {
            $service = app(NflModelMonitoringService::class);
            $default = $this->scope(null, []);
            $scopes = [$this->key($default) => ['scope' => $default]];
            foreach (Cache::get(self::PREFIX.'scopes', []) as $key => $entry) {
                if (($entry['requested_at'] ?? 0) >= now()->subDay()->timestamp) {
                    $scopes[$key] = $entry;
                }
            }
            $built = $attempted = 0;
            $failures = [];
            foreach ($scopes as $key => $entry) {
                if ($attempted >= max(1, min(4, $limit))) {
                    break;
                }
                if (! $force && Cache::has($key.':retry-after')) {
                    continue;
                }
                $saved = Cache::get($key);
                if (! $force && isset($saved['generated_at']) && Carbon::parse($saved['generated_at'])->gte(now()->subMinutes(15))) {
                    continue;
                }
                $attempted++;
                try {
                    $scope = $entry['scope'];
                    $payload = $service->dashboard($scope['artifact'], $scope['filters'], false);
                    Cache::forever($key, ['generated_at' => now()->toIso8601String(), 'payload' => $payload]);
                    Cache::forget($key.':retry-after');
                    $built++;
                } catch (\Throwable $e) {
                    report($e);
                    Cache::put($key.':retry-after', true, now()->addMinutes(5));
                    $failures[] = 'dashboard';
                }
            }
            $legacy = $this->legacy();
            try {
                if (! $force && Cache::has(self::PREFIX.'legacy:retry-after')) {
                    return ['built' => $built, 'failures' => $failures, 'last_good_preserved_on_failure' => true];
                }
                if (! $force && $legacy['generated_at'] && Carbon::parse($legacy['generated_at'])->gte(now()->subMinutes(15))) {
                    return ['built' => $built, 'failures' => $failures, 'last_good_preserved_on_failure' => true];
                }
                $grades = $service->signalGradePayload();
                Cache::forever(self::PREFIX.'legacy', ['generated_at' => now()->toIso8601String(), 'grades' => $grades]);
                Cache::forget(self::PREFIX.'legacy:retry-after');
            } catch (\Throwable $e) {
                report($e);
                Cache::put(self::PREFIX.'legacy:retry-after', true, now()->addMinutes(5));
                $failures[] = 'signal_grades';
            }

            return ['built' => $built, 'failures' => $failures, 'last_good_preserved_on_failure' => true];
        });

        return $result === false ? ['built' => 0, 'failures' => [], 'status' => 'already_running'] : $result;
    }
}
