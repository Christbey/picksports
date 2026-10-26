<?php

namespace App\Services\NFL\Matchups;

use App\Models\NFL\Game;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Describes offensive play outcomes against defensive units; no individual blame attribution. */
final class NflPositionEpaMatchups
{
    private const SPLITS = ['pass', 'run', 'WR', 'TE', 'RB'];

    public function build(Game $target, Collection $games): array
    {
        $games = $games->filter(fn ($g) => (int) $g->season === (int) $target->season
            && in_array((string) $g->season_type, ['2', 'regular', 'REG'], true)
            && in_array($g->status, ['STATUS_FINAL', 'final', 'completed'], true) && $g->game_date->lt($target->game_date))->keyBy('id');
        $positions = DB::table('nflverse_rosters')->where('season', $target->season)->whereNotNull('gsis_id')
            ->groupBy('team_id', 'gsis_id')->havingRaw('COUNT(position) = COUNT(*) AND COUNT(DISTINCT position) = 1')
            ->selectRaw('team_id, gsis_id, MIN(position) AS position')->get()->keyBy(fn ($r) => $r->team_id.':'.$r->gsis_id);
        $plays = $games->isEmpty() ? collect() : DB::table('nflverse_pbp_plays')->whereIn('nfl_game_id', $games->keys())
            ->whereIn('play_type', ['pass', 'run'])
            ->where(fn ($q) => $q->whereNull('description')->orWhereRaw('LOWER(description) NOT LIKE ?', ['%no play%']))
            ->where(fn ($q) => $q->whereNull('description')->orWhereRaw('LOWER(description) NOT LIKE ?', ['%two-point conversion attempt%']))
            ->get(['nfl_game_id', 'possession_team_id', 'defense_team_id', 'play_type', 'is_sack', 'receiver_player_id', 'epa']);
        $buckets = $identity = $teamGames = [];
        foreach ($games as $g) {
            foreach ([$g->home_team_id, $g->away_team_id] as $id) {
                $teamGames[$id][] = $g->id;
            }
        }
        foreach ($plays as $play) {
            $g = $games->get($play->nfl_game_id);
            $ids = [(int) $g->home_team_id, (int) $g->away_team_id];
            $offense = (int) $play->possession_team_id;
            $defense = (int) $play->defense_team_id;
            if ($offense === $defense || ! in_array($offense, $ids, true) || ! in_array($defense, $ids, true)) {
                continue;
            }
            $pass = $play->play_type === 'pass' || (int) $play->is_sack === 1;
            $split = $pass ? 'pass' : 'run';
            $position = null;
            if ($pass && $play->is_sack !== null && (int) $play->is_sack === 0) {
                $named = filled($play->receiver_player_id);
                $position = $named ? ($positions->get($offense.':'.$play->receiver_player_id)?->position) : null;
                foreach ([['offense', $offense], ['defense', $defense]] as [$side, $id]) {
                    $identity[$side][$id] ??= ['non_sack_passes' => 0, 'named_targets' => 0, 'classified_targets' => 0, 'unmapped_named_targets' => 0, 'unassigned_passes' => 0];
                    $identity[$side][$id]['non_sack_passes']++;
                    $identity[$side][$id]['named_targets'] += (int) $named;
                    $identity[$side][$id]['classified_targets'] += (int) in_array($position, ['WR', 'TE', 'RB'], true);
                    $identity[$side][$id]['unmapped_named_targets'] += (int) ($named && ! in_array($position, ['WR', 'TE', 'RB'], true));
                    $identity[$side][$id]['unassigned_passes'] += (int) ! $named;
                }
            }
            foreach (array_filter([$split, in_array($position, ['WR', 'TE', 'RB'], true) ? $position : null]) as $metric) {
                foreach ([['offense', $offense], ['defense', $defense]] as [$side, $id]) {
                    $buckets[$metric][$side][$id][$g->id] ??= ['candidates' => 0, 'plays' => 0, 'sum' => 0.0, 'successes' => 0, 'opponent_id' => $side === 'offense' ? $defense : $offense];
                    $row = &$buckets[$metric][$side][$id][$g->id];
                    $row['candidates']++;
                    if ($play->epa !== null) {
                        $row['plays']++;
                        $row['sum'] += (float) $play->epa;
                        $row['successes'] += (int) ($play->epa > 0);
                    }
                    unset($row);
                }
            }
        }
        $profiles = [];
        foreach (self::SPLITS as $split) {
            foreach (['offense', 'defense'] as $side) {
                foreach ($teamGames as $id => $expected) {
                    $rows = collect($buckets[$split][$side][$id] ?? []);
                    $n = $rows->sum('plays');
                    $candidates = $rows->sum('candidates');
                    $ids = $rows->filter(fn ($r) => $r['plays'] > 0)->keys()->all();
                    $mapping = $identity[$side][$id] ?? null;
                    $mappingCoverage = $mapping && $mapping['named_targets'] > 0 ? $mapping['classified_targets'] / $mapping['named_targets'] : null;
                    $coveredGames = count(array_unique([...array_keys($buckets['pass'][$side][$id] ?? []), ...array_keys($buckets['run'][$side][$id] ?? [])]));
                    $eligible = count($ids) >= 2 && $n >= 20 && $n >= $candidates * .9 && $coveredGames === count($expected)
                        && (in_array($split, ['pass', 'run'], true) || ($mappingCoverage !== null && $mappingCoverage >= .9));
                    $profiles[$split][$side][$id] = [
                        'eligible' => $eligible, 'epa_per_play' => $n ? round($rows->sum('sum') / $n, 4) : null,
                        'offensive_success_rate' => $n ? round($rows->sum('successes') / $n, 4) : null,
                        'plays' => $n, 'candidate_plays' => $candidates, 'games' => count($ids), 'expected_team_games' => count($expected),
                        'games_with_any_plays' => $coveredGames, 'game_ids' => $ids, 'target_mapping' => $mapping,
                        'target_mapping_coverage' => $mappingCoverage, 'rank' => null,
                        'reason' => $eligible ? null : 'Requires 2 games, 20 measured plays, 90% EPA coverage, complete game-feed coverage and 90% named-target position mapping for receiver splits.',
                    ];
                }
                $qualified = collect($profiles[$split][$side] ?? [])->where('eligible', true);
                foreach (array_keys($profiles[$split][$side] ?? []) as $id) {
                    $p = &$profiles[$split][$side][$id];
                    $p['league_teams'] = $qualified->count();
                    $p['rank'] = $p['eligible'] && $qualified->count() === 32
                        ? 1 + $qualified->filter(fn ($other) => $side === 'offense' ? $other['epa_per_play'] > $p['epa_per_play'] : $other['epa_per_play'] < $p['epa_per_play'])->count() : null;
                    unset($p);
                }
            }
        }
        $directions = [];
        foreach ([[$target->away_team_id, $target->home_team_id], [$target->home_team_id, $target->away_team_id]] as [$offense, $defense]) {
            $splits = [];
            foreach (self::SPLITS as $split) {
                $sample = $profiles[$split]['offense'][$offense] ?? null;
                $faced = collect($buckets[$split]['offense'][$offense] ?? [])->map(function ($row) use ($profiles, $split) {
                    $p = $profiles[$split]['defense'][$row['opponent_id']] ?? null;

                    return $p && $p['eligible'] ? ['opponent_team_id' => $row['opponent_id'], 'epa_allowed' => $p['epa_per_play'], 'rank' => $p['rank'], 'plays' => $row['plays']] : null;
                })->filter();
                $splits[] = ['split' => $split, 'offense' => $sample, 'defense' => $profiles[$split]['defense'][$defense] ?? null,
                    'previous_opponents' => ['games' => $faced->count(), 'weighted_epa_allowed' => $faced->sum('plays') > 0 ? round($faced->sum(fn ($r) => $r['epa_allowed'] * $r['plays']) / $faced->sum('plays'), 4) : null,
                        'comparison_games' => $faced->values()->all(), 'basis' => 'Play-weighted current-season defensive profiles of previous opponents; descriptive, not a fitted adjustment.']];
            }
            $directions[] = ['offense_team_id' => (int) $offense, 'defense_team_id' => (int) $defense, 'splits' => $splits];
        }

        return ['version' => 'nfl-position-epa-v1', 'season' => (int) $target->season, 'mode' => 'descriptive_only', 'directions' => $directions,
            'source' => 'nflverse_pbp_plays and season/team/GSIS-matched nflverse_rosters',
            'definition' => 'EPA is from the offense perspective: higher offense and lower defense EPA allowed are better. Pass includes sacks; target splits include incomplete and intercepted targets with known identities. Zero EPA remains an observation.',
            'limitations' => ['Position totals describe targeted-play outcomes, not individual defender responsibility.', 'Targets without unambiguous season/team roster positions are excluded and counted.',
                'League ranks require all 32 qualified defenses/offenses for that split.', 'Previous opponents are measured through the same cutoff; historical source revisions are not verified pregame inputs.',
                'These splits do not change predictions, approval status, prop probabilities or expected target volume.']];
    }
}
