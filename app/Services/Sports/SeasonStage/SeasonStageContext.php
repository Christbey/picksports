<?php

namespace App\Services\Sports\SeasonStage;

use App\Services\Sports\DateWindow;
use Carbon\CarbonImmutable;

class SeasonStageContext
{
    /**
     * @param  array<int, int>  $activeGameIds
     * @param  array<int, int>  $visibleGameIds
     * @param  array<int, int>  $remainingTeamIds
     * @param  array<int, int>  $eliminatedTeamIds
     * @param  array<string, mixed>  $seriesContext
     * @param  array<string, mixed>  $dataExpectations
     */
    public function __construct(
        public readonly string $sport,
        public readonly ?int $season,
        public readonly CarbonImmutable $asOf,
        public readonly string $stage,
        public readonly string $stageGroup,
        public readonly DateWindow $activeWindow,
        public readonly array $activeGameIds,
        public readonly array $visibleGameIds,
        public readonly array $remainingTeamIds = [],
        public readonly array $eliminatedTeamIds = [],
        public readonly array $seriesContext = [],
        public readonly array $dataExpectations = [],
    ) {}

    public function isChampionship(): bool
    {
        return $this->stageGroup === 'championship';
    }

    public function isPostseason(): bool
    {
        return in_array($this->stageGroup, ['postseason', 'championship'], true);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'sport' => $this->sport,
            'season' => $this->season,
            'as_of' => $this->asOf->toIso8601String(),
            'stage' => $this->stage,
            'stage_group' => $this->stageGroup,
            'active_window' => $this->activeWindow->toArray(),
            'active_game_ids' => $this->activeGameIds,
            'visible_game_ids' => $this->visibleGameIds,
            'remaining_team_ids' => $this->remainingTeamIds,
            'eliminated_team_ids' => $this->eliminatedTeamIds,
            'series_context' => $this->seriesContext,
            'data_expectations' => $this->dataExpectations,
        ];
    }
}
