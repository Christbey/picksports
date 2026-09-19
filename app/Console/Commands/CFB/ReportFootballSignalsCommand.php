<?php

namespace App\Console\Commands\CFB;

use App\Models\CanonicalPrediction;
use App\Services\CFB\Signals\CfbFootballSignalCatalog;
use Illuminate\Console\Command;

class ReportFootballSignalsCommand extends Command
{
    protected $signature = 'cfb:report-football-signals {--season=} {--catalog : Include every executable rule definition}';

    protected $description = 'Report distinct football rules, missing inputs, triggers and actual prediction contributions';

    public function handle(): int
    {
        $season = (string) ($this->option('season') ?: now()->year);
        if (! ctype_digit($season) || (int) $season < 2000 || (int) $season > 2100) {
            $this->error('Provide a valid --season=YYYY.');

            return self::FAILURE;
        }
        $catalog = CfbFootballSignalCatalog::all();
        $summary = [];
        foreach ($catalog as $id => $rule) {
            $summary[$id] = ['label' => $rule['label'], 'family' => $rule['family'], 'market' => $rule['market'],
                'evaluated' => 0, 'triggered' => 0, 'missing_inputs' => 0, 'applied' => 0, 'graded' => 0, 'error_reduction_sum' => 0.0];
        }
        $games = [];
        $predictions = CanonicalPrediction::query()->where('sport', 'cfb')->where('phase', 'pregame')->where('publication_state', 'published')
            ->whereHas('sportEvent', fn ($q) => $q->where('season', $season))
            ->with(['calculationRun', 'sportEvent.cfbGame'])->get();
        foreach ($predictions as $prediction) {
            $signals = data_get($prediction->calculationRun?->diagnostics, 'football_signals');
            if (! is_array($signals)) {
                continue;
            }
            $game = $prediction->sportEvent?->cfbGame;
            $final = $game?->status === 'STATUS_FINAL' && is_numeric($game->home_score) && is_numeric($game->away_score);
            foreach ($signals['signals'] as $signal) {
                $id = $signal['id'];
                if (! isset($summary[$id])) {
                    continue;
                }
                $summary[$id]['evaluated']++;
                $summary[$id]['triggered'] += (int) ($signal['matched'] === true);
                $summary[$id]['missing_inputs'] += (int) ($signal['matched'] === null);
                $active = abs($signal['contribution_points']) > .000001;
                $summary[$id]['applied'] += (int) $active;
                if ($final && $active) {
                    $spread = $signal['market'] === 'spread';
                    $actual = $spread ? $game->home_score - $game->away_score : $game->home_score + $game->away_score;
                    $full = round(($spread ? $signals['base_home_margin'] : $signals['base_total']) + $signals[$signal['market'].'_adjustment'], 1);
                    // Attribution removes the frozen allocated contribution, holding other allocations fixed.
                    $without = round(($spread ? $signals['base_home_margin'] : $signals['base_total']) + $signals[$signal['market'].'_adjustment'] - $signal['contribution_points'], 1);
                    $summary[$id]['graded']++;
                    $summary[$id]['error_reduction_sum'] += abs($without - $actual) - abs($full - $actual);
                }
            }
            $games[] = ['game_id' => $game?->id, 'prediction_id' => $prediction->id,
                ...array_diff_key($signals, ['signals' => true])];
        }
        $this->line(json_encode(['catalog_count' => count($catalog),
            'families' => collect($catalog)->countBy('family')->all(),
            'note' => 'Unique football rules; home/away evaluations are not additional signals. Missing or unsupported rules contribute no invented points. Allocated marginal error reduction is not causal attribution or proof of profitable bets.',
            'signals' => $summary, 'games' => $games,
            ...($this->option('catalog') ? ['catalog' => $catalog] : [])], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
