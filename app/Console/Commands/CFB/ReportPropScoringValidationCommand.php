<?php

namespace App\Console\Commands\CFB;

use App\Models\CFB\PlayerProp;
use App\Models\CFB\PlayerStat;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ReportPropScoringValidationCommand extends Command
{
    protected $signature = 'cfb:report-prop-scoring-validation';

    protected $description = 'Audit preserved pregame CFB prop signals without promoting scoring rules';

    public function handle(): int
    {
        $legacyQuery = PlayerProp::whereNotNull('graded_at')->whereNotNull('predicted_over_probability');
        if (Schema::hasTable('cfb_prop_signal_snapshots')) {
            $legacyQuery->whereNotIn('id', DB::table('cfb_prop_signal_snapshots')->select('prop_id'));
        }
        $legacy = $legacyQuery->count();
        $snapshots = Schema::hasTable('cfb_prop_signal_snapshots')
            ? DB::table('cfb_prop_signal_snapshots')->whereColumn('observed_at', '<', 'kickoff_at')
                ->orderByDesc('observed_at')->orderByDesc('id')->get()
            : collect();
        // One observation per player/game/market, avoiding repeated books and moved-line inflation.
        $selected = $snapshots->unique(fn ($s) => $s->game_id.'|'.$s->player_id.'|'.$s->market);
        $rows = [];
        foreach ($selected as $s) {
            if (CarbonImmutable::parse($s->kickoff_at, 'UTC')->isFuture()) {
                continue;
            }
            $payload = json_decode($s->payload, true, flags: JSON_THROW_ON_ERROR);
            $analysis = $payload['analysis'];
            $field = match ($s->market) {
                'player_pass_yds' => 'passing_yards', 'player_rush_yds' => 'rushing_yards',
                'player_reception_yds' => 'receiving_yards', default => null,
            };
            $stat = $field ? PlayerStat::where('game_id', $s->game_id)->where('player_id', $s->player_id)
                ->whereHas('game', fn ($q) => $q->where('status', 'STATUS_FINAL'))->first() : null;
            if (! $stat || ! is_numeric($stat->$field)) {
                continue;
            }
            $actual = (float) $stat->$field;
            $p = data_get($analysis, 'model_over_probability');
            $side = data_get($analysis, 'recommendation');
            $selectedForBoard = ($analysis['confidence'] ?? 0) >= 60 && in_array($side, ['Over', 'Under'], true);
            $push = $actual === (float) $s->line;
            $win = $side === 'Over' ? $actual > $s->line : $actual < $s->line;
            $rows[] = ['market' => $s->market, 'game_id' => $s->game_id,
                'kickoff' => $s->kickoff_at, 'selected_for_board' => $selectedForBoard,
                'candidate_selected' => data_get($payload, 'candidate.status') === 'shadow_only'
                    && data_get($payload, 'candidate.candidate_score', 0) >= 60 && in_array($side, ['Over', 'Under'], true),
                'result' => $push ? 'push' : ($win ? 'win' : 'loss'),
                'brier' => $push || ! is_numeric($p) ? null : (((float) $p / 100) - ($actual > $s->line ? 1 : 0)) ** 2];
        }
        $report = collect($rows)->groupBy('market')->map(function ($rows) {
            $bets = $rows->where('selected_for_board', true);

            return ['evaluated_observations' => $rows->count(), 'independent_games' => $rows->pluck('game_id')->unique()->count(),
                'diagnostic_brier' => $rows->avg('brier'), 'shadow_board_record' => [
                    'wins' => $bets->where('result', 'win')->count(), 'losses' => $bets->where('result', 'loss')->count(),
                    'pushes' => $bets->where('result', 'push')->count()], 'candidate_shadow_record' => [
                        'wins' => $rows->where('candidate_selected', true)->where('result', 'win')->count(),
                        'losses' => $rows->where('candidate_selected', true)->where('result', 'loss')->count(),
                        'pushes' => $rows->where('candidate_selected', true)->where('result', 'push')->count()],
                'is_real_money' => false];
        });
        $this->line(json_encode(['generated_at' => now()->toIso8601String(),
            'legacy_graded_probability_rows_timing_unverified' => $legacy,
            'preserved_pregame_snapshots' => $snapshots->count(), 'unique_selected_observations' => $selected->count(),
            'selection_policy' => 'latest preserved pregame observation per game/player/market',
            'metrics_scope' => 'descriptive prospective observations; no chronological holdout or candidate promotion',
            'markets' => $report, 'recommendations_approved' => false,
            'next_action' => 'Collect immutable pregame evidence across weeks, then compare frozen candidates on chronological game-level holdouts.'], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
