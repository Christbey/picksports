<?php

namespace App\Application\Sports\ReadModels;

use App\Models\NFL\Game;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/** Frozen calculation evidence with no persistence identity or model behavior. */
final readonly class NflCalibrationSample
{
    public function __construct(
        public Game $game,
        public string $predicted_spread,
        public string $predicted_total,
        public string $win_probability,
        public array $calibration_inputs = [],
        public array $canonical_lineage = [],
    ) {}

    public static function fromOutputs(array $outputs, Game $game, array $lineage = []): ?self
    {
        foreach (['predicted_spread', 'predicted_total', 'win_probability'] as $field) {
            if (! is_numeric($outputs[$field] ?? null) || ! is_finite((float) $outputs[$field])) {
                return null;
            }
        }
        if ((float) $outputs['win_probability'] <= 0 || (float) $outputs['win_probability'] >= 1) {
            return null;
        }

        return new self($game, self::decimal($outputs['predicted_spread'], 1), self::decimal($outputs['predicted_total'], 1),
            self::decimal($outputs['win_probability'], 3), (array) ($outputs['calibration_inputs'] ?? []), $lineage);
    }

    /** Apply validated stage inputs without changing the cached published sample. */
    public function withValues(array $values): self
    {
        return new self($this->game,
            self::decimal($values['predicted_spread'] ?? $this->predicted_spread, 1),
            self::decimal($values['predicted_total'] ?? $this->predicted_total, 1),
            self::decimal($values['win_probability'] ?? $this->win_probability, 3),
            $this->calibration_inputs, $this->canonical_lineage);
    }

    private static function decimal(mixed $value, int $places): string
    {
        return (string) BigDecimal::of((string) $value)->toScale($places, RoundingMode::HALF_UP);
    }
}
