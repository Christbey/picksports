<?php

namespace App\Services\NFL;

use App\Models\NFL\Game;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Support\Collection;

/** One prediction-scoped history read, shared by the four team profile calculations. */
class NflTeamHistory
{
    /** @var array<int, Collection<int, Game>> */
    private array $seasons = [];

    private array $rows = [];

    private array $elos = [];

    /** @param Closure(int, mixed): float $eloAtDate */
    public function __construct(
        private readonly Game $target,
        private readonly Closure $eloAtDate,
        private readonly array $quarterbacks = [],
    ) {}

    public function rolling(int $teamId): array
    {
        $rows = $this->rows($teamId, (int) $this->target->season, true);
        [$rows] = $this->offenseSample($teamId, $rows, 0);
        $stats = array_values(array_filter($rows, fn ($r) => $r['off'] !== null));
        $recent = max(1, (int) config('nfl.predictions.rolling_efficiency.recent_games', 5));

        return ['games' => count($rows),
            'avg_margin' => $this->mean($rows, fn ($r) => $r['for'] - $r['against']),
            'recent_margin' => $this->mean(array_slice($rows, -$recent), fn ($r) => $r['for'] - $r['against']),
            'yard_diff' => $this->mean($stats, fn ($r) => $r['off']['yards'] - $r['def']['yards']),
            'turnover_diff' => $this->mean($stats, fn ($r) => $r['def']['turnovers'] - $r['off']['turnovers']),
            'points_for' => $this->mean($rows, fn ($r) => $r['for']),
            'points_against' => $this->mean($rows, fn ($r) => $r['against']),
        ];
    }

    public function opponentAdjusted(int $teamId): array
    {
        $rows = $this->rows($teamId, (int) $this->target->season, true);
        [$rows] = $this->offenseSample($teamId, $rows, 0);
        $stats = array_values(array_filter($rows, fn ($r) => $r['off'] !== null));
        $weight = (float) config('nfl.predictions.opponent_adjusted_efficiency.opponent_elo_weight', .015);
        $default = (float) config('nfl.elo.default_rating', 1500);
        $elo = function (array $r): float {
            $key = $r['opponent'].'|'.$r['date'];

            return $this->elos[$key] ??= ($this->eloAtDate)($r['opponent'], $r['date']);
        };

        return ['games' => count($rows),
            'opponent_adjusted_margin' => $this->mean($rows, fn ($r) => $r['for'] - $r['against'] + ($elo($r) - $default) * $weight),
            'yard_diff' => $this->mean($stats, fn ($r) => ($r['off']['yards'] - $r['def']['yards']) / 100),
            'red_zone_rate_diff' => $this->mean($stats, fn ($r) => $r['off']['red_zone_rate'] - $r['def']['red_zone_rate']),
            'third_down_rate_diff' => $this->mean($stats, fn ($r) => $r['off']['third_down_rate'] - $r['def']['third_down_rate']),
            'avg_opponent_elo' => $this->mean($rows, $elo, 1),
        ];
    }

    public function totals(int $teamId): array
    {
        // Preserve the existing totals scope (all season types), distinct from regular-season profiles.
        $season = (int) $this->target->season;
        $rows = $this->rows($teamId, $season, false);
        $recent = max(1, (int) config('nfl.predictions.total_environment.recent_games', 8));
        if (count($rows) < (int) config('nfl.predictions.total_environment.min_games', 2) && $season > 1900) {
            $rows = [...array_slice($this->rows($teamId, $season - 1, false), -$recent), ...$rows];
        }
        $sample = array_slice($rows, -$recent);
        $stats = array_values(array_filter($sample, fn ($r) => $r['off'] !== null));
        [$offense, $qbContext] = $this->offenseSample($teamId, $sample, (int) config('nfl.predictions.total_environment.min_games', 2), $recent);
        $offStats = array_values(array_filter($offense, fn ($r) => $r['off'] !== null));
        $result = ['games' => count($rows),
            'points_for' => $this->mean($offense, fn ($r) => $r['for']),
            'points_against' => $this->mean($sample, fn ($r) => $r['against']),
        ];
        if ($qbContext !== []) {
            $result['games'] = min(count($offStats), count($stats));
            $result['qb_context'] = $qbContext;
        }
        foreach ([
            'offensive_plays' => ['off', 'plays'], 'defensive_plays' => ['def', 'plays'],
            'yards_per_play' => ['off', 'yards_per_play'], 'yards_allowed_per_play' => ['def', 'yards_per_play'],
            'pass_rate' => ['off', 'pass_rate'], 'red_zone_rate' => ['off', 'red_zone_rate'],
            'red_zone_allowed_rate' => ['def', 'red_zone_rate'], 'third_down_rate' => ['off', 'third_down_rate'],
            'third_down_allowed_rate' => ['def', 'third_down_rate'], 'turnover_rate' => ['off', 'turnover_rate'],
            'takeaway_rate' => ['def', 'turnover_rate'], 'penalty_yards' => ['off', 'penalty_yards'],
        ] as $key => [$side, $field]) {
            $result[$key] = $this->mean($side === 'off' ? $offStats : $stats, fn ($r) => $r[$side][$field], in_array($key, ['turnover_rate', 'takeaway_rate'], true) ? 4 : 3);
        }

        return $result;
    }

    public function line(int $teamId): array
    {
        $season = (int) $this->target->season;
        $rows = $this->rows($teamId, $season, true);
        if (count($rows) < (int) config('nfl.predictions.line_matchup.min_games', 2)) {
            $rows = [...$this->rows($teamId, $season - 1, true), ...$rows];
        }
        $rows = array_values(array_filter($rows, fn ($r) => $r['off'] !== null));
        [$offense, $qbContext] = $this->offenseSample($teamId, $rows, (int) config('nfl.predictions.line_matchup.min_games', 2));
        $offense = array_values(array_filter($offense, fn ($r) => $r['off'] !== null));
        $totals = [];
        foreach (['off', 'def'] as $side) {
            foreach (['pass', 'rush', 'rush_yards', 'sacks'] as $field) {
                $totals[$side][$field] = array_sum(array_map(fn ($r) => $r[$side][$field], $side === 'off' ? $offense : $rows));
            }
        }

        return ['games' => $qbContext === [] ? count($rows) : min(count($offense), count($rows)),
            'off_sack_allowed_rate' => round($this->rate($totals['off']['sacks'], $totals['off']['pass']), 4),
            'off_rush_yards_per_attempt' => round($this->rate($totals['off']['rush_yards'], $totals['off']['rush']), 3),
            'def_sack_rate' => round($this->rate($totals['def']['sacks'], $totals['def']['pass']), 4),
            'def_rush_yards_allowed_per_attempt' => round($this->rate($totals['def']['rush_yards'], $totals['def']['rush']), 3),
            'off_pass_attempts' => $totals['off']['pass'], 'off_rush_attempts' => $totals['off']['rush'],
            'def_pass_attempts' => $totals['def']['pass'], 'def_rush_attempts' => $totals['def']['rush'],
            ...($qbContext === [] ? [] : ['qb_context' => $qbContext]),
        ];
    }

    /** Keep current defense; replace only offense with verified same-QB history.
     * Missing starter identity is not assumed to be a backup or a match.
     * Prior-season fallback is bounded, regular-season only and same team.
     */
    private function offenseSample(int $teamId, array $rows, int $minimum, int $recent = 8): array
    {
        $name = $this->name($this->quarterbacks[$teamId]['qb_name'] ?? null);
        if ($name === '') {
            return [$rows, []];
        }
        $matches = fn ($r) => $r['regular'] && $r['qb_name'] === $name;
        $current = array_values(array_filter($rows, $matches));
        $prior = [];
        if (count($current) < $minimum) {
            $currentIds = array_column($current, 'id');
            $prior = array_values(array_filter($this->rows($teamId, (int) $this->target->season - 1, true),
                fn ($r) => $matches($r) && ! in_array($r['id'], $currentIds, true)));
        }
        $sample = array_slice([...$prior, ...$current], -max(1, $recent));

        return [$sample, ['projected_qb' => $this->quarterbacks[$teamId]['qb_name'],
            'policy' => 'same_team_same_starting_qb_regular_season', 'offense_games' => count($sample),
            'excluded_other_or_unknown_qb_games' => count($rows) - count($current),
            'prior_season_fallback' => count(array_filter($sample, fn ($r) => $r['season'] < (int) $this->target->season)) > 0,
            'sample_game_ids' => array_column($sample, 'id'), 'minimum_games_met' => count($sample) >= $minimum,
            'limitation' => 'Prior-season personnel/coaching can differ; no automatic return bonus.']];
    }

    private function name(?string $name): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower((string) $name));
    }

    private function rows(int $teamId, int $season, bool $regularOnly): array
    {
        $key = $teamId.'|'.$season.'|'.(int) $regularOnly;
        if (isset($this->rows[$key])) {
            return $this->rows[$key];
        }

        return $this->rows[$key] = $this->games($season)->filter(fn (Game $g) => ((int) $g->home_team_id === $teamId || (int) $g->away_team_id === $teamId)
            && (! $regularOnly || in_array((string) $g->season_type, ['2', 'regular'], true)))
            ->map(function (Game $g) use ($teamId): array {
                $home = (int) $g->home_team_id === $teamId;
                $opponent = (int) ($home ? $g->away_team_id : $g->home_team_id);
                $find = fn (int $id, string $side) => $g->teamStats->firstWhere('team_id', $id)
                    ?? $g->teamStats->first(fn ($stat) => strtolower((string) $stat->team_type) === $side);
                $off = $find($teamId, $home ? 'home' : 'away');
                $def = $find($opponent, $home ? 'away' : 'home');

                return ['id' => $g->id, 'season' => (int) $g->season, 'regular' => in_array((string) $g->season_type, ['2', 'regular'], true),
                    'qb_name' => $this->name($home ? $g->home_qb_name : $g->away_qb_name),
                    'for' => (float) ($home ? $g->home_score : $g->away_score),
                    'against' => (float) ($home ? $g->away_score : $g->home_score),
                    'opponent' => $opponent, 'date' => $g->game_date,
                    'off' => $off && $def ? $this->stats($off) : null,
                    'def' => $off && $def ? $this->stats($def) : null];
            })->values()->all();
    }

    private function games(int $season): Collection
    {
        if (isset($this->seasons[$season])) {
            return $this->seasons[$season];
        }
        $teams = [(int) $this->target->home_team_id, (int) $this->target->away_team_id];
        $date = $this->target->game_date;

        return $this->seasons[$season] = Game::query()
            ->select(['id', 'home_team_id', 'away_team_id', 'game_date', 'season', 'season_type', 'home_score', 'away_score', 'home_qb_name', 'away_qb_name'])
            ->with('teamStats:id,game_id,team_id,team_type,total_yards,passing_attempts,rushing_attempts,rushing_yards,sacks_allowed,interceptions,fumbles_lost,fumbles,red_zone_scores,red_zone_attempts,third_down_conversions,third_down_attempts,penalty_yards')
            ->where('season', $season)->where('status', 'STATUS_FINAL')
            ->whereNotNull('home_score')->whereNotNull('away_score')
            ->when($season === (int) $this->target->season && $date instanceof CarbonInterface,
                fn ($q) => $q->whereDate('game_date', '<', $date->toDateString()))
            ->where(fn ($q) => $q->whereIn('home_team_id', $teams)->orWhereIn('away_team_id', $teams))
            ->orderBy('game_date')->orderBy('id')->get();
    }

    private function stats(object $stat): array
    {
        $pass = (int) ($stat->passing_attempts ?? 0);
        $rush = (int) ($stat->rushing_attempts ?? 0);
        $sacks = (int) ($stat->sacks_allowed ?? 0);
        $plays = max(0.0, (float) $pass + $rush + $sacks);
        $yards = (float) ($stat->total_yards ?? 0);
        $turnovers = (float) ($stat->interceptions ?? 0) + (float) ($stat->fumbles_lost ?? $stat->fumbles ?? 0);

        return ['pass' => $pass, 'rush' => $rush, 'sacks' => $sacks, 'plays' => $plays,
            'yards' => $yards, 'rush_yards' => (int) ($stat->rushing_yards ?? 0), 'turnovers' => $turnovers,
            'yards_per_play' => $this->rate($yards, $plays), 'pass_rate' => $this->rate($pass, $plays),
            'red_zone_rate' => $this->rate((int) ($stat->red_zone_scores ?? 0), (int) ($stat->red_zone_attempts ?? 0)),
            'third_down_rate' => $this->rate((int) ($stat->third_down_conversions ?? 0), (int) ($stat->third_down_attempts ?? 0)),
            'turnover_rate' => $this->rate($turnovers, $plays), 'penalty_yards' => (float) ($stat->penalty_yards ?? 0)];
    }

    private function rate(float $numerator, float $denominator): float
    {
        return $denominator > 0 ? $numerator / $denominator : 0.0;
    }

    private function mean(array $rows, Closure $value, int $precision = 3): float
    {
        return $rows === [] ? 0.0 : round(array_sum(array_map($value, $rows)) / count($rows), $precision);
    }
}
