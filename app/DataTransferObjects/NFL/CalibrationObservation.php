<?php

namespace App\DataTransferObjects\NFL;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;

/** Immutable values from a verified pregame snapshot, never a mutable prediction row. */
final readonly class CalibrationObservation
{
    /** @param array<string, mixed> $calibrationInputs */
    public function __construct(
        public int $snapshotId,
        public CarbonImmutable $generatedAt,
        public CarbonImmutable $featuresAvailableAt,
        public CalibrationGameResult $game,
        public string $predicted_spread,
        public string $predicted_total,
        public string $win_probability,
        public array $calibrationInputs,
    ) {}

    /**
     * Preserve the former Eloquent decimal casts so the representation change cannot alter calculations.
     *
     * @param  array<string, mixed>  $outputs
     */
    public static function fromOutputs(
        int $snapshotId,
        CarbonImmutable $generatedAt,
        CarbonImmutable $featuresAvailableAt,
        CalibrationGameResult $game,
        array $outputs,
    ): self {
        return new self(
            $snapshotId, $generatedAt, $featuresAvailableAt, $game,
            self::decimal($outputs['predicted_spread'], 1),
            self::decimal($outputs['predicted_total'], 1),
            self::decimal($outputs['win_probability'], 3),
            $outputs['calibration_inputs'] ?? [],
        );
    }

    public function forStage(string $stage): ?self
    {
        if ($stage === 'published') {
            return $this;
        }
        $inputs = $this->calibrationInputs[$stage] ?? [];
        $fields = $stage === 'point' ? ['predicted_spread', 'predicted_total'] : ['win_probability'];
        foreach ($fields as $field) {
            if (! is_numeric($inputs[$field] ?? null) || ! is_finite((float) $inputs[$field])) {
                return null;
            }
        }

        return new self(
            $this->snapshotId, $this->generatedAt, $this->featuresAvailableAt, $this->game,
            $stage === 'point' ? self::decimal($inputs['predicted_spread'], 1) : $this->predicted_spread,
            $stage === 'point' ? self::decimal($inputs['predicted_total'], 1) : $this->predicted_total,
            $stage === 'point' ? $this->win_probability : self::decimal($inputs['win_probability'], 3),
            $this->calibrationInputs,
        );
    }

    private static function decimal(int|float|string $value, int $scale): string
    {
        return (string) BigDecimal::of($value)->toScale($scale, RoundingMode::HALF_UP);
    }
}
