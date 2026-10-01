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
        $metrics = $this->metrics($games);
        $quarterbacks = app(NflMatchupQuarterbacks::class)->metrics($game, $games, $cutoff);
        $metrics['qb_pass_epa']['offense'] = $quarterbacks;
        foreach ($quarterbacks as $teamId => $sample) {
            $metrics['qb_blitz_epa']['offense'][$teamId] = $sample['blitz_sample'] ?? [];
        }
        foreach (app(NflMatchupPersonnel::class)->forGame($game, $cutoff) as $teamId => $samples) {
            foreach ($samples as $metric => $sample) {
                $metrics[$metric]['offense'][$teamId] = $sample;
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
            ->get(['id', 'home_team_id', 'away_team_id', 'game_date', 'game_time', 'home_score', 'away_score', 'neutral_site'])
            ->filter(function (Game $prior) use ($cutoff): bool {
                $kickoff = $this->dates->gameDateTimeUtc($prior->getRawOriginal('game_date'), $prior->game_time);
                if ($kickoff === null || $kickoff->greaterThanOrEqualTo($cutoff)) {
                    return false;
                }

                return $kickoff->toDateString() < $cutoff->toDateString();
            })->keyBy('id');
    }

    /** Team metrics use grouped drive and play queries; player metrics load separately. */
    private function metrics(Collection $games): array
    {
        $buckets = [];
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
            $query = DB::table('nflverse_pbp_plays')->whereIn('nfl_game_id', $games->keys())
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
                    $series = collect($buckets[$metric][$side][$team]['games'] ?? [])
                        ->sortBy(fn ($row, $id) => $games->get($id)->game_date->getTimestamp())
                        ->map(fn ($row) => $row['count'] ? $row['sum'] / $row['count'] : null)->values()->all();
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
        $qbRule = in_array($rule['metric'], ['qb_pass_epa', 'qb_blitz_epa'], true);
        $personnel = $rule['personnel'] ?? false;
        $personnelOnly = $rule['personnel_only'] ?? false;
        if ($personnelOnly) {
            $defense = ['value' => null, 'rank' => null, 'eligible' => true, 'games' => 0];
        }
        $league = ($qbRule || $personnel) ? ($defense['league_teams'] ?? 0) : min($offense['league_teams'] ?? 0, $defense['league_teams'] ?? 0);
        $venueApplies = ! isset($rule['venue']) || (! $target->neutral_site && (($offenseId === (int) $target->home_team_id) === ($rule['venue'] === 'home')));
        $reason = match (true) {
            $scopeReason !== null => $scopeReason,
            $cutoff === null => 'Kickoff cutoff is unavailable.',
            ($qbRule || $personnel) && isset($offense['identity_reason']) => $offense['identity_reason'],
            $qbRule && ($offense['league_players'] ?? 0) < 24 => 'Quarterback rankings require at least 24 qualified passers.',
            (! $offense['eligible'] || ! $defense['eligible']) && (str_contains($rule['metric'], '_trend_') || str_contains($defenseMetric, '_trend_')) => 'Trend comparison requires the full four- or five-game sequence specified in the definition, with complete inputs.',
            ! $offense['eligible'] || ! $defense['eligible'] => 'At least two qualifying games per team are required, with volume and non-null coverage checks for every preceding game; missing values are not treated as zero.',
            ! $personnelOnly && $league !== self::LEAGUE_TEAMS => 'League rankings require qualified data for all 32 teams.',
            default => null,
        };
        $matched = $venueApplies && $reason === null && ($personnel ? $offense['value'] >= $rule['offense_threshold'] : $this->matches($offense, $rule['offense'], $rule['size'], true)) && $this->matches($defense, $rule['defense'], $rule['size'], false);

        return [
            'id' => $entry['id'], 'label' => $entry['label'], 'category' => $entry['category'],
            'status' => $reason !== null ? 'insufficient_data' : ($matched ? 'matched' : 'not_matched'),
            'offense_team_id' => $offenseId, 'defense_team_id' => $defenseId,
            'reason' => $reason,
            'evidence' => [
                'personnel_only' => $personnelOnly,
                'metric' => $rule['metric'],
                'offense_metric' => $rule['metric'],
                'defense_metric' => $defenseMetric,
                'definition' => $entry['definition'],
                'source' => match ($rule['metric']) {
                    'points_per_game' => 'nfl_games: final team scores',
                    'pass_yards_per_attempt' => 'nflverse_pbp_plays: pass attempts (sacks excluded)',
                    'qb_pass_epa', 'qb_blitz_epa' => 'nflverse_pbp_plays: selected quarterback passing plays and sacks',
                    'ol_changed', 'ol_changed_two', 'ol_same_four', 'rb1_out', 'backup_center' => 'Game-linked depth charts, historical pregame charts and timestamped injury snapshots',
                    'points_per_drive' => 'nflverse_pbp_plays: completed drives and possession-team scores',
                    default => 'nflverse_pbp_plays: pass/run plays (sacks included)',
                },
                'offense' => $offense, 'defense' => $defense, 'league_teams' => $league,
                'cutoff_at' => $cutoff?->toIso8601String(),
            ],
        ];
    }

    private function matches(array $sample, string $band, ?int $size, bool $offense): bool
    {
        return match ($band) {
            'any' => true,
            'improving' => $offense ? $sample['value'] > 0 : $sample['value'] < 0,
            'declining' => $offense ? $sample['value'] < 0 : $sample['value'] > 0,
            'top' => $sample['rank_end'] <= $size,
            'bottom' => $sample['rank'] > ($sample['league_players'] ?? self::LEAGUE_TEAMS) - $size,
            'above_average' => $offense ? $sample['value'] > $sample['league_average'] : $sample['value'] < $sample['league_average'],
            'below_average' => $offense ? $sample['value'] < $sample['league_average'] : $sample['value'] > $sample['league_average'],
        };
    }
}
