<?php

use App\Exceptions\Predictions\PredictionLifecycleException;
use App\Models\GameOddsSnapshot;
use App\Models\MLB\Game;
use App\Models\MLB\GameWeather;
use App\Models\MLB\StartingPitcherForecast;
use App\Models\MLB\Team;
use App\Services\MLB\MlbStartingPitcherForecastService;
use App\Services\MLB\MlbStartingPitcherProjectionService;
use App\Services\OddsApi\GameOddsSnapshotRecorder;
use App\Services\Predictions\CalculationRunRecovery;
use App\Services\Predictions\SnapshotProvenanceResolver;
use App\Services\Sports\SportEventIdentitySynchronizer;
use App\Support\MLB\MlbGameStart;
use App\Support\MLB\MlbQuoteTime;
use App\Support\MLB\MlbWeatherTime;
use App\Support\Sports\CanonicalEventStart;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config(['app.timezone' => 'America/Chicago', 'sports.business_timezone' => 'America/Chicago']);
    Http::preventStrayRequests();
    Queue::fake();
});

function mlbConsumerGame(string $instant = '2026-07-02T06:30:00Z'): Game
{
    $wall = Carbon::parse($instant)->setTimezone('America/Chicago');
    $attributes = Game::factory()->make(['home_team_id' => Team::factory()->create()->id, 'away_team_id' => Team::factory()->create()->id,
        'season' => 2026, 'season_type' => '2', 'status' => 'STATUS_SCHEDULED', 'game_date' => $wall->toDateString(), 'game_time' => $wall->toTimeString()])->getAttributes();

    return app(SportEventIdentitySynchronizer::class)->createAndSync('mlb', Game::class, $attributes + MlbGameStart::observed($instant, []));
}

function mlbConsumerOdds(Game $game, int $price = -120): array
{
    $home = trim($game->homeTeam->location.' '.$game->homeTeam->name);
    $away = trim($game->awayTeam->location.' '.$game->awayTeam->name);

    return ['home_team' => $home, 'away_team' => $away, 'bookmakers' => [['key' => 'synthetic', 'title' => 'Synthetic', 'markets' => [
        ['key' => 'h2h', 'outcomes' => [['name' => $home, 'price' => $price], ['name' => $away, 'price' => 105]]],
    ]]]];
}

it('fences same-clock schedule reversion while retaining equivalent current observation identity', function () {
    $this->travelTo(Carbon::parse('2026-07-01T06:00:00Z'));
    $game = mlbConsumerGame();
    $capture = MlbGameStart::capture($game, now());
    $firstId = $game->start_observation_id;
    $firstFact = $game->start_source_hash;
    $firstContext = $game->start_context_hash;
    $game->update(MlbGameStart::prepare($game, ['game_time' => '02:30:00'] + MlbGameStart::observed('2026-07-02T07:30:00Z', [])));
    $secondId = $game->start_observation_id;
    $game->update(MlbGameStart::prepare($game, ['game_time' => '01:30:00'] + MlbGameStart::observed('2026-07-02T06:30:00Z', [])));
    expect($game->start_observation_id)->not->toBeIn([$firstId, $secondId])->and($game->start_source_hash)->toBe($firstFact)
        ->and($game->start_context_hash)->not->toBe($firstContext)->and(MlbGameStart::capturedFor($game, $capture))->toBeFalse();
    $thirdId = $game->start_observation_id;
    $game->update(MlbGameStart::prepare($game, MlbGameStart::observed('2026-07-02T15:30:00+09:00', ['header' => ['id' => $game->espn_event_id]])));
    expect($game->start_observation_id)->toBe($thirdId);
});

it('refuses backdated observation fields with the original digest', function (array $changes) {
    $this->travelTo(Carbon::parse('2026-07-01T06:00:00Z'));
    $game = mlbConsumerGame();
    $game->newQuery()->whereKey($game->id)->update($changes);
    expect(MlbGameStart::for($game->fresh()))->toBeNull()
        ->and(MlbGameStart::known(Game::query())->whereKey($game->id)->exists())->toBeFalse();
})->with([
    [['start_observed_at' => '2020-01-01 00:00:00.000000']],
    [['start_observation_id' => '00000000-0000-4000-8000-000000000000']],
    [['start_observation_id' => null]],
]);

it('refuses stale projection and forecast callers without changing persisted projections', function () {
    $this->travelTo(Carbon::parse('2026-07-01T06:00:00Z'));
    $game = mlbConsumerGame();
    $stale = clone $game;
    $game->update(MlbGameStart::prepare($game, ['game_time' => '02:30:00'] + MlbGameStart::observed('2026-07-02T07:30:00Z', [])));
    $before = $game->fresh()->getAttributes();
    $result = app(MlbStartingPitcherProjectionService::class)->project($stale);
    $forecast = app(MlbStartingPitcherForecastService::class)->record($stale, 'home', ['pitcher_espn_id' => 'synthetic-pitcher']);
    expect($result['changed'])->toBeFalse()->and(data_get($result, 'home.evidence.reason'))->toBe('unknown_or_changed_start_context')
        ->and($forecast)->toBeNull()->and(StartingPitcherForecast::count())->toBe(0)
        ->and($game->fresh()->getAttributes())->toBe($before);
});

it('does not reuse an old projected pitcher after a start correction or erase its raw historical fields', function () {
    $this->travelTo(Carbon::parse('2026-07-01T06:00:00Z'));
    $game = mlbConsumerGame();
    $game->update(['projected_home_pitcher_espn_id' => 'synthetic-projection', 'projected_home_pitcher_confidence' => 0.7,
        'pitcher_projection_metadata' => ['timing_policy' => MlbGameStart::TIMING_POLICY, 'start_context_hash' => $game->start_context_hash, 'start_source_hash' => $game->start_source_hash]]);
    expect($game->resolvedStartingPitcherEspnId('home'))->toBe('synthetic-projection');
    $game->update(MlbGameStart::prepare($game, ['game_time' => '02:30:00'] + MlbGameStart::observed('2026-07-02T07:30:00Z', [])));
    expect($game->resolvedStartingPitcherEspnId('home'))->toBeNull()->and($game->startingPitcherConfidence('home'))->toBeNull()
        ->and($game->fresh()->getRawOriginal('projected_home_pitcher_espn_id'))->toBe('synthetic-projection');
});

it('distinguishes genuine quote observations across both repeated hours and rejects marker or quote mutations', function () {
    $this->travelTo(Carbon::parse('2026-10-31T12:00:00Z'));
    $game = mlbConsumerGame('2026-11-01T01:40:00-05:00');
    $recorder = app(GameOddsSnapshotRecorder::class);
    $this->travelTo(Carbon::parse('2026-11-01T06:30:00Z'));
    $first = $recorder->record('mlb', $game, ['id' => 'synthetic-odds', 'commence_time' => '2026-11-01T01:40:00-05:00'], mlbConsumerOdds($game), Carbon::parse('2026-11-01T06:30:00Z'));
    $this->travelTo(Carbon::parse('2026-11-01T07:30:00Z'));
    $second = $recorder->record('mlb', $game, ['id' => 'synthetic-odds', 'commence_time' => '2026-11-01T01:40:00-05:00'], mlbConsumerOdds($game, -125), Carbon::parse('2026-11-01T07:30:00Z'));
    expect($first->fresh()->getRawOriginal('captured_at'))->toBe($second->fresh()->getRawOriginal('captured_at'))
        ->and(MlbQuoteTime::marker($game, $second)['captured_epoch'] - MlbQuoteTime::marker($game, $first)['captured_epoch'])->toBe(3600)
        ->and(MlbQuoteTime::pregame($game, $first))->toBeTrue()->and(MlbQuoteTime::pregame($game, $second))->toBeFalse()
        ->and(MlbQuoteTime::latest($game)?->id)->toBe($first->id);
    $quote = $first->marketQuotes()->firstOrFail();
    expect(MlbQuoteTime::quote($game, $quote))->toBeTrue();
    $quote->update(['price' => -199]);
    expect(MlbQuoteTime::quote($game, $quote->fresh()))->toBeFalse();
    $context = $first->market_context;
    $context['_mlb_time']['captured_epoch']++;
    $first->update(['market_context' => $context]);
    expect(MlbQuoteTime::marker($game, $first->fresh()))->toBeNull()->and(MlbQuoteTime::latest($game))->toBeNull();
});

it('does not certify old quote timestamps or caller-provided timing metadata and can capture a fresh unchanged payload', function () {
    $this->travelTo(Carbon::parse('2026-07-01T06:00:00Z'));
    $game = mlbConsumerGame();
    $data = mlbConsumerOdds($game);
    $old = GameOddsSnapshot::create(['sport' => 'mlb', 'game_table' => 'mlb_games', 'game_id' => $game->id, 'captured_at' => now(), 'commence_time' => now()->addDay(),
        'payload_hash' => hash('sha256', json_encode($data)), 'odds_data' => $data]);
    expect(MlbQuoteTime::pregame($game, $old))->toBeFalse();
    $before = $old->fresh()->getAttributes();
    $new = app(GameOddsSnapshotRecorder::class)->record('mlb', $game, ['id' => 'new-observation', 'commence_time' => '2026-07-02T06:30:00Z'], $data, Carbon::instance(now()));
    expect($new)->not->toBeNull()->and(MlbQuoteTime::pregame($game, $new))->toBeTrue()->and($old->fresh()->getAttributes())->toBe($before);
});

it('uses weather receipt availability rather than a future forecast hour and rejects future receipts and context drift', function () {
    $this->travelTo(Carbon::parse('2026-07-01T06:00:00Z'));
    $game = mlbConsumerGame();
    $values = ['provider' => 'synthetic', 'temperature_f' => 78, 'wind_speed_mph' => 8, 'is_indoor' => false, 'roof_status' => 'open'];
    $hour = Carbon::parse('2026-07-02T06:00:00Z');
    $weather = GameWeather::create($values + ['game_id' => $game->id, 'observed_at' => MlbGameStart::legacyTimestamp($hour),
        'raw_payload' => ['_mlb_weather_time' => MlbWeatherTime::capture($game, $hour, $values)]]);
    expect($weather->observed_at->gt(now()))->toBeTrue()->and(MlbWeatherTime::available($game, $weather, now()))->toBeTrue();
    $this->travelTo(Carbon::parse('2026-07-01T08:00:00Z'));
    $future = new GameWeather($values + ['game_id' => $game->id, 'raw_payload' => ['_mlb_weather_time' => MlbWeatherTime::capture($game, $hour, $values)]]);
    expect(MlbWeatherTime::available($game, $future, Carbon::parse('2026-07-01T07:00:00Z')))->toBeFalse();
    $game->update(['venue_city' => 'Changed target venue']);
    expect(MlbWeatherTime::available($game, $weather, now()))->toBeFalse();
    $game->refresh();
    $weather->update(['temperature_f' => 99]);
    expect(MlbWeatherTime::marker($game, $weather->fresh()))->toBeNull();
});

it('does not confuse first-hour capture with second-hour weather receipt availability', function () {
    $this->travelTo(Carbon::parse('2026-10-31T12:00:00Z'));
    $game = mlbConsumerGame('2026-11-01T02:30:00-06:00');
    $this->travelTo(Carbon::parse('2026-11-01T07:30:00Z'));
    $hour = Carbon::parse('2026-11-01T01:00:00-06:00');
    $values = ['provider' => 'synthetic', 'is_indoor' => true, 'roof_status' => 'fixed_roof'];
    $weather = new GameWeather($values + ['game_id' => $game->id, 'observed_at' => MlbGameStart::legacyTimestamp($hour),
        'raw_payload' => ['_mlb_weather_time' => MlbWeatherTime::capture($game, $hour, $values)]]);
    expect(MlbWeatherTime::available($game, $weather, Carbon::parse('2026-11-01T01:40:00-05:00')))->toBeFalse()
        ->and(MlbWeatherTime::available($game, $weather, Carbon::parse('2026-11-01T01:40:00-06:00')))->toBeTrue();
});

it('refuses canonical time admissions based solely on retained legacy event timestamps', function () {
    $this->travelTo(Carbon::parse('2026-07-01T06:00:00Z'));
    $game = mlbConsumerGame();
    $event = $game->sportEvent;
    expect(CanonicalEventStart::forEvent($event)?->getTimestamp())->toBe((int) $game->start_epoch);
    $event->setRelation('mlbGame', $game);
    $game->newQuery()->whereKey($game->id)->update(['start_context_hash' => str_repeat('f', 64)]);
    expect(CanonicalEventStart::forEvent($event, true))->toBeNull()
        ->and(fn () => app(CalculationRunRecovery::class)->assertPregameOpen($event, 'pregame'))->toThrow(PredictionLifecycleException::class);
    expect($event->fresh()->starts_at)->not->toBeNull();
});

it('does not certify a quote received after an earlier prediction capture merely because both precede kickoff', function () {
    $this->travelTo(Carbon::parse('2026-07-01T06:00:00Z'));
    $game = mlbConsumerGame();
    $earlierCapture = Carbon::parse('2026-07-01T07:00:00Z');
    $this->travelTo(Carbon::parse('2026-07-01T08:00:00Z'));
    $snapshot = app(GameOddsSnapshotRecorder::class)->record('mlb', $game,
        ['id' => 'late-received-synthetic', 'commence_time' => '2026-07-02T06:30:00Z'], mlbConsumerOdds($game), $earlierCapture);
    expect(MlbQuoteTime::pregame($game, $snapshot))->toBeTrue();
    $payload = ['pregame_safe' => true, 'market_context' => ['vegas_spread' => 1, 'safety' => ['snapshot_id' => $snapshot->id]]];
    $resolver = app(SnapshotProvenanceResolver::class);
    expect($resolver->resolve($game, $payload, $earlierCapture)['pregame_safe'])->toBeFalse()
        ->and($resolver->resolve($game, $payload, Carbon::parse('2026-07-01T08:00:00Z'))['pregame_safe'])->toBeTrue();
});
