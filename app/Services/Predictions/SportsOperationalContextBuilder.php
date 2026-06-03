<?php

namespace App\Services\Predictions;

use App\Models\ValidationFinding;
use App\Models\ValidationRun;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

class SportsOperationalContextBuilder
{
    /**
     * @return array<string, mixed>
     */
    public function build(string $sport, ?Model $game = null): array
    {
        $sport = strtolower($sport);
        $latestRun = $this->latestValidationRun($sport);
        $findings = $latestRun
            ? $latestRun->findings()
                ->where('sport', $sport)
                ->orderByRaw("CASE status WHEN 'failing' THEN 3 WHEN 'warning' THEN 2 WHEN 'passing' THEN 1 ELSE 0 END DESC")
                ->latest('detected_at')
                ->limit(20)
                ->get()
            : collect();

        $blockingFindings = $findings
            ->filter(fn (ValidationFinding $finding): bool => in_array($finding->status, ['failing', 'warning'], true))
            ->values();

        $blockedOutputs = collect((array) data_get($latestRun?->ai_summary, 'blocked_outputs', []))
            ->filter()
            ->values();

        return [
            'schema_version' => 'sports_operational_context_v1',
            'generated_at' => now()->toIso8601String(),
            'sport' => $sport,
            'data_freshness' => [
                'latest_data_fresh_at' => $this->latestDataFreshAt($latestRun),
                'validation_completed_at' => $latestRun?->completed_at?->toIso8601String(),
                'validation_status' => $latestRun?->status ?? 'unknown',
                'market_odds_updated_at' => $this->dateAttribute($game, 'odds_updated_at'),
                'market_odds_age_minutes' => $this->ageMinutes($this->dateAttribute($game, 'odds_updated_at')),
            ],
            'data_schedule_today' => collect((array) data_get($latestRun?->ai_summary, 'data_schedule_today', []))
                ->filter()
                ->values()
                ->all(),
            'tweak_recommendations' => collect((array) data_get($latestRun?->ai_summary, 'tweak_recommendations', []))
                ->filter()
                ->values()
                ->all(),
            'validation_findings' => $findings
                ->map(fn (ValidationFinding $finding): array => $this->findingPayload($finding))
                ->values()
                ->all(),
            'check_statuses' => $this->checkStatuses($findings),
            'required_actions' => $blockingFindings
                ->pluck('recommended_action')
                ->filter()
                ->unique()
                ->values()
                ->all(),
            'blocked_outputs' => $blockedOutputs->all(),
            'publication_guardrails' => [
                'status' => $blockingFindings->contains(fn (ValidationFinding $finding): bool => $finding->status === 'failing') || $blockedOutputs->isNotEmpty()
                    ? 'blocked'
                    : ($blockingFindings->isNotEmpty() ? 'degraded' : 'clear'),
                'reason_count' => $blockingFindings->count() + $blockedOutputs->count(),
                'reasons' => $blockingFindings
                    ->pluck('message')
                    ->merge($blockedOutputs)
                    ->filter()
                    ->unique()
                    ->values()
                    ->take(8)
                    ->all(),
            ],
            'source_provenance' => [
                'validation_run_id' => $latestRun?->id,
                'validation_scope' => $latestRun?->scope,
                'validation_ai_generated_at' => $latestRun?->ai_generated_at?->toIso8601String(),
            ],
        ];
    }

    private function latestValidationRun(string $sport): ?ValidationRun
    {
        if (! Schema::hasTable('validation_runs')) {
            return null;
        }

        return ValidationRun::query()
            ->with('findings')
            ->where('command_name', 'healthcheck:validate-data')
            ->whereIn('scope', ['sport:'.$sport, 'all_sports'])
            ->latest('completed_at')
            ->latest('id')
            ->first();
    }

    private function latestDataFreshAt(?ValidationRun $run): ?string
    {
        $freshAt = data_get($run?->ai_summary, 'latest_data_fresh_at');

        if (is_string($freshAt) && trim($freshAt) !== '') {
            return $freshAt;
        }

        return $run?->completed_at?->toIso8601String();
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function checkStatuses($findings): array
    {
        $trackedChecks = [
            'current_day_game_data',
            'odds_completeness',
            'weather_completeness',
            'injury_freshness',
            'player_prop_freshness',
            'futures_odds_freshness',
            'pipeline_order',
            'prediction_completeness',
        ];

        return collect($trackedChecks)
            ->mapWithKeys(function (string $checkType) use ($findings): array {
                $matching = $findings->where('check_type', $checkType);
                $status = $matching->first()?->status ?? 'unknown';

                return [$checkType => [
                    'status' => $status,
                    'finding_count' => $matching->count(),
                    'recommended_actions' => $matching
                        ->pluck('recommended_action')
                        ->filter()
                        ->unique()
                        ->values()
                        ->all(),
                ]];
            })
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function findingPayload(ValidationFinding $finding): array
    {
        return [
            'id' => $finding->id,
            'check_type' => $finding->check_type,
            'scope_type' => $finding->scope_type,
            'scope_id' => $finding->scope_id,
            'status' => $finding->status,
            'severity' => $finding->severity,
            'message' => $finding->message,
            'facts' => $finding->facts ?? [],
            'recommended_action' => $finding->recommended_action,
            'detected_at' => $finding->detected_at?->toIso8601String(),
        ];
    }

    private function dateAttribute(?Model $model, string $key): ?string
    {
        if (! $model || ! array_key_exists($key, $model->getAttributes())) {
            return null;
        }

        $value = $model->getAttribute($key);

        if ($value instanceof Carbon) {
            return $value->toIso8601String();
        }

        if (is_string($value) && trim($value) !== '') {
            return Carbon::parse($value)->toIso8601String();
        }

        return null;
    }

    private function ageMinutes(?string $timestamp): ?int
    {
        if (! $timestamp) {
            return null;
        }

        return (int) Carbon::parse($timestamp)->diffInMinutes(now());
    }
}
