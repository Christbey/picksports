<?php

namespace App\Services\Sports\Elo;

use App\DataTransferObjects\ESPN\GameData;
use App\Models\SportEloWriterGate;
use DomainException;

class EloEligibilityPolicy
{
    public const LIMITS = ['games' => 10000, 'pitching_observations' => 100000, 'pitching_per_game' => 1000, 'entities_per_type' => 10000];

    public function specification(string $sport): array
    {
        if (! in_array($sport, SportEloWriterGate::SPORTS, true)) {
            throw new DomainException('Unsupported guarded Elo eligibility sport.');
        }
        $types = config($sport.'.season.types');
        if (! is_array($types)) {
            throw new DomainException('Explicit guarded Elo season type codes are required.');
        }
        $included = [];
        foreach (['regular', 'postseason'] as $key) {
            $included[] = $this->configurationCode($types[$key] ?? null);
        }
        $excluded = [];
        foreach (['preseason', 'spring_training', 'allstar'] as $key) {
            if (array_key_exists($key, $types)) {
                $excluded[] = $this->configurationCode($types[$key]);
            }
        }
        if (count(array_unique($included)) !== 2 || count(array_unique($excluded)) !== count($excluded)
            || array_intersect($included, $excluded) !== []) {
            throw new DomainException('Guarded Elo season type codes are ambiguous.');
        }
        sort($included);
        sort($excluded);

        return ['version' => 'observed-competitive-events-v1', 'included_codes' => $included, 'excluded_codes' => $excluded,
            'final_statuses' => GameData::finalStatuses(), 'unknown_final_type' => 'blocks', 'isolation' => 'mysql_repeatable_read_or_sqlite_serialized',
            'sql_clock' => EloSqlClockPolicy::policy(),
            'legacy_clock_binding' => ['timezone' => config('app.timezone'), 'carrier' => 'same_utc_second_or_refuse', 'capture_evidence' => 'exact_utc_metadata_legacy_seconds_columns'],
            'incomplete_final_facts' => 'blocks', 'resource_preflight' => 'scalar_current_locks_before_value_materialization', 'mutable_legacy_opener_inference' => 'not_used', 'limits' => self::LIMITS, 'dependency_graph' => EloDependencyGraph::policy(),
            ...($sport === 'nfl' ? ['imported_clock' => EloSourceContext::NFL_IMPORT_CLOCK_POLICY] : []),
            ...($sport === 'mlb' ? ['source_clock' => EloSourceContext::MLB_CLOCK_POLICY] : [])];
    }

    public function classify(string $sport, mixed $storedType): string
    {
        $policy = $this->specification($sport);
        if ((! is_string($storedType) && ! is_int($storedType)) || preg_match('/\A[1-9][0-9]*\z/', (string) $storedType) !== 1) {
            return 'unknown';
        }
        $code = (string) $storedType;

        return in_array($code, $policy['included_codes'], true) ? 'competitive'
            : (in_array($code, $policy['excluded_codes'], true) ? 'excluded' : 'unknown');
    }

    private function configurationCode(mixed $value): string
    {
        if ((! is_int($value) && ! is_string($value)) || preg_match('/\A[1-9][0-9]*\z/', (string) $value) !== 1 || strlen((string) $value) > 3) {
            throw new DomainException('Guarded Elo eligibility needs explicit canonical season codes.');
        }

        return (string) $value;
    }
}
