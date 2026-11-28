<?php

use App\Models\SportsGameContextReport;
use App\Services\Predictions\SportsExternalGameContextBuilder;
use Tests\Support\PredictionReviewFixtures;

it('never applies the NFL scenario probability conversion to another sport', function (string $sport) {
    $this->travelTo('2026-11-20 12:00:00 America/Chicago');
    [$game, $prediction] = PredictionReviewFixtures::game($sport);
    SportsGameContextReport::create([
        'sport' => $sport, 'game_id' => $game->id, 'game_date' => $game->game_date,
        'status' => 'partial', 'provider' => 'stored', 'model' => 'descriptive',
        'prompt_version' => 'fixture', 'input_hash' => hash('sha256', $sport),
        'confidence' => 0, 'summary' => 'Stored descriptive context only.',
        'facts' => [], 'sources' => [], 'researched_at' => now(), 'expires_at' => now()->addHour(),
    ]);

    $context = app(SportsExternalGameContextBuilder::class)->build($sport, $game, $prediction);

    expect($context['available'])->toBeTrue()
        ->and($context['context_adjusted_model'])->toBeNull()
        ->and($context['deterministic_adjustment'])->toBe([
            'home_margin_points' => 0.0, 'total_points' => 0.0, 'components' => [],
        ]);
    $this->travelBack();
})->with(['nba', 'mlb', 'cbb', 'wcbb', 'wnba', 'cfb']);
