<?php

namespace App\Services\NFL;

use App\Models\NFL\Game;
use Carbon\CarbonImmutable;
use Illuminate\Container\Attributes\Scoped;

#[Scoped]
class NflSpreadAssessmentService
{
    private array $datasets = [];

    public function __construct(private NflSpreadValidationDataset $dataset, private NflSpreadProbability $probability) {}

    public function assess(Game $game, float $margin, float $homeLine, string $modelVersion): array
    {
        $asOf = CarbonImmutable::now();
        $kickoff = $game->sportEvent?->starts_at;
        if ($kickoff === null || $kickoff->lte($asOf)) {
            return [...$this->probability->estimate([], $margin, $homeLine),
                'reason' => 'requires_future_canonical_kickoff', 'model_version' => $modelVersion];
        }

        $key = $game->season.':'.$modelVersion;
        $data = $this->datasets[$key] ??= $this->dataset->load(max(2000, (int) $game->season - 5), (int) $game->season, $modelVersion);
        $residuals = [];
        foreach ($data['rows'] as $row) {
            $start = CarbonImmutable::parse($row['kickoff']);
            $generated = CarbonImmutable::parse($row['generated_at']);
            $available = CarbonImmutable::parse($row['result_available_at']);
            if ($row['game_id'] === $game->id || $row['model_version'] !== $modelVersion
                || $generated->gte($start) || $start->gte($kickoff)
                || $available->lte($start) || $available->gte($asOf)) {
                continue;
            }
            $residuals[] = $row['actual_margin'] - $row['model_margin'];
        }

        return [...$this->probability->estimate($residuals, $margin, $homeLine),
            'model_version' => $modelVersion, 'evaluated_at' => $asOf->toIso8601String(),
            'history_source' => 'frozen_observed_pregame_same_model',
            'validation_status' => count($residuals) < NflSpreadProbability::MINIMUM_RESIDUALS
                ? 'insufficient_training_history' : 'held_out_validation_required'];
    }
}
