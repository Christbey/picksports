<?php

namespace App\Services\NFL\Matchups;

use App\Models\NFL\Game;
use App\Services\Sports\SportsDateWindowService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Reconstructed, cutoff-safe descriptions, not a fitted predictor or wager gate. */
final class NflMatchupSignalService
{
    private const MIN_GAMES = 2;

    private const LEAGUE_TEAMS = 32;

    public function __construct(private NflMatchupSignalCatalog $catalog, private SportsDateWindowService $dates) {}

    public function build(Game $game, string $window = 'season_to_date'): array
    {
        if (! in_array($window, ['season_to_date', 'previous_season'], true)) {
            throw new \InvalidArgumentException('Unsupported matchup evidence window.');
        }
        $season = (int) $game->season - ($window === 'previous_season' ? 1 : 0);
        $cutoff = $this->cutoff($game);
        $scopeReason = ! in_array((string) $game->season_type, [(string) config('nfl.season.types.regular', 2), 'regular', 'REG'], true)
            ? 'This catalog version supports regular-season target games only.' : null;
        $games = $cutoff === null || $scopeReason !== null ? collect() : $this->priorGames($game, $cutoff, $season);
        [$games, $baseline] = $this->baseline($games, $season, $window);
        $metrics = $this->metrics($games);
        foreach (app(NflMatchupWinRates::class)->metrics($games, $cutoff, $season) as $metric => $samples) {
            $metrics[$metric] = $samples;
        }
        foreach (app(NflMatchupDefensiveSize::class)->metrics($game, $cutoff) as $metric => $samples) {
            $metrics[$metric] = $samples;
        }
        $quarterbacks = app(NflMatchupQuarterbacks::class)->metrics($game, $games, $cutoff);
        foreach (app(NflMatchupPressure::class)->metrics($games, $quarterbacks) as $metric => $samples) {
            $metrics[$metric] = $samples;
        }
        foreach (app(NflMatchupTracking::class)->metrics($games, $quarterbacks) as $metric => $samples) {
            $metrics[$metric] = $samples;
        }
        $metrics['qb_pass_epa']['offense'] = $quarterbacks;
        foreach ($quarterbacks as $teamId => $sample) {
            foreach (['man', 'zone', 'cover_1', 'cover_2', 'cover_3', 'cover_4'] as $coverage) {
                $metrics['qb_'.$coverage.'_epa']['offense'][$teamId] = $sample[$coverage.'_sample'] ?? [];
            }
            $metrics['qb_pressure_epa']['offense'][$teamId] = $sample['pressure_sample'] ?? [];
            $metrics['qb_blitz_epa']['offense'][$teamId] = $sample['blitz_sample'] ?? [];
            $metrics['qb_deep_epa']['offense'][$teamId] = $sample['deep_sample'] ?? [];
            $metrics['qb_play_action_epa']['offense'][$teamId] = $sample['play_action_sample'] ?? [];
            $metrics['qb_rpo_epa']['offense'][$teamId] = $sample['rpo_sample'] ?? [];
            $metrics['qb_pass_epa_trend_3']['offense'][$teamId] = $sample['trend_sample'] ?? [];
            $metrics['qb_scramble_rate']['offense'][$teamId] = $sample['mobility_sample'] ?? [];
            $metrics['qb_checkdown_rate']['offense'][$teamId] = $sample['checkdown_sample'] ?? [];
            $metrics['qb_turnover_rate']['offense'][$teamId] = $sample['turnover_sample'] ?? [];
            $metrics['qb_recent_change']['offense'][$teamId] = $sample['change_sample'] ?? [];
            $metrics['backup_qb']['offense'][$teamId] = $sample['backup_sample'] ?? [];
            $metrics['rookie_qb']['offense'][$teamId] = $sample['rookie_sample'] ?? [];
        }
        foreach (app(NflMatchupPersonnel::class)->forGame($game, $cutoff) as $teamId => $samples) {
            foreach ($samples as $metric => $sample) {
                $metrics[$metric]['offense'][$teamId] = $sample;
                if (in_array($metric, ['secondary_out', 'safety_out', 'lb_out', 'cb1_out'], true)) {
                    $metrics[$metric]['defense'][$teamId] = $sample;
                }
            }
        }
        $entries = collect($this->catalog->entries())->keyBy('id');
        $signals = [];
        foreach ($this->catalog->rules() as $id => $rule) {
            foreach ([[(int) $game->home_team_id, (int) $game->away_team_id], [(int) $game->away_team_id, (int) $game->home_team_id]] as [$offenseId, $defenseId]) {
                $signals[] = $this->evaluate($entries[$id], $rule, $metrics, $offenseId, $defenseId, $cutoff, $scopeReason, $game);
            }
        }
        $counts = array_count_values(array_column($signals, 'status'));

        return [
            'version' => NflMatchupSignalCatalog::VERSION,
            'mode' => 'descriptive_only',
            'predictive_weight' => 0,
            'season' => $season,
            'target_season' => (int) $game->season,
            'cutoff_at' => $cutoff?->toIso8601String(),
            'window' => $window,
            'baseline' => $baseline,
            'prediction_effect' => ['winner' => false, 'spread' => false, 'total' => false, 'confidence' => false, 'approval' => false],
            'minimum_games' => self::MIN_GAMES,
            'minimum_league_teams' => self::LEAGUE_TEAMS,
            'limitations' => [
                'Reconstructed from currently stored historical results and provider plays, not an immutable as-known-at-kickoff snapshot. Later corrections may change these descriptions.',
                'Only final regular-season games from the explicitly selected season and a prior UTC calendar date are included. Same-day results are excluded because the games table has no reliable completion timestamp.',
                'Previous-season context is a separate selected sample, never a silent fallback or blended forecast input. Rosters, quarterbacks and coaches may have changed.',
                'Team rankings require all 32 teams; QB rankings require at least 24 qualified passers. Both require at least two qualifying games. Ties crossing a top/bottom boundary do not qualify.',
                'Each prior game must meet volume floors: 30 overall; 15 passing or early-down; 8 rushing; 5 late-down; 4 first-down rushing. First-down passing requires 16 pooled opportunities; third-down passing, red-zone passing and short-yardage require 6 pooled opportunities across at least two complete games. Require 90% metric/context coverage in every game, including games with zero situational opportunities. These checks cannot independently prove that a provider import contains every play.',
                'EPA and success use nflverse pass/run plays including sacks, excluding no-play, conversion-attempt and special-teams rows. Success means EPA greater than zero; rushing includes scrambles classified as runs.',
                'Scoring uses team points scored/allowed, including defensive and special-teams scores, not isolated offensive scoring.',
                'Overlapping rules are correlated descriptions, not independent votes, calibrated probabilities, or approved bets.',
            ],
            'summary' => [
                'catalog_entries' => $entries->count(),
                'supported_rules' => count($this->catalog->rules()),
                'matched' => $counts['matched'] ?? 0,
                'not_matched' => $counts['not_matched'] ?? 0,
                'insufficient_data' => $counts['insufficient_data'] ?? 0,
                'unsupported' => $entries->where('support', 'unavailable')->count(),
                'situational_records' => $entries->where('support', 'situational_records')->count(),
            ],
            'signals' => $signals,
            'catalog' => $entries->values()->all(),
        ];
    }

    private function cutoff(Game $game): ?CarbonImmutable
    {
        $date = $game->getRawOriginal('game_date');
        $hasTime = is_string($game->game_time) && preg_match('/^\d{1,2}:\d{2}/', $game->game_time) === 1;
        $hasTimestamp = is_string($date) && preg_match('/[ T](?!00:00(?::00)?(?:$|[.+Z-]))\d{2}:\d{2}/', $date) === 1;
        if (! $hasTime && ! $hasTimestamp) {
            return null;
        }
        try {
            return $this->dates->gameDateTimeUtc($date, $game->game_time);
        } catch (\Throwable) {
            return null;
        }
    }

    private function priorGames(Game $game, CarbonImmutable $cutoff, int $season): Collection
    {
        return Game::query()->where('season', $season)
            ->whereIn('season_type', [(string) config('nfl.season.types.regular', 2), 'regular', 'REG'])
            ->whereIn('status', [config('nfl.statuses.final', 'STATUS_FINAL'), 'final', 'completed'])
            ->where('id', '!=', $game->id)
            ->whereDate('game_date', '<', $cutoff->toDateString())
            ->get(['id', 'season', 'week', 'home_team_id', 'away_team_id', 'game_date', 'game_time', 'home_score', 'away_score', 'neutral_site'])
            ->filter(function (Game $prior) use ($cutoff): bool {
                $kickoff = $this->dates->gameDateTimeUtc($prior->getRawOriginal('game_date'), $prior->game_time);
                if ($kickoff === null || $kickoff->greaterThanOrEqualTo($cutoff)) {
                    return false;
                }

                return $kickoff->toDateString() < $cutoff->toDateString();
            })->keyBy('id');
    }

    /** Keep league comparisons on a complete week while the next week is still arriving. */
    private function baseline(Collection $games, int $season, string $window): array
    {
        $baseline = null;
        if ($window !== 'season_to_date' || $games->isEmpty()) {
            return [$games, $baseline];
        }
        $latestWeek = (int) $games->max('week');
        $covered = DB::table('nflverse_pbp_plays')->whereIn('nfl_game_id', $games->keys())
            ->whereIn('play_type', ['pass', 'run'])->distinct()->pluck('nfl_game_id');
        $missing = $games->reject(fn (Game $prior): bool => $covered->contains($prior->id));
        if ($latestWeek < 2 || $missing->contains(fn (Game $prior): bool => (int) $prior->week !== $latestWeek)) {
            return [$games, $baseline];
        }
        $schedule = Game::query()->where('season', $season)
            ->whereIn('season_type', [(string) config('nfl.season.types.regular', 2), 'regular', 'REG'])
            ->whereBetween('week', [1, $latestWeek])->get(['id', 'week']);
        $weekIncomplete = $schedule->where('week', $latestWeek)->contains(fn (Game $prior): bool => ! $games->has($prior->id));
        if ($missing->isEmpty() && ! $weekIncomplete) {
            return [$games, $baseline];
        }
        $throughWeek = 0;
        for ($week = 1; $week < $latestWeek; $week++) {
            $scheduled = $schedule->where('week', $week);
            if ($scheduled->isEmpty() || $scheduled->contains(fn (Game $prior): bool => ! $games->has($prior->id) || ! $covered->contains($prior->id))) {
                break;
            }
            $throughWeek = $week;
        }
        if ($throughWeek === 0) {
            return [$games, $baseline];
        }
        $selected = $games->filter(fn (Game $prior): bool => (int) $prior->week <= $throughWeek);
        $throughDate = $selected->map(fn (Game $prior): string => $this->dates->gameDateTimeUtc($prior->getRawOriginal('game_date'), $prior->game_time)->toDateString())->max();
        $baseline = [
            'mode' => 'last_complete_week',
            'through_week' => $throughWeek,
            'through_date' => $throughDate,
            'games' => $selected->count(),
            'label' => "{$season} season through Week {$throughWeek} ({$throughDate})",
        ];

        return [$selected, $baseline];
    }

    /** Team metrics use grouped drive and play queries; player metrics load separately. */
    private function metrics(Collection $games): array
    {
        $buckets = [];
        $targetCounts = [];
        $scheduled = [];
        $venueScheduled = [];
        foreach ($games as $game) {
            foreach ([[$game->home_team_id, $game->away_team_id, $game->home_score, $game->away_score], [$game->away_team_id, $game->home_team_id, $game->away_score, $game->home_score]] as [$offense, $defense, $scored, $allowed]) {
                if (! $game->neutral_site) {
                    $venue = (int) $offense === (int) $game->home_team_id ? 'home_pass_epa' : 'road_pass_epa';
                    $venueScheduled[$venue][(int) $offense] = ($venueScheduled[$venue][(int) $offense] ?? 0) + 1;
                }
                $scheduled[(int) $offense] = ($scheduled[(int) $offense] ?? 0) + 1;
                if ($scored !== null && $allowed !== null && $scored >= 0 && $allowed >= 0) {
                    $this->add($buckets, 'points_per_game', 'offense', (int) $offense, (int) $game->id, (float) $scored, 1, 1);
                    $this->add($buckets, 'points_per_game', 'defense', (int) $offense, (int) $game->id, (float) $allowed, 1, 1);
                }
            }
        }
        if ($games->isNotEmpty()) {
            $penalties = DB::table('nflverse_pbp_plays')->whereIn('nfl_game_id', $games->keys())
                ->where(fn ($q) => $q->whereIn('play_type', ['pass', 'run'])->orWhere(fn ($q) => $q->where('play_type', 'no_play')->where(fn ($q) => $q->where('is_penalty', true)->orWhereNull('is_penalty'))))
                ->where(fn ($q) => $q->whereNull('description')->orWhereRaw('LOWER(description) NOT LIKE ?', ['%two-point conversion attempt%']))
                ->select(['nfl_game_id', 'possession_team_id', 'defense_team_id'])
                ->selectRaw("COUNT(*) AS candidates,
                    SUM(CASE WHEN is_penalty = 0 OR (is_penalty = 1 AND NULLIF(TRIM(penalty_type), '') IS NOT NULL AND penalty_team_id IN (possession_team_id, defense_team_id)) THEN 1 ELSE 0 END) AS classified,
                    SUM(CASE WHEN is_penalty = 1 AND LOWER(TRIM(penalty_type)) = 'false start' AND penalty_team_id = possession_team_id THEN 1 ELSE 0 END) AS false_starts")
                ->groupBy('nfl_game_id', 'possession_team_id', 'defense_team_id')->get();
            foreach ($penalties as $row) {
                $game = $games->get($row->nfl_game_id);
                $teams = [(int) $game->home_team_id, (int) $game->away_team_id];
                if ($row->possession_team_id === $row->defense_team_id || ! in_array((int) $row->possession_team_id, $teams, true) || ! in_array((int) $row->defense_team_id, $teams, true)) {
                    continue;
                }
                $this->add($buckets, 'false_start_rate', 'offense', (int) $row->possession_team_id, (int) $row->nfl_game_id,
                    (float) $row->false_starts, (int) $row->classified, (int) $row->candidates);
            }
            $drives = DB::table('nflverse_pbp_plays')->whereIn('nfl_game_id', $games->keys())
                ->select(['nfl_game_id', 'possession_team_id', 'defense_team_id', 'fixed_drive'])
                ->selectRaw("SUM(CASE WHEN play_type IN ('pass', 'run') THEN 1 ELSE 0 END) AS scrimmage_plays")
                ->selectRaw('COUNT(*) AS rows_count, COUNT(possession_score_before) AS before_count, COUNT(possession_score_after) AS after_count')
                ->selectRaw('MIN(possession_score_before) AS start_score, MAX(possession_score_after) AS end_score')
                ->selectRaw('MIN(fixed_drive_result) AS result_min, MAX(fixed_drive_result) AS result_max, COUNT(fixed_drive_result) AS result_count')
                ->groupBy('nfl_game_id', 'possession_team_id', 'defense_team_id', 'fixed_drive')->get();
            foreach ($drives as $drive) {
                if ((int) $drive->scrimmage_plays === 0) {
                    continue;
                }
                $game = $games->get($drive->nfl_game_id);
                $offense = (int) $drive->possession_team_id;
                $defense = (int) $drive->defense_team_id;
                if ($offense === $defense || ! in_array($offense, [(int) $game->home_team_id, (int) $game->away_team_id], true)
                    || ! in_array($defense, [(int) $game->home_team_id, (int) $game->away_team_id], true)) {
                    continue;
                }
                $points = (int) $drive->end_score - (int) $drive->start_score;
                $valid = $drive->fixed_drive !== null && $drive->before_count === $drive->rows_count
                    && $drive->after_count === $drive->rows_count && $drive->result_count === $drive->rows_count
                    && $drive->result_min === $drive->result_max
                    && in_array($drive->result_min, ['Touchdown', 'Punt', 'Field goal', 'Turnover', 'Turnover on downs', 'End of half', 'Missed field goal', 'Opp touchdown', 'Safety'], true)
                    && $points >= 0 && $points <= 8;
                foreach ([['offense', $offense], ['defense', $defense]] as [$side, $team]) {
                    $this->add($buckets, 'points_per_drive', $side, $team, (int) $drive->nfl_game_id, $valid ? $points : 0, $valid ? 1 : 0, 1);
                    $this->add($buckets, 'drive_success_rate', $side, $team, (int) $drive->nfl_game_id, $valid && in_array($drive->result_min, ['Touchdown', 'Field goal'], true) ? 1 : 0, $valid ? 1 : 0, 1);
                }
            }
            $positions = DB::table('nflverse_rosters')->whereIn('season', $games->pluck('season')->unique())
                ->whereNotNull('gsis_id')->groupBy('season', 'team_id', 'gsis_id')
                ->havingRaw('COUNT(position) = COUNT(*) AND COUNT(DISTINCT position) = 1')
                ->selectRaw('season AS roster_season, team_id AS roster_team, gsis_id AS roster_player, MIN(position) AS roster_position');
            $positions->selectRaw('CASE WHEN COUNT(height) = COUNT(*) AND COUNT(DISTINCT height) = 1 AND MIN(height) BETWEEN 60 AND 90 THEN MIN(height) ELSE NULL END AS roster_height,
                CASE WHEN COUNT(weight) = COUNT(*) AND COUNT(DISTINCT weight) = 1 AND MIN(weight) BETWEEN 120 AND 450 THEN MIN(weight) ELSE NULL END AS roster_weight');
            $query = DB::table('nflverse_pbp_plays')->whereIn('nfl_game_id', $games->keys())
                ->leftJoin('nfl_games as position_games', 'position_games.id', '=', 'nflverse_pbp_plays.nfl_game_id')
                ->leftJoinSub(clone $positions, 'run_roster', fn ($join) => $join->on('run_roster.roster_player', '=', 'nflverse_pbp_plays.rusher_player_id')->on('run_roster.roster_team', '=', 'nflverse_pbp_plays.possession_team_id')->on('run_roster.roster_season', '=', 'position_games.season'))
                ->leftJoinSub(clone $positions, 'catch_roster', fn ($join) => $join->on('catch_roster.roster_player', '=', 'nflverse_pbp_plays.receiver_player_id')->on('catch_roster.roster_team', '=', 'nflverse_pbp_plays.possession_team_id')->on('catch_roster.roster_season', '=', 'position_games.season'))
                ->whereIn('play_type', ['pass', 'run'])
                ->where(fn ($query) => $query->whereNull('description')->orWhereRaw('LOWER(description) NOT LIKE ?', ['%no play%']))
                ->where(fn ($query) => $query->whereNull('description')->orWhereRaw('LOWER(description) NOT LIKE ?', ['%two-point conversion attempt%']))
                ->select(['nfl_game_id', 'possession_team_id', 'defense_team_id', 'play_type', 'is_sack'])
                ->selectRaw('COUNT(*) AS candidate_plays, COUNT(epa) AS epa_plays, SUM(epa) AS epa_sum, SUM(CASE WHEN epa > 0 THEN 1 ELSE 0 END) AS successes, COUNT(yards_gained) AS yard_plays, SUM(yards_gained) AS yards_sum')
                ->selectRaw("SUM(CASE WHEN yards_gained >= CASE WHEN play_type = 'pass' OR is_sack = 1 THEN 20 ELSE 10 END THEN 1 ELSE 0 END) AS explosives")
                ->groupBy('nfl_game_id', 'possession_team_id', 'defense_team_id', 'play_type', 'is_sack');
            // Constant SQL definitions; no user-supplied expression enters this query.
            $contexts = [
                'early_epa' => ['down IN (1, 2)', 'down IS NULL OR down NOT BETWEEN 1 AND 4', 'epa'],
                'late_epa' => ['down IN (3, 4)', 'down IS NULL OR down NOT BETWEEN 1 AND 4', 'epa'],
                'first_down_epa' => ['down = 1', 'down IS NULL OR down NOT BETWEEN 1 AND 4', 'epa'],
                'third_down_epa' => ['down = 3', 'down IS NULL OR down NOT BETWEEN 1 AND 4', 'epa'],
                'red_zone_epa' => ['yardline_100 BETWEEN 0 AND 20', 'yardline_100 IS NULL OR yardline_100 NOT BETWEEN 0 AND 100', 'epa'],
                'short_yardage_success_rate' => ['yards_to_go BETWEEN 1 AND 2', 'yards_to_go IS NULL OR yards_to_go < 1', 'yards_gained'],
            ];
            foreach ($contexts as $key => [$condition, $unknown, $field]) {
                $value = $key === 'short_yardage_success_rate' ? 'CASE WHEN yards_gained >= yards_to_go THEN 1 ELSE 0 END' : 'epa';
                $query->selectRaw("SUM(CASE WHEN ({$condition}) OR ({$unknown}) THEN 1 ELSE 0 END) AS {$key}_candidates")
                    ->selectRaw("SUM(CASE WHEN ({$condition}) AND {$field} IS NOT NULL THEN 1 ELSE 0 END) AS {$key}_count")
                    ->selectRaw("SUM(CASE WHEN ({$condition}) AND {$field} IS NOT NULL THEN {$value} ELSE 0 END) AS {$key}_sum");
            }
            $splits = NflMatchupMetricDefinitions::splits();
            foreach ($splits as $key => [$condition, $unknown, $field, $value]) {
                $query->selectRaw("SUM(CASE WHEN ({$condition}) OR ({$unknown}) THEN 1 ELSE 0 END) AS split_{$key}_candidates")
                    ->selectRaw("SUM(CASE WHEN ({$condition}) AND {$field} IS NOT NULL THEN 1 ELSE 0 END) AS split_{$key}_count")
                    ->selectRaw("SUM(CASE WHEN ({$condition}) AND {$field} IS NOT NULL THEN {$value} ELSE 0 END) AS split_{$key}_sum");
            }
            $rows = $query->get();
            $targets = $query->cloneWithout(['columns', 'groups'])->select(['nfl_game_id', 'possession_team_id', 'defense_team_id', 'receiver_player_id'])
                ->selectRaw('COUNT(*) AS targets')->where('play_type', 'pass')->where('is_sack', false)
                ->where('catch_roster.roster_position', 'WR')->whereNotNull('receiver_player_id')
                ->groupBy('nfl_game_id', 'possession_team_id', 'defense_team_id', 'receiver_player_id')->get();
            foreach ($targets as $target) {
                $game = $games->get($target->nfl_game_id);
                $ids = [(int) $game->home_team_id, (int) $game->away_team_id];
                if ($target->possession_team_id === $target->defense_team_id || ! in_array((int) $target->possession_team_id, $ids, true) || ! in_array((int) $target->defense_team_id, $ids, true)) {
                    continue;
                }
                $targetCounts[$target->possession_team_id][$target->receiver_player_id] = ($targetCounts[$target->possession_team_id][$target->receiver_player_id] ?? 0) + (int) $target->targets;
            }

            foreach ($rows as $row) {
                $game = $games->get($row->nfl_game_id);
                $offense = (int) $row->possession_team_id;
                $defense = (int) $row->defense_team_id;
                if ($offense === $defense || ! in_array($offense, [(int) $game->home_team_id, (int) $game->away_team_id], true) || ! in_array($defense, [(int) $game->home_team_id, (int) $game->away_team_id], true)) {
                    continue;
                }
                $split = $row->play_type === 'pass' || $row->is_sack ? 'pass_epa' : 'rush_epa';
                $pass = $split === 'pass_epa';
                foreach ([['offense', $offense], ['defense', $defense]] as [$side, $team]) {
                    if ($pass && ! $game->neutral_site) {
                        $venue = $team === (int) $game->home_team_id ? 'home_pass_epa' : 'road_pass_epa';
                        $this->add($buckets, $venue, $side, $team, (int) $row->nfl_game_id, (float) $row->epa_sum, (int) $row->epa_plays, (int) $row->candidate_plays);
                    }
                    if ($row->play_type === 'pass' && $row->is_sack !== null && ! $row->is_sack) {
                        $this->add($buckets, 'pass_yards_per_attempt', $side, $team, (int) $row->nfl_game_id,
                            (float) $row->yards_sum, (int) $row->yard_plays, (int) $row->candidate_plays);
                    }
                    foreach ([['epa', $row->epa_sum, $row->epa_plays], [$split, $row->epa_sum, $row->epa_plays], ['success_rate', $row->successes, $row->epa_plays],
                        [$pass ? 'pass_success_rate' : 'rush_success_rate', $row->successes, $row->epa_plays],
                        ['explosive_rate', $row->explosives, $row->yard_plays],
                        [$pass ? 'pass_explosive_rate' : 'rush_explosive_rate', $row->explosives, $row->yard_plays],
                        ['yards_per_play', $row->yards_sum, $row->yard_plays]] as [$metric, $sum, $count]) {
                        $this->add($buckets, $metric, $side, $team, (int) $row->nfl_game_id, (float) $sum, (int) $count, (int) $row->candidate_plays);
                    }
                    $contextMetrics = ['early_epa' => 'early_epa', 'late_epa' => 'late_epa',
                        'first_down_epa' => $pass ? 'first_down_pass_epa' : 'first_down_rush_epa',
                        'short_yardage_success_rate' => 'short_yardage_success_rate'];
                    if ($pass) {
                        $contextMetrics += ['third_down_epa' => 'third_down_pass_epa', 'red_zone_epa' => 'red_zone_pass_epa'];
                    }
                    foreach ($splits as $key => $definition) {
                        $this->add($buckets, $key, $side, $team, (int) $row->nfl_game_id,
                            (float) $row->{'split_'.$key.'_sum'}, (int) $row->{'split_'.$key.'_count'}, (int) $row->{'split_'.$key.'_candidates'});
                    }
                    foreach ($contextMetrics as $key => $metric) {
                        $this->add($buckets, $metric, $side, $team, (int) $row->nfl_game_id,
                            (float) $row->{$key.'_sum'}, (int) $row->{$key.'_count'}, (int) $row->{$key.'_candidates'});
                    }
                }
            }
        }
        // Leave the game being adjusted out of the opponent baseline to avoid
        // subtracting the same performance from itself.
        foreach ($buckets['epa'] ?? [] as $side => $teams) {
            foreach ($teams as $team => $bucket) {
                foreach ($bucket['games'] as $gameId => $sample) {
                    $game = $games->get($gameId);
                    $opponent = (int) $game->home_team_id === $team ? (int) $game->away_team_id : (int) $game->home_team_id;
                    $otherSide = $side === 'offense' ? 'defense' : 'offense';
                    $opponentGames = $buckets['epa'][$otherSide][$opponent]['games'] ?? [];
                    unset($opponentGames[$gameId]);
                    $validOpponent = array_filter($opponentGames, fn ($row) => $row['count'] >= 30 && $row['count'] >= $row['candidate'] * .9);
                    $count = array_sum(array_column($validOpponent, 'count'));
                    $valid = count($validOpponent) >= 2 && count($validOpponent) === ($scheduled[$opponent] ?? 0) - 1;
                    $baseline = $count ? array_sum(array_column($validOpponent, 'sum')) / $count : 0;
                    $this->add($buckets, 'opponent_adjusted_epa', $side, $team, $gameId,
                        $valid ? $sample['sum'] - $baseline * $sample['count'] : 0, $valid ? $sample['count'] : 0, $sample['candidate']);
                }
            }
        }
        $metrics = [];
        foreach ($buckets as $metric => $sides) {
            foreach ($sides as $side => $teams) {
                foreach ($teams as $team => $bucket) {
                    $splitDefinition = NflMatchupMetricDefinitions::splits()[$metric] ?? null;
                    $minimumPerGame = $splitDefinition[4] ?? match ($metric) {
                        'points_per_game' => 1,
                        'points_per_drive', 'drive_success_rate' => 5,
                        'home_pass_epa', 'road_pass_epa', 'pass_epa', 'pass_success_rate', 'pass_explosive_rate', 'pass_yards_per_attempt', 'early_epa' => 15,
                        'rush_epa', 'rush_success_rate', 'rush_explosive_rate', 'first_down_pass_epa' => 8,
                        'late_epa' => 5,
                        'first_down_rush_epa' => 4,
                        'third_down_pass_epa', 'red_zone_pass_epa', 'short_yardage_success_rate' => 3,
                        default => 30
                    };
                    $pooledSituation = $splitDefinition !== null || in_array($metric, ['first_down_pass_epa', 'third_down_pass_epa', 'red_zone_pass_epa', 'short_yardage_success_rate'], true);
                    $validGames = array_filter($bucket['games'], function (array $sample, int $gameId) use ($metric, $minimumPerGame, $pooledSituation, $buckets, $side, $team): bool {
                        if ($metric === 'false_start_rate') {
                            $base = $buckets['epa'][$side][$team]['games'][$gameId] ?? null;

                            return $sample['count'] >= 30 && $sample['count'] >= $sample['candidate'] * .9
                                && $base !== null && $base['count'] >= 30 && $base['count'] >= $base['candidate'] * .9;
                        }
                        if (in_array($metric, ['points_per_drive', 'drive_success_rate'], true)) {
                            return $sample['count'] >= $minimumPerGame && $sample['count'] === $sample['candidate'];
                        }
                        if (! $pooledSituation) {
                            return $sample['count'] >= $minimumPerGame && $sample['count'] >= $sample['candidate'] * .9;
                        }

                        // Zero situational opportunities are valid only when the
                        // underlying game is complete; unknown values remain gaps.
                        $base = $buckets['epa'][$side][$team]['games'][$gameId] ?? null;

                        return $base !== null && $base['count'] >= 30
                            && $base['count'] >= $base['candidate'] * .9
                            && $sample['count'] >= $sample['candidate'] * .9;
                    }, ARRAY_FILTER_USE_BOTH);
                    $count = array_sum(array_column($validGames, 'count'));
                    $expectedGames = $venueScheduled[$metric][$team] ?? ($scheduled[$team] ?? 0);
                    $eligible = count($validGames) >= self::MIN_GAMES && count($validGames) === $expectedGames
                        && (! $pooledSituation || $count >= $minimumPerGame * self::MIN_GAMES);
                    $metrics[$metric][$side][$team] = [
                        'metric' => $metric,
                        'value' => $count > 0 ? array_sum(array_column($validGames, 'sum')) / $count : null,
                        'games' => count($validGames),
                        'game_ids' => array_keys($validGames),
                        'plays' => $metric === 'points_per_game' ? null : $count,
                        'scheduled_games' => $expectedGames,
                        'eligible' => $eligible,
                        'volume_policy' => $pooledSituation ? 'pooled_situational_opportunities' : 'per_game',
                        'minimum_plays' => $pooledSituation ? $minimumPerGame * self::MIN_GAMES : $minimumPerGame,
                        'rank' => null,
                    ];
                    if ($metric === 'false_start_rate' && $eligible) {
                        $metrics[$metric][$side][$team]['display_value'] = number_format($metrics[$metric][$side][$team]['value'] * 100, 2).' false starts per 100 classified offensive opportunities';
                    }
                    if (in_array($metric, ['wr_target_height', 'wr_target_weight'], true) && $eligible) {
                        $metrics[$metric][$side][$team]['display_value'] = 'Target-weighted WR '.($metric === 'wr_target_height' ? 'height ' : 'weight ').number_format($metrics[$metric][$side][$team]['value'], 2).($metric === 'wr_target_height' ? ' in' : ' lb');
                    }
                }
                $eligible = array_filter($metrics[$metric][$side] ?? [], fn (array $sample): bool => $sample['eligible']);
                foreach ($eligible as $team => $sample) {
                    $higherIsBetter = ($side === 'offense') !== NflMatchupMetricDefinitions::lowerOffenseIsBetter($metric);
                    $better = count(array_filter($eligible, fn (array $other): bool => $higherIsBetter ? $other['value'] - $sample['value'] > .0000001 : $sample['value'] - $other['value'] > .0000001));
                    $ties = count(array_filter($eligible, fn (array $other): bool => abs($other['value'] - $sample['value']) < .0000001));
                    $metrics[$metric][$side][$team]['rank'] = count($eligible) === self::LEAGUE_TEAMS ? $better + 1 : null;
                    $metrics[$metric][$side][$team]['rank_end'] = count($eligible) === self::LEAGUE_TEAMS ? $better + $ties : null;
                    $metrics[$metric][$side][$team]['league_teams'] = count($eligible);
                    $metrics[$metric][$side][$team]['league_average'] = array_sum(array_column($eligible, 'value')) / count($eligible);
                }
            }
        }

        foreach (['epa', 'pass_epa', 'rush_epa'] as $metric) {
            foreach (['offense', 'defense'] as $side) {
                foreach ($metrics[$metric][$side] ?? [] as $team => $sample) {
                    $gameValues = collect($buckets[$metric][$side][$team]['games'] ?? [])
                        ->sortBy(fn ($row, $id) => $games->get($id)->game_date->getTimestamp())
                        ->map(fn ($row) => $row['count'] ? $row['sum'] / $row['count'] : null);
                    $series = $gameValues->values()->all();
                    if ($metric === 'epa') {
                        $eligible = $sample['eligible'] && count($series) >= 3 && ! in_array(null, $series, true);
                        $deviation = null;
                        if ($eligible) {
                            $mean = array_sum($series) / count($series);
                            $deviation = sqrt(array_sum(array_map(fn ($value) => ($value - $mean) ** 2, $series)) / (count($series) - 1));
                        }
                        $metrics['epa_stddev'][$side][$team] = [...$sample, 'metric' => 'epa_stddev',
                            'value' => $deviation, 'eligible' => $eligible, 'rank' => null, 'rank_end' => null,
                            'minimum_games' => 3, 'game_epa_values' => $gameValues->all(), 'league_teams' => 0];
                    }
                    foreach ([3, 5] as $window) {
                        $count = $window === 3 ? 4 : 5;
                        $recent = array_slice($series, -$count);
                        $eligible = $sample['eligible'] && count($recent) === $count && ! in_array(null, $recent, true);
                        $trend = null;
                        if ($eligible) {
                            $changes = [];
                            for ($i = 1; $i < count($recent); $i++) {
                                $changes[] = $recent[$i] - $recent[$i - 1];
                            }
                            $trend = $window === 3
                                ? (min($changes) > 0 ? min($changes) : (max($changes) < 0 ? max($changes) : 0))
                                : array_sum(array_map(fn ($i) => ($i - 2) * $recent[$i], range(0, 4))) / 10;
                        }
                        $metrics[$metric.'_trend_'.$window][$side][$team] = [...$sample,
                            'metric' => $metric.'_trend_'.$window, 'value' => $trend, 'eligible' => $eligible,
                            'rank' => null, 'rank_end' => null, 'trend_games_required' => $count, 'trend_game_values' => $recent];
                    }
                }
            }
        }

        foreach (['offense', 'defense'] as $side) {
            $eligible = array_filter($metrics['epa_stddev'][$side] ?? [], fn ($sample) => $sample['eligible']);
            foreach ($eligible as $team => $sample) {
                $lower = count(array_filter($eligible, fn ($other) => $sample['value'] - $other['value'] > .0000001));
                $ties = count(array_filter($eligible, fn ($other) => abs($other['value'] - $sample['value']) < .0000001));
                $metrics['epa_stddev'][$side][$team]['rank'] = count($eligible) === self::LEAGUE_TEAMS ? $lower + 1 : null;
                $metrics['epa_stddev'][$side][$team]['rank_end'] = count($eligible) === self::LEAGUE_TEAMS ? $lower + $ties : null;
                $metrics['epa_stddev'][$side][$team]['league_teams'] = count($eligible);
                $metrics['epa_stddev'][$side][$team]['league_average'] = array_sum(array_column($eligible, 'value')) / count($eligible);
            }
        }

        foreach ($metrics['te_target_share']['offense'] ?? [] as $team => $sample) {
            $counts = $targetCounts[$team] ?? [];
            $maximum = $counts ? max($counts) : 0;
            $metrics['wr_target_concentration']['offense'][$team] = [...$sample, 'metric' => 'wr_target_concentration',
                'value' => $sample['eligible'] ? $maximum / $sample['plays'] : null, 'rank' => null, 'rank_end' => null,
                'league_average' => null, 'leading_receiver_ids' => array_keys(array_filter($counts, fn ($count) => $count === $maximum))];
        }
        $qualified = array_filter($metrics['wr_target_concentration']['offense'] ?? [], fn ($sample) => $sample['eligible']);
        foreach ($qualified as $team => $sample) {
            $rank = 1 + count(array_filter($qualified, fn ($other) => $other['value'] - $sample['value'] > .0000001));
            $ties = count(array_filter($qualified, fn ($other) => abs($other['value'] - $sample['value']) < .0000001));
            $metrics['wr_target_concentration']['offense'][$team]['league_average'] = array_sum(array_column($qualified, 'value')) / count($qualified);
            $metrics['wr_target_concentration']['offense'][$team]['league_teams'] = count($qualified);
            $metrics['wr_target_concentration']['offense'][$team]['rank'] = count($qualified) === 32 ? $rank : null;
            $metrics['wr_target_concentration']['offense'][$team]['rank_end'] = count($qualified) === 32 ? $rank + $ties - 1 : null;
        }

        return $metrics;
    }

    private function add(array &$buckets, string $metric, string $side, int $team, int $game, float $sum, int $count, int $candidate): void
    {
        $sample = &$buckets[$metric][$side][$team]['games'][$game];
        $sample ??= ['sum' => 0.0, 'count' => 0, 'candidate' => 0];
        $sample['sum'] += $sum;
        $sample['count'] += $count;
        $sample['candidate'] += $candidate;
    }

    private function evaluate(array $entry, array $rule, array $metrics, int $offenseId, int $defenseId, ?CarbonImmutable $cutoff, ?string $scopeReason, Game $target): array
    {
        $offense = $metrics[$rule['metric']]['offense'][$offenseId] ?? ['value' => null, 'rank' => null, 'games' => 0, 'plays' => null, 'eligible' => false];
        $defenseMetric = $rule['defense_metric'] ?? $rule['metric'];
        $defense = $metrics[$defenseMetric]['defense'][$defenseId] ?? ['value' => null, 'rank' => null, 'games' => 0, 'plays' => null, 'eligible' => false];
        $qbRule = ($rule['coverage'] ?? false) || in_array($rule['metric'], ['qb_pass_epa', 'qb_pressure_epa', 'qb_blitz_epa', 'qb_deep_epa', 'qb_play_action_epa', 'qb_rpo_epa', 'qb_pass_epa_trend_3', 'qb_scramble_rate', 'qb_checkdown_rate', 'qb_turnover_rate', 'qb_pressure_to_sack_rate', 'qb_release_time'], true);
        $defensePersonnel = $rule['defense_personnel'] ?? false;
        $personnel = $rule['personnel'] ?? false;
        $personnelOnly = $rule['personnel_only'] ?? false;
        $offenseOnly = $rule['offense_only'] ?? false;
        if ($personnelOnly || $offenseOnly) {
            $defense = ['value' => null, 'rank' => null, 'eligible' => true, 'games' => 0];
        }
        $league = ($qbRule || $personnel) ? ($defense['league_teams'] ?? 0) : min($offense['league_teams'] ?? 0, $defense['league_teams'] ?? 0);
        if ($defensePersonnel || $offenseOnly) {
            $league = $offense['league_teams'] ?? 0;
        }
        $venueApplies = ! isset($rule['venue']) || (! $target->neutral_site && (($offenseId === (int) $target->home_team_id) === ($rule['venue'] === 'home')));
        $reason = match (true) {
            $scopeReason !== null => $scopeReason,
            $cutoff === null => 'Kickoff cutoff is unavailable.',
            ($rule['win_rates'] ?? false) && ! $offense['eligible'] => 'A complete 32-team ESPN win-rate snapshot for this season and baseline week is required, published and observed before the cutoff within 14 days, with two prior games per team.',
            in_array($defenseMetric, ['cb_weight', 'secondary_height'], true) && ! $defense['eligible'] => 'A fresh game-linked defensive chart with enough uniquely identified starters and complete roster dimensions is required.',
            $defensePersonnel && isset($defense['identity_reason']) => $defense['identity_reason'],
            ($qbRule || $personnel) && isset($offense['identity_reason']) => $offense['identity_reason'],
            (($rule['coverage'] ?? false) || ($rule['participation'] ?? false)) && (! $offense['eligible'] || ! $defense['eligible']) => 'Complete charted coverage samples are required. Free FTN participation is published after the postseason; previous-season evidence must be selected explicitly.',
            $qbRule && $rule['metric'] !== 'qb_pass_epa_trend_3' && ($offense['league_players'] ?? 0) < 24 => 'Quarterback rankings require at least 24 qualified passers.',
            (! $offense['eligible'] || ! $defense['eligible']) && (str_contains($rule['metric'], '_trend_') || str_contains($defenseMetric, '_trend_')) => 'Trend comparison requires the full four- or five-game sequence specified in the definition, with complete inputs.',
            $rule['metric'] === 'epa_stddev' && (! $offense['eligible'] || ! $defense['eligible']) => 'Variability requires at least three complete games, with volume and EPA coverage checks for every preceding game.',
            ! $offense['eligible'] || ! $defense['eligible'] => 'At least two qualifying games per team are required, with volume and non-null coverage checks for every preceding game; missing values are not treated as zero.',
            ! $personnelOnly && $league !== self::LEAGUE_TEAMS => 'League rankings require qualified data for all 32 teams.',
            default => null,
        };
        $matched = $venueApplies && $reason === null && ($personnel ? ($offense['value'] >= $rule['offense_threshold'] && (! isset($rule['offense_maximum']) || $offense['value'] <= $rule['offense_maximum'])) : $this->matches($offense, $rule['offense'], $rule['size'], true)) && ($defensePersonnel ? $defense['value'] >= $rule['defense_threshold'] : $this->matches($defense, $rule['defense'], $rule['size'], false));

        $conditionEvidence = null;
        if (isset($rule['condition'])) {
            $condition = $rule['condition'];
            $teamId = $condition['side'] === 'offense' ? $offenseId : $defenseId;
            $sample = $metrics[$condition['metric']][$condition['side']][$teamId] ?? [];
            $conditionReason = match (true) {
                isset($sample['identity_reason']) => $sample['identity_reason'],
                ! ($sample['eligible'] ?? false) => 'The additional '.$condition['metric'].' condition requires a complete, identified sample.',
                ($condition['quarterback'] ?? false) && ($sample['league_players'] ?? 0) < 24 => 'The additional quarterback condition requires at least 24 qualified passers.',
                ! ($condition['personnel'] ?? false) && ! ($condition['quarterback'] ?? false) && ($sample['league_teams'] ?? 0) !== self::LEAGUE_TEAMS => 'The additional defensive condition requires all 32 qualified teams.',
                default => null,
            };
            $conditionMatched = $conditionReason === null && (($condition['personnel'] ?? false)
                ? $sample['value'] >= 1 : $this->matches($sample, $condition['band'], 10, $condition['side'] === 'offense'));
            $reason ??= $conditionReason;
            $matched = $matched && $conditionMatched;
            $conditionEvidence = [...$condition, 'team_id' => $teamId, 'sample' => $sample, 'matched' => $conditionMatched, 'reason' => $conditionReason];
            if ($conditionReason === null) {
                $description = $sample['display_value'] ?? match ($condition['metric']) {
                    'backup_qb' => ($sample['player_name'] ?? 'Selected QB').': '.($sample['value'] >= 1 ? 'backup' : 'starter'),
                    'qb_scramble_rate' => ($sample['player_name'] ?? 'Selected QB').': '.number_format($sample['value'] * 100, 1).'% scramble rate, rank '.$sample['rank'].' of '.$sample['league_players'],
                    'qb_pass_epa' => ($sample['player_name'] ?? 'Selected QB').': '.number_format($sample['value'], 3).' passing EPA, rank '.$sample['rank'].' of '.$sample['league_players'],
                    default => 'Defense: '.number_format($sample['value'], 3).' passing EPA allowed, rank '.$sample['rank'].' of 32',
                };
                $offense['display_value'] = ($offense['display_value'] ?? 'Pass-block win rate unavailable').' · '.$description;
            }
        }

        $profile = null;
        if (isset($rule['profile_threshold'])) {
            $threshold = $rule['profile_threshold'];
            $value = $reason === null ? ($offense['value'] - $offense['league_average']) + ($defense['value'] - $defense['league_average']) : null;
            $matched = $venueApplies && $value !== null && ($threshold > 0 ? $value >= $threshold - 1e-9 : $value <= $threshold + 1e-9);
            $profile = ['value' => $value, 'unit' => 'EPA/play', 'threshold' => $threshold,
                'operator' => $threshold > 0 ? '>=' : '<=', 'offense_league_mean' => $offense['league_average'] ?? null,
                'defense_league_mean' => $defense['league_average'] ?? null, 'predictive_weight' => 0];
            if ($value !== null) {
                $offense['display_value'] = 'EPA profile differential '.sprintf('%+.3f', $value).' EPA/play';
            }
        }

        return [
            'id' => $entry['id'], 'label' => $entry['label'], 'category' => $entry['category'],
            'status' => $reason !== null ? 'insufficient_data' : ($matched ? 'matched' : 'not_matched'),
            'offense_team_id' => $offenseId, 'defense_team_id' => $defenseId,
            'reason' => $reason,
            'evidence' => [
                ...($conditionEvidence !== null ? ['additional_condition' => $conditionEvidence] : []),
                ...($profile !== null ? ['epa_profile_differential' => $profile] : []),
                'defense_personnel' => $defensePersonnel,
                'personnel_only' => $personnelOnly,
                'offense_only' => $offenseOnly,
                'metric' => $rule['metric'],
                'offense_metric' => $rule['metric'],
                'defense_metric' => $defenseMetric,
                'definition' => $entry['definition'],
                'source' => match ($rule['metric']) {
                    'wr_target_height', 'wr_target_weight' => 'Season/team roster dimensions weighted by WR targets; current game-linked defensive chart with roster dimensions and fresh 32-team chart cohort',
                    'false_start_rate' => 'nflverse penalty flag, penalty type and penalized team; includes penalty no-play rows',
                    'qb_turnover_rate' => 'Selected quarterback GSIS identity, nflverse interception/lost-fumble flags and individual fumbler identities',
                    'qb_checkdown_rate' => 'FTN read_thrown joined to nflverse pass attempts by game/play identity and selected quarterback GSIS ID',
                    'qb_scramble_rate' => 'nflverse_pbp_plays: selected quarterback passing plays, sacks and identified scrambles',
                    'qb_recent_change', 'backup_qb' => 'Game-selected quarterback, target-season roster mapping and timestamped game-linked depth chart',
                    'rookie_qb' => 'Game-selected quarterback identity and target-season nflverse_rosters years_exp',
                    'points_per_game' => 'nfl_games: final team scores',
                    'pass_yards_per_attempt' => 'nflverse_pbp_plays: pass attempts (sacks excluded)',
                    'rush_ybc', 'rush_yac' => 'PFR weekly rushing contact yards and carries via nflverse, verified against game/team play-by-play',
                    'qb_pressure_epa', 'charted_pressure_rate', 'four_rusher_pressure_rate', 'wr_zone_target_epa', 'personnel_11_rate', 'personnel_12_rate', 'personnel_21_rate' => 'FTN Data via nflverse participation (CC-BY-SA 4.0), verified game/play identities',
                    'pass_block_win_rate', 'run_block_win_rate' => 'ESPN Analytics published team win rates and ranks'.($defenseMetric === 'rush_epa' ? '; run-defense comparison uses nflverse rushing EPA' : ''),
                    'qb_release_time' => 'NFL Next Gen Stats via nflverse, weekly identified passing attempts',
                    'yac_per_catch', 'wr_yac_per_catch' => 'nflverse completed-pass YAC and PFR team missed tackles',
                    'pressure_rate', 'pressure_to_sack_rate', 'qb_pressure_to_sack_rate' => 'PFR weekly advanced passing via nflverse, roster-mapped identities and play-by-play dropbacks',
                    'qb_man_epa', 'qb_zone_epa', 'qb_cover_1_epa', 'qb_cover_2_epa', 'qb_cover_3_epa', 'qb_cover_4_epa' => 'FTN Data via nflverse participation (CC-BY-SA 4.0), joined to selected-QB play identities',
                    'qb_pass_epa', 'qb_pressure_epa', 'qb_blitz_epa', 'qb_deep_epa', 'qb_play_action_epa', 'qb_rpo_epa', 'qb_pass_epa_trend_3' => 'nflverse_pbp_plays: selected quarterback passing plays and sacks',
                    'ol_out', 'ol_changed', 'ol_changed_two', 'ol_same_four', 'rb1_out', 'wr1_out', 'te1_out', 'multiple_wr_out', 'backup_center' => 'Game-linked depth charts, historical pregame charts and timestamped injury snapshots',
                    'points_per_drive' => 'nflverse_pbp_plays: completed drives and possession-team scores',
                    default => 'nflverse_pbp_plays: pass/run plays (sacks included)',
                }.($defensePersonnel ? '; game-linked defensive depth chart and timestamped injury evidence' : '').($conditionEvidence !== null ? '; additional condition uses game-selected quarterback identity, chart status or the specified nflverse EPA/scramble sample' : ''),
                'offense' => $offense, 'defense' => $defense, 'league_teams' => $league,
                'cutoff_at' => $cutoff?->toIso8601String(),
            ],
        ];
    }

    private function matches(array $sample, string $band, ?int $size, bool $offense): bool
    {
        return match ($band) {
            'any' => true,
            'positive' => $sample['value'] > 0,
            'negative' => $sample['value'] < 0,
            'improving' => $offense ? $sample['value'] > 0 : $sample['value'] < 0,
            'declining' => $offense ? $sample['value'] < 0 : $sample['value'] > 0,
            'top' => $sample['rank_end'] <= $size,
            'bottom' => $sample['rank'] > ($sample['league_players'] ?? self::LEAGUE_TEAMS) - $size,
            'above_average' => $offense ? $sample['value'] > $sample['league_average'] : $sample['value'] < $sample['league_average'],
            'below_average' => $offense ? $sample['value'] < $sample['league_average'] : $sample['value'] > $sample['league_average'],
        };
    }
}
