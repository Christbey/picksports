<?php

namespace App\Services\NFL;

use App\Models\NFL\TeamMetricSnapshot;
use App\Support\Sports\ExplicitOffsetInstant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

/** Receipt provenance, independent of mutable metric row timestamps. */
final class TeamMetricSnapshotObservation
{
    public const VERSION = 'nfl-metric-observation-v1';

    public const CURRENT = 'current_capture';

    public const RECONSTRUCTED = 'historical_reconstruction';

    public static function values(array $row): array
    {
        $values = [];
        foreach (['team_id', 'season', 'wins', 'losses'] as $field) {
            $values[$field] = (int) $row[$field];
        }
        foreach (['predictive_rating', 'future_strength_of_schedule', 'recent_form_rating', 'injury_total_adjustment'] as $field) {
            $values[$field] = $row[$field] === null ? null : number_format((float) $row[$field], 3, '.', '');
        }
        $values['calculation_date'] = $row['calculation_date'] === null ? null : substr((string) $row['calculation_date'], 0, 10);

        return $values;
    }

    public static function hash(array $row): string
    {
        return hash('sha256', json_encode(self::values($row), JSON_THROW_ON_ERROR));
    }

    public static function key(array $row, string $mode, string $cutoff): string
    {
        return sha1(json_encode([self::VERSION, $mode, (int) $row['team_id'], (int) $row['season'], $cutoff], JSON_THROW_ON_ERROR));
    }

    public static function receipt(array $row, string $mode, array $source, string $cutoff, string $observed): array
    {
        $receipt = ['schema' => self::VERSION, 'mode' => $mode, 'observed_at' => $observed,
            'cutoff_at' => $cutoff, 'storage_timezone' => (string) config('app.timezone', 'UTC'),
            'source' => $source, 'values_hash' => self::hash($row)];
        $receipt['receipt_hash'] = self::descriptorHash($receipt);

        return $receipt;
    }

    private static function exactKeys(array $value, array $keys): bool
    {
        $actual = array_keys($value);
        sort($actual);
        sort($keys);

        return $actual === $keys;
    }

    public static function lineage(TeamMetricSnapshot $snapshot): array
    {
        $unknown = ['mode' => 'legacy_unverified', 'observed_at' => null,
            'cutoff_at' => null, 'historical_availability_proven' => false];
        $raw = $snapshot->getRawOriginal('observation');
        if (! is_string($raw) || strlen($raw) > 4096) {
            return $unknown;
        }
        try {
            $receipt = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
            if (! is_array($receipt)
                || ! self::exactKeys($receipt, ['schema', 'mode', 'observed_at', 'cutoff_at', 'storage_timezone', 'source', 'values_hash', 'receipt_hash'])
                || $receipt['schema'] !== self::VERSION
                || ! in_array($receipt['mode'], [self::CURRENT, self::RECONSTRUCTED], true)
                || ! is_array($receipt['source'])
                || ! is_string($receipt['values_hash']) || ! preg_match('/^[a-f0-9]{64}$/D', $receipt['values_hash'])
                || ! is_string($receipt['receipt_hash']) || ! preg_match('/^[a-f0-9]{64}$/D', $receipt['receipt_hash'])
                || ! hash_equals(self::hash($snapshot->getAttributes()), $receipt['values_hash'])
                || ! is_string($receipt['storage_timezone'])
                || ! in_array($receipt['storage_timezone'], \DateTimeZone::listIdentifiers(), true)) {
                return $unknown;
            }
            $signed = $receipt;
            unset($signed['receipt_hash']);
            if (! hash_equals(self::descriptorHash($signed), $receipt['receipt_hash'])) {
                return $unknown;
            }
            $observed = ExplicitOffsetInstant::parse($receipt['observed_at']);
            $cutoff = ExplicitOffsetInstant::parse($receipt['cutoff_at']);
            if ($observed === null || $cutoff === null || $observed->isFuture()
                || $receipt['observed_at'] !== $observed->format('Y-m-d\TH:i:s.u\Z')
                || $receipt['cutoff_at'] !== $cutoff->toIso8601String()
                || $snapshot->getRawOriginal('snapshot_key') !== self::key($snapshot->getAttributes(), $receipt['mode'], $receipt['cutoff_at'])) {
                return $unknown;
            }
            $stored = $snapshot->getRawOriginal('captured_at');
            if (! is_string($stored) || ! preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $stored)) {
                return $unknown;
            }
            $carrier = CarbonImmutable::createFromFormat('!Y-m-d H:i:s', $stored, $receipt['storage_timezone']);
            if ($carrier === false || $carrier->format('Y-m-d H:i:s') !== $stored || ! $carrier->eq($cutoff)) {
                return $unknown;
            }
            if ($receipt['mode'] === self::CURRENT
                && (! self::exactKeys($receipt['source'], ['metric_id', 'season_type'])
                    || ! is_int($receipt['source']['metric_id']) || $receipt['source']['metric_id'] <= 0
                    || $receipt['source']['season_type'] !== (string) config('nfl.season.types.regular', 2))) {
                return $unknown;
            }
            if ($receipt['mode'] === self::RECONSTRUCTED
                && (! self::exactKeys($receipt['source'], ['calculator', 'reconstruct_score_priors'])
                    || $receipt['source']['calculator'] !== HistoricalTeamMetricCalculator::class
                    || ! is_bool($receipt['source']['reconstruct_score_priors']))) {
                return $unknown;
            }

            return ['mode' => $receipt['mode'], 'observed_at' => $observed->format('Y-m-d\TH:i:s.u\Z'),
                'cutoff_at' => $cutoff->toIso8601String(), 'source' => $receipt['source'],
                'values_hash' => $receipt['values_hash'],
                'historical_availability_proven' => $receipt['mode'] === self::CURRENT,
                'limitation' => $receipt['mode'] === self::CURRENT
                    ? 'Captured metric values were observed at this receipt time; other forecast inputs are not certified.'
                    : 'Computed later from the historical calculator; not a contemporaneous observed forecast.'];
        } catch (\Throwable) {
            return $unknown;
        }
    }

    public static function descriptorHash(array $value): string
    {
        return hash('sha256', json_encode(self::normalize($value), JSON_THROW_ON_ERROR));
    }

    private static function normalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(self::normalize(...), $value);
    }

    public static function sameDescriptor(array $left, array $right): bool
    {
        return self::normalize($left) === self::normalize($right);
    }

    public static function summarize(array $rows): array
    {
        $modes = [];
        foreach ($rows as $row) {
            $mode = $row['metric_evidence']['mode'] ?? 'legacy_unverified';
            $modes[$mode] = ($modes[$mode] ?? 0) + 1;
        }
        ksort($modes);

        return ['mode_counts' => $modes, 'limitation' => 'Metric receipt lineage only; results, odds and other forecast inputs are not certified as historically observed.'];
    }

    public static function eligibleAt(array $lineage, Carbon $asOf): bool
    {
        return ($lineage['mode'] ?? null) === self::RECONSTRUCTED
            || (($lineage['mode'] ?? null) === self::CURRENT
                && CarbonImmutable::parse($lineage['observed_at'])->lte($asOf));
    }
}
