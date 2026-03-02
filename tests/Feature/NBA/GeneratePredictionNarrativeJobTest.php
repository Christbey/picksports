<?php

use App\Jobs\NBA\GeneratePredictionNarrative;
use App\Models\NBA\Game;
use App\Models\NBA\Prediction;
use App\Models\NBA\Team;
use Illuminate\Support\Facades\Bus;

test('nba narrative job persists narrative payload and generation metadata', function () {
    config()->set('nba.prediction.narrative.provider', 'template');
    config()->set('services.openai.api_key', null);

    $home = Team::factory()->create([
        'location' => 'New York',
        'name' => 'Knicks',
        'abbreviation' => 'NYK',
    ]);
    $away = Team::factory()->create([
        'location' => 'San Antonio',
        'name' => 'Spurs',
        'abbreviation' => 'SAS',
    ]);

    $game = Game::factory()->create([
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
    ]);

    $prediction = Prediction::query()->create([
        'game_id' => $game->id,
        'predicted_spread' => 3.8,
        'predicted_total' => 227.3,
        'win_probability' => 0.72,
        'confidence_score' => 72.1,
        'home_off_eff' => 116.2,
        'away_off_eff' => 116.1,
        'home_def_eff' => 112.5,
        'away_def_eff' => 113.0,
        'home_recent_form' => 3.071,
        'away_recent_form' => -8.525,
    ]);

    Bus::dispatchSync(new GeneratePredictionNarrative($prediction->id, true));

    $prediction->refresh();

    expect($prediction->narrative_json)->toBeArray()
        ->and($prediction->narrative_json)->toHaveKeys(['summary', 'key_points', 'risk_note', 'generated_by', 'betting_plan'])
        ->and($prediction->narrative_input_hash)->not->toBeEmpty()
        ->and($prediction->narrative_generated_at)->not->toBeNull()
        ->and($prediction->narrative_latency_ms)->toBeInt();
});
