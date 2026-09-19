<?php

namespace App\Actions\CFB;

use App\Actions\Sports\AbstractFootballUpdateLivePrediction;
use App\Models\CFB\Game;
use App\Services\CFB\Live\LiveScoreProjector;
use App\Services\CFB\Live\LiveSnapshotRecorder;
use Illuminate\Support\Facades\DB;

class UpdateLivePrediction extends AbstractFootballUpdateLivePrediction
{
    public function execute(object $game): ?array
    {
        if (! $game instanceof Game) {
            return null;
        }

        return DB::transaction(function () use ($game) {
            $game = Game::whereKey($game->id)->lockForUpdate()->firstOrFail();
            $recorder = app(LiveSnapshotRecorder::class);
            $baseline = $recorder->baseline($game);
            if (! $baseline) {
                return null;
            }
            if (! in_array($game->status, ['STATUS_IN_PROGRESS', 'STATUS_HALFTIME', 'STATUS_END_PERIOD', 'STATUS_FINAL'], true)) {
                return null;
            }
            $status = 'live';
            $clock = $game->status === 'STATUS_HALFTIME' && (int) $game->period === 2 ? '0:00' : $game->game_clock;
            $projection = null;
            if ($game->home_score === null || $game->away_score === null || $game->home_score < 0 || $game->away_score < 0) {
                $status = 'missing_score';
            } elseif ($game->status === 'STATUS_FINAL') {
                $status = 'final';
                $projection = ['spread' => $game->home_score - $game->away_score, 'total' => $game->home_score + $game->away_score,
                    'home_points' => $game->home_score, 'away_points' => $game->away_score,
                    'model_version' => LiveScoreProjector::VERSION,
                    'home_win_probability' => $game->home_score === $game->away_score ? null : ($game->home_score > $game->away_score ? 1 : 0), 'seconds_remaining' => 0];
            } elseif ((int) $game->period > 4) {
                // College overtime is possession-based, not the NFL's timed overtime.
                $status = 'overtime_unmodeled';
            } elseif ((int) $game->period < 1 || ! preg_match('/^(?:0?[0-9]|1[0-4]):[0-5][0-9]$|^15:00$/', (string) $clock)) {
                $status = 'missing_clock';
            } elseif (! is_numeric($baseline['spread']) || ! is_numeric($baseline['total'])
                || ! is_finite((float) $baseline['spread']) || ! is_finite((float) $baseline['total'])
                || $baseline['total'] < abs($baseline['spread'])) {
                $status = 'missing_pregame';
            } else {
                $remaining = $this->calculateSecondsRemaining((int) $game->period, $clock);
                $projection = app(LiveScoreProjector::class)->project(
                    $game->home_score, $game->away_score, $remaining,
                    (float) $baseline['spread'], (float) $baseline['total'],
                    is_numeric($baseline['home_win_probability']) ? (float) $baseline['home_win_probability'] : null,
                );
            }
            // Only the live columns are mutable. Pregame and historical snapshots are untouched.
            $game->prediction()->update([
                'live_predicted_spread' => $status === 'live' ? $projection['spread'] : null,
                'live_predicted_total' => $status === 'live' ? $projection['total'] : null,
                'live_win_probability' => $status === 'live' ? $projection['home_win_probability'] : null,
                'live_seconds_remaining' => $status === 'live' ? $projection['seconds_remaining'] : null,
                'live_updated_at' => $status === 'live' ? now() : null,
            ]);
            $recorder->record($game, $baseline, $projection, $status);

            return $status === 'live' ? ['live_predicted_spread' => $projection['spread'], 'live_predicted_total' => $projection['total'],
                'live_win_probability' => $projection['home_win_probability'], 'live_seconds_remaining' => $projection['seconds_remaining']] : null;
        });
    }
}
