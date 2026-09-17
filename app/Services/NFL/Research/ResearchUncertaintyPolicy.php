<?php

namespace App\Services\NFL\Research;

class ResearchUncertaintyPolicy
{
    public const VERSION = 1;

    public const REASONS = [
        'routine_starter_confirmation', 'future_report', 'future_usage', 'forecast_variance',
        'material_availability', 'model_input_conflict', 'market_data_gap', 'source_gap',
    ];

    public function normalize(array $item): array
    {
        $reason = $item['reason_code'] ?? null;
        if (! in_array($reason, self::REASONS, true)) {
            // Old reports and malformed classifications are never silently cleared.
            return $item;
        }

        $item['requested_blocking'] = $item['blocking'];
        if (in_array($reason, ['routine_starter_confirmation', 'future_report', 'future_usage', 'forecast_variance'], true)) {
            $item['blocking'] = false;
            $item['policy_disposition'] = 'conditional_analysis';
        } elseif ($reason === 'model_input_conflict') {
            $item['scope'] = 'game';
            $item['blocking'] = true;
            $item['policy_disposition'] = 'verify_and_recompute';
        } else {
            $item['policy_disposition'] = $item['blocking'] ? 'hold_affected_market' : 'conditional_analysis';
        }

        return $item;
    }

    public function applyStatus(array $payload): array
    {
        $payload['uncertainty_policy_version'] = self::VERSION;
        $payload['researcher_status'] = $payload['status'];
        $items = $payload['decision_research']['unresolved'] ?? [];
        $gameBlockers = collect($items)->contains(fn ($item) => ($item['blocking'] ?? true) && ($item['scope'] ?? 'game') === 'game');
        if ($gameBlockers) {
            $payload['status'] = $payload['status'] === 'insufficient' ? 'insufficient' : 'partial';

            return $payload;
        }

        // Only reinterpret an AI completeness label when all stated gaps are
        // classified and both teams have cited facts and two-sided reasoning.
        $facts = collect($payload['facts'] ?? []);
        $bothTeams = collect(['home', 'away'])->every(fn ($side) => $facts->contains(fn ($fact) => in_array($fact['team_side'] ?? null, [$side, 'both'], true) && ! empty($fact['source_urls'])));
        $conflicts = collect($payload['risk_flags'] ?? [])->contains(fn ($flag) => str_contains($flag, 'source_conflict') || in_array($flag, ['insufficient_sourced_context', 'provider_citations_missing'], true));
        if ($items !== [] && $bothTeams && ! $conflicts && ! empty($payload['sources'])
            && ! empty($payload['decision_research']['supporting']) && ! empty($payload['decision_research']['opposing'])
            && collect($items)->every(fn ($item) => in_array($item['reason_code'] ?? null, self::REASONS, true))) {
            $payload['status'] = 'ready';
        }

        return $payload;
    }

    public function holdsFor(array $items, string $market): array
    {
        return collect($items)->filter(fn ($item) => ($item['blocking'] ?? true)
            && in_array($item['scope'] ?? 'game', ['game', $market], true))->values()->all();
    }
}
