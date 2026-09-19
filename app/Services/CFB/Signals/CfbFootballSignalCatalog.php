<?php

namespace App\Services\CFB\Signals;

/**
 * Executable research hypotheses, not 200 independently validated edges.
 * Evaluate each definition once per team orientation; never count orientation as another signal.
 * Historical feature values require three observed games. Fit effect sizes out of sample.
 */
final class CfbFootballSignalCatalog
{
    public const VERSION = 'cfb-football-signals-v1';

    private static ?array $catalog = null;

    private const ALIASES = [
        't' => 'team.history.current_season', 'o' => 'opponent.history.current_season',
        'r' => 'team.history.last3', 'p' => 'team.history.prior_season', 'v' => 'team.history.venue',
        'ta' => 'team.history.away', 'th' => 'team.history.home', 'tn' => 'team.history.neutral',
        'tc' => 'team.history.conference', 'tx' => 'team.history.nonconference',
        'tm' => 'team.metrics', 'om' => 'opponent.metrics', 'pm' => 'team.prior_metrics',
        'tp' => 'team.personnel', 'op' => 'opponent.personnel',
        'tl' => 'team.late', 'ol' => 'opponent.late',
    ];

    /** @return array<string,array<string,mixed>> */
    public static function all(): array
    {
        if (self::$catalog !== null) {
            return self::$catalog;
        }
        $rules = [];
        foreach (file(__DIR__.'/football-signals.tsv', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            [$id, $family, $label, $market, $expression] = explode('|', $line);
            $conditions = $inputs = [];
            foreach (explode(';', $expression) as $clause) {
                [$field, $operator, $value] = explode(' ', $clause, 3);
                $field = self::path($field);
                $inputs[] = $field;
                $leaf = ['field' => $field, 'operator' => $operator];
                if (str_starts_with($value, '@')) {
                    $leaf['other_field'] = self::path(substr($value, 1));
                    $inputs[] = $leaf['other_field'];
                } else {
                    $leaf['value'] = match ($value) {
                        'true' => true, 'false' => false, default => (float) $value,
                    };
                }
                $conditions[] = $leaf;
            }
            $rules[$id] = ['id' => $id, 'label' => $label, 'family' => $family,
                'inputs' => array_values(array_unique($inputs)), 'condition' => ['all' => $conditions],
                'market' => $market, 'direction' => $market === 'total' ? 'learned_total_residual' : 'learned_team_margin_residual',
                'outcome' => $market === 'total' ? 'actual_total_minus_frozen_base_total' : 'actual_team_margin_minus_frozen_base_team_margin',
                'minimum_history_games' => 3, 'validation_status' => 'registered_hypothesis',
                'coefficient' => null, 'effect_policy' => 'learn_from_eligible_prior_frozen_predictions_only'];
        }

        return self::$catalog = $rules;
    }

    /** Null means unavailable required evidence, not false or a zero-valued measurement. */
    public static function evaluate(array $definition, array $features): ?bool
    {
        $unknown = false;
        foreach ($definition['condition']['all'] as $condition) {
            $left = data_get($features, $condition['field']);
            $right = isset($condition['other_field']) ? data_get($features, $condition['other_field']) : $condition['value'];
            if ($left === null || $right === null || (! is_bool($left) && ! is_numeric($left))
                || (! is_bool($right) && ! is_numeric($right))) {
                $unknown = true;

                continue;
            }
            $result = match ($condition['operator']) {
                '>' => $left > $right, '>=' => $left >= $right,
                '<' => $left < $right, '<=' => $left <= $right,
                '==' => is_bool($right) ? $left === $right : (float) $left === (float) $right,
                default => throw new \InvalidArgumentException('Unsupported signal operator'),
            };
            if (! $result) {
                return false;
            }
        }

        return $unknown ? null : true;
    }

    /** Adapt an immutable canonical snapshot. No database, live odds, or present-day reads. */
    public static function features(array $inputs, string $side): array
    {
        if (! in_array($side, ['home', 'away'], true)) {
            throw new \InvalidArgumentException('Signal side must be home or away');
        }
        $other = $side === 'home' ? 'away' : 'home';
        $context = (array) ($inputs['signal_context'] ?? []);
        $context['home'] = $side === 'home';
        $context['neutral'] = data_get($inputs, 'event.neutral_site');
        $context['week'] = data_get($inputs, 'event.week');
        $context['rest_days'] = data_get($inputs, 'historical_signals.'.$side.'.rest_days.value');
        $context['opponent_rest_days'] = data_get($inputs, 'historical_signals.'.$other.'.rest_days.value');
        $context['spread'] = is_numeric($context['home_spread'] ?? null)
            ? (float) $context['home_spread'] * ($side === 'home' ? 1 : -1) : null;
        // Outdoor weather cannot be attributed to an indoor playing environment.
        if (($context['is_indoor'] ?? null) === true) {
            foreach (['wind_speed_mph', 'wind_gust_mph', 'precipitation_inches', 'temperature_f'] as $field) {
                $context[$field] = null;
            }
        }

        return ['context' => $context, 'team' => self::teamFeatures($inputs, $side),
            'opponent' => self::teamFeatures($inputs, $other)];
    }

    private static function teamFeatures(array $inputs, string $side): array
    {
        $team = (array) ($inputs[$side] ?? []);
        $out = ['elo' => $team['elo'] ?? null, 'metrics' => $team['metrics'] ?? [],
            'prior_metrics' => $team['prior_metrics'] ?? [], 'history' => [], 'personnel' => [], 'late' => []];
        foreach ((array) data_get($inputs, 'historical_signals.'.$side.'.windows', []) as $window => $data) {
            foreach ((array) ($data['metrics'] ?? []) as $metric => $evidence) {
                $out['history'][$window][$metric] = ($evidence['sample_games'] ?? 0) >= 3
                    && is_numeric($evidence['value'] ?? null) ? (float) $evidence['value'] : null;
            }
        }
        $seasonSacks = data_get($team, 'prior_metrics.season_sack_evidence', []);
        if (data_get($out, 'history.prior_season.sacks_allowed_per_game') === null
            && ($seasonSacks['source'] ?? null) === 'cfbd_stats_season'
            && (int) ($seasonSacks['season'] ?? 0) === (int) data_get($inputs, 'event.season') - 1
            && is_numeric($seasonSacks['games'] ?? null) && $seasonSacks['games'] >= 3
            && is_numeric($seasonSacks['sacks_allowed'] ?? null) && $seasonSacks['sacks_allowed'] >= 0) {
            // A complete prior-season aggregate supplies its mean without fabricating per-game values.
            $out['history']['prior_season']['sacks_allowed_per_game'] = $seasonSacks['sacks_allowed'] / $seasonSacks['games'];
        }
        $venue = data_get($inputs, 'event.neutral_site') === true ? 'neutral' : $side;
        $out['history']['venue'] = $out['history'][$venue] ?? [];
        $components = (array) data_get($team, 'personnel.components', []);
        foreach (['returning_usage' => ['returning_production', 'usage'],
            'returning_passing_usage' => ['returning_production', 'passingUsage'],
            'returning_rushing_usage' => ['returning_production', 'rushingUsage'],
            'returning_receiving_usage' => ['returning_production', 'receivingUsage'],
            'talent' => ['talent', 'talent'], 'recruiting_rank' => ['recruiting', 'rank'],
            'qb_changed' => ['quarterback', 'observed_primary_passer_changed']] as $field => [$component, $value]) {
            $out['personnel'][$field] = ($components[$component]['status'] ?? null) === 'verified'
                ? data_get($components[$component], 'values.'.$value) : null;
        }
        $coach = $components['head_coach'] ?? [];
        $out['personnel']['coach_changed'] = ($coach['status'] ?? null) === 'verified'
            && count(data_get($coach, 'values.current', [])) === 1 && count(data_get($coach, 'values.prior', [])) === 1
            ? data_get($coach, 'values.current.0.name') !== data_get($coach, 'values.prior.0.name') : null;
        $out['personnel']['unrated_transfers'] = ($components['transfers']['status'] ?? null) === 'verified'
            ? data_get($components, 'transfers.rating_coverage.unrated') : null;
        $large = (array) ($team['large_spread_evidence'] ?? []);
        foreach (['plays' => ['pace', 'plays_proxy_per_game'], 'fourth_margin' => ['late_game', 'fourth_quarter_margin'],
            'fourth_allowed' => ['late_game', 'fourth_quarter_points_allowed'],
            'leading_fourth_margin' => ['late_game', 'fourth_margin_when_leading_20_plus']] as $field => [$group, $value]) {
            $sample = $field === 'leading_fourth_margin' ? 'entered_fourth_leading_20_plus_games' : 'sample_games';
            $out['late'][$field] = data_get($large, $group.'.'.$sample, 0) >= 3 ? data_get($large, $group.'.'.$value) : null;
        }

        return $out;
    }

    private static function path(string $path): string
    {
        [$prefix, $suffix] = explode('.', $path, 2);

        return (self::ALIASES[$prefix] ?? $prefix).'.'.$suffix;
    }
}
