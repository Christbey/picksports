<?php

namespace App\Services\MLB\Picks;

use Carbon\CarbonInterface;

final class MlbPickCandidateData
{
    /**
     * @param  list<string>  $reasonCodes
     * @param  list<string>  $riskFlags
     * @param  array<string,mixed>  $featureSnapshot
     * @param  array<string,mixed>  $marketSnapshot
     */
    public function __construct(
        public readonly int $gameId,
        public readonly ?int $predictionId,
        public readonly int $season,
        public readonly string $marketType,
        public readonly string $marketKey,
        public readonly string $side,
        public readonly ?float $line,
        public readonly ?int $price,
        public readonly ?string $book,
        public readonly ?float $modelProbability,
        public readonly ?float $marketProbability,
        public readonly ?float $noVigProbability,
        public readonly ?float $blendProbability,
        public readonly ?float $edgeRaw,
        public readonly ?float $edgeNoVig,
        public readonly ?float $projectedValue,
        public readonly array $reasonCodes,
        public readonly array $riskFlags,
        public readonly array $featureSnapshot,
        public readonly array $marketSnapshot,
        public readonly ?int $teamId = null,
        public readonly ?int $playerId = null,
        public readonly ?CarbonInterface $gameStartAt = null,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function toPayload(): array
    {
        return [
            'season' => $this->season,
            'game_id' => $this->gameId,
            'prediction_id' => $this->predictionId,
            'team_id' => $this->teamId,
            'player_id' => $this->playerId,
            'market_type' => $this->marketType,
            'market_key' => $this->marketKey,
            'side' => $this->side,
            'line' => $this->line,
            'price' => $this->price,
            'book' => $this->book,
            'market_probability' => $this->marketProbability,
            'no_vig_probability' => $this->noVigProbability,
            'model_probability' => $this->modelProbability,
            'blend_probability' => $this->blendProbability,
            'edge_raw' => $this->edgeRaw,
            'edge_no_vig' => $this->edgeNoVig,
            'projected_value' => $this->projectedValue,
            'reason_codes' => array_values(array_unique($this->reasonCodes)),
            'risk_flags' => array_values(array_unique($this->riskFlags)),
            'feature_snapshot' => $this->featureSnapshot,
            'market_snapshot' => $this->marketSnapshot,
            'game_start_at' => $this->gameStartAt,
        ];
    }
}
