<?php

namespace App\Console\Commands\MLB;

use App\Models\MLB\Game;
use App\Services\MLB\MlbPredictionCalculationAuditService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class ExplainPredictionCommand extends Command
{
    protected $signature = 'mlb:explain-prediction
        {game_id : MLB game id}
        {--as-of= : Explanation timestamp in ISO or Y-m-d H:i:s format}
        {--json : Output structured JSON}
        {--historical : Treat the selected prediction as historical context}
        {--no-write : Accepted for safety; this command is read-only by default}';

    protected $description = 'Explain one MLB prediction calculation from stored inputs, metadata, and outputs.';

    public function handle(MlbPredictionCalculationAuditService $audit): int
    {
        $game = Game::query()
            ->with(['homeTeam', 'awayTeam', 'prediction', 'weather'])
            ->find((int) $this->argument('game_id'));

        if (! $game) {
            $this->error('MLB game not found.');

            return self::FAILURE;
        }

        $prediction = $game->prediction;
        if (! $prediction) {
            $this->warn('No stored MLB prediction exists for this game. Generate one first; this explain command does not mutate by default.');

            return self::FAILURE;
        }

        $asOf = $this->option('as-of')
            ? Carbon::parse((string) $this->option('as-of'))
            : now();

        $explanation = $audit->explain($prediction, $asOf);

        if ($this->option('historical')) {
            $explanation['phase'] = 'historical';
        }

        if ($this->option('json')) {
            $this->line(json_encode($explanation, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info('MLB Prediction Explanation');
        $this->line('Game: '.$explanation['game_id'].' | '.$explanation['teams']['away'].' @ '.$explanation['teams']['home']);
        $this->line('Date: '.($explanation['game']['date'] ?? 'unknown').' | Status: '.($explanation['game']['status'] ?? 'unknown').' | Phase: '.$explanation['phase']);
        $this->line('Versions: model '.($explanation['versions']['model_version'] ?? 'missing').', feature '.($explanation['versions']['feature_version'] ?? 'missing').', blend '.($explanation['versions']['blend_version'] ?? 'missing'));
        $this->newLine();

        $this->table(
            ['Input', 'Home', 'Away'],
            [
                ['Team Elo', $explanation['inputs']['team_elo']['home'], $explanation['inputs']['team_elo']['away']],
                ['Pitcher Elo', $explanation['inputs']['pitcher_elo']['home'], $explanation['inputs']['pitcher_elo']['away']],
                ['Combined Elo', $explanation['inputs']['team_elo']['home_combined'], $explanation['inputs']['team_elo']['away_combined']],
                ['Pitcher Source', data_get($explanation, 'inputs.pitcher_elo.source.home_source'), data_get($explanation, 'inputs.pitcher_elo.source.away_source')],
            ]
        );

        $this->table(
            ['Adjustment', 'Value'],
            collect($explanation['adjustments'])
                ->map(fn (mixed $value, string $key): array => [$key, is_array($value) ? json_encode($value) : $value])
                ->values()
                ->all()
        );

        $this->table(
            ['Output', 'Value'],
            collect($explanation['outputs'])
                ->map(fn (mixed $value, string $key): array => [$key, is_array($value) ? json_encode($value) : $value])
                ->values()
                ->all()
        );

        $warnings = $explanation['safety']['warnings'] ?? [];
        $failures = $explanation['safety']['hard_failures'] ?? [];

        if ($warnings !== []) {
            $this->warn('Warnings: '.implode(', ', $warnings));
        }

        if ($failures !== []) {
            $this->error('Hard failures: '.implode(', ', $failures));

            return self::FAILURE;
        }

        $this->info('Prediction calculation invariants passed.');

        return self::SUCCESS;
    }
}
