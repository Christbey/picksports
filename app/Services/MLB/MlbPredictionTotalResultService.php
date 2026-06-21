<?php

namespace App\Services\MLB;

use App\Models\MLB\Prediction;

class MlbPredictionTotalResultService
{
    /**
     * @return array{total_pick_side: string|null, total_pick_line: float|null, total_pick_result: string|null, total_pick_edge: float|null}
     */
    public function result(Prediction $prediction, float $actualTotal): array
    {
        $predictedTotal = $this->numeric($prediction->predicted_total);
        $marketTotal = $this->marketTotal($prediction);

        if ($predictedTotal === null || $marketTotal === null) {
            return $this->emptyResult();
        }

        $edge = round($predictedTotal - $marketTotal, 2);
        $side = match (true) {
            $edge > 0 => 'over',
            $edge < 0 => 'under',
            default => null,
        };

        if ($side === null) {
            return [
                'total_pick_side' => null,
                'total_pick_line' => round($marketTotal, 2),
                'total_pick_result' => null,
                'total_pick_edge' => 0.0,
            ];
        }

        $result = match (true) {
            round($actualTotal, 1) === round($marketTotal, 1) => 'push',
            $side === 'over' => $actualTotal > $marketTotal ? 'win' : 'loss',
            default => $actualTotal < $marketTotal ? 'win' : 'loss',
        };

        return [
            'total_pick_side' => $side,
            'total_pick_line' => round($marketTotal, 2),
            'total_pick_result' => $result,
            'total_pick_edge' => $edge,
        ];
    }

    /**
     * @return array{total_pick_side: null, total_pick_line: null, total_pick_result: null, total_pick_edge: null}
     */
    public function emptyResult(): array
    {
        return [
            'total_pick_side' => null,
            'total_pick_line' => null,
            'total_pick_result' => null,
            'total_pick_edge' => null,
        ];
    }

    private function marketTotal(Prediction $prediction): ?float
    {
        $metadata = is_array($prediction->model_metadata) ? $prediction->model_metadata : [];

        return $this->numeric(data_get($metadata, 'market_context.market_total'));
    }

    private function numeric(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}
