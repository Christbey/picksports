<?php

use App\AI\Agents\SportsPredictionNarrativeAgent;
use App\Jobs\Predictions\GeneratePredictionNarrative;
use App\Models\NFL\Game;
use App\Models\NFL\Prediction;
use App\Models\NFL\Team;
use Illuminate\Support\Facades\Bus;

test('generic prediction narrative job persists nfl narrative payload and metadata', function () {
    config()->set('nba.prediction.narrative.provider', 'openai');
    config()->set('services.openai.api_key', 'test-openai-key');
    config()->set('ai.providers.openai.key', 'test-openai-key');
    config()->set('ai.features.sports_prediction_narratives.model', 'gpt-4o-mini');

    SportsPredictionNarrativeAgent::fake([
        [
            'summary' => 'NFL lean: Bears (61% win probability).',
            'key_points' => [
                'Matchup favors Chicago at home.',
                'Model win view leans Chicago.',
                'Projected spread suggests a tight edge.',
                'Confidence stays moderate.',
            ],
            'risk_note' => 'Risk note: market movement could narrow the edge.',
            'betting_plan' => [
                'bet_pick' => 'Bet Bears moneyline.',
                'reasoning' => 'The model gives Chicago the stronger win probability.',
            ],
            'social_caption' => 'NFL lean: Bears moneyline.',
        ],
    ])->preventStrayPrompts();

    $home = Team::factory()->create([
        'location' => 'Chicago',
        'name' => 'Bears',
        'abbreviation' => 'CHI',
    ]);
    $away = Team::factory()->create([
        'location' => 'Detroit',
        'name' => 'Lions',
        'abbreviation' => 'DET',
    ]);

    $game = Game::factory()->create([
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
        'status' => 'STATUS_SCHEDULED',
    ]);

    $prediction = Prediction::query()->create([
        'game_id' => $game->id,
        'home_elo' => 1510,
        'away_elo' => 1496,
        'predicted_spread' => 2.5,
        'predicted_total' => 44.5,
        'win_probability' => 0.61,
        'confidence_score' => 61.0,
    ]);

    Bus::dispatchSync(new GeneratePredictionNarrative(Prediction::class, $prediction->id, 'nfl', true));

    $prediction->refresh();

    expect($prediction->narrative_json)->toBeArray()
        ->and($prediction->narrative_json['generated_by'])->toBe('openai:gpt-4o-mini')
        ->and($prediction->narrative_json['betting_plan']['bet_pick'])->toBe('Bet Bears moneyline.')
        ->and($prediction->narrative_input_hash)->not->toBeEmpty()
        ->and($prediction->narrative_generated_at)->not->toBeNull();
});
