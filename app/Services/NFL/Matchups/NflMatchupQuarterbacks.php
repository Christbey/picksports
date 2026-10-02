<?php

namespace App\Services\NFL\Matchups;

use App\Models\NFL\DepthChartSnapshot;
use App\Models\NFL\DepthChartSnapshotEntry;
use App\Models\NFL\Game;
use App\Models\NFL\GameDepthChartLink;
use App\Services\NFL\QuarterbackAvailability;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class NflMatchupQuarterbacks
{
    public function metrics(Game $target, Collection $games, ?CarbonImmutable $cutoff): array
    {
        if (! $cutoff || $games->isEmpty()) {
            return [];
        }
        $query = DB::table('nflverse_pbp_plays')->whereIn('nfl_game_id', $games->keys())
            ->where('play_type', 'pass')
            ->where(fn ($q) => $q->whereNull('description')->orWhereRaw('LOWER(description) NOT LIKE ?', ['%no play%']))
            ->where(fn ($q) => $q->whereNull('description')->orWhereRaw('LOWER(description) NOT LIKE ?', ['%two-point conversion attempt%']));
        $splits = [
            'deep' => ['is_sack = 0 AND air_yards >= 20', 'is_sack = 1 OR (is_sack = 0 AND air_yards IS NOT NULL)'],
            'play_action' => ['ftn_is_play_action = 1', 'ftn_is_play_action IS NOT NULL'],
            'rpo' => ['ftn_is_rpo = 1', 'ftn_is_rpo IS NOT NULL'],
        ];
        foreach (['man' => ['participation_man_zone', 'MAN_COVERAGE'], 'zone' => ['participation_man_zone', 'ZONE_COVERAGE'],
            'cover_1' => ['participation_coverage', 'COVER_1'], 'cover_2' => ['participation_coverage', 'COVER_2'], 'cover_3' => ['participation_coverage', 'COVER_3'], 'cover_4' => ['participation_coverage', 'COVER_4']] as $key => [$field, $code]) {
            $splits[$key] = ["{$field} = '{$code}'", "{$field} IS NOT NULL"];
        }
        $fumbleKnown = "(is_fumble_lost = 0 OR (is_fumble_lost = 1 AND NULLIF(fumbled_1_player_id, '') IS NOT NULL AND NULLIF(fumbled_2_player_id, '') IS NULL))";
        $query->select(['nfl_game_id', 'possession_team_id', 'defense_team_id', 'passer_player_id'])
            ->selectRaw('COUNT(*) AS candidates, COUNT(epa) AS measured, SUM(epa) AS total')
            ->selectRaw("SUM(CASE WHEN is_interception IN (0, 1) AND {$fumbleKnown} THEN 1 ELSE 0 END) AS turnover_count,
                SUM(CASE WHEN is_interception IN (0, 1) AND {$fumbleKnown} THEN is_interception + CASE WHEN is_fumble_lost = 1 AND fumbled_1_player_id = passer_player_id THEN 1 ELSE 0 END ELSE 0 END) AS turnovers")
            ->selectRaw("SUM(CASE WHEN is_sack = 0 OR is_sack IS NULL THEN 1 ELSE 0 END) AS read_candidates,
                SUM(CASE WHEN is_sack = 0 AND ftn_read_thrown IN ('0', '1', '2', 'CHK', 'DES', 'SD') THEN 1 ELSE 0 END) AS read_count,
                SUM(CASE WHEN is_sack = 0 AND ftn_read_thrown = 'CHK' THEN 1 ELSE 0 END) AS checkdowns")
            ->selectRaw('COUNT(ftn_n_blitzers) AS charted, SUM(CASE WHEN ftn_n_blitzers > 0 THEN 1 ELSE 0 END) AS blitzes, SUM(CASE WHEN ftn_n_blitzers > 0 AND epa IS NOT NULL THEN 1 ELSE 0 END) AS blitz_measured, SUM(CASE WHEN ftn_n_blitzers > 0 THEN epa ELSE 0 END) AS blitz_total');
        foreach ($splits as $key => [$condition, $known]) {
            $query->selectRaw("SUM(CASE WHEN {$known} THEN 1 ELSE 0 END) AS {$key}_charted,
                SUM(CASE WHEN {$condition} THEN 1 ELSE 0 END) AS {$key}_candidates,
                SUM(CASE WHEN ({$condition}) AND epa IS NOT NULL THEN 1 ELSE 0 END) AS {$key}_count,
                SUM(CASE WHEN {$condition} THEN epa ELSE 0 END) AS {$key}_sum");
        }
        $rows = $query->groupBy('nfl_game_id', 'possession_team_id', 'defense_team_id', 'passer_player_id')->get();
        $appearances = [];
        $coverage = [];
        foreach ($rows as $row) {
            $game = $games->get($row->nfl_game_id);
            $ids = [(int) $game->home_team_id, (int) $game->away_team_id];
            if ((int) $row->possession_team_id === (int) $row->defense_team_id
                || ! in_array((int) $row->possession_team_id, $ids, true) || ! in_array((int) $row->defense_team_id, $ids, true)) {
                continue;
            }
            $key = $row->nfl_game_id.':'.$row->possession_team_id;
            $coverage[$key] ??= ['total' => 0, 'identified' => 0];
            $coverage[$key]['total'] += (int) $row->candidates;
            if (! filled($row->passer_player_id)) {
                continue;
            }
            $coverage[$key]['identified'] += (int) $row->candidates;
            $appearance = ['game_id' => (int) $row->nfl_game_id, 'key' => $key,
                'turnover_count' => (int) $row->turnover_count, 'turnovers' => (int) $row->turnovers,
                'read_candidates' => (int) $row->read_candidates, 'read_count' => (int) $row->read_count, 'checkdowns' => (int) $row->checkdowns,
                'count' => (int) $row->measured, 'candidate' => (int) $row->candidates, 'sum' => (float) $row->total,
                'charted' => (int) $row->charted, 'blitzes' => (int) $row->blitzes, 'blitz_count' => (int) $row->blitz_measured, 'blitz_sum' => (float) $row->blitz_total];
            foreach ($splits as $key => $_) {
                foreach (['charted', 'candidates', 'count', 'sum'] as $field) {
                    $appearance[$key.'_'.$field] = (float) $row->{$key.'_'.$field};
                }
            }
            $appearances[$row->passer_player_id][] = $appearance;
        }
        $runs = DB::table('nflverse_pbp_plays')->whereIn('nfl_game_id', $games->keys())->where('play_type', 'run')
            ->where(fn ($q) => $q->whereNull('is_sack')->orWhere('is_sack', false))
            ->where(fn ($q) => $q->whereNull('description')->orWhereRaw('LOWER(description) NOT LIKE ?', ['%no play%']))
            ->where(fn ($q) => $q->whereNull('description')->orWhereRaw('LOWER(description) NOT LIKE ?', ['%two-point conversion attempt%']))
            ->select(['nfl_game_id', 'possession_team_id', 'defense_team_id', 'rusher_player_id'])
            ->selectRaw("SUM(CASE WHEN NULLIF(rusher_player_id, '') IS NOT NULL THEN 1 ELSE 0 END) AS identified,
                SUM(CASE WHEN {$fumbleKnown} THEN 1 ELSE 0 END) AS turnover_count,
                SUM(CASE WHEN {$fumbleKnown} AND is_fumble_lost = 1 AND fumbled_1_player_id = rusher_player_id THEN 1 ELSE 0 END) AS turnovers")
            ->selectRaw("COUNT(*) AS candidates, SUM(CASE WHEN qb_scramble IS NOT NULL AND rusher_player_id IS NOT NULL AND rusher_player_id <> '' THEN 1 ELSE 0 END) AS charted, SUM(CASE WHEN qb_scramble = 1 THEN 1 ELSE 0 END) AS scrambles")
            ->groupBy('nfl_game_id', 'possession_team_id', 'defense_team_id', 'rusher_player_id')->get();
        $runCoverage = [];
        $scrambles = [];
        $runTurnovers = [];
        foreach ($runs as $run) {
            $game = $games->get($run->nfl_game_id);
            $teams = [(int) $game->home_team_id, (int) $game->away_team_id];
            if ($run->possession_team_id === $run->defense_team_id || ! in_array((int) $run->possession_team_id, $teams, true) || ! in_array((int) $run->defense_team_id, $teams, true)) {
                continue;
            }
            $key = $run->nfl_game_id.':'.$run->possession_team_id;
            $runCoverage[$key] ??= ['candidates' => 0, 'charted' => 0, 'identified' => 0];
            $runCoverage[$key]['candidates'] += (int) $run->candidates;
            $runCoverage[$key]['charted'] += (int) $run->charted;
            $runCoverage[$key]['identified'] += (int) $run->identified;
            $scrambles[$run->rusher_player_id][$key] = (int) $run->scrambles;
            $runTurnovers[$run->rusher_player_id][$key] = ['candidates' => (int) $run->candidates, 'count' => (int) $run->turnover_count, 'turnovers' => (int) $run->turnovers];
        }
        $mobilitySamples = [];
        $checkdownSamples = [];
        $turnoverSamples = [];
        $samples = [];
        $blitzSamples = [];
        $trendSamples = [];
        $splitSamples = [];
        foreach ($appearances as $id => $rows) {
            $valid = array_filter($rows, fn ($r) => $r['count'] >= 15 && $r['count'] >= $r['candidate'] * .9
                && $coverage[$r['key']]['identified'] >= $coverage[$r['key']]['total'] * .9);
            $count = array_sum(array_column($valid, 'count'));
            $samples[$id] = ['value' => $count ? array_sum(array_column($valid, 'sum')) / $count : null,
                'games' => count($valid), 'game_ids' => array_column($valid, 'game_id'), 'plays' => $count,
                'eligible' => count($valid) >= 2 && count($valid) === count($rows), 'rank' => null,
                'player_id' => $id, 'player_id_namespace' => 'gsis', 'minimum_plays_per_appearance' => 15];
            $turnoverComplete = collect($valid)->every(function ($row) use ($id, $runTurnovers, $runCoverage): bool {
                $run = $runTurnovers[$id][$row['key']] ?? ['count' => 0, 'candidates' => 0];

                return $row['turnover_count'] === $row['candidate'] && $run['count'] === $run['candidates']
                    && ($runCoverage[$row['key']]['identified'] ?? 0) >= ($runCoverage[$row['key']]['candidates'] ?? 0) * .9;
            });
            $turnoverPlays = array_sum(array_column($valid, 'turnover_count')) + array_sum(array_map(fn ($row) => $runTurnovers[$id][$row['key']]['count'] ?? 0, $valid));
            $turnovers = array_sum(array_column($valid, 'turnovers')) + array_sum(array_map(fn ($row) => $runTurnovers[$id][$row['key']]['turnovers'] ?? 0, $valid));
            $turnoverSamples[$id] = [...$samples[$id], 'value' => $turnoverPlays ? $turnovers / $turnoverPlays : null,
                'eligible' => $samples[$id]['eligible'] && $turnoverComplete, 'plays' => $turnoverPlays, 'turnovers' => $turnovers];
            $reads = array_sum(array_column($valid, 'read_count'));
            $checkdowns = array_sum(array_column($valid, 'checkdowns'));
            $readComplete = collect($valid)->every(fn ($row) => $row['read_count'] >= 15 && $row['read_count'] >= $row['read_candidates'] * .9);
            $checkdownSamples[$id] = [...$samples[$id], 'value' => $reads ? $checkdowns / $reads : null,
                'eligible' => $samples[$id]['eligible'] && $readComplete, 'plays' => $reads, 'checkdowns' => $checkdowns,
                'candidate_throws' => array_sum(array_column($valid, 'read_candidates'))];
            $runComplete = collect($valid)->every(fn ($row) => ($runCoverage[$row['key']]['charted'] ?? 0) >= ($runCoverage[$row['key']]['candidates'] ?? 0) * .9);
            $scrambleCount = array_sum(array_map(fn ($row) => $scrambles[$id][$row['key']] ?? 0, $valid));
            $dropbacks = array_sum(array_column($valid, 'candidate')) + $scrambleCount;
            $mobilitySamples[$id] = [...$samples[$id], 'value' => $dropbacks ? $scrambleCount / $dropbacks : null,
                'eligible' => $samples[$id]['eligible'] && $runComplete, 'plays' => $dropbacks, 'scrambles' => $scrambleCount];
            $recent = collect($rows)->sortBy(fn ($row) => $games->get($row['game_id'])->game_date->getTimestamp())->take(-4)->values();
            $trendEligible = $samples[$id]['eligible'] && $recent->count() === 4;
            $values = $recent->map(fn ($row) => $row['count'] ? $row['sum'] / $row['count'] : null)->all();
            $trend = null;
            if ($trendEligible) {
                $changes = array_map(fn ($index) => $values[$index] - $values[$index - 1], [1, 2, 3]);
                $trend = min($changes) > 0 ? min($changes) : (max($changes) < 0 ? max($changes) : 0);
            }
            $trendSamples[$id] = [...$samples[$id], 'value' => $trend, 'eligible' => $trendEligible,
                'games' => $recent->count(), 'game_ids' => $recent->pluck('game_id')->all(), 'plays' => $recent->sum('count'),
                'trend_games_required' => 4, 'trend_game_values' => $values, 'ranking_population' => null];
            $charted = array_filter($valid, fn ($r) => $r['charted'] >= $r['candidate'] * .9 && $r['blitz_count'] >= $r['blitzes'] * .9);
            $blitzCount = array_sum(array_column($charted, 'blitz_count'));
            $blitzSamples[$id] = [...$samples[$id], 'value' => $blitzCount ? array_sum(array_column($charted, 'blitz_sum')) / $blitzCount : null,
                'games' => count($charted), 'game_ids' => array_column($charted, 'game_id'), 'plays' => $blitzCount,
                'eligible' => $samples[$id]['eligible'] && count($charted) === count($rows) && $blitzCount >= 10,
                'minimum_blitz_plays' => 10];
            foreach ($splits as $key => $_) {
                $complete = array_filter($valid, fn ($r) => $r[$key.'_charted'] >= $r['candidate'] * .9
                    && $r[$key.'_count'] >= $r[$key.'_candidates'] * .9);
                $splitCount = (int) array_sum(array_column($complete, $key.'_count'));
                $splitSamples[$key][$id] = [...$samples[$id],
                    'value' => $splitCount ? array_sum(array_column($complete, $key.'_sum')) / $splitCount : null,
                    'games' => count($complete), 'game_ids' => array_column($complete, 'game_id'), 'plays' => $splitCount,
                    'eligible' => $samples[$id]['eligible'] && count($complete) === count($rows) && $splitCount >= 10,
                    'minimum_split_plays' => 10];
            }

        }
        $mobilitySamples = $this->rank($mobilitySamples);
        $checkdownSamples = $this->rank($checkdownSamples);
        $turnoverSamples = $this->rank($turnoverSamples);
        $samples = $this->rank($samples);
        $blitzSamples = $this->rank($blitzSamples);
        foreach ($splitSamples as $key => $split) {
            $splitSamples[$key] = $this->rank($split);
        }
        $qualified = array_filter($samples, fn ($r) => $r['eligible']);
        $target->loadMissing(['homeTeam', 'awayTeam']);
        $rosters = DB::table('nflverse_rosters')->where('season', $target->season)
            ->whereIn('team_id', [$target->home_team_id, $target->away_team_id])->where('position', 'QB')->get();
        $result = [];
        foreach (['home', 'away'] as $side) {
            $team = (int) $target->{$side.'_team_id'};
            $identity = $this->identity($target, $side, $cutoff);
            $sample = $samples[$identity['player_id'] ?? ''] ?? ['value' => null, 'rank' => null, 'games' => 0, 'plays' => 0, 'eligible' => false, 'league_players' => count($qualified)];
            $availability = app(QuarterbackAvailability::class);
            if (! isset($identity['identity_reason']) && $availability->excludes($availability->forGame($target, $team), $identity['local_player_id'] ?? null, $identity['player_name'] ?? null)) {
                $identity['identity_reason'] = 'The selected quarterback is unavailable in game-scoped injury evidence; no replacement is guessed.';
            }
            if (isset($identity['identity_reason'])) {
                $sample['eligible'] = false;
                $sample['value'] = null;
                $sample['rank'] = null;
            }
            $blitz = $blitzSamples[$identity['player_id'] ?? ''] ?? ['value' => null, 'rank' => null, 'games' => 0, 'plays' => 0, 'eligible' => false, 'league_players' => 0];
            if (isset($identity['identity_reason'])) {
                $blitz['eligible'] = false;
                $blitz['value'] = null;
                $blitz['rank'] = null;
            }
            $trend = $trendSamples[$identity['player_id'] ?? ''] ?? ['value' => null, 'rank' => null, 'games' => 0, 'plays' => 0, 'eligible' => false, 'trend_games_required' => 4];
            if (isset($identity['identity_reason'])) {
                $trend['eligible'] = false;
                $trend['value'] = null;
            }
            $result[$team] = [...$sample, ...$identity, 'blitz_sample' => [...$blitz, ...$identity], 'trend_sample' => [...$trend, ...$identity]];
            $mobility = $mobilitySamples[$identity['player_id'] ?? ''] ?? ['value' => null, 'rank' => null, 'games' => 0, 'plays' => 0, 'eligible' => false, 'league_players' => 0];
            if (isset($identity['identity_reason'])) {
                $mobility['eligible'] = false;
                $mobility['value'] = null;
                $mobility['rank'] = null;
            }
            $result[$team]['mobility_sample'] = [...$mobility, ...$identity];
            $checkdown = $checkdownSamples[$identity['player_id'] ?? ''] ?? ['value' => null, 'rank' => null, 'games' => 0, 'plays' => 0, 'eligible' => false, 'league_players' => 0];
            if (isset($identity['identity_reason'])) {
                $checkdown['eligible'] = false;
                $checkdown['value'] = null;
                $checkdown['rank'] = null;
            }
            $result[$team]['checkdown_sample'] = [...$checkdown, ...$identity];
            $turnover = $turnoverSamples[$identity['player_id'] ?? ''] ?? ['value' => null, 'rank' => null, 'games' => 0, 'plays' => 0, 'eligible' => false, 'league_players' => 0];
            if (isset($identity['identity_reason'])) {
                $turnover['eligible'] = false;
                $turnover['value'] = null;
                $turnover['rank'] = null;
            }
            $result[$team]['turnover_sample'] = [...$turnover, ...$identity];
            $result[$team]['backup_sample'] = $this->backupSample($target, $side, $identity, $rosters, $cutoff);
            $result[$team]['change_sample'] = $this->changeSample($target, $team, $result[$team]['backup_sample'], $cutoff);
            $result[$team]['rookie_sample'] = $this->rookieSample($identity, $rosters, $team, (int) $target->season);
            foreach ($splits as $key => $_) {
                $split = $splitSamples[$key][$identity['player_id'] ?? ''] ?? ['value' => null, 'rank' => null, 'games' => 0, 'plays' => 0, 'eligible' => false, 'league_players' => 0];
                if (isset($identity['identity_reason'])) {
                    $split['eligible'] = false;
                    $split['value'] = null;
                    $split['rank'] = null;
                }
                $result[$team][$key.'_sample'] = [...$split, ...$identity];
            }

        }

        return $result;
    }

    private function backupSample(Game $target, string $side, array $identity, Collection $rosters, CarbonImmutable $cutoff): array
    {
        $missing = [...$identity, 'value' => null, 'eligible' => false, 'rank' => null, 'games' => 0, 'plays' => null,
            'identity_reason' => $identity['identity_reason'] ?? 'The selected quarterback needs an unambiguous roster mapping and a fresh game-linked QB depth order.'];
        if (isset($identity['identity_reason'])) {
            return $missing;
        }
        $team = (int) $target->{$side.'_team_id'};
        $mapping = $rosters->filter(fn ($row) => (int) $row->team_id === $team && $row->gsis_id === ($identity['player_id'] ?? null));
        $espnIds = $mapping->pluck('espn_id')->uniqueStrict();
        if ($espnIds->count() !== 1 || ! filled($espnIds->first())) {
            return $missing;
        }
        $espn = (string) $espnIds->first();
        if ($rosters->filter(fn ($row) => (int) $row->team_id === $team && (string) $row->espn_id === $espn)->pluck('gsis_id')->uniqueStrict()->count() !== 1) {
            return $missing;
        }
        $asOf = $cutoff->min(CarbonImmutable::now());
        $link = GameDepthChartLink::with('snapshot.entries')->where('game_id', $target->id)->where('side', $side)->where('team_id', $team)
            ->where('as_of', '<=', $asOf)->where('observed_at', '<=', $asOf)->latest('as_of')->latest('id')->first();
        $chart = $link?->snapshot;
        if (! $chart || (int) $chart->team_id !== $team || (int) $chart->season !== (int) $target->season
            || ! $chart->observed_at || $chart->observed_at->gt($asOf) || $chart->observed_at->lt($asOf->subDays(7))
            || $chart->source_updated_at?->gt($asOf)) {
            return $missing;
        }
        $entries = $chart->entries->filter(fn ($entry) => strtoupper((string) $entry->position_code) === 'QB');
        $starter = $entries->filter(fn ($entry) => (int) $entry->depth_rank === 1);
        $selected = $entries->filter(fn ($entry) => (string) $entry->espn_athlete_id === $espn);
        if ($starter->count() !== 1 || $selected->count() !== 1
            || ! filled($starter->first()->espn_athlete_id) || (int) $selected->first()->depth_rank < 1
            || ! $starter->merge($selected)->every(fn ($entry) => $entry->observed_at && $entry->observed_at->lte($chart->observed_at)
                && (! $entry->source_updated_at || $entry->source_updated_at->lte($chart->observed_at)))) {
            return $missing;
        }
        $backup = (int) $selected->first()->depth_rank > 1;

        return [...$identity, 'value' => (int) $backup, 'eligible' => true, 'rank' => null, 'games' => 0, 'plays' => null,
            'depth_chart_link_id' => $link->id, 'depth_chart_snapshot_id' => $chart->id,
            'selected_depth_rank' => (int) $selected->first()->depth_rank, 'charted_starter_espn_id' => $starter->first()->espn_athlete_id,
            'display_value' => $backup ? 'Selected QB listed below the charted starter' : 'Selected QB is the charted starter'];
    }

    private function changeSample(Game $target, int $team, array $current, CarbonImmutable $cutoff): array
    {
        $missing = [...$current, 'value' => null, 'eligible' => false,
            'identity_reason' => $current['identity_reason'] ?? 'Two recent unambiguous QB chart observations, with the selected QB currently first, are required.'];
        if (! $current['eligible'] || ($current['selected_depth_rank'] ?? null) !== 1) {
            return $missing;
        }
        $chart = DepthChartSnapshot::find($current['depth_chart_snapshot_id']);
        $asOf = $cutoff->min(CarbonImmutable::now());
        $prior = DepthChartSnapshot::with('entries')->where('team_id', $team)->where('season', $target->season)
            ->where('observed_at', '<', $chart->observed_at)->where('observed_at', '>=', $asOf->subDays(7))
            ->where(fn ($q) => $q->whereNull('source_updated_at')->orWhere('source_updated_at', '<=', $asOf))
            ->latest('observed_at')->latest('id')->first();
        $qbs = $prior?->entries->filter(fn ($entry) => strtoupper((string) $entry->position_code) === 'QB' && (int) $entry->depth_rank === 1);
        $qb = $qbs?->first();
        if (! $qb || $qbs->count() !== 1 || ! filled($qb->espn_athlete_id) || ! $qb->observed_at
            || $qb->observed_at->gt($prior->observed_at) || $qb->source_updated_at?->gt($prior->observed_at)) {
            return $missing;
        }
        $changed = (string) $qb->espn_athlete_id !== (string) $current['charted_starter_espn_id'];

        return [...$current, 'value' => (int) $changed, 'previous_snapshot_id' => $prior->id,
            'previous_charted_qb_espn_id' => $qb->espn_athlete_id,
            'display_value' => $changed ? 'Latest projected QB differs from the preceding chart' : 'Same projected QB in the two latest charts'];
    }

    private function rookieSample(array $identity, Collection $rosters, int $team, int $season): array
    {
        $rows = $rosters->filter(fn ($row) => (int) $row->team_id === $team && filled($identity['player_id'] ?? null)
            && $row->gsis_id === $identity['player_id']);
        $experience = $rows->pluck('years_exp')->uniqueStrict();
        $known = $rows->isNotEmpty() && $experience->count() === 1 && $experience->first() !== null;
        $reason = $identity['identity_reason'] ?? ($known ? null : 'Unambiguous quarterback experience from the target-season team roster is required.');
        $years = $known ? (int) $experience->first() : null;
        $sample = [...$identity, 'value' => $reason === null ? (int) ($years === 0) : null,
            'eligible' => $reason === null, 'rank' => null, 'games' => 0, 'plays' => null,
            'years_experience' => $years, 'roster_season' => $season, 'roster_ids' => $rows->pluck('id')->values()->all(),
            'display_value' => $reason !== null ? null : ($years === 0 ? 'Rookie quarterback' : 'Veteran quarterback ('.$years.' years)'),
        ];
        if ($reason !== null) {
            $sample['identity_reason'] = $reason;
        }

        return $sample;
    }

    private function rank(array $samples): array
    {
        $qualified = array_filter($samples, fn ($r) => $r['eligible']);
        foreach ($samples as &$sample) {
            $sample['league_players'] = count($qualified);
            $sample['ranking_population'] = 'qualified_quarterbacks';
            if (! $sample['eligible'] || count($qualified) < 24) {
                continue;
            }
            $sample['rank'] = 1 + count(array_filter($qualified, fn ($r) => $r['value'] - $sample['value'] > .0000001));
            $sample['rank_end'] = $sample['rank'] - 1 + count(array_filter($qualified, fn ($r) => abs($r['value'] - $sample['value']) < .0000001));
        }
        unset($sample);

        return $samples;
    }

    private function identity(Game $game, string $side, CarbonImmutable $cutoff): array
    {
        $id = $game->{$side.'_qb_id'};
        if (is_string($id) && preg_match('/^00-\d+$/', $id)) {
            return ['player_id' => $id, 'player_name' => $game->{$side.'_qb_name'}, 'identity_source' => 'game_quarterback', 'identity_status' => 'game_record_identity'];
        }
        $asOf = $cutoff->min(CarbonImmutable::now());
        $link = GameDepthChartLink::where('game_id', $game->id)->where('side', $side)->where('team_id', $game->{$side.'_team_id'})
            ->where('as_of', '<=', $asOf)->where('observed_at', '<=', $asOf)
            ->where('observed_at', '>=', $asOf->subDays(7))->orderByDesc('as_of')->orderByDesc('id')->first();
        $missing = ['identity_reason' => 'A unique game-linked quarterback identity with a verified player-ID mapping is required.'];
        if (! $link) {
            return $missing;
        }
        $entries = DepthChartSnapshotEntry::with('player')->where('snapshot_id', $link->snapshot_id)
            ->where('position_code', 'QB')->where('depth_rank', 1)->get();
        if ($entries->count() !== 1) {
            return $missing;
        }
        $entry = $entries->first();
        if (! filled($entry->espn_athlete_id)) {
            return $missing;
        }
        $rosters = DB::table('nflverse_rosters')->where('season', $game->season)->where('team_id', $game->{$side.'_team_id'})
            ->where('position', 'QB')->where('espn_id', $entry->espn_athlete_id)->whereNotNull('gsis_id')->get();
        $ids = $rosters->pluck('gsis_id')->filter()->unique();
        if ($ids->count() !== 1) {
            return $missing;
        }

        return ['player_id' => $ids->first(), 'player_name' => $entry->player?->full_name ?? $rosters->first()->full_name,
            'local_player_id' => $entry->player_id, 'identity_source' => 'game_depth_chart_link', 'depth_chart_link_id' => $link->id,
            'identity_status' => 'projected_not_confirmed_starter', 'identity_observed_at' => $link->observed_at->toIso8601String()];
    }
}
