<?php

namespace App\Services\Predictions;

use App\Exceptions\Predictions\PredictionLifecycleException;
use App\Models\CalculationRelease;
use App\Models\SportEvent;
use Carbon\CarbonImmutable;

class CalculationReleaseSelector
{
    public function select(
        SportEvent $event,
        string $phase,
        ?CarbonImmutable $effectiveAt = null,
    ): CalculationRelease {
        $effectiveAt ??= now()->toImmutable();

        $releases = CalculationRelease::query()
            ->where('sport', $event->sport)
            ->where('phase', $phase)
            ->whereIn('status', ['approved', 'retired'])
            ->whereNotNull('effective_at')
            ->where('effective_at', '<=', $effectiveAt)
            ->where(function ($query) use ($effectiveAt): void {
                $query->whereNull('retired_at')->orWhere('retired_at', '>', $effectiveAt);
            })
            ->orderByDesc('effective_at')
            ->orderByDesc('id')
            ->get();

        if ($releases->count() !== 1) {
            throw new PredictionLifecycleException(sprintf(
                'Expected exactly one approved %s %s calculation release; found %d.',
                $event->sport,
                $phase,
                $releases->count(),
            ));
        }

        return $releases->sole();
    }
}
