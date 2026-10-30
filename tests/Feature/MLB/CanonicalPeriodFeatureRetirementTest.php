<?php

use App\Models\CalculationRelease;
use App\Models\CalculationRun;
use App\Models\CanonicalPrediction;
use App\Models\EventInputSnapshot;
use App\Models\MLB\Game;
use App\Models\MLB\PeriodFeatureSnapshot;
use App\Models\MLB\Prediction;
use App\Models\MLB\Team;
use App\Models\PredictionFeatureSnapshot;
use App\Models\PredictionMarket;
use App\Models\SportEvent;
use App\Services\MLB\MlbPeriodFeatureBuilder;
use App\Services\MLB\MlbPeriodFeatureStore;
use App\Services\MLB\MlbPeriodForecastSource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->travelTo('2026-10-30 10:00:00');
    Http::preventStrayRequests();
    config(['prediction_lifecycle.canonical_pipeline.mlb' => true, 'prediction_lifecycle.canonical_reads.mlb' => true]);
});
afterEach(fn () => $this->travelBack());

function periodFeatureRetirementFixture(string $variant = 'valid'): array
{
    $event = SportEvent::factory()->create(['sport' => 'mlb', 'season' => 2026, 'starts_at' => now()->addDay()->setTime(17, 0)]);
    $game = Game::factory()->create(['sport_event_id' => $event->id, 'season' => 2026, 'season_type' => 2,
        'status' => 'STATUS_SCHEDULED', 'game_date' => now()->addDay()->toDateString(), 'game_time' => '17:00:00',
        'home_team_id' => Team::factory(), 'away_team_id' => Team::factory()]);
    $start = $event->refresh()->starts_at;
    $legacy = Prediction::query()->create(['game_id' => $game->id, 'predicted_spread' => -8, 'predicted_total' => 18,
        'win_probability' => 0.1, 'confidence_score' => 90, 'model_version' => 'historical', 'feature_version' => 'historical', 'blend_version' => 'historical']);
    $input = EventInputSnapshot::factory()->create(['sport' => 'mlb', 'sport_event_id' => $event->id,
        'captured_at' => now()->subMinutes(10), 'cutoff_at' => now()->subMinutes(10),
        'latest_source_available_at' => now()->subMinutes(12), 'pregame_safety_status' => $variant === 'unsafe' ? 'unverified' : 'verified',
        'inputs' => ['event' => ['starts_at' => ($variant === 'kickoff_mismatch' ? $start->copy()->addHour() : $start)->toIso8601String()],
            'home' => ['elo' => 1510, 'metrics' => ['runs_per_game' => 4.8]], 'away' => ['elo' => 1480],
            'pitching' => ['home' => ['elo' => 1600], 'away' => ['elo' => 1500]],
            'weather' => ['temperature_f' => 77, 'observed_at' => now()->subHour()->toIso8601String()],
            'pregame_market' => ['available' => true, 'consensus' => ['spread' => ['line' => -1.5, 'captured_at' => now()->subMinutes(15)->toIso8601String()]]]]]);
    $release = CalculationRelease::factory()->approved()->create(['sport' => 'mlb', 'phase' => 'pregame']);
    $run = CalculationRun::factory()->create(['sport_event_id' => $event->id, 'event_input_snapshot_id' => $input->id,
        'calculation_release_id' => $release->id, 'status' => 'succeeded', 'diagnostics' => ['stored_adjustment' => 0.4]]);
    $native = CanonicalPrediction::query()->create(['sport' => 'mlb', 'sport_event_id' => $event->id,
        'calculation_run_id' => $run->id, 'phase' => 'pregame', 'publication_state' => 'draft', 'published_at' => null,
        'generated_at' => now()->subMinutes(5), 'detail_source' => null, 'detail_sport' => null, 'detail_id' => null]);
    foreach ([['moneyline', 'home', null, 0.65], ['moneyline', 'away', null, $variant === 'probability_sum' ? 0.4 : 0.35],
        ['spread', 'home', -2, null], ['total', 'combined', $variant === 'negative_score' ? 1 : 9, null]] as [$type, $side, $line, $probability]) {
        PredictionMarket::factory()->create(['prediction_id' => $native->id, 'market_type' => $type,
            'selection' => $side, 'projected_line' => $line, 'probability' => $probability, 'confidence_score' => null]);
    }
    if ($variant !== 'draft') {
        $native->update(['publication_state' => 'published', 'published_at' => now()->subMinutes(5)]);
    }
    if ($variant === 'withdrawn') {
        $native->update(['publication_state' => 'withdrawn', 'withdrawn_at' => now()]);
    }

    PredictionFeatureSnapshot::create(['sport' => 'mlb', 'prediction_table' => 'mlb_predictions', 'prediction_id' => $legacy->id,
        'game_id' => $game->id, 'snapshot_run_id' => (string) Str::uuid(), 'model_version' => 'historical', 'feature_version' => 'historical', 'blend_version' => 'historical',
        'features' => ['home_pitcher_elo' => 1900, 'away_pitcher_elo' => 1700], 'outputs' => [], 'feature_hash' => str_repeat('a', 64),
        'generated_at' => now()->subHour(), 'features_available_at' => now()->subHour(), 'game_start_at' => $start, 'pregame_safe' => true, 'availability_status' => 'observed_pregame']);

    return [$game, $native, $legacy];
}

it('separates persisted native and historical period features without changing model input shape', function () {
    [$game,$native] = periodFeatureRetirementFixture();
    config(['prediction_lifecycle.canonical_reads.mlb' => false]);
    $store = app(MlbPeriodFeatureStore::class);
    $old = $store->materialize(collect([$game]))->sole();
    expect($old->forecast_source)->toBe('legacy')->and($old->features['first_3_moneyline']['feature_home_pitcher_elo'])->toBe(1900);
    config(['prediction_lifecycle.canonical_reads.mlb' => true]);
    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        $new = $store->materialize(collect([$game]))->sole();
        $sql = collect(DB::getQueryLog())->pluck('query')->implode(' ');
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
    expect($new->id)->not->toBe($old->id)->and($new->forecast_source)->toBe('canonical')
        ->and($new->canonical_prediction_id)->toBe($native->id)
        ->and($new->features['first_3_moneyline']['feature_home_pitcher_elo'])->toBe(1600)
        ->and($sql)->not->toContain('prediction_feature_snapshots')->not->toContain('mlb_predictions');
    expect($store->forGames(collect([$game]))[$game->id])->toEqual($new->features)
        ->and($store->materialize(collect([$game]))->sole()->id)->toBe($new->id)
        ->and(array_keys($new->features))->toBe(array_keys(MlbPeriodFeatureBuilder::MARKETS));
    config(['prediction_lifecycle.canonical_reads.mlb' => false]);
    expect($store->forGames(collect([$game]))[$game->id])->toEqual($old->features)
        ->and($old->fresh()->features)->toEqual($old->features);
});

it('does not borrow legacy pitcher inputs for ineligible native forecasts', function (string $variant) {
    [$game] = periodFeatureRetirementFixture($variant);
    $row = app(MlbPeriodFeatureStore::class)->materialize(collect([$game]))->sole();
    expect($row->forecast_source)->toBe('canonical')->and($row->canonical_prediction_id)->toBeNull()
        ->and($row->features['first_3_moneyline']['feature_home_pitcher_elo'])->toBeNull()
        ->and($row->features['first_3_moneyline']['feature_pitcher_context_known'])->toBe(0);
})->with(['withdrawn', 'unsafe', 'draft', 'kickoff_mismatch']);

it('rejects stale revision features and source mode changes for pinned native inference', function () {
    [$game,$native] = periodFeatureRetirementFixture();
    $store = app(MlbPeriodFeatureStore::class);
    $row = $store->materialize(collect([$game]))->sole();
    $native->update(['publication_state' => 'withdrawn', 'withdrawn_at' => now()]);
    expect($store->forGames(collect([$game]), [$game->id => $native->id]))->toBeEmpty();
    $fallback = $store->materialize(collect([$game]))->sole();
    expect($fallback->canonical_prediction_id)->toBeNull()->and($fallback->id)->not->toBe($row->id)
        ->and($fallback->features['first_5_moneyline']['feature_home_pitcher_elo'])->toBeNull();
    config(['prediction_lifecycle.canonical_reads.mlb' => false]);
    expect($store->forGames(collect([$game]), [$game->id => $native->id]))->toBeEmpty();
});

it('rechecks publication and cutover after replaying features before persistence', function (string $change) {
    [$game,$native] = periodFeatureRetirementFixture();
    $builder = Mockery::mock(MlbPeriodFeatureBuilder::class);
    $builder->shouldReceive('liveFeaturesForGames')->once()->andReturnUsing(function () use ($native, $change) {
        if ($change === 'withdrawn') {
            $native->update(['publication_state' => 'withdrawn', 'withdrawn_at' => now()]);
        } else {
            config(['prediction_lifecycle.canonical_reads.mlb' => false]);
        }

        return [$native->sportEvent->mlbGame->id => ['first_3_moneyline' => ['feature_home_pitcher_elo' => 1600]]];
    });
    expect((new MlbPeriodFeatureStore($builder))->materialize(collect([$game])))->toBeEmpty()
        ->and(PeriodFeatureSnapshot::count())->toBe(0);
})->with(['withdrawn', 'cutover']);

it('refuses destructive rollback after native period features are recorded', function () {
    [$game] = periodFeatureRetirementFixture('withdrawn');
    app(MlbPeriodFeatureStore::class)->materialize(collect([$game]));
    $migration = require database_path('migrations/2026_10_30_175657_add_forecast_lineage_to_mlb_period_feature_snapshots.php');
    expect(fn () => $migration->down())->toThrow(LogicException::class, 'Preserve native MLB period feature lineage');
});

it('separates identical numerical features from different canonical revisions', function () {
    [$game,$first] = periodFeatureRetirementFixture();
    $store = app(MlbPeriodFeatureStore::class);
    $old = $store->materialize(collect([$game]))->sole();
    $run = CalculationRun::factory()->create(['sport_event_id' => $first->sport_event_id,
        'event_input_snapshot_id' => $first->calculationRun->event_input_snapshot_id,
        'calculation_release_id' => $first->calculationRun->calculation_release_id, 'status' => 'succeeded']);
    $next = CanonicalPrediction::factory()->create(['sport' => 'mlb', 'sport_event_id' => $first->sport_event_id,
        'calculation_run_id' => $run->id, 'phase' => 'pregame', 'publication_state' => 'draft', 'published_at' => null,
        'generated_at' => now()->subMinute(), 'revision' => $first->refresh()->revision + 1,
        'detail_source' => null, 'detail_sport' => null, 'detail_id' => null]);
    foreach ($first->markets as $market) {
        PredictionMarket::factory()->create(['prediction_id' => $next->id, ...$market->only(['market_type', 'selection', 'projected_line', 'probability', 'confidence_score'])]);
    }
    $first->update(['publication_state' => 'superseded', 'superseded_at' => now()]);
    $next->update(['publication_state' => 'published', 'published_at' => now()->subMinute()]);
    $new = $store->materialize(collect([$game]))->sole();
    expect($new->features)->toEqual($old->features)->and($new->feature_hash)->not->toBe($old->feature_hash)
        ->and($new->canonical_prediction_id)->toBe($next->id)
        ->and($store->forGames(collect([$game]), [$game->id => $first->id]))->toBeEmpty()
        ->and($store->forGames(collect([$game]), [$game->id => $next->id])[$game->id])->toEqual($new->features);
    expect(fn () => $new->update(['canonical_prediction_id' => $first->id]))->toThrow(LogicException::class, 'immutable');
});

it('bounds native period-source hydration without reading historical feature snapshots', function () {
    $games = collect();
    for ($index = 0; $index < 26; $index++) {
        $games->push(periodFeatureRetirementFixture()[0]);
    }
    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        $sources = app(MlbPeriodForecastSource::class)->forGames($games);
        $sql = collect(DB::getQueryLog())->pluck('query');
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
    expect($sources)->toHaveCount(26)->and($sql->implode(' '))->not->toContain('prediction_feature_snapshots')->not->toContain('mlb_predictions');
    expect($sql->filter(fn ($query) => str_starts_with($query, 'select "predictions".*') && str_contains($query, 'limit 25'))->count())->toBe(2);
});

it('preserves historical features across a safe migration rollback and reapply', function () {
    [$game] = periodFeatureRetirementFixture();
    config(['prediction_lifecycle.canonical_reads.mlb' => false]);
    $old = app(MlbPeriodFeatureStore::class)->materialize(collect([$game]))->sole();
    $migration = require database_path('migrations/2026_10_30_175657_add_forecast_lineage_to_mlb_period_feature_snapshots.php');
    $migration->down();
    $migration->up();
    expect($old->fresh()->forecast_source)->toBe('legacy')->and($old->fresh()->canonical_prediction_id)->toBeNull()
        ->and($old->fresh()->feature_hash)->toBe($old->feature_hash)->and($old->fresh()->features)->toEqual($old->features);
});
