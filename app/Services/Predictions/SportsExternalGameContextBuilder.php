<?php

namespace App\Services\Predictions;

use App\Models\SportsGameContextReport;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class SportsExternalGameContextBuilder
{
    /** @return array<string, mixed> */
    public function build(string $sport, ?Model $game, ?Model $prediction = null): array
    {
        if (! $game || ! Schema::hasTable('sports_game_context_reports')) {
            return $this->unavailable('context_table_or_game_missing');
        }

        $report = SportsGameContextReport::query()
            ->where('sport', strtolower($sport))
            ->where('game_id', (int) $game->getKey())
            ->whereIn('status', ['ready', 'partial'])
            ->where(function ($query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->latest('researched_at')
            ->latest('id')
            ->first();

        if (! $report) {
            return $this->unavailable('no_fresh_research');
        }

        $adjustments = strtolower($sport) === 'nfl'
            ? $this->nflAdjustments($report)
            : ['home_margin_points' => 0.0, 'total_points' => 0.0, 'components' => []];
        $baseSpread = $this->numericAttribute($prediction, 'predicted_spread');
        $baseTotal = $this->numericAttribute($prediction, 'predicted_total');
        $contextSpread = $baseSpread !== null ? round($baseSpread + $adjustments['home_margin_points'], 2) : null;
        $contextTotal = $baseTotal !== null ? round($baseTotal + $adjustments['total_points'], 2) : null;
        $spreadCoefficient = (float) config('nfl.predictions.spread_to_probability_coefficient', 7.0);

        return [
            'schema_version' => 'sports_external_game_context_v1',
            'available' => true,
            'status' => $report->status,
            'report_id' => (int) $report->id,
            'researched_at' => $report->researched_at?->toIso8601String(),
            'expires_at' => $report->expires_at?->toIso8601String(),
            'confidence' => (int) $report->confidence,
            'summary' => $report->summary,
            'team_context' => $report->team_context ?? [],
            'situational_context' => $report->situational_context ?? [],
            'market_snapshot' => $report->market_snapshot ?? [],
            'facts' => array_values((array) ($report->facts ?? [])),
            'sources' => array_values((array) ($report->sources ?? [])),
            'risk_flags' => array_values((array) ($report->risk_flags ?? [])),
            'deterministic_adjustment' => $adjustments,
            'context_adjusted_model' => [
                'predicted_spread' => $contextSpread,
                'predicted_total' => $contextTotal,
                'home_win_probability' => $contextSpread !== null && $spreadCoefficient > 0
                    ? round(1 / (1 + exp(-$contextSpread / $spreadCoefficient)), 4)
                    : null,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function nflAdjustments(SportsGameContextReport $report): array
    {
        $home = (array) data_get($report->team_context, 'home', []);
        $away = (array) data_get($report->team_context, 'away', []);
        $minimumConfidence = (int) config('ai.features.nfl_game_context_research.minimum_adjustment_confidence', 55);
        $eligible = (int) $report->confidence >= $minimumConfidence
            && count((array) $report->sources) > 0
            && count((array) $report->facts) > 0;
        $components = [
            'starter_participation' => $eligible
                ? ($this->supportedTeamScore($report, 'starter_participation', 'home', fn () => $this->starterScore($home['starter_participation'] ?? null))
                    - $this->supportedTeamScore($report, 'starter_participation', 'away', fn () => $this->starterScore($away['starter_participation'] ?? null))) * 1.25
                : 0.0,
            'qb_rotation_quality' => $eligible
                ? ($this->supportedTeamScore($report, 'qb_rotation', 'home', fn () => $this->qbScore($home['qb_rotation_quality'] ?? null))
                    - $this->supportedTeamScore($report, 'qb_rotation', 'away', fn () => $this->qbScore($away['qb_rotation_quality'] ?? null))) * 0.75
                : 0.0,
            'coaching_intent' => $eligible
                ? ($this->supportedTeamScore($report, 'coaching_intent', 'home', fn () => $this->intentScore($home['coaching_intent'] ?? null))
                    - $this->supportedTeamScore($report, 'coaching_intent', 'away', fn () => $this->intentScore($away['coaching_intent'] ?? null))) * 0.5
                : 0.0,
            'injury_impact' => $eligible
                ? $this->supportedTeamScore($report, 'injury', 'home', fn () => $this->injuryPenalty($home['injury_impact'] ?? null))
                    - $this->supportedTeamScore($report, 'injury', 'away', fn () => $this->injuryPenalty($away['injury_impact'] ?? null))
                : 0.0,
        ];
        $homeMargin = max(-4.0, min(4.0, array_sum($components)));
        $homeStarterTotal = $eligible
            ? $this->supportedTeamScore($report, 'starter_participation', 'home', fn () => $this->starterTotal($home['starter_participation'] ?? null))
            : 0.0;
        $awayStarterTotal = $eligible
            ? $this->supportedTeamScore($report, 'starter_participation', 'away', fn () => $this->starterTotal($away['starter_participation'] ?? null))
            : 0.0;
        $weather = $eligible && $this->supports($report, 'weather', 'game')
            ? match (data_get($report->situational_context, 'weather_effect')) {
                'boosts_scoring' => 1.0,
                'suppresses_scoring' => -1.5,
                default => 0.0,
            }
        : 0.0;
        $jointPractice = $eligible
            && $this->supports($report, 'joint_practice', 'game')
            && data_get($report->situational_context, 'joint_practice_effect') === 'reduces_game_reps'
                ? -0.5
                : 0.0;
        $total = max(-3.0, min(3.0, $homeStarterTotal + $awayStarterTotal + $weather + $jointPractice));

        return [
            'home_margin_points' => round($homeMargin, 2),
            'total_points' => round($total, 2),
            'max_home_margin_adjustment' => 4.0,
            'max_total_adjustment' => 3.0,
            'components' => array_map(fn (float $value): float => round($value, 2), $components),
            'policy' => 'bounded_nfl_context_v1',
            'eligible' => $eligible,
            'minimum_confidence' => $minimumConfidence,
        ];
    }

    private function supportedTeamScore(
        SportsGameContextReport $report,
        string $category,
        string $side,
        callable $score,
    ): float {
        return $this->supports($report, $category, $side) ? (float) $score() : 0.0;
    }

    private function supports(SportsGameContextReport $report, string $category, string $side): bool
    {
        return collect((array) $report->facts)->contains(function ($fact) use ($category, $side): bool {
            if (! is_array($fact) || ($fact['category'] ?? null) !== $category) {
                return false;
            }

            if (! in_array($fact['certainty'] ?? null, ['confirmed', 'reported'], true)) {
                return false;
            }

            $factSide = $fact['team_side'] ?? 'game';

            return in_array($factSide, [$side, 'both', 'game'], true)
                && count((array) ($fact['source_urls'] ?? [])) > 0;
        });
    }

    private function starterScore(mixed $value): float
    {
        return match ($value) {
            'full' => 1.5,
            'extended' => 1.0,
            'limited' => 0.0,
            'none' => -1.0,
            default => 0.0,
        };
    }

    private function starterTotal(mixed $value): float
    {
        return match ($value) {
            'full' => 0.75,
            'extended' => 0.5,
            'none' => -0.5,
            default => 0.0,
        };
    }

    private function qbScore(mixed $value): float
    {
        return match ($value) {
            'strong' => 1.0,
            'weak' => -1.0,
            default => 0.0,
        };
    }

    private function intentScore(mixed $value): float
    {
        return match ($value) {
            'aggressive' => 1.0,
            'conservative' => -1.0,
            default => 0.0,
        };
    }

    private function injuryPenalty(mixed $value): float
    {
        return match ($value) {
            'low' => -0.25,
            'medium' => -0.5,
            'high' => -1.0,
            default => 0.0,
        };
    }

    private function numericAttribute(?Model $model, string $key): ?float
    {
        if (! $model || ! array_key_exists($key, $model->getAttributes())) {
            return null;
        }

        $value = $model->getAttribute($key);

        return is_numeric($value) ? (float) $value : null;
    }

    /** @return array<string, mixed> */
    private function unavailable(string $reason): array
    {
        return [
            'schema_version' => 'sports_external_game_context_v1',
            'available' => false,
            'status' => 'missing',
            'reason' => $reason,
            'facts' => [],
            'sources' => [],
            'risk_flags' => ['missing_external_game_context'],
            'deterministic_adjustment' => null,
            'context_adjusted_model' => null,
        ];
    }
}
