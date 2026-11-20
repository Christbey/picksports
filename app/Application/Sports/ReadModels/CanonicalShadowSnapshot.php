<?php

namespace App\Application\Sports\ReadModels;

use Carbon\CarbonInterface;

final readonly class CanonicalShadowSnapshot
{
    public int $prediction_id;

    public function __construct(
        public int $id,
        public int $game_id,
        public array $features,
        public array $outputs,
        public array $market_context,
        public array $model_metadata,
        public string $feature_hash,
        public string $snapshot_run_id,
        public CarbonInterface $generated_at,
        public CarbonInterface $features_available_at,
        public CarbonInterface $game_start_at,
        public array $lineage,
        public bool $pregame_safe = true,
        public string $availability_status = 'observed_pregame',
        public string $prediction_table = 'predictions',
        public string $model_version = '',
        public string $feature_version = '',
        public string $blend_version = '',
    ) {
        $this->prediction_id = $id;
    }

    public function identity(): array
    {
        return ['prediction_feature_snapshot_id' => null, 'canonical_prediction_id' => $this->id];
    }
}
