<?php

use App\Models\CalculationRun;
use App\Models\CanonicalPrediction;
use App\Models\EventInputSnapshot;
use App\Models\PredictionMarket;
use App\Models\SportEvent;
use App\Models\User;

it('keeps every prediction endpoint on the selected source without legacy fallback', function (string $sport) {
    $namespace = strtoupper($sport);
    $gameClass = "App\\Models\\{$namespace}\\Game";
    $teamClass = "App\\Models\\{$namespace}\\Team";
    $predictionClass = "App\\Models\\{$namespace}\\Prediction";
    $event = SportEvent::factory()->create(['sport' => $sport, 'season' => 2026, 'starts_at' => now()->addDay()]);
    $game = $gameClass::factory()->create([
        'sport_event_id' => $event->id, 'season' => 2026,
        'home_team_id' => $teamClass::factory(), 'away_team_id' => $teamClass::factory(),
        'status' => 'STATUS_SCHEDULED', 'game_date' => now()->addDay()->utc()->toDateString(),
        'game_time' => now()->addDay()->utc()->toTimeString(),
    ]);
    $legacy = $sport === 'mlb'
        ? $predictionClass::query()->create(['game_id' => $game->id, 'season' => 2026])
        : $predictionClass::factory()->create(['game_id' => $game->id]);
    $user = User::factory()->create();
    config([
        'subscriptions.tier_bypass_user_ids' => [$user->id],
        "prediction_lifecycle.canonical_reads.{$sport}" => false,
        "prediction_lifecycle.canonical_pipeline.{$sport}" => true,
    ]);
    $this->actingAs($user);
    $base = "/api/v2/sports/{$sport}";

    $this->getJson("{$base}/predictions?season=2026")->assertOk()
        ->assertJsonPath('data.0.id', $legacy->id)->assertJsonMissingPath('meta.prediction_source');
    $this->getJson("{$base}/predictions/{$legacy->id}")->assertOk()->assertJsonPath('data.id', $legacy->id);
    $this->getJson("{$base}/games/{$game->id}/prediction")->assertOk()
        ->assertJsonPath('meta.prediction_source', 'legacy');

    config(["prediction_lifecycle.canonical_reads.{$sport}" => true]);

    $this->getJson("{$base}/predictions?season=2026")->assertOk()->assertJsonCount(0, 'data');
    $this->getJson("{$base}/predictions/{$legacy->id}")->assertNotFound();
    $this->getJson("{$base}/games/{$game->id}/prediction")->assertNotFound();
    $this->getJson("{$base}/predictions/available-seasons")->assertOk()->assertJsonCount(0, 'data');
    $this->getJson("{$base}/predictions/available-dates?season=2026")->assertOk()->assertJsonCount(0, 'data');

    if (in_array($sport, ['nfl', 'mlb'], true)) {
        $this->getJson("{$base}/games/{$game->id}/page")->assertOk()->assertJsonPath('data.prediction', null);
    }

    $snapshot = EventInputSnapshot::factory()->create(['sport' => $sport, 'sport_event_id' => $event->id]);
    $run = CalculationRun::factory()->create([
        'sport_event_id' => $event->id, 'event_input_snapshot_id' => $snapshot->id, 'status' => 'succeeded',
    ]);
    $canonical = CanonicalPrediction::factory()->create([
        'sport' => $sport, 'sport_event_id' => $event->id, 'calculation_run_id' => $run->id,
        'phase' => 'pregame', 'publication_state' => 'draft', 'published_at' => null,
        'detail_source' => null, 'detail_sport' => null, 'detail_id' => null,
    ]);
    PredictionMarket::factory()->create(['prediction_id' => $canonical->id, 'selection' => 'home', 'probability' => 0.65]);
    PredictionMarket::factory()->create(['prediction_id' => $canonical->id, 'market_type' => 'spread', 'selection' => 'home', 'projected_line' => -3.5]);
    PredictionMarket::factory()->create(['prediction_id' => $canonical->id, 'market_type' => 'total', 'selection' => 'combined', 'projected_line' => 45.5]);

    $this->getJson("{$base}/games/{$game->id}/prediction")->assertNotFound();
    $canonical->update(['publication_state' => 'published', 'published_at' => now()]);

    $this->getJson("{$base}/predictions?season=2026")->assertOk()
        ->assertJsonPath('data.0.id', $canonical->public_id)->assertJsonPath('meta.prediction_source', 'canonical');
    $this->getJson("{$base}/predictions/{$canonical->public_id}")->assertOk()
        ->assertJsonPath('data.id', $canonical->public_id)->assertJsonPath('meta.prediction_source', 'canonical');
    $this->getJson("{$base}/games/{$game->id}/prediction")->assertOk()
        ->assertJsonPath('data.id', $canonical->public_id)->assertJsonPath('meta.game_id', $game->id);
    $this->getJson("{$base}/predictions/available-seasons")->assertOk()
        ->assertJsonPath('data.0', 2026)->assertJsonPath('meta.prediction_source', 'canonical');
    $this->getJson("{$base}/predictions/available-dates?season=2026")->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('meta.prediction_source', 'canonical');

    if (in_array($sport, ['nfl', 'mlb'], true)) {
        $this->getJson("{$base}/games/{$game->id}/page")->assertOk()
            ->assertJsonPath('data.prediction.id', $canonical->public_id)
            ->assertJsonPath('meta.prediction_source', 'canonical');
    }

    config(["prediction_lifecycle.canonical_reads.{$sport}" => false]);
    $this->getJson("{$base}/games/{$game->id}/prediction")->assertOk()
        ->assertJsonPath('data.id', $legacy->id)->assertJsonPath('meta.prediction_source', 'legacy');
})->with(['nba', 'nfl', 'mlb', 'cbb', 'cfb', 'wcbb', 'wnba']);
