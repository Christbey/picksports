<?php

namespace App\Services\NFL;

use App\Models\EventInputSnapshot;
use App\Models\NFL\Game;
use App\Models\PredictionFeatureSnapshot;
use Carbon\CarbonImmutable;

class NflFrozenSpreadReplayAudit
{
    /** Read-only. No current team metrics, odds, injuries, training or forecast writes. */
    public function run(int $season, int $throughWeek = 2, string $variant = 'safeguards_v1'): array
    {
        if (! in_array($variant, NflFrozenSpreadReplay::VARIANTS, true)) {
            throw new \InvalidArgumentException('Unsupported replay variant');
        }
        $dataset = (new NflSpreadValidationDataset)->load($season, $season);
        $rows = array_values(array_filter($dataset['rows'], fn ($row) => $row['week'] >= 1 && $row['week'] <= $throughWeek));
        $games = Game::query()->select(['id', 'sport_event_id', 'season', 'home_team_id', 'away_team_id', 'neutral_site'])
            ->with(['homeTeam:id,name', 'awayTeam:id,name'])->whereIn('id', array_column($rows, 'game_id'))->get()->keyBy('id');
        $snapshots = PredictionFeatureSnapshot::query()->select(['id', 'model_metadata', 'features'])
            ->whereIn('id', array_column($rows, 'snapshot_id'))->get()->keyBy('id');
        $inputs = EventInputSnapshot::query()->select(['id', 'sport', 'phase', 'pregame_safety_status', 'sport_event_id', 'captured_at', 'latest_source_available_at',
            'inputs->home->team_id as home_team_id', 'inputs->away->team_id as away_team_id',
            'inputs->home->metrics->record_season as home_season', 'inputs->away->metrics->record_season as away_season',
            'inputs->home->metrics->predictive_rating as home_rating', 'inputs->away->metrics->predictive_rating as away_rating',
            'inputs->event->season_type as season_type',
        ])->where('sport', 'nfl')->where('phase', 'pregame')->where('pregame_safety_status', 'verified')
            ->whereIn('sport_event_id', $games->pluck('sport_event_id')->filter()->all())
            ->whereColumn('latest_source_available_at', '<=', 'captured_at')
            ->where('captured_at', '<=', CarbonImmutable::parse(collect($rows)->max('generated_at') ?? '1900-01-01')->setTimezone(config('app.timezone', 'UTC')))
            ->orderByDesc('captured_at')->orderByDesc('id')->get()->groupBy('sport_event_id');
        $report = ['policy' => 'frozen_spread_signals_v2', 'variant' => $variant, 'season' => $season, 'through_week' => $throughWeek,
            'scope' => match ($variant) {
                'rushing_direction_only' => 'Only rushing spread direction corrected; all other legacy stages retained',
                'injury_precision_only' => 'Only injury-stage margin rounding removed; saved source signals remain rounded',
                'history_context_off_only' => 'Only H2H/division/conference/same-week spread contributions removed; venue, rest and coaching retained',
                'batch_v2' => 'Safeguards v1 plus rushing spread direction correction and removal of injury-stage rounding; history context retained',
                default => 'EPA quarantined; preseason fallback 25%; sample prior 8; legacy spread bias disabled; other signals and feature switches frozen',
            },
            'candidate_parameter_assumptions' => ['rushing_run_weight' => 1.35, 'rushing_pressure_weight' => 34, 'rushing_signal_cap' => 4, 'context_adjustment_cap' => 2],
            'precision' => 'Reconstruction from rounded saved signals; original published spread must reproduce to 0.1 points',
            'line_policy' => 'Original frozen observed lines, not necessarily closing or freshly fetched; not wager ROI',
            'validation_note' => 'Retrospective diagnostic, not an independent holdout or full current-model regeneration',
            'production_modified' => false, 'promotion_allowed' => false,
            'dataset_exclusions' => $dataset['excluded'], 'completed_games' => count($rows), 'weeks' => [], 'games' => []];
        foreach ($rows as $row) {
            $game = $games->get($row['game_id']);
            $snapshot = $snapshots->get($row['snapshot_id']);
            $metadata = $snapshot?->model_metadata ?? [];
            $recovery = null;
            if (in_array($variant, ['safeguards_v1', 'batch_v2'], true) && data_get($metadata, 'preseason_signal.reason') === 'true_epa_already_applied') {
                foreach ($inputs->get($game->sport_event_id, collect()) as $input) {
                    $recovery = $this->recoverPreseason($input, $game, $row, $snapshot->features ?? []);
                    if ($recovery !== null) {
                        break;
                    }
                }
            }
            $result = in_array($row['model_version'], ['nfl-historical-elo-v2', 'nfl-historical-elo-v2-career-regular-v2'], true)
                ? (new NflFrozenSpreadReplay)->replay($metadata, $row['model_margin'], $recovery, $variant)
                : ['status' => 'excluded', 'reason' => 'unsupported_model_version'];
            $original = (new NflSpreadBacktestEvaluator)->evaluate($row['model_margin'], $row['home_line'], $row['actual_margin']);
            $week = $row['week'];
            $report['weeks'][$week] ??= ['original_all' => [], 'original_matched' => [], 'revised_matched' => [], 'excluded' => 0, 'changed_picks' => 0];
            $this->countResult($report['weeks'][$week]['original_all'], $original['result']);
            $item = ['game_id' => $row['game_id'], 'snapshot_id' => $row['snapshot_id'], 'week' => $week,
                'matchup' => $game->awayTeam->name.' @ '.$game->homeTeam->name,
                'generated_at' => $row['generated_at'], 'home_line' => $row['home_line'],
                'actual_margin' => $row['actual_margin'], 'original_margin' => $row['model_margin'],
                'original_pick' => $original['pick'], 'original_result' => $original['result'], ...$result];
            if ($result['status'] === 'replayed') {
                $revised = (new NflSpreadBacktestEvaluator)->evaluate($result['revised_margin'], $row['home_line'], $row['actual_margin']);
                $this->countResult($report['weeks'][$week]['original_matched'], $original['result']);
                $this->countResult($report['weeks'][$week]['revised_matched'], $revised['result']);
                $item['revised_pick'] = $revised['pick'];
                $item['revised_result'] = $revised['result'];
                $item['changed_pick'] = $original['pick'] !== $revised['pick'];
                $report['weeks'][$week]['changed_picks'] += (int) $item['changed_pick'];
            } else {
                $report['weeks'][$week]['excluded']++;
            }
            $report['games'][] = $item;
        }

        return $report;
    }

    public function recoverPreseason(EventInputSnapshot $input, Game $game, array $row, array $features): ?array
    {
        $asOf = CarbonImmutable::parse($row['generated_at']);
        $kickoff = CarbonImmutable::parse($row['kickoff']);
        if ($input->sport !== 'nfl' || $input->phase !== 'pregame' || $input->pregame_safety_status !== 'verified'
            || (int) $input->sport_event_id !== (int) $game->sport_event_id
            || ! $input->captured_at || ! $input->latest_source_available_at
            || $input->captured_at->gt($asOf) || $input->captured_at->gte($kickoff)
            || $input->latest_source_available_at->gt($input->captured_at)
            || (int) $input->home_team_id !== (int) $game->home_team_id || (int) $input->away_team_id !== (int) $game->away_team_id
            || (int) $input->home_season !== (int) $game->season || (int) $input->away_season !== (int) $game->season
            || ! in_array((string) $input->season_type, ['2', 'regular'], true)
            || ! is_numeric($input->home_rating) || ! is_numeric($input->away_rating)
            || ! is_finite((float) $input->home_rating) || ! is_finite((float) $input->away_rating)) {
            return null;
        }
        // Recover the original home-field points from frozen Elo inputs, not today's team state.
        $hfaElo = $features['home_field_advantage'] ?? null;
        if (! is_numeric($hfaElo) || ! is_finite((float) $hfaElo)) {
            return null;
        }
        // Explicit current policy: 0.09 points/Elo; the frozen HFA is zero at neutral sites.
        $signal = (float) $input->home_rating - (float) $input->away_rating + (float) $hfaElo * 0.09;

        return ['input_snapshot_id' => $input->id, 'captured_at' => $input->captured_at->toIso8601String(),
            'age_hours' => round($input->captured_at->diffInSeconds($asOf) / 3600, 2),
            'source' => 'last_verified_archived_current_season_ratings', 'signal_spread' => $signal];
    }

    private function countResult(array &$counts, string $result): void
    {
        $counts += ['win' => 0, 'loss' => 0, 'push' => 0, 'no_pick' => 0];
        $counts[$result]++;
    }
}
