<?php

namespace App\Services\NFL\Matchups;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class NflMatchupPressure
{
    public function metrics(Collection $games, array $quarterbacks): array
    {
        if ($games->isEmpty()) {
            return [];
        }
        $saved = DB::table('nfl_matchup_pressure_samples')->whereIn('game_id', $games->keys())->get()
            ->keyBy(fn ($r) => $r->game_id.'|'.$r->team_id.'|'.$r->gsis_id);
        $player = "CASE WHEN play_type = 'pass' THEN passer_player_id ELSE rusher_player_id END";
        $rows = DB::table('nflverse_pbp_plays')->whereIn('nfl_game_id', $games->keys())
            ->where(fn ($q) => $q->where('play_type', 'pass')->orWhere(fn ($q) => $q->where('play_type', 'run')->where('qb_scramble', 1)))
            ->where(fn ($q) => $q->whereNull('description')->orWhereRaw('LOWER(description) NOT LIKE ?', ['%no play%']))
            ->where(fn ($q) => $q->whereNull('description')->orWhereRaw('LOWER(description) NOT LIKE ?', ['%two-point conversion attempt%']))
            ->select(['nfl_game_id', 'possession_team_id', 'defense_team_id'])->selectRaw("{$player} AS player_id, COUNT(*) AS dropbacks,
                SUM(CASE WHEN play_type = 'pass' THEN 1 ELSE 0 END) AS passes,
                SUM(CASE WHEN play_type = 'pass' AND is_sack IS NULL THEN 1 ELSE 0 END) AS unknown_sacks,
                SUM(CASE WHEN play_type = 'pass' AND is_sack = 1 THEN 1 ELSE 0 END) AS sacks")
            ->groupBy('nfl_game_id', 'possession_team_id', 'defense_team_id')->groupByRaw($player)->get();
        $seen = [];
        $units = [];
        $players = [];
        foreach ($rows as $row) {
            $game = $games->get($row->nfl_game_id);
            $teams = [(int) $game->home_team_id, (int) $game->away_team_id];
            if (! in_array((int) $row->possession_team_id, $teams, true) || ! in_array((int) $row->defense_team_id, $teams, true)
                || $row->possession_team_id === $row->defense_team_id) {
                continue;
            }
            $key = $row->nfl_game_id.'|'.$row->possession_team_id.'|'.$row->player_id;
            $seen[$key] = true;
            $sample = $saved->get($key);
            $valid = filled($row->player_id) && $sample !== null && (int) $sample->opponent_id === (int) $row->defense_team_id
                && $sample->pressures !== null && $sample->sacks !== null && $sample->pressure_rate !== null
                && (int) $row->unknown_sacks === 0 && (int) $sample->sacks === (int) $row->sacks
                && (int) $sample->pressures <= (int) $row->dropbacks;
            $item = ['dropbacks' => (int) $row->dropbacks, 'passes' => (int) $row->passes, 'pressures' => $valid ? (int) $sample->pressures : 0,
                'sacks' => (int) $row->sacks, 'valid' => $valid, 'game_id' => (int) $row->nfl_game_id];
            foreach (['offense' => $row->possession_team_id, 'defense' => $row->defense_team_id] as $side => $team) {
                $units[$side][$team][$row->nfl_game_id][] = $item;
            }
            if (filled($row->player_id)) {
                $players[$row->player_id][] = $item;
            }
        }
        foreach ($saved as $key => $sample) {
            if (isset($seen[$key])) {
                continue;
            }
            $missing = ['dropbacks' => 0, 'passes' => 0, 'pressures' => 0, 'sacks' => 0, 'valid' => false, 'game_id' => (int) $sample->game_id];
            $units['offense'][$sample->team_id][$sample->game_id][] = $missing;
            $units['defense'][$sample->opponent_id][$sample->game_id][] = $missing;
            $players[$sample->gsis_id][] = $missing;
        }
        $expected = [];
        foreach ($games as $game) {
            foreach ([$game->home_team_id, $game->away_team_id] as $team) {
                $expected[$team] = ($expected[$team] ?? 0) + 1;
            }
        }
        $result = [];
        foreach ($units as $side => $teams) {
            foreach ($teams as $team => $appearances) {
                $items = [];
                $complete = count($appearances) === ($expected[$team] ?? 0);
                foreach ($appearances as $rows) {
                    $complete = $complete && array_sum(array_column($rows, 'passes')) >= 15;
                    $items = [...$items, ...$rows];
                }
                foreach (['pressure_rate', 'pressure_to_sack_rate'] as $metric) {
                    $result[$metric][$side][$team] = $this->sample($items, $complete, $metric === 'pressure_to_sack_rate');
                }
            }
            foreach (['pressure_rate', 'pressure_to_sack_rate'] as $metric) {
                $result[$metric][$side] = $this->rank($result[$metric][$side], $side === 'offense', false);
            }
        }
        $qb = [];
        foreach ($players as $id => $items) {
            $qb[$id] = $this->sample($items, collect($items)->every(fn ($r) => $r['passes'] >= 15), true);
        }
        $qb = $this->rank($qb, true, true);
        foreach ($quarterbacks as $team => $identity) {
            $sample = $qb[$identity['player_id'] ?? ''] ?? ['value' => null, 'rank' => null, 'eligible' => false, 'games' => 0, 'plays' => 0, 'league_players' => 0];
            foreach (['player_id', 'player_name', 'player_id_namespace', 'identity_reason'] as $key) {
                if (isset($identity[$key])) {
                    $sample[$key] = $identity[$key];
                }
            }
            if (isset($identity['identity_reason'])) {
                $sample['eligible'] = false;
                $sample['value'] = null;
                $sample['rank'] = null;
            }
            $result['qb_pressure_to_sack_rate']['offense'][$team] = $sample;
        }

        return $result;
    }

    private function sample(array $items, bool $complete, bool $conversion): array
    {
        $games = array_values(array_unique(array_column($items, 'game_id')));
        $dropbacks = array_sum(array_column($items, 'dropbacks'));
        $pressures = array_sum(array_column($items, 'pressures'));
        $sacks = array_sum(array_column($items, 'sacks'));
        $denominator = $conversion ? $pressures : $dropbacks;
        $eligible = $complete && count($games) >= 2 && collect($items)->every(fn ($r) => $r['valid'])
            && $dropbacks >= 30 && (! $conversion || $pressures >= 10);

        return ['value' => $eligible && $denominator ? ($conversion ? $sacks : $pressures) / $denominator : null,
            'rank' => null, 'eligible' => $eligible, 'games' => count($games), 'game_ids' => $games,
            'plays' => $denominator, 'dropbacks' => $dropbacks, 'pressures' => $pressures, 'sacks' => $sacks,
            'minimum_pressures' => $conversion ? 10 : null, 'source' => 'PFR weekly advanced passing via nflverse; verified against play-by-play'];
    }

    private function rank(array $samples, bool $lowerFirst, bool $quarterbacks): array
    {
        $eligible = array_filter($samples, fn ($s) => $s['eligible']);
        $minimum = $quarterbacks ? 24 : 32;
        foreach ($samples as &$sample) {
            $sample[$quarterbacks ? 'league_players' : 'league_teams'] = count($eligible);
            if (! $sample['eligible'] || count($eligible) < $minimum) {
                continue;
            }
            $sample['rank'] = 1 + count(array_filter($eligible, fn ($other) => $lowerFirst ? $other['value'] < $sample['value'] - 1e-9 : $other['value'] > $sample['value'] + 1e-9));
            $sample['rank_end'] = $sample['rank'] - 1 + count(array_filter($eligible, fn ($other) => abs($other['value'] - $sample['value']) < 1e-9));
            $sample['league_average'] = array_sum(array_column($eligible, 'value')) / count($eligible);
            $sample['display_value'] = number_format($sample['value'] * 100, 2).'%';
        }

        return $samples;
    }
}
