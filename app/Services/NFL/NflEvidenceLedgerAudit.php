<?php

namespace App\Services\NFL;

use App\Models\CommandHeartbeat;
use App\Models\NFL\Game;
use App\Models\NflSignalGrade;
use App\Models\NflSignalObservation;
use App\Models\PredictionFeatureSnapshot;
use App\Services\Predictions\SnapshotProvenanceResolver;
use App\Support\NflModelVersion;
use Carbon\CarbonImmutable;

class NflEvidenceLedgerAudit
{
    public function report(int $season, ?string $version = null): array
    {
        $version ??= NflModelVersion::current();
        $games = Game::with('sportEvent')->where('season', $season)->whereIn('season_type', ['2', 'regular'])->get()->keyBy('id');
        $latest = PredictionFeatureSnapshot::where('sport', 'nfl')->where('prediction_table', 'nfl_predictions')
            ->where('model_version', $version)->whereIn('game_id', $games->keys())->where('blend_version', '!=', NflEvidenceCapture::POLICY)
            ->where('pregame_safe', true)->where('availability_status', 'observed_pregame')->where('generated_at', '<=', now())
            ->whereColumn('generated_at', '<', 'game_start_at')->whereColumn('features_available_at', '<=', 'generated_at')
            ->selectRaw('MAX(id)')->groupBy('game_id');
        $snapshots = PredictionFeatureSnapshot::whereIn('id', $latest)->get()->keyBy('game_id');
        $observations = NflSignalObservation::whereIn('prediction_feature_snapshot_id', $snapshots->pluck('id'))
            ->selectRaw('prediction_feature_snapshot_id, COUNT(*) as observations, MAX(created_at) as materialized_at')
            ->groupBy('prediction_feature_snapshot_id')->get()->keyBy('prediction_feature_snapshot_id');
        $grades = NflSignalGrade::join('nfl_signal_observations as o', 'o.id', '=', 'nfl_signal_grades.nfl_signal_observation_id')
            ->whereIn('o.prediction_feature_snapshot_id', $snapshots->pluck('id'))->where('evaluation_source', 'outcome')
            ->selectRaw('o.prediction_feature_snapshot_id, nfl_signal_grades.market_type, COUNT(*) as grade_count, MAX(graded_at) as latest_grade')
            ->groupBy('o.prediction_feature_snapshot_id', 'nfl_signal_grades.market_type')->get()
            ->keyBy(fn ($r) => $r->prediction_feature_snapshot_id.':'.$r->market_type);
        $reasons = [];
        $details = [];
        foreach ($games as $game) {
            $snapshot = $snapshots->get($game->id);
            $kickoff = app(SnapshotProvenanceResolver::class)->gameStartAt($game);
            foreach (['winner', 'spread', 'total'] as $market) {
                $field = ['winner' => 'win_probability', 'spread' => 'predicted_spread', 'total' => 'predicted_total'][$market];
                $reason = match (true) {
                    $game->status !== 'STATUS_FINAL' || $game->home_score === null || $game->away_score === null => 'awaiting_final_result',
                    $snapshot === null => 'missing_verified_pregame_snapshot',
                    $kickoff === null || ! $snapshot->game_start_at?->eq($kickoff) => 'kickoff_mismatch',
                    ! is_numeric($snapshot->outputs[$field] ?? null) => 'missing_forecast_value',
                    $market !== 'winner' && ! is_numeric($snapshot->market_context[$market === 'spread' ? 'market_home_margin' : 'market_total'] ?? null) => 'missing_market_line',
                    ! $observations->has($snapshot->id) => 'not_materialized',
                    (int) ($grades->get($snapshot->id.':'.$market)?->grade_count ?? 0) < (int) $observations[$snapshot->id]->observations => 'grading_backlog',
                    default => 'graded',
                };
                $quote = $snapshot?->market_context['odds_updated_at'] ?? null;
                $quoteStatus = 'missing_observation_time';
                if ($quote !== null && $snapshot) {
                    try {
                        $time = CarbonImmutable::parse($quote);
                        $quoteStatus = $time->gt($snapshot->generated_at) ? 'after_forecast' : ($time->lt($snapshot->generated_at->toImmutable()->subHours(36)) ? 'stale' : 'fresh');
                    } catch (\Throwable) {
                        $quoteStatus = 'invalid_observation_time';
                    }
                }
                $reasons[$market][$reason] = ($reasons[$market][$reason] ?? 0) + 1;
                $details[] = ['game_id' => $game->id, 'matchup' => $game->short_name ?: $game->name, 'snapshot_id' => $snapshot?->id, 'market' => $market, 'reason' => $reason, 'quote_status' => $quoteStatus];
            }
        }
        $scope = NflSignalObservation::where('season', $season)->where('model_version', $version);
        $heartbeat = CommandHeartbeat::where('command', 'like', '%nfl:%signal%')->get();

        return ['checked_at' => now()->toIso8601String(), 'season' => $season, 'model_version' => $version,
            'game_count' => $games->count(), 'reasons' => $reasons, 'games' => $details,
            'freshness' => ['last_forecast' => $snapshots->max('generated_at'),
                'last_capture' => PredictionFeatureSnapshot::where('sport', 'nfl')->where('model_version', $version)->where('blend_version', NflEvidenceCapture::POLICY)->max('generated_at'),
                'last_materialization' => (clone $scope)->max('created_at'),
                'last_grade' => NflSignalGrade::whereIn('nfl_signal_observation_id', (clone $scope)->select('id'))->max('graded_at')],
            'scheduler_heartbeats' => $heartbeat->toArray(), 'sample_unit' => 'game', 'read_only' => true];
    }
}
