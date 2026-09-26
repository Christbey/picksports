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
    private const MIN_GAMES = 3;

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
        $entries = collect($this->catalog->entries())->keyBy('id');
        $signals = [];
        foreach ($this->catalog->rules() as $id => $rule) {
            foreach ([[(int) $game->home_team_id, (int) $game->away_team_id], [(int) $game->away_team_id, (int) $game->home_team_id]] as [$offenseId, $defenseId]) {
                $signals[] = $this->evaluate($entries[$id], $rule, $metrics, $offenseId, $defenseId, $cutoff, $scopeReason);
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
                'Rankings require all 32 teams with at least three qualifying games. Ties crossing a top/bottom boundary do not qualify.',
                'Each prior game must meet volume floors: 30 overall; 15 passing or early-down; 8 rushing or first-down passing; 5 late-down; 4 first-down rushing; 3 third-down passing, red-zone passing or short-yardage plays. Require 90% metric/context coverage. These checks cannot independently prove that a provider import contains every play.',
                'EPA and success use nflverse pass/run plays including sacks, excluding no-play and special-teams rows. Success means EPA greater than zero; rushing includes scrambles classified as runs.',
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
            ->get(['id', 'home_team_id', 'away_team_id', 'game_date', 'game_time', 'home_score', 'away_score'])
            ->filter(function (Game $prior) use ($cutoff): bool {
                $kickoff = $this->dates->gameDateTimeUtc($prior->getRawOriginal('game_date'), $prior->game_time);
                if ($kickoff === null || $kickoff->greaterThanOrEqualTo($cutoff)) {
                    return false;
                }

                return $kickoff->toDateString() < $cutoff->toDateString();
            })->keyBy('id');
    }

    /** Two queries total per build: qualifying games, then grouped provider plays. */
    private function metrics(Collection $games): array
    {
        $buckets = [];
        $scheduled = [];
        foreach ($games as $game) {
            foreach ([[$game->home_team_id, $game->away_team_id, $game->home_score, $game->away_score], [$game->away_team_id, $game->home_team_id, $game->away_score, $game->home_score]] as [$offense, $defense, $scored, $allowed]) {
                $scheduled[(int) $offense] = ($scheduled[(int) $offense] ?? 0) + 1;
                if ($scored !== null && $allowed !== null && $scored >= 0 && $allowed >= 0) {
                    $this->add($buckets, 'points_per_game', 'offense', (int) $offense, (int) $game->id, (float) $scored, 1, 1);
                    $this->add($buckets, 'points_per_game', 'defense', (int) $offense, (int) $game->id, (float) $allowed, 1, 1);
                }
            }
        }
        if ($games->isNotEmpty()) {
            $query = DB::table('nflverse_pbp_plays')->whereIn('nfl_game_id', $games->keys())
                ->whereIn('play_type', ['pass', 'run'])
                ->where(fn ($query) => $query->whereNull('description')->orWhereRaw('LOWER(description) NOT LIKE ?', ['%no play%']))
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
                    foreach ($contextMetrics as $key => $metric) {
                        $this->add($buckets, $metric, $side, $team, (int) $row->nfl_game_id,
                            (float) $row->{$key.'_sum'}, (int) $row->{$key.'_count'}, (int) $row->{$key.'_candidates'});
                    }
                }
            }
        }
        $metrics = [];
        foreach ($buckets as $metric => $sides) {
            foreach ($sides as $side => $teams) {
                foreach ($teams as $team => $bucket) {
                    $minimumPerGame = match ($metric) {
                        'points_per_game' => 1,
                        'pass_epa', 'pass_success_rate', 'pass_explosive_rate', 'early_epa' => 15,
                        'rush_epa', 'rush_success_rate', 'rush_explosive_rate', 'first_down_pass_epa' => 8,
                        'late_epa' => 5,
                        'first_down_rush_epa' => 4,
                        'third_down_pass_epa', 'red_zone_pass_epa', 'short_yardage_success_rate' => 3,
                        default => 30
                    };
                    $validGames = array_filter($bucket['games'], fn (array $sample): bool => $sample['count'] >= $minimumPerGame && $sample['count'] >= $sample['candidate'] * .9);
                    $count = array_sum(array_column($validGames, 'count'));
                    $eligible = count($validGames) >= self::MIN_GAMES && count($validGames) === ($scheduled[$team] ?? 0);
                    $metrics[$metric][$side][$team] = [
                        'value' => $count > 0 ? array_sum(array_column($validGames, 'sum')) / $count : null,
                        'games' => count($validGames),
                        'game_ids' => array_keys($validGames),
                        'plays' => $metric === 'points_per_game' ? null : $count,
                        'scheduled_games' => $scheduled[$team] ?? 0,
                        'eligible' => $eligible,
                        'rank' => null,
                    ];
                }
                $eligible = array_filter($metrics[$metric][$side] ?? [], fn (array $sample): bool => $sample['eligible']);
                foreach ($eligible as $team => $sample) {
                    $better = count(array_filter($eligible, fn (array $other): bool => $side === 'offense' ? $other['value'] - $sample['value'] > .0000001 : $sample['value'] - $other['value'] > .0000001));
                    $ties = count(array_filter($eligible, fn (array $other): bool => abs($other['value'] - $sample['value']) < .0000001));
                    $metrics[$metric][$side][$team]['rank'] = count($eligible) === self::LEAGUE_TEAMS ? $better + 1 : null;
                    $metrics[$metric][$side][$team]['rank_end'] = count($eligible) === self::LEAGUE_TEAMS ? $better + $ties : null;
                    $metrics[$metric][$side][$team]['league_teams'] = count($eligible);
                    $metrics[$metric][$side][$team]['league_average'] = array_sum(array_column($eligible, 'value')) / count($eligible);
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

    private function evaluate(array $entry, array $rule, array $metrics, int $offenseId, int $defenseId, ?CarbonImmutable $cutoff, ?string $scopeReason): array
    {
        $offense = $metrics[$rule['metric']]['offense'][$offenseId] ?? ['value' => null, 'rank' => null, 'games' => 0, 'plays' => null, 'eligible' => false];
        $defense = $metrics[$rule['metric']]['defense'][$defenseId] ?? ['value' => null, 'rank' => null, 'games' => 0, 'plays' => null, 'eligible' => false];
        $league = min($offense['league_teams'] ?? 0, $defense['league_teams'] ?? 0);
        $reason = match (true) {
            $scopeReason !== null => $scopeReason,
            $cutoff === null => 'Kickoff cutoff is unavailable.',
            ! $offense['eligible'] || ! $defense['eligible'] => 'At least three qualifying games per team are required, with volume and non-null coverage checks for every preceding game; missing values are not treated as zero.',
            $league !== self::LEAGUE_TEAMS => 'League rankings require qualified data for all 32 teams.',
            default => null,
        };
        $matched = $reason === null && $this->matches($offense, $rule['offense'], $rule['size'], true) && $this->matches($defense, $rule['defense'], $rule['size'], false);

        return [
            'id' => $entry['id'], 'label' => $entry['label'], 'category' => $entry['category'],
            'status' => $reason !== null ? 'insufficient_data' : ($matched ? 'matched' : 'not_matched'),
            'offense_team_id' => $offenseId, 'defense_team_id' => $defenseId,
            'reason' => $reason,
            'evidence' => [
                'metric' => $rule['metric'],
                'definition' => $this->catalog->definition($rule['metric']),
                'source' => $rule['metric'] === 'points_per_game' ? 'nfl_games: final team scores' : 'nflverse_pbp_plays: pass/run plays (sacks included)',
                'offense' => $offense, 'defense' => $defense, 'league_teams' => $league,
                'cutoff_at' => $cutoff?->toIso8601String(),
            ],
        ];
    }

    private function matches(array $sample, string $band, ?int $size, bool $offense): bool
    {
        return match ($band) {
            'top' => $sample['rank_end'] <= $size,
            'bottom' => $sample['rank'] > self::LEAGUE_TEAMS - $size,
            'above_average' => $offense ? $sample['value'] > $sample['league_average'] : $sample['value'] < $sample['league_average'],
            'below_average' => $offense ? $sample['value'] < $sample['league_average'] : $sample['value'] > $sample['league_average'],
        };
    }
}
