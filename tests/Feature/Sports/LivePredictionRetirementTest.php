<?php

use App\Actions\NBA\UpdateLivePrediction;
use App\Actions\Validation\Checks\LivePredictionFreshnessCheck;
use App\Models\CalculationRelease;
use App\Models\CalculationRun;
use App\Models\CanonicalPrediction;
use App\Models\CFB\Prediction;
use App\Models\EventInputSnapshot;
use App\Models\LivePredictionSnapshot;
use App\Models\PredictionMarket;
use App\Models\SportEvent;
use App\Services\Api\V2\SportContextResolver;
use App\Services\Api\V2\SportPredictionReader;
use App\Services\Predictions\LivePredictionStore;
use Illuminate\Support\Facades\DB;

beforeEach(fn () => $this->travelTo('2026-10-28 10:00:00'));
afterEach(fn () => $this->travelBack());

function liveRetirementFixture(string $sport = 'nba'): array
{
    $context = app(SportContextResolver::class)->resolve($sport);
    $event = SportEvent::factory()->create(['sport' => $sport, 'starts_at' => now()->addHour()]);
    $team = $context->models['team'];
    $gameClass = $context->models['game'];
    $game = $gameClass::factory()->create([
        'home_team_id' => $team::factory()->create()->id, 'away_team_id' => $team::factory()->create()->id,
        'sport_event_id' => $event->id, 'status' => 'STATUS_SCHEDULED', 'season' => 2026,
        'game_date' => now()->toDateString(), 'game_time' => '11:00:00',
    ]);
    $release = CalculationRelease::factory()->approved()->create(['sport' => $sport, 'phase' => 'pregame']);
    $input = EventInputSnapshot::factory()->create(['sport' => $sport, 'sport_event_id' => $event->id,
        'phase' => 'pregame', 'pregame_safety_status' => 'verified', 'captured_at' => now(), 'cutoff_at' => $event->starts_at]);
    $run = CalculationRun::factory()->create(['sport_event_id' => $event->id, 'event_input_snapshot_id' => $input->id,
        'calculation_release_id' => $release->id, 'status' => 'succeeded']);
    $native = CanonicalPrediction::factory()->create(['sport' => $sport, 'sport_event_id' => $event->id,
        'calculation_run_id' => $run->id, 'phase' => 'pregame', 'publication_state' => 'draft',
        'detail_source' => null, 'detail_id' => null, 'detail_sport' => null,
        'output_metadata' => ['input_quality' => ['qualified' => true], 'market_conventions' => ['spread' => 'sportsbook_home_line']]]);
    $total = $sport === 'mlb' ? 8.5 : (in_array($sport, ['nfl', 'cfb'], true) ? 45.0 : 150.0);
    foreach ([['moneyline', 'home', null, 0.65], ['spread', 'home', -4.0, null], ['total', 'combined', $total, null]] as [$type, $side, $line, $probability]) {
        PredictionMarket::factory()->create(['prediction_id' => $native->id, 'market_type' => $type, 'selection' => $side,
            'projected_line' => $line, 'probability' => $probability]);
    }
    $native->update(['publication_state' => 'published', 'published_at' => now()]);

    return [$game, $native->refresh(), $total];
}

function putGameInProgress($game, string $sport): void
{
    $game->update($sport === 'mlb'
        ? ['status' => 'STATUS_IN_PROGRESS', 'inning' => 5, 'inning_half' => 'top', 'home_score' => 3, 'away_score' => 2]
        : ['status' => 'STATUS_IN_PROGRESS', 'period' => 2, 'game_clock' => '5:00', 'home_score' => 24, 'away_score' => 20]);
}

it('preserves sport live calculations while writing neither legacy nor canonical pregame models', function (string $sport) {
    [$game, $native, $total] = liveRetirementFixture($sport);
    $legacyClass = app(SportContextResolver::class)->resolve($sport)->models['prediction'];
    $attributes = ['game_id' => $game->id, 'predicted_spread' => 4.0, 'predicted_total' => $total, 'win_probability' => 0.65];
    $legacy = $sport === 'mlb' ? $legacyClass::query()->create([...$attributes, 'season' => 2026, 'season_type' => '2'])
        : $legacyClass::factory()->create($attributes);
    $legacyBefore = $legacy->refresh()->getAttributes();
    $nativeBefore = $native->getAttributes();
    $this->travel(2)->hours();
    putGameInProgress($game, $sport);
    $actionClass = 'App\\Actions\\'.strtoupper($sport).'\\UpdateLivePrediction';
    $action = app($actionClass);
    config(["prediction_lifecycle.canonical_reads.{$sport}" => false]);
    $legacyOutput = $action->execute($game->fresh());
    config(["prediction_lifecycle.canonical_reads.{$sport}" => true]);
    $nativeOutput = $action->execute($game->fresh());
    expect($nativeOutput)->toBe($legacyOutput)->not->toBeNull()
        ->and($legacy->fresh()->getAttributes())->toBe($legacyBefore)
        ->and($native->fresh()->getAttributes())->toBe($nativeBefore);
    $snapshot = LivePredictionSnapshot::query()->where('canonical_prediction_id', $native->id)->sole();
    expect($snapshot->baseline['calculation_run_id'])->toBe($native->calculation_run_id)
        ->and($snapshot->projection)->toEqual($nativeOutput)
        ->and($snapshot->baseline['pinned'])->toBeTrue();
    $store = app(LivePredictionStore::class);
    expect($store->fields($game->fresh())['live_win_probability'])->toBe($nativeOutput['live_win_probability']);
    $resource = app(SportPredictionReader::class)->resource(app(SportContextResolver::class)->resolve($sport), $native)->resolve(request());
    expect($resource['live_predicted_spread'])->toBe($nativeOutput['live_predicted_spread']);
    $game->update(['status' => 'STATUS_FINAL']);
    expect($action->execute($game->fresh()))->toBeNull()
        ->and($store->fields($game->fresh())['live_win_probability'])->toBeNull()
        ->and($snapshot->fresh()->projection)->toEqual($nativeOutput)
        ->and($legacy->fresh()->getAttributes())->toBe($legacyBefore);
})->with(['nba', 'cbb', 'wnba', 'wcbb', 'nfl', 'mlb']);

it('supports canonical-only live games and fails closed on a withdrawn baseline without legacy fallback', function () {
    [$game, $native] = liveRetirementFixture();
    config(['prediction_lifecycle.canonical_reads.nba' => true]);
    $this->travel(2)->hours();
    putGameInProgress($game, 'nba');
    $action = app(UpdateLivePrediction::class);
    expect($action->execute($game->fresh()))->not->toBeNull()
        ->and($game->prediction()->exists())->toBeFalse();
    $native->update(['publication_state' => 'withdrawn', 'withdrawn_at' => now()]);
    expect(app(LivePredictionStore::class)->fields($game->fresh())['live_win_probability'])->toBeNull()
        ->and($action->execute($game->fresh()))->toBeNull();
});

it('uses observation order instead of insertion order and keeps live history immutable', function () {
    [$game] = liveRetirementFixture();
    config(['prediction_lifecycle.canonical_reads.nba' => true]);
    $this->travel(2)->hours();
    putGameInProgress($game, 'nba');
    $store = app(LivePredictionStore::class);
    $baseline = $store->baseline($game);
    $values = ['live_predicted_spread' => 5.0, 'live_predicted_total' => 151.0, 'live_win_probability' => 0.7, 'live_seconds_remaining' => 100];
    $latest = $store->record($game, $baseline, $values, 'live', 'fixture', now());
    $store->record($game, $baseline, [...$values, 'live_win_probability' => 0.1], 'live', 'fixture', now()->subMinute());
    expect($store->fields($game)['live_win_probability'])->toBe(0.7)
        ->and($store->record($game, $baseline, $values, 'live', 'fixture', now())->id)->toBe($latest->id);
    expect(fn () => $latest->update(['status' => 'inactive']))->toThrow(LogicException::class);
    expect(fn () => $latest->delete())->toThrow(LogicException::class);
});

it('loads the latest live projections with a bounded query count', function () {
    [$game] = liveRetirementFixture();
    config(['prediction_lifecycle.canonical_reads.nba' => true]);
    $this->travel(2)->hours();
    putGameInProgress($game, 'nba');
    app(UpdateLivePrediction::class)->execute($game);
    $games = $game->newCollection([$game->fresh()]);
    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        $store = app(LivePredictionStore::class);
        $store->preload($games);
        $queries = count(DB::getQueryLog());
        $store->fields($games->first());
        $store->fields($games->first());
        expect($queries)->toBe(2)->and(DB::getQueryLog())->toHaveCount(2);
    } finally {
        DB::disableQueryLog();
    }
});

it('validates live freshness from snapshots instead of legacy columns', function () {
    [$game] = liveRetirementFixture();
    config(['prediction_lifecycle.canonical_reads.nba' => true]);
    $this->travel(2)->hours();
    putGameInProgress($game, 'nba');
    app(UpdateLivePrediction::class)->execute($game);
    $check = app(LivePredictionFreshnessCheck::class);
    expect($check->run('nba', config('validation.sports.nba'))['metadata']['problem_games'])->toBe(0);
    $this->travel(7)->minutes();
    expect($check->run('nba', config('validation.sports.nba'))['metadata']['stale_live_models'])->toBe(1);
});

it('keeps CFB live snapshots authoritative and removes the legacy column mirror', function () {
    [$game, $native] = liveRetirementFixture('cfb');
    $legacy = Prediction::factory()->create(['game_id' => $game->id]);
    $before = $legacy->refresh()->getAttributes();
    $this->travel(2)->hours();
    putGameInProgress($game, 'cfb');
    app(App\Actions\CFB\UpdateLivePrediction::class)->execute($game);
    expect($legacy->fresh()->getAttributes())->toBe($before)
        ->and(App\Models\CFB\LivePredictionSnapshot::query()->sole()->pregame['canonical_prediction_id'])->toBe($native->id)
        ->and(App\Models\CFB\LivePredictionSnapshot::query()->sole()->projection)->not->toBeNull()
        ->and(LivePredictionSnapshot::query()->where('sport', 'cfb')->count())->toBe(0);
});

it('avoids inactive snapshot churn for scheduled and already-cleared games', function () {
    [$game] = liveRetirementFixture();
    config(['prediction_lifecycle.canonical_reads.nba' => true]);
    $action = app(UpdateLivePrediction::class);
    $action->execute($game->fresh());
    expect(LivePredictionSnapshot::query()->count())->toBe(0);
    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        $store = app(LivePredictionStore::class);
        $store->preload(collect([$game]));
        expect($store->fields($game)['live_win_probability'])->toBeNull()
            ->and(DB::getQueryLog())->toBeEmpty();
    } finally {
        DB::disableQueryLog();
    }
    $this->travel(2)->hours();
    putGameInProgress($game, 'nba');
    $action->execute($game->fresh());
    $game->update(['status' => 'STATUS_FINAL']);
    $action->execute($game->fresh());
    $this->travel(1)->minutes();
    $game->touch();
    $action->execute($game->fresh());
    expect(LivePredictionSnapshot::query()->count())->toBe(2)
        ->and(app(LivePredictionStore::class)->fields($game->fresh())['live_win_probability'])->toBeNull();
});
