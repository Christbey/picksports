<?php

namespace App\Console\Commands\CFB;

use App\Models\CFB\Game;
use App\Services\CFB\Data\CfbDataReadiness;
use Illuminate\Console\Command;

class AuditGameDataCommand extends Command
{
    protected $signature = 'cfb:audit-game-data {--season=} {--game=} {--report=}';

    protected $description = 'Read-only completeness audit; existing rows without accepted source evidence remain unverified';

    public function handle(CfbDataReadiness $readiness): int
    {
        if (! $this->option('season') && ! $this->option('game')) {
            $this->error('Specify season or game');

            return self::FAILURE;
        }
        $rows = [];
        foreach (Game::where('status', 'STATUS_FINAL')->when($this->option('season'), fn ($q, $v) => $q->where('season', $v))
            ->when($this->option('game'), fn ($q, $v) => $q->whereKey($v))->lazyById(100) as $game) {
            $rows[] = ['game_id' => $game->id, ...$readiness->forGame($game)];
        }
        $report = ['games' => $rows, 'ready' => count(array_filter($rows, fn ($r) => $r['ready'])), 'total' => count($rows)];
        $json = json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        if ($this->option('report')) {
            file_put_contents($this->option('report'), $json);
        }
        $this->line($json);

        return $report['ready'] === $report['total'] ? self::SUCCESS : 2;
    }
}
