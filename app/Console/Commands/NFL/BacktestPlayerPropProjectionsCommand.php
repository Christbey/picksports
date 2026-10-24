<?php

namespace App\Console\Commands\NFL;

use App\Services\BettingRecommendations\NflPropProjectionBacktest;
use Illuminate\Console\Command;

class BacktestPlayerPropProjectionsCommand extends Command
{
    protected $signature = 'nfl:backtest-player-prop-projections
        {--season= : Required NFL season}
        {--through= : Last completed-game date, defaults to today in Chicago}
        {--market= : Limit the audit to one stored market for bounded execution}
        {--validation-from= : First validation game date; earlier targets form the development period}
        {--min-games=3 : Minimum reported prior games}
        {--include-samples : Export chronological benchmark features; requires --json}
        {--json : Print aggregate JSON instead of tables}';

    protected $description = 'Read-only chronological comparison of legacy and opportunity prop baselines';

    public function handle(NflPropProjectionBacktest $backtest): int
    {
        $season = filter_var($this->option('season'), FILTER_VALIDATE_INT);
        $minGames = filter_var($this->option('min-games'), FILTER_VALIDATE_INT);
        $through = $this->option('through') ?: now('America/Chicago')->toDateString();
        if (! $season || $season < 2000 || $season > (int) now('America/Chicago')->year || ! $minGames || $minGames < 1
            || ! is_string($through) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $through)
            || ! checkdate((int) substr($through, 5, 2), (int) substr($through, 8, 2), (int) substr($through, 0, 4)) || $through > now('America/Chicago')->toDateString()) {
            $this->error('Supply a valid --season, --through date no later than today, and positive --min-games.');

            return self::FAILURE;
        }
        $validationFrom = $this->option('validation-from');
        if ($validationFrom !== null && (! is_string($validationFrom) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $validationFrom)
            || ! checkdate((int) substr($validationFrom, 5, 2), (int) substr($validationFrom, 8, 2), (int) substr($validationFrom, 0, 4)))) {
            $this->error('Supply a valid --validation-from date.');

            return self::FAILURE;
        }
        if ($this->option('include-samples') && ! $this->option('json')) {
            $this->error('--include-samples requires --json.');

            return self::FAILURE;
        }
        $result = ['season' => $season, 'through' => $through, ...$backtest->run($season, $through, $minGames, $validationFrom, $this->option('market'), (bool) $this->option('include-samples'))];
        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }
        $this->info('NFL prop baseline comparison — read only');
        $this->line('Evaluated: '.$result['coverage']['evaluated'].' distinct player/game/market observations.');
        $this->table(['Market', 'N', 'Matched legacy MAE', 'Opportunity MAE', 'Stabilized MAE', 'MAE improvement', 'Legacy units', 'Opportunity units'],
            collect($result['markets'])->map(fn ($row, $market) => [$market, $row['n'], $row['old_mae'], $row['new_mae'], $row['stabilized_mae'],
                $row['mae_improvement'], $row['old_bets']['units'], $row['new_bets']['units']])->values()->all());
        if (isset($result['date_split'])) {
            $split = $result['date_split'];
            $this->line('Validation begins '.$split['validation_from'].'; evaluated observations: '.$split['validation']['coverage']['evaluated']);
            $this->line($split['policy']);
            foreach (['development', 'validation'] as $period) {
                $this->info(ucfirst($period).' period');
                $this->table(['Market', 'N', 'Matched legacy MAE', 'Opportunity MAE', 'Stabilized MAE'],
                    collect($split[$period]['markets'])->map(fn ($row, $market) => [$market, $row['n'], $row['old_mae'], $row['new_mae'], $row['stabilized_mae']])->values()->all());
            }
        }
        foreach ($result['limitations'] as $limitation) {
            $this->line($limitation);
        }

        return self::SUCCESS;
    }
}
