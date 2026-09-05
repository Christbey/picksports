<?php

use App\Models\NBA\Game;
use App\Models\NBA\Prediction;
use App\Models\NBA\Team;
use App\Models\SportEvent;
use App\Models\User;
use App\Models\UserBet;
use Illuminate\Support\Facades\Artisan;

function normalizedUserBetPayload(array $overrides = []): array
{
    return array_merge([
        'bet_amount' => 100,
        'odds' => '-110',
        'bet_type' => 'spread',
        'selection_side' => 'home',
        'selection_label' => 'Home -3.5',
        'line' => -3.5,
    ], $overrides);
}

it('rejects php model classes from the external user bet contract', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/api/v2/user-bets', normalizedUserBetPayload([
            'prediction_id' => 123,
            'prediction_type' => Prediction::class,
        ]))
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonValidationErrors('prediction_type');

    $this->actingAs($user)
        ->postJson('/api/v2/user-bets', normalizedUserBetPayload([
            'prediction_id' => 123,
            'prediction_sport' => 'App\\Models\\NBA\\Prediction',
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('prediction_sport');

    expect(UserBet::query()->count())->toBe(0);
});

it('persists an allowlisted prediction reference without exposing its php class in v2', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->postJson('/api/v2/user-bets', normalizedUserBetPayload([
            'prediction_id' => 321,
            'prediction_sport' => 'nba',
        ]))
        ->assertCreated()
        ->assertJsonPath('data.prediction_id', 321)
        ->assertJsonPath('data.prediction_sport', 'nba')
        ->assertJsonPath('data.prediction_reference.sport', 'nba')
        ->assertJsonPath('data.prediction_reference.id', 321)
        ->assertJsonMissingPath('data.prediction_type');

    expect($response->json('data.prediction_reference.event_id'))->toBeNull();

    $this->assertDatabaseHas('user_bets', [
        'user_id' => $user->id,
        'prediction_id' => 321,
        'prediction_sport' => 'nba',
        'prediction_type' => Prediction::class,
        'sport_event_id' => null,
    ]);
});

it('links a resolvable prediction to its canonical sport event', function () {
    $user = User::factory()->create();
    $event = SportEvent::factory()->create(['sport' => 'nba']);
    $game = Game::factory()->create([
        'sport_event_id' => $event->id,
        'home_team_id' => Team::factory(),
        'away_team_id' => Team::factory(),
    ]);
    $prediction = Prediction::factory()->create(['game_id' => $game->id]);

    $this->actingAs($user)
        ->postJson('/api/v2/user-bets', normalizedUserBetPayload([
            'prediction_id' => $prediction->id,
            'prediction_sport' => 'nba',
        ]))
        ->assertCreated()
        ->assertJsonPath('data.prediction_reference.event_id', $event->public_id);

    $this->assertDatabaseHas('user_bets', [
        'prediction_sport' => 'nba',
        'prediction_id' => $prediction->id,
        'sport_event_id' => $event->id,
    ]);
});

it('reads and filters allowlisted legacy rows without dereferencing arbitrary classes', function () {
    $user = User::factory()->create();
    UserBet::factory()->create([
        'user_id' => $user->id,
        'prediction_id' => 99,
        'prediction_sport' => null,
        'prediction_type' => Prediction::class,
    ]);
    UserBet::factory()->create([
        'user_id' => $user->id,
        'prediction_id' => 99,
        'prediction_sport' => null,
        'prediction_type' => 'Vendor\\Untrusted\\Prediction',
    ]);

    $this->actingAs($user)
        ->getJson('/api/v2/user-bets?prediction_id=99&prediction_sport=nba')
        ->assertOk()
        ->assertJsonCount(1, 'bets.data')
        ->assertJsonPath('bets.data.0.prediction_sport', 'nba')
        ->assertJsonMissingPath('bets.data.0.prediction_type');
});

it('dry-runs and writes the legacy prediction reference backfill explicitly', function () {
    $event = SportEvent::factory()->create(['sport' => 'nba']);
    $game = Game::factory()->create([
        'sport_event_id' => $event->id,
        'home_team_id' => Team::factory(),
        'away_team_id' => Team::factory(),
    ]);
    $prediction = Prediction::factory()->create(['game_id' => $game->id]);
    $legacy = UserBet::factory()->create([
        'prediction_id' => $prediction->id,
        'prediction_sport' => null,
        'prediction_type' => Prediction::class,
        'sport_event_id' => null,
    ]);
    $unknown = UserBet::factory()->create([
        'prediction_id' => 999,
        'prediction_sport' => null,
        'prediction_type' => 'Vendor\\Untrusted\\Prediction',
        'sport_event_id' => null,
    ]);

    expect(Artisan::call('user-bets:backfill-prediction-references'))->toBe(0)
        ->and(Artisan::output())->toContain('dry-run', 'Dry run only')
        ->and($legacy->fresh()->prediction_sport)->toBeNull()
        ->and($unknown->fresh()->prediction_sport)->toBeNull();

    expect(Artisan::call('user-bets:backfill-prediction-references', ['--write' => true]))->toBe(0)
        ->and(Artisan::output())->toContain('write')
        ->and($legacy->fresh()->prediction_sport->value)->toBe('nba')
        ->and($legacy->fresh()->sport_event_id)->toBe($event->id)
        ->and($unknown->fresh()->prediction_sport)->toBeNull();
});
