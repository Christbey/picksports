<?php

namespace App\Application\Sports\ReadModels;

use App\Models\CanonicalPrediction;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

final readonly class BettingForecast
{
    /** @param array<string, mixed> $modelMetadata */
    public function __construct(
        public Model $source,
        public ?Model $game,
        public ?float $predictedSpread = null,
        public ?float $predictedTotal = null,
        public ?float $winProbability = null,
        public ?float $confidenceScore = null,
        public array $modelMetadata = [],
        public ?float $vegasSpread = null,
        public ?float $homePitcherElo = null,
        public ?float $awayPitcherElo = null,
        public ?float $homeTeamElo = null,
        public ?float $awayTeamElo = null,
        public ?float $restDaysHome = null,
        public ?float $restDaysAway = null,
        public ?float $homeAwaySplitAdjustment = null,
        public ?float $turnoverDifferentialAdjustment = null,
        public ?float $reboundMarginAdjustment = null,
        public ?float $injurySpreadAdjustment = null,
        public ?float $homeInjuriesQuestionable = null,
        public ?float $awayInjuriesQuestionable = null,
        public ?float $livePredictedSpread = null,
        public ?float $livePredictedTotal = null,
        public ?float $liveWinProbability = null,
        public ?float $liveOutsRemaining = null,
        public ?CarbonInterface $liveUpdatedAt = null,
        public ?float $marketHomeMargin = null,
        public ?float $marketTotal = null,
    ) {}

    public function season(): int
    {
        return $this->isCanonical() ? (int) $this->game?->season : (int) $this->source->getAttribute('season');
    }

    public function isCanonical(): bool
    {
        return $this->source instanceof CanonicalPrediction;
    }

    public function legacyPredictionId(): ?int
    {
        return $this->isCanonical() ? null : (int) $this->source->getKey();
    }

    public function canonicalPredictionId(): ?int
    {
        return $this->isCanonical() ? (int) $this->source->getKey() : null;
    }
}
