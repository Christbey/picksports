<?php

use App\Models\CalculationRelease;
use App\Models\CalculationRun;
use App\Models\CanonicalPrediction;
use App\Models\EventInputSnapshot;
use App\Models\PredictionMarket;
use App\Models\SportEvent;
use App\Models\User;
use App\Services\Api\V2\SportContextResolver;
use App\Services\Api\V2\SportGameQuery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->travelTo('2026-10-29 12:00:00');
    Http::preventStrayRequests();
    config(['subscriptions.enforce_tiers' => false]);
});
afterEach(fn () => $this->travelBack());

function gameReadRetirementFixture(string $sport, string $variant = 'valid'): array
{
    config(["prediction_lifecycle.canonical_reads.{$sport}" => true]);
    $context = app(SportContextResolver::class)->resolve($sport);
    $gameClass = $context->models['game'];
    $teamClass = $context->models['team'];
    $legacyClass = $context->models['prediction'];
    $start = now()->addHours(2)->utc();
    $event = SportEvent::factory()->create(['sport' => $sport, 'season' => 2026, 'starts_at' => $start]);
    $game = $gameClass::factory()->create(['sport_event_id' => $event->id, 'season' => 2026,
        'season_type' => '2', 'game_date' => $start->toDateString(), 'game_time' => $start->toTimeString(),
        'status' => 'STATUS_SCHEDULED', 'home_team_id' => $teamClass::factory(), 'away_team_id' => $teamClass::factory()]);
    $legacy = $legacyClass::create(['game_id' => $game->id, 'season' => 2026, 'season_type' => '2',
        'home_team_elo' => 1500, 'away_team_elo' => 1500, 'predicted_spread' => -20,
        'predicted_total' => 199.0, 'win_probability' => 0.1]);
    $release = CalculationRelease::factory()->approved()->create(['sport' => $sport, 'phase' => 'pregame']);
    $input = EventInputSnapshot::factory()->create(['sport' => $sport, 'sport_event_id' => $event->id,
        'pregame_safety_status' => $variant === 'unsafe' ? 'unverified' : 'verified']);
    $run = CalculationRun::factory()->create(['sport_event_id' => $event->id,
        'calculation_release_id' => $release->id, 'event_input_snapshot_id' => $input->id, 'status' => 'succeeded']);
    $native = CanonicalPrediction::factory()->create(['sport' => $sport, 'sport_event_id' => $event->id,
        'calculation_run_id' => $run->id, 'phase' => 'pregame', 'publication_state' => 'draft',
        'published_at' => null, 'detail_source' => null, 'detail_sport' => null, 'detail_id' => null]);
    foreach ([['moneyline', 'home', null, 0.7], ['spread', 'home', -4.5, null], ['total', 'combined', 40.0, null]] as [$type, $side, $line, $probability]) {
        PredictionMarket::factory()->create(['prediction_id' => $native->id, 'market_type' => $type,
            'selection' => $side, 'projected_line' => $line, 'probability' => $probability]);
    }
    if ($variant !== 'draft') {
        $native->update(['publication_state' => 'published', 'published_at' => now()]);
    }
    if ($variant === 'withdrawn') {
        $native->update(['publication_state' => 'withdrawn', 'withdrawn_at' => now()]);
    }

    return [$context, $game, $native->refresh(), $legacy];
}

function gameReadRetirementQueries(Closure $read): string
{
    DB::enableQueryLog();
    try {
        $read();

        return collect(DB::getQueryLog())->pluck('query')->implode(' ');
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
}

it('loads canonical game forecasts without querying legacy predictions across relation profiles', function (string $sport, string $profile) {
    [$context, $game, $native] = gameReadRetirementFixture($sport);
    $queries = gameReadRetirementQueries(function () use ($context, $game, $native, $profile) {
        $games = app(SportGameQuery::class);
        $found = $games->find($context, $game->id, relationProfile: $profile);
        $listed = $games->paginate($context, ['season' => 2026, 'season_type' => '2'], relationProfile: $profile)->getCollection()->sole();
        foreach ([$found, $listed] as $loaded) {
            expect($loaded->getRelation('prediction'))->toBeInstanceOf(CanonicalPrediction::class)
                ->and($loaded->getRelation('prediction')->public_id)->toBe($native->public_id)
                ->and($loaded->homeTeam->id)->toBe($game->home_team_id);
        }
    });
    expect($queries)->not->toContain($sport.'_predictions');
})->with(['nba', 'nfl', 'mlb', 'cbb', 'cfb', 'wcbb', 'wnba'])->with(['summary', 'detail', 'page']);

it('does not use legacy coverage when canonical forecasts are ineligible', function (string $sport, string $variant) {
    [$context, $game] = gameReadRetirementFixture($sport, $variant);
    $queries = gameReadRetirementQueries(function () use ($context, $game) {
        $games = app(SportGameQuery::class);
        expect($games->find($context, $game->id)->getRelation('prediction'))->toBeNull()
            ->and($games->paginateSummaries($context)->getCollection()->sole()->prediction)->toBeNull();
    });
    expect($queries)->not->toContain($sport.'_predictions');
})->with(['nba', 'nfl', 'mlb', 'cbb', 'cfb', 'wcbb', 'wnba'])->with(['draft', 'withdrawn', 'unsafe']);

it('preserves legacy-mode game forecasts after the read switch is disabled', function (string $sport) {
    [$context, $game, , $legacy] = gameReadRetirementFixture($sport);
    config(["prediction_lifecycle.canonical_reads.{$sport}" => false]);
    $games = app(SportGameQuery::class);
    expect($games->find($context, $game->id)->prediction->is($legacy))->toBeTrue()
        ->and($games->paginateSummaries($context)->getCollection()->sole()->prediction->id)->toBe($legacy->id);
})->with(['nba', 'nfl', 'mlb', 'cbb', 'cfb', 'wcbb', 'wnba']);

it('serves canonical game lists and detail endpoints without legacy prediction reads', function (string $sport) {
    [, $game] = gameReadRetirementFixture($sport);
    Sanctum::actingAs(User::factory()->create());
    $queries = gameReadRetirementQueries(function () use ($sport, $game) {
        $this->getJson("/api/v2/sports/{$sport}/games?season=2026&season_type=2")
            ->assertSuccessful()->assertJsonCount(1, 'data')->assertJsonPath('data.0.has_prediction', true);
        $this->getJson("/api/v2/sports/{$sport}/teams/{$game->home_team_id}/games?season=2026&season_type=2")
            ->assertSuccessful()->assertJsonCount(1, 'data')->assertJsonPath('data.0.has_prediction', true);
        $this->getJson("/api/v2/sports/{$sport}/games/{$game->id}")
            ->assertSuccessful()->assertJsonPath('data.has_prediction', true);
    });
    expect($queries)->not->toContain($sport.'_predictions');
})->with(['nba', 'nfl', 'mlb', 'cbb', 'cfb', 'wcbb', 'wnba']);

it('preserves canonical composite page payloads without legacy prediction reads', function (string $sport) {
    [, $game, $native] = gameReadRetirementFixture($sport);
    Sanctum::actingAs(User::factory()->create());
    $expected = $this->getJson("/api/v2/sports/{$sport}/games/{$game->id}/prediction")->assertSuccessful()->json('data');
    $queries = gameReadRetirementQueries(function () use ($sport, $game, $native, $expected) {
        $response = $this->getJson("/api/v2/sports/{$sport}/games/{$game->id}/page")
            ->assertSuccessful()->assertJsonPath('data.game.has_prediction', true)
            ->assertJsonPath('data.prediction.id', $native->public_id)->assertJsonPath('meta.prediction_source', 'canonical');
        expect($response->json('data.prediction'))->toBe($expected);
    });
    expect($queries)->not->toContain($sport.'_predictions');
})->with(['mlb', 'nfl']);

it('exposes canonical gaps consistently through game and composite page endpoints', function (string $sport) {
    [, $game] = gameReadRetirementFixture($sport, 'withdrawn');
    Sanctum::actingAs(User::factory()->create());
    $queries = gameReadRetirementQueries(function () use ($sport, $game) {
        $this->getJson("/api/v2/sports/{$sport}/games?season=2026")
            ->assertSuccessful()->assertJsonPath('data.0.has_prediction', false);
        $this->getJson("/api/v2/sports/{$sport}/games/{$game->id}")
            ->assertSuccessful()->assertJsonPath('data.has_prediction', false);
        $this->getJson("/api/v2/sports/{$sport}/games/{$game->id}/prediction")->assertNotFound();
        if (in_array($sport, ['mlb', 'nfl'], true)) {
            $this->getJson("/api/v2/sports/{$sport}/games/{$game->id}/page")
                ->assertSuccessful()->assertJsonPath('data.game.has_prediction', false)
                ->assertJsonPath('data.prediction', null);
        }
    });
    expect($queries)->not->toContain($sport.'_predictions');
})->with(['nba', 'nfl', 'mlb', 'cbb', 'cfb', 'wcbb', 'wnba']);
