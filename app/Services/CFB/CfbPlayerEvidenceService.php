<?php

namespace App\Services\CFB;

use App\Models\CFB\Game;
use App\Models\CFB\PlayerInjury;
use App\Models\CFB\PlayerProp;
use App\Models\CFB\PlayerStat;
use Illuminate\Support\Collection;

class CfbPlayerEvidenceService
{
    public function history(Game $game, int $teamId): Collection
    {
        return PlayerStat::with(['game', 'player'])->where('team_id', $teamId)
            ->whereHas('player', fn ($q) => $q->where('team_id', $teamId))
            ->whereHas('game', fn ($q) => $q->where('status', 'STATUS_FINAL')
                ->whereIn('season_type', ['2', '3', 'regular', 'postseason'])
                ->whereBetween('season', [$game->season - 1, $game->season])
                ->whereDate('game_date', '<', $game->game_date))
            ->get()->sortByDesc(fn ($row) => $row->game->game_date->format('Y-m-d').' '.$row->game->game_time)->values();
    }

    public function injuries(Game $game, int $teamId): Collection
    {
        return PlayerInjury::with('player')->where('team_id', $teamId)->where('is_active', true)
            ->where('updated_at', '<=', now())
            ->where(fn ($q) => $q->whereNull('source_updated_at')->orWhere('source_updated_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('injury_date')->orWhereDate('injury_date', '<=', $game->game_date))
            ->where(fn ($q) => $q->whereNull('return_date')->orWhereDate('return_date', '>=', $game->game_date))
            ->whereRaw("LOWER(status) NOT IN ('active', 'available', 'probable')")->get();
    }

    /** The last observed passer is evidence, never a confirmed starter. */
    public function quarterback(Game $game, int $teamId): array
    {
        $history = $this->history($game, $teamId);
        $lastGameId = $history->first()?->game_id;
        $passers = $history->where('game_id', $lastGameId)->filter(fn ($s) => (int) $s->passing_attempts > 0)
            ->sortByDesc('passing_attempts')->values();
        $leader = $passers->first();
        $ambiguous = $leader && $passers->count() > 1 && $leader->passing_attempts === $passers[1]->passing_attempts;
        $injuries = $this->injuries($game, $teamId);
        $conflict = $leader && $injuries->contains('player_id', $leader->player_id);

        return ['status' => ! $leader ? 'unknown' : ($conflict || $ambiguous ? 'unresolved' : 'last_observed'),
            'last_observed_player_id' => $leader?->player_id, 'source_game_id' => $lastGameId,
            'confirmed_starter' => false, 'quarterback_hold' => (bool) ($conflict || $ambiguous),
            'injury_coverage' => $injuries->isEmpty() ? 'unknown_or_no_reported_injuries' : 'reported',
            'replacement_player_id' => null];
    }

    public function forProp(PlayerProp $prop): array
    {
        $field = match ($prop->market) {
            'player_pass_yds' => 'passing_yards', 'player_rush_yds' => 'rushing_yards',
            'player_reception_yds' => 'receiving_yards', default => null,
        };
        if (! $field || ! $prop->game || ! $prop->player) {
            return ['hold_reasons' => ['missing_player_context'], 'windows' => []];
        }
        $history = $this->history($prop->game, $prop->player->team_id);
        $rows = $history->where('player_id', $prop->player_id)->filter(fn ($s) => is_numeric($s->$field))->values();
        $windows = [
            'last_5' => $rows->take(5), 'last_10' => $rows->take(10),
            'this_season' => $rows->filter(fn ($s) => $s->game->season === $prop->game->season),
            'historical' => $rows->filter(fn ($s) => $s->game->season < $prop->game->season),
        ];
        $windows = collect($windows)->map(fn ($sample) => ['games' => $sample->count(),
            'game_ids' => $sample->pluck('game_id')->values()->all(),
            'seasons' => $sample->map(fn ($s) => $s->game->season)->unique()->values()->all(),
            'average' => $sample->isEmpty() ? null : round($sample->avg($field), 1),
        ])->all();
        $holds = [];
        $positions = match ($prop->market) {
            'player_pass_yds' => ['QB'], 'player_reception_yds' => ['QB', 'WR', 'TE'],
            default => ['QB', 'RB', 'FB'],
        };
        $recentParticipants = $history->whereIn('game_id', $history->pluck('game_id')->unique()->take(2))
            ->pluck('player_id')->unique();
        foreach ($this->injuries($prop->game, $prop->player->team_id) as $injury) {
            if ($injury->player_id !== $prop->player_id && $recentParticipants->contains($injury->player_id)
                && in_array(strtoupper((string) $injury->player?->position), $positions, true)) {
                $holds[] = 'teammate_workload_unresolved';
            }
        }
        if ($this->quarterback($prop->game, $prop->player->team_id)['quarterback_hold']) {
            $holds[] = 'quarterback_unresolved';
        }
        $opportunity = match ($prop->market) {
            'player_pass_yds' => 'passing_attempts', 'player_rush_yds' => 'rushing_attempts', default => 'receiving_targets',
        };
        // Compare disjoint windows, requiring every opportunity value; never fill missing targets with zero.
        $recent = $rows->take(2);
        $baseline = $rows->slice(2, 3);
        $change = null;
        if ($recent->count() === 2 && $baseline->count() === 3
            && $recent->concat($baseline)->every(fn ($s) => is_numeric($s->$opportunity) && $s->$opportunity >= 0)
            && $baseline->avg($opportunity) > 0) {
            $change = $recent->avg($opportunity) / $baseline->avg($opportunity) - 1;
            if (abs($change) >= 0.5) {
                $holds[] = 'material_workload_change';
            }
        }

        return ['hold_reasons' => array_values(array_unique($holds)), 'windows' => $windows,
            'workload' => ['field' => $opportunity, 'relative_change' => $change, 'recent_games' => 2, 'baseline_games' => 3],
            'starter_status' => 'not_confirmed', 'teammate_workload_adjusted' => false];
    }
}
