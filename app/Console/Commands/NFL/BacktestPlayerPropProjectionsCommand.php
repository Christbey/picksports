<?php

namespace App\Console\Commands\NFL;

use App\Services\BettingRecommendations\NflPropProjectionBacktest;
use Illuminate\Console\Command;

class BacktestPlayerPropProjectionsCommand extends Command
{
    protected $signature = 'nfl:backtest-player-prop-projections
        {--season= : Required NFL season}
        {--through= : Last completed-game date, defaults to today in Chicago}
        {--min-games=3 : Minimum reported prior games}
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
        $result = ['season' => $season, 'through' => $through, ...$backtest->run($season, $through, $minGames)];
        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }
        $this->info('NFL prop baseline comparison — read only');
        $this->line('Evaluated: '.$result['coverage']['evaluated'].' distinct player/game/market observations.');
        $this->table(['Market', 'N', 'Legacy MAE', 'Opportunity MAE', 'MAE improvement', 'Legacy units', 'Opportunity units'],
            collect($result['markets'])->map(fn ($row, $market) => [$market, $row['n'], $row['old_mae'], $row['new_mae'],
                $row['mae_improvement'], $row['old_bets']['units'], $row['new_bets']['units']])->values()->all());
        foreach ($result['limitations'] as $limitation) {
            $this->line($limitation);
        }

        return self::SUCCESS;
    }
}
