<?php

namespace App\Services\CFB\Live\Overtime;

use App\Models\CFB\Game;

/** Only explicit drive boundaries establish completed possessions. */
class OvertimeState
{
    public function fromSummary(Game $game, array $payload): ?array
    {
        if ((int) $game->period <= 4) {
            return null;
        }
        $situation = data_get($payload, 'situation', data_get($payload, 'header.competitions.0.situation', []));
        $teams = [(string) $game->homeTeam?->espn_id => 'home', (string) $game->awayTeam?->espn_id => 'away'];
        $completed = [];
        $ids = [];
        foreach (data_get($payload, 'drives.previous', []) as $drive) {
            if ((int) data_get($drive, 'start.period.number') !== (int) $game->period
                || ! data_get($drive, 'end') || ! data_get($drive, 'result')) {
                continue;
            }
            // A touchdown is not a completed series while its try is pending.
            $lastPlay = collect($drive['plays'] ?? [])->last();
            if (str_contains(strtoupper((string) $drive['result']), 'TD') || str_contains(strtoupper((string) $drive['result']), 'TOUCHDOWN')) {
                $lastType = strtolower((string) data_get($lastPlay, 'type.text'));
                if (! str_contains($lastType, 'extra point') && ! str_contains($lastType, 'conversion')
                    && ! is_numeric(data_get($lastPlay, 'pointAfterAttempt.value'))) {
                    continue;
                }
            }
            $side = $teams[(string) data_get($drive, 'team.id')] ?? null;
            if ($side !== null) {
                $completed[] = $side;
                $ids[] = (string) ($drive['id'] ?? '');
            }
        }
        $active = data_get($payload, 'drives.current');
        $side = $teams[(string) ($situation['possession'] ?? '')] ?? null;
        $activeSide = is_array($active) && (int) data_get($active, 'start.period.number') === (int) $game->period
            ? ($teams[(string) data_get($active, 'team.id')] ?? null) : null;
        $conflict = $side !== null && $activeSide !== null && $side !== $activeSide;
        $side ??= $activeSide;
        // ESPN situation often omits distance to goal; a matching play end can supply it.
        $last = is_array($active) ? collect($active['plays'] ?? [])->last() : null;
        $end = is_array($last) && (int) ($last['homeScore'] ?? -1) === (int) $game->home_score
            && (int) ($last['awayScore'] ?? -1) === (int) $game->away_score
            && ($teams[(string) data_get($last, 'end.team.id')] ?? null) === $side
            ? data_get($last, 'end', []) : [];
        $completed = array_values(array_unique($completed));
        if (count($completed) < 2 && in_array($side, $completed, true)) {
            $conflict = true;
        }

        return ['contract' => 'ncaa-ot-possession-v1', 'source' => 'espn_summary', 'observed_at' => now()->toIso8601String(),
            'period' => (int) $game->period, 'round' => (int) $game->period - 4,
            'format' => (int) $game->period >= 7 ? 'two_point_try' : 'possession_from_25',
            'home_score' => (int) $game->home_score, 'away_score' => (int) $game->away_score,
            'possession' => $side, 'completed_possessions' => $completed,
            'drive_ids' => $ids, 'active_drive_id' => data_get($active, 'id'),
            'down' => $situation['down'] ?? $end['down'] ?? null, 'distance' => $situation['distance'] ?? $end['distance'] ?? null,
            'yards_to_endzone' => $situation['yardsToEndzone'] ?? $end['yardsToEndzone'] ?? null,
            'status' => $conflict ? 'conflicting_possession' : ($side !== null || count($completed) === 2 ? 'observed' : 'missing_possession')];
    }
}
