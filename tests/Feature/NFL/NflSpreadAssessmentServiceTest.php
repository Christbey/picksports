<?php

use App\Models\NFL\Game;
use App\Models\SportEvent;
use App\Services\NFL\NflSpreadAssessmentService;
use App\Services\NFL\NflSpreadProbability;
use App\Services\NFL\NflSpreadValidationDataset;
use Carbon\Carbon;

it('uses only completed same-version frozen history available before this forecast', function () {
    $this->travelTo(Carbon::parse('2026-10-10T20:00:00Z'));
    $game = new Game(['season' => 2026]);
    $game->id = 100;
    $game->setRelation('sportEvent', new SportEvent(['starts_at' => '2026-10-11T17:00:00Z']));
    $row = ['game_id' => 1, 'model_version' => 'v3', 'kickoff' => '2026-10-01T17:00:00Z',
        'generated_at' => '2026-10-01T15:00:00Z', 'result_available_at' => '2026-10-01T21:00:00Z',
        'actual_margin' => 7.0, 'model_margin' => 4.0];
    $dataset = Mockery::mock(NflSpreadValidationDataset::class);
    $dataset->shouldReceive('load')->once()->with(2021, 2026, 'v3')->andReturn(['rows' => [
        $row,
        array_replace($row, ['game_id' => 2, 'model_version' => 'v2']),
        array_replace($row, ['game_id' => 3, 'result_available_at' => '2026-10-10T21:00:00Z']),
        array_replace($row, ['game_id' => 4, 'generated_at' => '2026-10-01T18:00:00Z']),
        array_replace($row, ['game_id' => 5, 'result_available_at' => '2026-10-01T16:00:00Z']),
    ]]);
    $service = new NflSpreadAssessmentService($dataset, new NflSpreadProbability);
    foreach ([1, 2] as $repeat) {
        expect($service->assess($game, -8.8, 2.5, 'v3'))->toMatchArray([
            'sample_size' => 1, 'additional_samples_required' => 199,
            'cover_probability' => null, 'bet_eligible' => false,
            'history_source' => 'frozen_observed_pregame_same_model',
        ]);
    }
});

it('does not use present-day history for historical forecasts or unknown kickoff', function () {
    $dataset = Mockery::mock(NflSpreadValidationDataset::class);
    $dataset->shouldNotReceive('load');
    $game = new Game(['season' => 2025]);
    $game->setRelation('sportEvent', null);
    $service = new NflSpreadAssessmentService($dataset, new NflSpreadProbability);
    expect($service->assess($game, 5, -3, 'v3')['reason'])->toBe('requires_future_canonical_kickoff');
    $game->setRelation('sportEvent', new SportEvent(['starts_at' => now()->subDay()]));
    expect($service->assess($game, 5, -3, 'v3')['reason'])->toBe('requires_future_canonical_kickoff');
});
