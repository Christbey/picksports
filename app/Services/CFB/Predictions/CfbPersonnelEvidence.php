<?php

namespace App\Services\CFB\Predictions;

use App\Models\CFB\Game;
use App\Models\CFB\PlayerStat;
use App\Models\CFB\PreseasonTeamSignal;
use App\Services\Predictions\CanonicalPayloadHasher;
use Carbon\CarbonImmutable;

/** Frozen personnel covariates; no unvalidated point adjustments are promoted. */
class CfbPersonnelEvidence
{
    public function forTeam(Game $game, int $teamId, CarbonImmutable $captured, CarbonImmutable $cutoff): array
    {
        $signal = PreseasonTeamSignal::where('team_id', $teamId)->where('season', $game->season)
            ->where('updated_at', '<=', $captured)->where('updated_at', '<', $cutoff)->first();
        $components = [];
        foreach (['returning_production', 'transfers', 'talent', 'recruiting', 'head_coach'] as $component) {
            $evidence = (array) data_get($signal?->source_evidence, $component, []);
            $payload = $signal?->getAttribute($evidence['payload_field'] ?? 'missing');
            $status = 'unverified';
            try {
                $observed = isset($evidence['observed_at']) ? CarbonImmutable::parse($evidence['observed_at']) : null;
                if (($evidence['source'] ?? null) === 'cfbd' && (int) ($evidence['season'] ?? 0) === (int) $game->season
                    && $observed && $observed->lte($captured) && $observed->lt($cutoff)
                    && $observed->gte($captured->subDays(7)) && is_array($payload) && $this->payloadMatchesSeason($component, $payload, (int) $game->season) && $this->usable($component, $payload)
                    && hash_equals((string) ($evidence['payload_hash'] ?? ''), app(CanonicalPayloadHasher::class)->hash($payload))) {
                    $status = $component === 'head_coach' && ($payload['comparison_status'] ?? null) !== 'verified'
                        ? 'unresolved' : 'verified';
                }
            } catch (\Throwable) {
                $status = 'unverified';
            }
            $components[$component] = ['status' => $status, 'source' => $evidence['source'] ?? null,
                'observed_at' => $evidence['observed_at'] ?? null, 'payload_hash' => $evidence['payload_hash'] ?? null,
                'values' => $status === 'verified' ? $payload : null];
        }
        $components['quarterback'] = $this->quarterback($game, $teamId, $captured, $cutoff);
        $missing = array_keys(array_filter($components, fn ($c) => $c['status'] !== 'verified'));

        return ['schema' => 'cfb-personnel-v1', 'signal_id' => $signal?->id, 'season' => (int) $game->season,
            'components' => $components, 'missing_components' => $missing, 'coverage_complete' => $missing === [],
            'role' => 'early_season_evidence_gate_and_shadow_covariates', 'margin_adjustment_applied' => false,
            'adjustment_status' => 'requires_out_of_sample_validation',
            'limitations' => ['Head-coach data does not establish offensive/defensive coordinator continuity.',
                'Observed quarterback participation does not confirm the next starting quarterback.',
                'Transfer/recruiting ratings are covariates, not validated point values.']];
    }

    private function payloadMatchesSeason(string $component, array $payload, int $season): bool
    {
        $rows = $component === 'transfers' ? $payload : [$payload];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                return false;
            }
            foreach (['season', 'year'] as $field) {
                if (array_key_exists($field, $row) && (! is_numeric($row[$field]) || (float) $row[$field] !== (float) $season)) {
                    return false;
                }
            }
        }

        return true;
    }

    private function usable(string $component, array $payload): bool
    {
        return match ($component) {
            'returning_production' => is_numeric($payload['usage'] ?? null) && $payload['usage'] >= 0 && $payload['usage'] <= 1,
            'talent' => is_numeric($payload['talent'] ?? null) && $payload['talent'] >= 0,
            'recruiting' => is_numeric($payload['points'] ?? null) && $payload['points'] >= 0
                && is_numeric($payload['rank'] ?? null) && $payload['rank'] >= 1,
            'transfers' => $payload !== [] && collect($payload)->every(fn ($row) => is_array($row)
                && filled($row['origin'] ?? null) && (is_numeric($row['rating'] ?? null) || is_numeric($row['stars'] ?? null))),
            'head_coach' => count($payload['current'] ?? []) === 1 && count($payload['prior'] ?? []) === 1,
            default => false,
        };
    }

    private function quarterback(Game $game, int $teamId, CarbonImmutable $captured, CarbonImmutable $cutoff): array
    {
        $rows = PlayerStat::with('game')->where('team_id', $teamId)
            ->where('updated_at', '<=', $captured)->where('updated_at', '<', $cutoff)
            ->where('passing_attempts', '>', 0)
            ->whereHas('game', fn ($q) => $q->where('status', 'STATUS_FINAL')
                ->whereBetween('season', [$game->season - 1, $game->season])
                ->whereDate('game_date', '<', $game->game_date))->get();
        $leaders = [];
        foreach ([$game->season - 1, $game->season] as $year) {
            $seasonRows = $rows->filter(fn ($row) => (int) $row->game->season === (int) $year);
            $attempts = $seasonRows->groupBy('player_id')->map(fn ($r) => $r->sum('passing_attempts'))->sortDesc();
            $uniqueLeader = $attempts->isNotEmpty() && ($attempts->count() === 1 || $attempts->values()[0] > $attempts->values()[1]);
            $leaders[$year] = ['player_id' => $uniqueLeader ? (int) $attempts->keys()->first() : null,
                'games' => $seasonRows->pluck('game_id')->unique()->count(), 'stat_ids' => $seasonRows->pluck('id')->all()];
        }
        $current = $leaders[$game->season];
        $prior = $leaders[$game->season - 1];
        $known = $current['player_id'] !== null && $prior['player_id'] !== null;

        return ['status' => $known ? 'verified' : 'unresolved', 'source' => 'stored_pregame_player_statistics',
            'observed_at' => $rows->max('updated_at')?->toIso8601String(), 'confirmed_starter' => false,
            'values' => ['current' => $current, 'prior' => $prior,
                'observed_primary_passer_changed' => $known ? $current['player_id'] !== $prior['player_id'] : null]];
    }
}
