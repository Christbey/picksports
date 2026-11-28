<?php

namespace App\Services\CollegeFootballData\Fpi;

use App\Support\Json\JsonAllocationBudget;
use App\Support\Sports\ExplicitOffsetInstant;
use Closure;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/** Own-written bounded strings; the framework cache backend/deserializer remains trusted. */
final class FpiSourceCache
{
    public function acquire(FpiAcquisition $context, FpiSeasonCoordinator $coordinator, Closure $fetch): array
    {
        $prefix = $coordinator->cachePrefix($context);

        // No SQL transaction or row locks span cache IO or the provider callback.
        return Cache::lock($prefix.':fetch', 120)->block(3, function () use ($context, $coordinator, $fetch): array {
            $origin = $coordinator->cacheOrigin($context);
            if ($origin !== null && ($raw = Cache::get($origin['key'])) !== null) {
                $snapshot = $this->decode($raw);
                if ($origin['rows'] !== count($snapshot['rows'])
                    || $origin['observed_at'] !== ExplicitOffsetInstant::parse($snapshot['observed_at'])->utc()->format('Y-m-d H:i:s.u')
                    || ! hash_equals($origin['hash'], $coordinator->responseHash($snapshot['rows'], ExplicitOffsetInstant::parse($snapshot['observed_at'])))) {
                    throw new RuntimeException('FPI applied source cache does not match its SQL origin hash.');
                }
                $observed = ExplicitOffsetInstant::parse($snapshot['observed_at']);
                if ($observed->addHour()->isFuture()) {
                    return $this->stage($context, $coordinator, $snapshot);
                }
            }

            return $this->stage($context, $coordinator, $fetch());
        });
    }

    private function stage(FpiAcquisition $context, FpiSeasonCoordinator $coordinator, mixed $snapshot): array
    {
        if (! is_array($snapshot) || ! is_array($snapshot['rows'] ?? null) || ! array_is_list($snapshot['rows'])
            || count($snapshot['rows']) > 1024 || ExplicitOffsetInstant::parse($snapshot['observed_at'] ?? null) === null) {
            throw new RuntimeException('FPI acquired source cache shape or clock is invalid.');
        }
        $nodes = 0;
        $bytes = 0;
        $this->plain($snapshot['rows'], 1, $nodes, $bytes);
        $rawRows = json_encode($snapshot['rows'], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        JsonAllocationBudget::inspect($rawRows, 2097152, 100000, 16, 'FPI acquired rows');
        $raw = '{"rows":'.$rawRows.',"observed_at":'.json_encode($snapshot['observed_at'], JSON_THROW_ON_ERROR).'}';
        $copy = $this->decode($raw); // Detaches provider PHP references before cache/command use.
        $observed = ExplicitOffsetInstant::parse($copy['observed_at']);
        $hash = $coordinator->responseHash($copy['rows'], $observed);
        $key = $coordinator->sourceCacheKey($context, $hash);
        $expiry = $observed->addHour();
        if ($expiry->isFuture()) {
            if (! Cache::add($key, $raw, $expiry)) {
                $existing = $this->decode(Cache::get($key));
                if (! hash_equals($hash, $coordinator->responseHash($existing['rows'], ExplicitOffsetInstant::parse($existing['observed_at'])))) {
                    throw new RuntimeException('FPI immutable source cache key has conflicting content.');
                }
            }
        }
        // A stale acquisition can at most leave its own expiring orphan, never a newer pointer.
        $coordinator->recordStagedSource($context, $key, $copy['rows'], $observed);

        return $copy;
    }

    private function decode(mixed $raw): array
    {
        if (! is_string($raw)) {
            throw new RuntimeException('FPI applied source cache requires a bounded JSON string.');
        }
        JsonAllocationBudget::inspect($raw, 2097280, 100010, 17, 'FPI source cache');
        $snapshot = json_decode($raw, true, 18, JSON_THROW_ON_ERROR);
        $observed = ExplicitOffsetInstant::parse($snapshot['observed_at'] ?? null);
        if (! is_array($snapshot) || ! is_array($snapshot['rows'] ?? null) || ! array_is_list($snapshot['rows'])
            || count($snapshot['rows']) > 1024 || $observed === null || $observed->isFuture()) {
            throw new RuntimeException('FPI applied source cache shape or clock is invalid.');
        }

        return ['rows' => $snapshot['rows'], 'observed_at' => $snapshot['observed_at']];
    }

    private function plain(mixed $value, int $depth, int &$nodes, int &$bytes): void
    {
        if ((is_array($value) && $depth > 16) || ++$nodes > 100000) {
            throw new RuntimeException('FPI acquired rows exceed the plain-data budget.');
        }
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $bytes += strlen((string) $key);
                $this->plain($item, $depth + 1, $nodes, $bytes);
            }
        } elseif (is_string($value)) {
            $bytes += strlen($value);
        } elseif (! is_int($value) && ! is_bool($value) && $value !== null && (! is_float($value) || ! is_finite($value))) {
            throw new RuntimeException('FPI acquired source must contain only finite plain data.');
        }
        if ($bytes > 2097152) {
            throw new RuntimeException('FPI acquired plain-data byte budget exceeded.');
        }
    }
}
