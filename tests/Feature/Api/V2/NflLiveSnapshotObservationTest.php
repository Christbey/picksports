<?php

use App\Actions\NFL\UpdateLivePrediction;
use App\Models\CanonicalPrediction;
use App\Models\NFL\Game;
use App\Models\NFL\Prediction;
use App\Models\NFL\Team;
use App\Models\PredictionMarket;
use App\Models\SportEvent;
use App\Models\User;
use App\Support\Sports\LegacyTimestampCarrier;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CanonicalReadFixture;

beforeEach(function () {
    config(['app.timezone' => 'America/Chicago', 'subscriptions.enforce_tiers' => false]);
    $this->travelTo(CarbonImmutable::parse('2026-11-27T12:00:00Z'));
    Sanctum::actingAs(User::factory()->create());
});

it('reads an admitted canonical live baseline without consulting retired legacy predictions', function () {
    config(['prediction_lifecycle.canonical_reads.nfl' => true]);
    $start = now()->addHour()->toImmutable();
    $event = SportEvent::factory()->create(['sport' => 'nfl', 'starts_at' => LegacyTimestampCarrier::forInstant($start)]);
    $game = Game::factory()->create(['sport_event_id' => $event->id, 'home_team_id' => Team::factory(), 'away_team_id' => Team::factory(),
        'game_date' => $start->utc()->toDateString(), 'game_time' => $start->utc()->toTimeString(), 'status' => 'STATUS_SCHEDULED']);
    $run = CanonicalReadFixture::run($event, ['home' => ['team_id' => $game->home_team_id], 'away' => ['team_id' => $game->away_team_id]]);
    $native = CanonicalPrediction::factory()->create(['sport' => 'nfl', 'sport_event_id' => $event->id, 'calculation_run_id' => $run->id,
        'output_hash' => $run->output_hash, 'generated_at' => $run->inputSnapshot->captured_at,
        'phase' => 'pregame', 'publication_state' => 'draft', 'published_at' => null, 'detail_source' => null, 'detail_sport' => null, 'detail_id' => null]);
    foreach ([['moneyline', 'home', null, .72], ['spread', 'home', -7.0, null], ['total', 'combined', 45.0, null]] as [$type, $side, $line, $probability]) {
        PredictionMarket::factory()->create(['prediction_id' => $native->id, 'market_type' => $type, 'selection' => $side, 'projected_line' => $line, 'probability' => $probability]);
    }
    $native->update(['publication_state' => 'published', 'published_at' => now()]);
    $legacy = Prediction::factory()->create(['game_id' => $game->id, 'predicted_spread' => -40, 'predicted_total' => 99, 'win_probability' => .01]);
    $before = $legacy->fresh()->getRawOriginal();
    $this->travelTo($start->addHour());
    $game->update(['status' => 'STATUS_IN_PROGRESS', 'period' => 2, 'game_clock' => '0:39', 'home_score' => 27, 'away_score' => 7]);
    $expected = app(UpdateLivePrediction::class)->preview($game->fresh());
    expect($expected)->not->toBeNull();
    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        $this->getJson("/api/v2/sports/nfl/games/{$game->id}/live-snapshot")->assertOk()->assertJsonPath('data.projection', $expected);
        $queries = collect(DB::getQueryLog())->pluck('query')->implode(' ');
    } finally {
        DB::disableQueryLog();
    }
    expect($queries)->not->toContain('nfl_predictions')
        ->and($legacy->fresh()->getRawOriginal())->toBe($before);
});

it('keeps local record mutation separate from unknown provider observation while retaining real zero scores', function (bool $missingRecordClock) {
    config(['prediction_lifecycle.canonical_reads.nfl' => false]);
    $game = Game::factory()->create(['home_team_id' => Team::factory(), 'away_team_id' => Team::factory(),
        'status' => 'STATUS_IN_PROGRESS', 'period' => 1, 'game_clock' => '15:00', 'home_score' => 0, 'away_score' => 0]);
    Prediction::factory()->create(['game_id' => $game->id, 'predicted_spread' => 0, 'predicted_total' => 0, 'win_probability' => .5]);
    if ($missingRecordClock) {
        DB::table('nfl_games')->where('id', $game->id)->update(['updated_at' => null]);
    }
    $before = $game->fresh()->getRawOriginal();
    $this->getJson("/api/v2/sports/nfl/games/{$game->id}/live-snapshot")->assertOk()
        ->assertJsonPath('data.game.home_score', 0)->assertJsonPath('data.game.away_score', 0)
        ->assertJsonPath('data.source_updated_at', null)
        ->assertJsonPath('data.source_clock_status', 'observation_unavailable')
        ->assertJsonPath('data.record_updated_at', null)
        ->assertJsonPath('data.record_clock_status', 'unverified_storage_clock')
        ->assertJsonPath('data.record_updated_at_raw', $before['updated_at']);
    expect($game->fresh()->getRawOriginal())->toBe($before);
})->with(['stored raw clock' => [false], 'missing raw clock' => [true]]);

it('documents the unknown provider and unqualified stored record clocks without broadening the live model contract', function () {
    Artisan::call('api:v2-openapi-generate', ['--stdout' => true]);
    $spec = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    $schema = $spec['components']['schemas']['NflLiveSnapshotData'];
    expect($schema['required'])->toContain('source_updated_at', 'record_updated_at', 'record_updated_at_raw', 'record_clock_status', 'source_clock_status')
        ->and($schema['properties']['source_updated_at']['type'])->toBe('null')
        ->and($schema['properties']['record_updated_at']['type'])->toBe('null')
        ->and($schema['properties']['record_updated_at_raw']['type'])->toBe(['string', 'null'])
        ->and($schema['properties']['record_clock_status']['const'])->toBe('unverified_storage_clock')
        ->and($schema['properties']['source_clock_status']['const'])->toBe('observation_unavailable')
        ->and($schema['properties']['provisional']['const'])->toBeTrue()
        ->and($schema['properties']['calibrated']['const'])->toBeFalse();
});
