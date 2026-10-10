<?php

namespace App\Console\Commands\CFB;

use App\Models\CanonicalPrediction;
use App\Models\CFB\Game;
use App\Models\CFB\TeamMetric;
use App\Services\Validation\CfbValidationCoverage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

class AuditForecastFreshnessCommand extends Command
{
    protected $signature = 'cfb:audit-forecast-freshness {--season=} {--week=} {--max-age-hours=6}';

    protected $description = 'Audit every weekly FBS forecast against publication time and current team metrics';

    public function handle(CfbValidationCoverage $coverage): int
    {
        $options = ['season' => $this->option('season'), 'week' => $this->option('week'), 'age' => $this->option('max-age-hours')];
        if (Validator::make($options, ['season' => 'required|integer|min:2000|max:2100',
            'week' => 'required|integer|min:1|max:30', 'age' => 'required|integer|min:1|max:24'])->fails()) {
            $this->error('Provide a valid season, week, and freshness limit (1–24 hours).');

            return self::FAILURE;
        }
        $games = Game::with(['homeTeam', 'awayTeam', 'sportEvent'])->where('season', $options['season'])
            ->where('week', $options['week'])->orderBy('game_date')->orderBy('game_time')->get();
        $predictions = CanonicalPrediction::with(['markets', 'calculationRun.inputSnapshot'])
            ->where('sport', 'cfb')->where('phase', 'pregame')->where('publication_state', 'published')
            ->whereIn('sport_event_id', $games->pluck('sport_event_id')->filter())->orderByDesc('revision')
            ->get()->unique('sport_event_id')->keyBy('sport_event_id');
        $metrics = TeamMetric::where('season', $options['season'])
            ->whereIn('team_id', $games->flatMap(fn ($g) => [$g->home_team_id, $g->away_team_id])->unique())
            ->orderByDesc('calculation_date')->orderByDesc('id')->get()->unique('team_id')->keyBy('team_id');
        $rows = [];
        $excluded = 0;
        foreach ($games as $game) {
            if ($coverage->requiredTeamIds($game) === []) {
                $excluded++;

                continue;
            }
            $prediction = $predictions->get($game->sport_event_id);
            $kickoff = $game->sportEvent?->starts_at;
            $snapshot = $prediction?->calculationRun?->inputSnapshot;
            $reasons = [];
            $upcoming = in_array($game->status, ['STATUS_SCHEDULED', 'STATUS_PRE_GAME', 'STATUS_DELAYED'], true);
            if ($upcoming) {
                if (! $kickoff || ! $kickoff->isFuture()) {
                    $reasons[] = 'unverified_future_kickoff';
                }
                if (! $prediction || $prediction->markets->isEmpty()) {
                    $reasons[] = 'missing_forecast';
                }
                if (! $prediction?->generated_at || $prediction->generated_at->lt(now()->subHours((int) $options['age']))
                    || $prediction->generated_at->isFuture()) {
                    $reasons[] = 'stale_forecast';
                }
                if (! $snapshot || $snapshot->pregame_safety_status !== 'verified'
                    || ! $prediction?->published_at || ! $kickoff || $prediction->published_at->gte($kickoff)
                    || ! $snapshot->cutoff_at || ! $snapshot->cutoff_at->equalTo($kickoff)) {
                    $reasons[] = 'unsafe_publication';
                }
                foreach (['home', 'away'] as $side) {
                    $metric = $metrics->get($game->{$side.'_team_id'});
                    if (! $metric) {
                        $reasons[] = $side.'_missing_metric';
                    } elseif (! $snapshot || $metric->updated_at->gt($snapshot->captured_at)) {
                        $reasons[] = $side.'_metric_newer_than_forecast';
                    }
                }
            }
            $rows[] = ['game_id' => $game->id, 'away' => $game->awayTeam?->abbreviation, 'home' => $game->homeTeam?->abbreviation,
                'status' => $game->status, 'kickoff' => $kickoff?->toIso8601String(), 'prediction_id' => $prediction?->public_id,
                'generated_at' => $prediction?->generated_at?->toIso8601String(), 'captured_at' => $snapshot?->captured_at?->toIso8601String(),
                'freshness' => $upcoming ? ($reasons === [] ? 'fresh' : 'held') : 'archived_after_kickoff',
                'risk_flags' => $reasons,
                'forecast_quality_flags' => data_get($prediction?->output_metadata, 'input_quality.risk_flags', []),
                'model_inputs_qualified' => data_get($prediction?->output_metadata, 'input_quality.qualified'),
                'stale_at_kickoff' => ! $upcoming && $kickoff && (! $prediction?->generated_at || $prediction->generated_at->lt($kickoff->copy()->subHours(24)))];
        }
        $counts = collect($rows)->countBy('freshness')->all();
        $report = ['season' => (int) $options['season'], 'week' => (int) $options['week'],
            'audited_at' => now()->toIso8601String(), 'max_age_hours' => (int) $options['age'],
            'scope' => 'FBS_and_unknown_membership', 'excluded_non_fbs_games' => $excluded,
            'counts' => $counts, 'ready' => ($counts['held'] ?? 0) === 0 && $rows !== [], 'games' => $rows];
        $this->line(json_encode($report, JSON_THROW_ON_ERROR));

        return $report['ready'] ? self::SUCCESS : 2;
    }
}
