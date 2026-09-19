<?php

namespace App\Console\Commands\CFB;

use App\Services\CFB\Predictions\CfbCalculationReleaseDefinition;
use App\Services\CFB\Signals\CfbFootballSignalHistoricalTrainer;
use App\Services\CFB\Signals\CfbFootballSignalModel;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class TrainFootballSignalsCommand extends Command
{
    protected $signature = 'cfb:train-football-signals {--from-season=} {--to-season=}';

    protected $description = 'Reconstruct prior-game football trends and cache explicitly retrospective training evidence';

    public function handle(CfbFootballSignalHistoricalTrainer $trainer): int
    {
        $to = $this->option('to-season') ?? now()->year - 1;
        $from = $this->option('from-season') ?? $to - 3;
        if (! is_numeric($from) || ! is_numeric($to) || $from < 2001 || $to < $from || $to >= now()->year) {
            $this->error('Use completed prior seasons, with 2001 <= from-season <= to-season < current year.');

            return self::FAILURE;
        }
        $artifact = $trainer->train(app(CfbCalculationReleaseDefinition::class)->configuration(), (int) $from, (int) $to,
            CarbonImmutable::now(), fn ($count) => $this->line("Reconstructed {$count} games."));
        $fits = array_map(fn ($rows) => app(CfbFootballSignalModel::class)->fit(array_values($rows)), $artifact['observations']);
        $this->line(json_encode(['games' => count($artifact['source_game_ids']), 'rules_with_observations' => count($fits),
            'fit_statuses' => array_count_values(array_column($fits, 'status')),
            'supported' => array_filter($fits, fn ($fit) => $fit['status'] === 'validated_residual'),
            'limitations' => $artifact['limitations']], JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }
}
