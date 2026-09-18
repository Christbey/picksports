<?php

namespace App\Services\NFL;

use App\Actions\NFL\CalculateTeamTrends;
use App\Services\Trends\TrendSignalScorer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class NflTeamEvidenceService
{
    private const MIN_COMPARISON_SAMPLE = 3;

    public function __construct(private readonly CalculateTeamTrends $calculator, private readonly TrendSignalScorer $scorer) {}

    public function build(object $team, int $season, string $before): array
    {
        $cutoff = Carbon::parse($before, 'UTC')->utc();
        $games = $this->calculator->evidenceGames($team, $season, $cutoff->toIso8601String());
        // Each historical prediction must predate its own game's kickoff,
        // not merely the target game. Retrospective reruns are not pregame Elo.
        foreach ($games as $game) {
            $sourceKickoff = $game->game_time
                ? Carbon::parse($game->game_date->toDateString().' '.$game->game_time, 'UTC') : null;
            if ($game->prediction && (! $sourceKickoff || ! $game->prediction->updated_at
                || $game->prediction->updated_at->gte($sourceKickoff) || $game->prediction->updated_at->gte($cutoff))) {
                $game->setRelation('prediction', null);
            }
        }
        $sets = [
            'recent_5' => ['Last 5 regular-season games', $games->take(5)],
            'recent_10' => ['Last 10 regular-season games', $games->take(10)],
            'season' => ["{$season} regular season", $games->where('season', $season)],
            'historical' => ['Previous 3 regular seasons', $games->where('season', '<', $season)],
        ];
        $windows = [];
        foreach ($sets as $key => [$label, $sample]) {
            $windows[$key] = $this->window($team, $sample->values(), $label);
        }
        // The comparison baseline needs metrics, not another collector/scorer pass.
        $prior = $this->evidence($team, $games->slice(5, 10)->values(), 'Preceding 10 games');
        $changes = [];
        foreach (['points_for', 'points_against', 'margin', 'yards_per_play', 'turnovers'] as $metric) {
            $recent = $windows['recent_5']['evidence']['metrics'][$metric];
            $baseline = $prior['metrics'][$metric];
            $available = $recent['sample_size'] >= self::MIN_COMPARISON_SAMPLE
                && $baseline['sample_size'] >= self::MIN_COMPARISON_SAMPLE;
            $changes[] = [
                'metric' => $metric, 'recent' => $recent, 'baseline' => $baseline,
                'delta' => $available ? round($recent['value'] - $baseline['value'], 2) : null,
                'status' => $available ? 'descriptive_comparison' : 'insufficient_data',
            ];
        }

        return [
            'version' => 'nfl-team-evidence-v1', 'default_window' => 'recent_5',
            'cutoff_at' => $cutoff->toIso8601String(), 'generated_at' => now()->toIso8601String(),
            'source' => 'nfl_games+nfl_team_stats+eligible_nfl_predictions',
            'season_type' => '2', 'windows' => $windows, 'changes' => $changes,
            'comparison_baseline' => $prior,
            'limitations' => [
                'Windows overlap; do not add their signal counts as independent evidence.',
                'Changes compare last 5 with the preceding 10, not an overlapping season average. They are not opponent-adjusted or validated betting edges.',
                'Older rosters, coaches and quarterbacks may not represent the current team.',
                'Historical scores/stats reflect currently stored records, including later corrections; this is not a point-in-time backtest.',
                'EPA, pressure, injury, weather and market-value conclusions are not inferred from missing or mutable historical inputs. Use the separate game analysis for verified matchup context.',
            ],
        ];
    }

    private function window(object $team, Collection $games, string $label): array
    {
        $summary = $this->calculator->summarizeGames($team, $games);
        $signals = $this->scorer->score('nfl', $summary['trends'], $games->count());

        return [
            'team_id' => $team->id, 'team_abbreviation' => $team->abbreviation,
            'sample_size' => $games->count(), 'trends' => $summary['trends'], 'locked_trends' => [],
            'scored_signals' => $signals, 'trend_signal_summary' => $this->scorer->summarize($signals),
            'evidence' => $this->evidence($team, $games, $label),
        ];
    }

    private function evidence(object $team, Collection $games, string $label): array
    {
        $values = [];
        foreach (['points_for', 'points_against', 'margin', 'yards_per_play', 'turnovers', 'third_down_pct', 'red_zone_pct', 'yards_allowed'] as $key) {
            $values[$key] = [];
        }
        $wins = $losses = $ties = 0;
        foreach ($games as $game) {
            $home = (int) $game->home_team_id === (int) $team->id;
            $scored = (int) ($home ? $game->home_score : $game->away_score);
            $allowed = (int) ($home ? $game->away_score : $game->home_score);
            $wins += (int) ($scored > $allowed);
            $losses += (int) ($scored < $allowed);
            $ties += (int) ($scored === $allowed);
            $values['points_for'][] = $scored;
            $values['points_against'][] = $allowed;
            $values['margin'][] = $scored - $allowed;
            $stats = $game->teamStats->firstWhere('team_id', $team->id);
            $opponent = $game->teamStats->firstWhere('team_id', $home ? $game->away_team_id : $game->home_team_id);
            if (is_numeric($opponent?->total_yards)) {
                $values['yards_allowed'][] = (float) $opponent->total_yards;
            }
            if (! $stats) {
                continue;
            }
            if (is_numeric($stats->total_yards) && $this->validCount($stats->passing_attempts)
                && $this->validCount($stats->rushing_attempts) && $this->validCount($stats->sacks_allowed)) {
                $plays = $stats->passing_attempts + $stats->rushing_attempts + $stats->sacks_allowed;
                if ($plays > 0) {
                    $values['yards_per_play'][] = $stats->total_yards / $plays;
                }
            }
            if ($this->validCount($stats->interceptions) && $this->validCount($stats->fumbles_lost)) {
                $values['turnovers'][] = $stats->interceptions + $stats->fumbles_lost;
            }
            foreach (['third_down_pct' => ['third_down_conversions', 'third_down_attempts'], 'red_zone_pct' => ['red_zone_scores', 'red_zone_attempts']] as $key => [$made, $attempts]) {
                if ($this->validCount($stats->$made) && $this->validCount($stats->$attempts)
                    && $stats->$attempts > 0 && $stats->$made <= $stats->$attempts) {
                    $values[$key][] = 100 * $stats->$made / $stats->$attempts;
                }
            }
        }
        $metrics = [];
        foreach ($values as $key => $numbers) {
            $metrics[$key] = ['value' => count($numbers) ? round(array_sum($numbers) / count($numbers), 2) : null,
                'sample_size' => count($numbers), 'aggregation' => 'mean_of_game_values'];
        }
        $dates = $games->map(fn ($g) => $g->game_date->toDateString())->sort()->values();

        return [
            'label' => $label, 'sample_size' => $games->count(), 'game_ids' => $games->pluck('id')->values()->all(),
            'seasons' => $games->pluck('season')->unique()->sort()->values()->all(),
            'from_date' => $dates->first(), 'through_date' => $dates->last(),
            'latest_source_update' => $games->pluck('updated_at')->merge($games->flatMap(fn ($g) => $g->teamStats->pluck('updated_at')))->filter()->max()?->toIso8601String(),
            'record' => ['wins' => $wins, 'losses' => $losses, 'ties' => $ties], 'metrics' => $metrics,
        ];
    }

    private function validCount(mixed $value): bool
    {
        return is_numeric($value) && is_finite((float) $value) && $value >= 0 && floor((float) $value) == $value;
    }
}
