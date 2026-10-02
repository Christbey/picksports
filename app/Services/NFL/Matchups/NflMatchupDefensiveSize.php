<?php

namespace App\Services\NFL\Matchups;

use App\Models\NFL\DepthChartSnapshot;
use App\Models\NFL\Game;
use App\Models\NFL\GameDepthChartLink;
use App\Models\NFL\Team;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Current projected group size, never individual coverage assignments. */
final class NflMatchupDefensiveSize
{
    public function metrics(Game $game, ?CarbonImmutable $cutoff): array
    {
        if (! $cutoff) {
            return [];
        }
        $asOf = $cutoff->min(CarbonImmutable::now());
        $headers = DepthChartSnapshot::where('season', $game->season)->whereBetween('observed_at', [$asOf->subDays(7), $asOf])
            ->where(fn ($q) => $q->whereNull('source_updated_at')->orWhere('source_updated_at', '<=', $asOf))
            ->orderByDesc('observed_at')->orderByDesc('id')->get(['id', 'team_id'])->unique('team_id');
        $charts = DepthChartSnapshot::with('entries:id,snapshot_id,position_code,depth_rank,espn_athlete_id,observed_at,source_updated_at')->whereIn('id', $headers->pluck('id'))->get(['id', 'team_id', 'season', 'observed_at', 'source_updated_at'])->keyBy('team_id');
        $linkIds = [];
        foreach (['home', 'away'] as $side) {
            $team = (int) $game->{$side.'_team_id'};
            $link = GameDepthChartLink::with(['snapshot:id,team_id,season,observed_at,source_updated_at', 'snapshot.entries:id,snapshot_id,position_code,depth_rank,espn_athlete_id,observed_at,source_updated_at'])->where('game_id', $game->id)->where('team_id', $team)->where('side', $side)
                ->where('as_of', '<=', $asOf)->where('observed_at', '<=', $asOf)->latest('as_of')->latest('id')->first();
            $charts[$team] = $link?->snapshot;
            $linkIds[$team] = $link?->id;
        }
        $rosters = DB::table('nflverse_rosters')->where('season', $game->season)->whereNotNull('espn_id')->get(['team_id', 'espn_id', 'gsis_id', 'position', 'height', 'weight'])->groupBy(fn ($row) => $row->team_id.':'.$row->espn_id);
        $result = [];
        foreach (Team::pluck('id') as $team) {
            $chart = $charts->get($team);
            $valid = $chart && (int) $chart->team_id === (int) $team && (int) $chart->season === (int) $game->season
                && $chart->observed_at?->betweenIncluded($asOf->subDays(7), $asOf)
                && (! $chart->source_updated_at || $chart->source_updated_at->lte($asOf));
            foreach (['cb_weight' => ['weight', ['CB', 'LCB', 'RCB'], 2, 120, 450, 'lb'],
                'secondary_height' => ['height', ['CB', 'LCB', 'RCB', 'S', 'FS', 'SS'], 4, 60, 90, 'in']] as $metric => [$field, $positions, $minimum, $low, $high, $unit]) {
                $sample = ['value' => null, 'eligible' => false, 'rank' => null, 'games' => 0, 'plays' => null,
                    'depth_chart_snapshot_id' => $chart?->id, 'depth_chart_link_id' => $linkIds[$team] ?? null, 'as_of' => $asOf->toIso8601String(), 'unit' => $unit];
                $entries = $valid ? $chart->entries->filter(fn ($entry) => (int) $entry->depth_rank === 1 && in_array(strtoupper((string) $entry->position_code), $positions, true)) : collect();
                $values = [];
                if ($entries->count() >= $minimum && $entries->pluck('espn_athlete_id')->uniqueStrict()->count() === $entries->count()) {
                    foreach ($entries as $entry) {
                        $rows = $rosters->get($team.':'.$entry->espn_athlete_id, collect());
                        $sizes = $rows->pluck($field)->uniqueStrict();
                        if (! filled($entry->espn_athlete_id) || ! $entry->observed_at || $entry->observed_at->gt($chart->observed_at) || $entry->source_updated_at?->gt($chart->observed_at)
                            || $rows->isEmpty() || ! $rows->every(fn ($row) => filled($row->gsis_id) && in_array($row->position, ['CB', 'DB', 'FS', 'SS', 'S'], true))
                            || $rows->pluck('gsis_id')->uniqueStrict()->count() !== 1 || $sizes->count() !== 1 || ! is_numeric($sizes->first()) || $sizes->first() < $low || $sizes->first() > $high) {
                            break;
                        }
                        $values[] = (float) $sizes->first();
                    }
                }
                if (count($values) === $entries->count() && count($values) >= $minimum) {
                    $sample = [...$sample, 'value' => array_sum($values) / count($values), 'eligible' => true, 'games' => 1,
                        'projected_players' => count($values), 'player_ids' => $entries->pluck('espn_athlete_id')->values()->all(),
                        'display_value' => 'Projected '.($metric === 'cb_weight' ? 'cornerbacks' : 'secondary').' average '.number_format(array_sum($values) / count($values), 2).' '.$unit];
                }
                $result[$metric]['defense'][$team] = $sample;
            }
        }
        foreach ($result as &$sides) {
            $qualified = array_filter($sides['defense'], fn ($sample) => $sample['eligible']);
            foreach ($sides['defense'] as &$sample) {
                $sample['league_teams'] = count($qualified);
                if ($sample['eligible'] && count($qualified) === 32) {
                    $sample['rank'] = 1 + count(array_filter($qualified, fn ($other) => $other['value'] - $sample['value'] > .0000001));
                    $sample['rank_end'] = $sample['rank'] - 1 + count(array_filter($qualified, fn ($other) => abs($other['value'] - $sample['value']) < .0000001));
                }
            }
            unset($sample);
        }
        unset($sides);

        return $result;
    }
}
