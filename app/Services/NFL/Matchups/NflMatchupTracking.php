<?php

namespace App\Services\NFL\Matchups;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class NflMatchupTracking
{
    public function metrics(Collection $games, array $quarterbacks): array
    {
        if ($games->isEmpty()) {
            return [];
        }
        $saved = DB::table('nfl_matchup_tracking_samples')->whereIn('game_id', $games->keys())->get();
        $release = $saved->where('metric', 'release_time')->keyBy(fn ($r) => $r->game_id.'|'.$r->team_id.'|'.$r->player_id);
        $appearances = DB::table('nflverse_pbp_plays')->whereIn('nfl_game_id', $games->keys())->where('play_type', 'pass')->where('is_sack', false)
            ->where(fn ($q) => $q->whereNull('description')->orWhereRaw('LOWER(description) NOT LIKE ?', ['%no play%']))
            ->where(fn ($q) => $q->whereNull('description')->orWhereRaw('LOWER(description) NOT LIKE ?', ['%two-point conversion attempt%']))
            ->select(['nfl_game_id', 'possession_team_id', 'passer_player_id'])->selectRaw('COUNT(*) AS attempts')
            ->groupBy('nfl_game_id', 'possession_team_id', 'passer_player_id')->get();
        $seen = [];
        $players = [];
        foreach ($appearances as $row) {
            if (! filled($row->passer_player_id)) {
                continue;
            }
            $game = $games->get($row->nfl_game_id);
            if (! in_array((int) $row->possession_team_id, [(int) $game->home_team_id, (int) $game->away_team_id], true)) {
                continue;
            }
            $key = $row->nfl_game_id.'|'.$row->possession_team_id.'|gsis:'.$row->passer_player_id;
            $seen[$key] = true;
            $sample = $release->get($key);
            $valid = $sample !== null && $sample->value !== null && $sample->sample_size !== null && $row->attempts >= 15 && $sample->sample_size >= 15
                && min((int) $sample->sample_size, (int) $row->attempts) >= max((int) $sample->sample_size, (int) $row->attempts) * .9;
            $players[$row->passer_player_id][] = ['game_id' => (int) $row->nfl_game_id, 'valid' => $valid,
                'attempts' => $valid ? (int) $sample->sample_size : 0, 'total' => $valid ? (float) $sample->value * (int) $sample->sample_size : 0];
        }
        foreach ($release as $key => $sample) {
            if (! isset($seen[$key])) {
                $players[substr($sample->player_id, 5)][] = ['game_id' => (int) $sample->game_id, 'valid' => false, 'attempts' => 0, 'total' => 0];
            }
        }
        $qb = [];
        foreach ($players as $id => $rows) {
            $attempts = array_sum(array_column($rows, 'attempts'));
            $eligible = count($rows) >= 2 && collect($rows)->every(fn ($r) => $r['valid']);
            $qb[$id] = ['eligible' => $eligible, 'value' => $eligible && $attempts ? array_sum(array_column($rows, 'total')) / $attempts : null,
                'games' => count($rows), 'game_ids' => array_column($rows, 'game_id'), 'plays' => $attempts, 'rank' => null];
        }
        $qb = $this->rank($qb, true);
        $result = [];
        foreach ($quarterbacks as $team => $identity) {
            $sample = $qb[$identity['player_id'] ?? ''] ?? ['eligible' => false, 'value' => null, 'rank' => null, 'games' => 0, 'plays' => 0, 'league_players' => 0];
            foreach (['player_id', 'player_name', 'player_id_namespace', 'identity_reason'] as $field) {
                if (isset($identity[$field])) {
                    $sample[$field] = $identity[$field];
                }
            }
            if (isset($identity['identity_reason'])) {
                $sample['eligible'] = false;
                $sample['value'] = null;
                $sample['rank'] = null;
            }
            $sample['display_value'] = $sample['eligible'] ? number_format($sample['value'], 2).' seconds to throw' : null;
            $result['qb_release_time']['offense'][$team] = $sample;
        }
        $expected = [];
        foreach ($games as $game) {
            foreach ([$game->home_team_id, $game->away_team_id] as $team) {
                $expected[$team][] = (int) $game->id;
            }
        }
        $tackles = $saved->where('metric', 'missed_tackles')->groupBy('team_id');
        $defense = [];
        foreach ($expected as $team => $gameIds) {
            $byGame = $tackles->get($team, collect())->groupBy('game_id');
            $validGames = $byGame->filter(fn ($rows, $gameId) => in_array((int) $gameId, $gameIds, true)
                && $rows->every(fn ($row) => $row->value !== null && $row->sample_size !== null)
                && $rows->sum('sample_size') >= 30);
            $rows = $validGames->flatten(1);
            $count = (int) $rows->sum('sample_size');
            $misses = (int) $rows->sum('value');
            $eligible = count($gameIds) >= 2 && $validGames->count() === count($gameIds);
            $defense[$team] = ['eligible' => $eligible, 'value' => $eligible && $count ? $misses / $count : null,
                'games' => $validGames->count(), 'game_ids' => $validGames->keys()->all(), 'plays' => $count,
                'missed_tackles' => $misses, 'rank' => null, 'source' => 'PFR player missed tackles and combined tackles via nflverse'];
        }
        $result['missed_tackle_rate']['defense'] = $this->rank($defense, false);
        foreach ($this->contactYards($games, $saved, $expected) as $metric => $samples) {
            $result[$metric] = $samples;
        }

        return $result;
    }

    private function contactYards(Collection $games, Collection $saved, array $expected): array
    {
        $carries = DB::table('nflverse_pbp_plays')->whereIn('nfl_game_id', $games->keys())
            ->whereIn('play_type', ['run', 'qb_kneel'])
            ->where(fn ($q) => $q->whereNull('description')->orWhereRaw('LOWER(description) NOT LIKE ?', ['%no play%']))
            ->where(fn ($q) => $q->whereNull('description')->orWhereRaw('LOWER(description) NOT LIKE ?', ['%two-point conversion attempt%']))
            ->select(['nfl_game_id', 'possession_team_id', 'defense_team_id'])->selectRaw('COUNT(*) AS carries')
            ->groupBy('nfl_game_id', 'possession_team_id', 'defense_team_id')->get()
            ->keyBy(fn ($row) => $row->nfl_game_id.'|'.$row->possession_team_id.'|'.$row->defense_team_id);
        $result = [];
        foreach (['rush_ybc', 'rush_yac'] as $metric) {
            $samples = $saved->where('metric', $metric)->groupBy(fn ($row) => $row->game_id.'|'.$row->team_id);
            $buckets = [];
            foreach ($games as $game) {
                foreach ([[$game->home_team_id, $game->away_team_id], [$game->away_team_id, $game->home_team_id]] as [$offense, $defense]) {
                    $rows = $samples->get($game->id.'|'.$offense, collect());
                    $count = (int) $rows->sum('sample_size');
                    $observed = (int) ($carries->get($game->id.'|'.$offense.'|'.$defense)?->carries ?? 0);
                    $valid = $count >= 8 && $observed >= 8
                        && min($count, $observed) >= max($count, $observed) * .9
                        && $rows->every(fn ($row) => $row->value !== null && $row->sample_size !== null);
                    foreach (['offense' => $offense, 'defense' => $defense] as $side => $team) {
                        if ($valid) {
                            $buckets[$side][$team][$game->id] = ['yards' => (float) $rows->sum('value'), 'carries' => $count];
                        }
                    }
                }
            }
            foreach (['offense', 'defense'] as $side) {
                $teams = [];
                foreach ($expected as $team => $ids) {
                    $rows = $buckets[$side][$team] ?? [];
                    $count = array_sum(array_column($rows, 'carries'));
                    $eligible = count($ids) >= 2 && count($rows) === count($ids);
                    $teams[$team] = ['eligible' => $eligible, 'value' => $eligible && $count ? array_sum(array_column($rows, 'yards')) / $count : null,
                        'games' => count($rows), 'game_ids' => array_keys($rows), 'plays' => $count, 'rank' => null];
                }
                $result[$metric][$side] = $this->rank($teams, false, $side === 'offense');
            }
        }

        return $result;
    }

    private function rank(array $samples, bool $quarterbacks, bool $descending = false): array
    {
        $eligible = array_filter($samples, fn ($s) => $s['eligible']);
        foreach ($samples as &$sample) {
            $sample[$quarterbacks ? 'league_players' : 'league_teams'] = count($eligible);
            if (! $sample['eligible'] || count($eligible) < ($quarterbacks ? 24 : 32)) {
                continue;
            }
            $sample['rank'] = 1 + count(array_filter($eligible, fn ($other) => $descending ? $other['value'] > $sample['value'] + 1e-9 : $other['value'] < $sample['value'] - 1e-9));
            $sample['rank_end'] = $sample['rank'] - 1 + count(array_filter($eligible, fn ($other) => abs($other['value'] - $sample['value']) < 1e-9));
            $sample['league_average'] = array_sum(array_column($eligible, 'value')) / count($eligible);
        }

        return $samples;
    }
}
