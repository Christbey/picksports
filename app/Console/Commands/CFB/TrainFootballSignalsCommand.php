<?php

namespace App\Console\Commands\CFB;

use App\Models\SportEvent;
use App\Services\CFB\Signals\CfbFootballSignalArtifactStore;
use App\Services\CFB\Signals\CfbFootballSignalHistoricalTrainer;
use App\Services\CFB\Signals\CfbFootballSignalJointModel;
use App\Services\CFB\Signals\CfbFootballSignalModel;
use App\Services\Predictions\CalculationReleaseSelector;
use App\Services\Predictions\CanonicalPayloadHasher;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class TrainFootballSignalsCommand extends Command
{
    protected $signature = 'cfb:train-football-signals {--from-season=} {--to-season=} {--if-missing : Recover absent evidence for the active production release}';

    protected $description = 'Reconstruct prior-game football trends and cache explicitly retrospective training evidence';

    public function handle(CfbFootballSignalHistoricalTrainer $trainer): int
    {
        $lock = Cache::lock('cfb:signal-training', 1800);
        if (! $lock->get()) {
            $this->warn('CFB signal training is already running.');

            return self::FAILURE;
        }
        try {
            return $this->train($trainer);
        } finally {
            $lock->release();
        }
    }

    private function train(CfbFootballSignalHistoricalTrainer $trainer): int
    {
        $to = $this->option('to-season') ?? now()->year - 1;
        $from = $this->option('from-season') ?? $to - 3;
        if (! is_numeric($from) || ! is_numeric($to) || $from < 2001 || $to < $from || $to >= now()->year) {
            $this->error('Use completed prior seasons, with 2001 <= from-season <= to-season < current year.');

            return self::FAILURE;
        }
        $release = app(CalculationReleaseSelector::class)->select(new SportEvent(['sport' => 'cfb']), 'pregame');
        $configuration = $release->configuration;
        $existing = app(CfbFootballSignalArtifactStore::class)->load($configuration);
        if ($this->option('if-missing') && ! empty($existing['source_game_ids'])
            && CarbonImmutable::parse($existing['available_at'])->lte(CarbonImmutable::now())
            && CarbonImmutable::parse($existing['available_at'])->gte(CarbonImmutable::now()->subDays(8))) {
            app(CfbFootballSignalArtifactStore::class)->save($configuration, $existing);
            $this->line(json_encode(['status' => 'ready', 'release_id' => $release->id, 'games' => count($existing['source_game_ids'])]));

            return self::SUCCESS;
        }
        // Historical reconstruction is an offline operation, bounded independently of web requests.
        ini_set('memory_limit', '512M');
        $artifact = $trainer->train($configuration, (int) $from, (int) $to,
            CarbonImmutable::now(), fn ($count) => $this->line("Reconstructed {$count} games."));
        Cache::forget('cfb:football-signal-evidence:'.app(CanonicalPayloadHasher::class)->hash($configuration).':'.$release->semantic_version);
        $fits = array_map(fn ($rows) => app(CfbFootballSignalModel::class)->fit(array_values($rows)), $artifact['observations']);
        $observedRules = array_fill_keys(array_keys($artifact['observations']), true);
        foreach ($artifact['joint_observations'] as $row) {
            foreach ($row['features'] as $features) {
                foreach ($features as $id => $value) {
                    $observedRules[$id] = true;
                }
            }
        }
        $this->line(json_encode(['release_id' => $release->id, 'release_version' => $release->semantic_version, 'games' => count($artifact['source_game_ids']), 'rules_with_observations' => count($observedRules),
            'fit_statuses' => array_count_values(array_column($fits, 'status')),
            'supported' => array_filter($fits, fn ($fit) => $fit['status'] === 'validated_residual'),
            'joint_models' => collect(['spread', 'total'])->mapWithKeys(fn ($market) => [$market => app(CfbFootballSignalJointModel::class)->fit(array_values($artifact['joint_observations']), $market, (float) data_get($configuration, 'football_signals.maximum_'.$market.'_adjustment'))])->all(),
            'limitations' => $artifact['limitations']], JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }
}
