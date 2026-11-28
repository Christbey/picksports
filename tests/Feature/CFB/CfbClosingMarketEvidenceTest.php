<?php

use App\Actions\CFB\GradePredictions;
use App\Http\Resources\Api\V2\SportPredictionResource;
use App\Models\CFB\Game;
use App\Models\CFB\Prediction;
use App\Models\CFB\Team;
use App\Models\MarketQuote;
use App\Models\PredictionEvaluation;
use App\Models\PredictionFeatureSnapshot;
use App\Models\PredictionPostgameEnrichment;
use App\Services\Api\V2\CfbClosingMarketEvidenceQuery;
use App\Services\Api\V2\SportContextResolver;
use App\Services\Api\V2\SportPredictionPresentationService;
use App\Services\CFB\CfbClosingMarketEvidenceRecorder;
use App\Services\CFB\CfbSnapshotCaptureIntegrity;
use App\Services\OddsApi\GameOddsSnapshotRecorder;
use App\Services\Predictions\PredictionFeatureSnapshotRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\ObservedCfbGameFixture;

beforeEach(function () {
    $this->originalPhpTimezone = date_default_timezone_get();
    date_default_timezone_set('UTC');
    config(['app.timezone' => 'UTC']);
    $this->travelTo(CarbonImmutable::parse('2026-09-04T12:00:00Z'));
    Http::preventStrayRequests();
});

afterEach(function () {
    date_default_timezone_set($this->originalPhpTimezone);
});

/** @return array{Game,Prediction,PredictionFeatureSnapshot} */
function cfbClosingEvidenceFixture(array $metadataOverrides = [], array $gameOverrides = []): array
{
    $home = Team::factory()->create(['school' => 'Synthetic Home', 'division' => 'FBS']);
    $away = Team::factory()->create(['school' => 'Synthetic Away', 'division' => 'FBS']);
    $game = Game::factory()->create(['season' => 2026, 'week' => 1, 'season_type' => 'regular',
        'home_team_id' => $home->id, 'away_team_id' => $away->id, 'status' => 'STATUS_SCHEDULED',
        'game_date' => '2026-09-05', 'game_time' => '19:00:00', 'home_score' => null, 'away_score' => null, ...$gameOverrides]);
    $game = ObservedCfbGameFixture::observe($game, '2026-09-05T19:00:00Z');
    $prediction = Prediction::create(['game_id' => $game->id, 'home_elo' => 1500, 'away_elo' => 1500,
        'predicted_spread' => 8.5, 'predicted_total' => 50, 'win_probability' => .6, 'confidence_score' => 55,
        'model_version' => 'synthetic-v1', 'feature_version' => 'synthetic-v1', 'blend_version' => 'baseline-v1']);
    $entry = ['source' => 'market_quotes_consensus', 'model_pick_side' => 'home', 'model_home_margin' => 8.5,
        'current_bookmaker_home_line' => -6.5, 'current_home_margin' => 6.5, 'current_captured_at' => now()->toIso8601String()];
    $snapshot = app(PredictionFeatureSnapshotRecorder::class)->record($prediction, $game, 'cfb', $prediction->attributesToArray(), [
        'features' => ['market_movement' => $entry], 'outputs' => ['predicted_spread' => 8.5, 'predicted_total' => 50],
        'market_context' => ['cfb_market_movement' => $entry], 'model_metadata' => ['cfb_market_movement' => $entry, ...$metadataOverrides],
    ]);

    return [$game, $prediction, $snapshot];
}

function cfbClosingEvidenceQuote(Game $game, float $line = -7.5): void
{
    $home = $game->homeTeam->school;
    $away = $game->awayTeam->school;
    app(GameOddsSnapshotRecorder::class)->record('cfb', $game,
        ['id' => 'synthetic-closing-'.$game->id, 'commence_time' => '2026-09-05T19:00:00Z'],
        ['home_team' => $home, 'away_team' => $away, 'bookmakers' => [['key' => 'synthetic-book', 'title' => 'Synthetic Book',
            'markets' => [['key' => 'spreads', 'outcomes' => [
                ['name' => $home, 'price' => -110, 'point' => $line], ['name' => $away, 'price' => -110, 'point' => -$line],
            ]]]]]], Carbon::parse(now()->toIso8601String()));
}

/** Trusted corruption/storage fixture only; production instance APIs must refuse these writes. */
function cfbTrustedSnapshotFixtureWrite(PredictionFeatureSnapshot $snapshot, array $changes): void
{
    $values = [];
    foreach ($changes as $key => $value) {
        $values[$key] = is_array($value) ? json_encode($value, JSON_THROW_ON_ERROR) : $value;
    }
    DB::table($snapshot->getTable())->where('id', $snapshot->id)->update($values);
    $snapshot->refresh();
}

it('keeps captured entry snapshot bytes and hash unchanged when postgame closing value is graded', function () {
    [$game, $prediction, $snapshot] = cfbClosingEvidenceFixture();
    $before = $snapshot->fresh()->getAttributes();
    $this->travelTo(CarbonImmutable::parse('2026-09-05T18:45:00Z'));
    cfbClosingEvidenceQuote($game);
    $this->travelTo(CarbonImmutable::parse('2026-09-06T01:00:00Z'));
    $game->update(['status' => 'STATUS_FINAL', 'home_score' => 31, 'away_score' => 20]);
    $result = app(GradePredictions::class)->executeForGame($game->id);
    expect($result['graded'])->toBe(1)->and($prediction->fresh()->graded_at)->not->toBeNull();
    expect($snapshot->fresh()->getAttributes())->toBe($before);
});

function cfbCloseEvidenceGame(Game $game, bool $withQuote = true): void
{
    test()->travelTo(CarbonImmutable::parse('2026-09-05T18:45:00Z'));
    if ($withQuote) {
        cfbClosingEvidenceQuote($game);
    }
    test()->travelTo(CarbonImmutable::parse('2026-09-06T01:00:00Z'));
    $game->update(['status' => 'STATUS_FINAL', 'home_score' => 31, 'away_score' => 20]);
}

it('records a typed closing receipt idempotently without changing captured inputs', function () {
    [$game, $prediction, $snapshot] = cfbClosingEvidenceFixture();
    cfbCloseEvidenceGame($game);
    app(GradePredictions::class)->executeForGame($game->id);
    $receipt = PredictionPostgameEnrichment::sole();
    expect($receipt->availability_status)->toBe('available')
        ->and($receipt->source_integrity_status)->toBe('verified_capture_v2')
        ->and($receipt->output['closing_line_value_points'])->toBe(1)
        ->and($receipt->output['closing_line_value_bucket'])->toBe('positive')
        ->and($receipt->prediction_feature_snapshot_id)->toBe($snapshot->id);
    $before = $receipt->fresh()->getAttributes();
    app(GradePredictions::class)->executeForGame($game->id);
    expect(PredictionPostgameEnrichment::count())->toBe(1)
        ->and($receipt->fresh()->getAttributes())->toBe($before);
});

it('appends a third receipt for A B A instead of resurrecting obsolete evidence', function () {
    [$game, $prediction] = cfbClosingEvidenceFixture();
    cfbCloseEvidenceGame($game);
    $recorder = app(CfbClosingMarketEvidenceRecorder::class);
    $a = $recorder->recordForPrediction($prediction, $game);
    $quote = MarketQuote::where('side', 'home')->sole();
    $quote->newQuery()->whereKey($quote->id)->update(['line' => -8.5, 'bookmaker_home_line' => -8.5]);
    $b = $recorder->recordForPrediction($prediction, $game);
    $quote->newQuery()->whereKey($quote->id)->update(['line' => -7.5, 'bookmaker_home_line' => -7.5]);
    $again = $recorder->recordForPrediction($prediction, $game);
    expect($again->id)->not->toBe($a->id)->and($again->revision)->toBe(3)
        ->and($again->supersedes_enrichment_id)->toBe($b->id)
        ->and($again->semantic_hash)->toBe($a->semantic_hash)
        ->and(PredictionPostgameEnrichment::count())->toBe(3);
});

it('declines legacy missing and changed capture evidence without certifying or repairing it', function (string $change) {
    [$game, $prediction, $snapshot] = cfbClosingEvidenceFixture();
    $metadata = $snapshot->model_metadata;
    if ($change === 'legacy') {
        unset($metadata['snapshot_integrity']);
        cfbTrustedSnapshotFixtureWrite($snapshot, ['model_metadata' => $metadata]);
    } elseif ($change === 'marker') {
        $metadata['snapshot_integrity']['captured_at'] = '2000-01-01T00:00:00.000000Z';
        cfbTrustedSnapshotFixtureWrite($snapshot, ['model_metadata' => $metadata]);
    } elseif ($change === 'payload') {
        cfbTrustedSnapshotFixtureWrite($snapshot, ['features' => ['changed' => true]]);
    } elseif ($change === 'timing') {
        $game->update(['game_time' => '20:00:00']);
    }
    $before = $snapshot->fresh()->getAttributes();
    cfbCloseEvidenceGame($game, $change !== 'no_quote');
    app(GradePredictions::class)->executeForGame($game->id);
    $receipt = PredictionPostgameEnrichment::sole();
    expect($receipt->availability_status)->toBe('unavailable')
        ->and($receipt->reason_codes)->not->toBeEmpty()
        ->and($snapshot->fresh()->getAttributes())->toBe($before);
})->with(['legacy', 'marker', 'payload', 'timing', 'no_quote']);

it('replaces caller supplied capture certification and ignores unhashed metadata as entry authority', function () {
    [$game, $prediction, $snapshot] = cfbClosingEvidenceFixture(['snapshot_integrity' => ['content_hash' => str_repeat('f', 64)]]);
    expect($snapshot->model_metadata['snapshot_integrity']['content_hash'])->not->toBe(str_repeat('f', 64));
    $metadata = $snapshot->model_metadata;
    $metadata['cfb_market_movement']['current_home_margin'] = 999;
    cfbTrustedSnapshotFixtureWrite($snapshot, ['model_metadata' => $metadata]);
    cfbCloseEvidenceGame($game);
    $receipt = app(CfbClosingMarketEvidenceRecorder::class)->recordForPrediction($prediction, $game);
    expect($receipt->availability_status)->toBe('available')
        ->and($receipt->output['closing_line_value_points'])->toBe(1);
});

it('guards postgame receipts through quiet delete save and numeric APIs', function (string $operation) {
    [$game, $prediction] = cfbClosingEvidenceFixture();
    cfbCloseEvidenceGame($game);
    $receipt = app(CfbClosingMarketEvidenceRecorder::class)->recordForPrediction($prediction, $game);
    $before = $receipt->fresh()->getAttributes();
    expect(fn () => match ($operation) {
        'save' => $receipt->updateQuietly(['output' => ['tampered' => true]]),
        'delete' => $receipt->deleteQuietly(),
        'numeric' => $receipt->incrementQuietly('revision'),
    })->toThrow(LogicException::class);
    expect($receipt->fresh()->getAttributes())->toBe($before);
})->with(['save', 'delete', 'numeric']);

it('presents batched postgame provenance without any read-side write and holds changed game context', function () {
    [$game, $prediction, $snapshot] = cfbClosingEvidenceFixture();
    cfbCloseEvidenceGame($game);
    app(GradePredictions::class)->executeForGame($game->id);
    $prediction = $prediction->fresh(['game']);
    $context = app(SportContextResolver::class)->resolve('cfb');
    $presentations = app(SportPredictionPresentationService::class)->forPredictions($context, collect([$prediction]));
    $data = (new SportPredictionResource($prediction, $context, $presentations->get($prediction->id)))
        ->resolve(Request::create('/synthetic', 'GET'));
    expect(data_get($data, 'cfb_signal_context.closing_market_evidence.availability_status'))->toBe('available')
        ->and(data_get($data, 'cfb_signal_context.closing_market_evidence.phase'))->toBe('postgame')
        ->and((float) data_get($data, 'cfb_signal_context.market_movement.closing_line_value_points'))->toBe(1.0);
    $count = PredictionPostgameEnrichment::count();
    $game->update(['away_score' => 21]);
    $result = app(CfbClosingMarketEvidenceQuery::class)->forPredictions(collect([$prediction->fresh(['game'])]), collect([$prediction->id => $snapshot->fresh()]));
    expect($result[$prediction->id]['availability_status'])->toBe('unavailable')
        ->and($result[$prediction->id]['reason_codes'])->toContain('postgame_game_context_changed')
        ->and($result[$prediction->id]['output'])->toBeNull()
        ->and(PredictionPostgameEnrichment::count())->toBe($count);
});

it('uses one receipt SELECT per bounded page instead of a per-forecast lookup', function (int $size) {
    $predictions = collect();
    $snapshots = collect();
    for ($i = 0; $i < $size; $i++) {
        $this->travelTo(CarbonImmutable::parse('2026-09-04T12:00:00Z'));
        [$game, $prediction, $snapshot] = cfbClosingEvidenceFixture();
        cfbCloseEvidenceGame($game);
        app(CfbClosingMarketEvidenceRecorder::class)->recordForPrediction($prediction, $game);
        $predictions->push($prediction->fresh(['game']));
        $snapshots->put($prediction->id, $snapshot->fresh());
    }
    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        $result = app(CfbClosingMarketEvidenceQuery::class)->forPredictions($predictions, $snapshots);
        $queries = collect(DB::getQueryLog());
    } finally {
        DB::disableQueryLog();
    }
    expect($queries->filter(fn ($query) => str_contains($query['query'], 'prediction_postgame_enrichments'))->count())->toBe(1)
        ->and($queries->contains(fn ($query) => preg_match('/^\s*(insert|update|delete)/i', $query['query']) === 1))->toBeFalse()
        ->and($result->where('availability_status', 'available')->count())->toBe($size);
})->with([1, 10, 50]);

it('keeps canonical capture verification stable under JSON object key reordering', function () {
    [$game, , $snapshot] = cfbClosingEvidenceFixture();
    cfbTrustedSnapshotFixtureWrite($snapshot, ['features' => array_reverse($snapshot->features, true), 'outputs' => array_reverse($snapshot->outputs, true)]);
    $proof = app(CfbSnapshotCaptureIntegrity::class)->inspect($snapshot->fresh(), $game);
    expect($proof['status'])->toBe('verified_capture_v2')->and($proof['reasons'])->toBe([]);
});

it('rolls back grade and evaluation writes if the typed evidence insert fails', function () {
    [$game, $prediction] = cfbClosingEvidenceFixture();
    cfbCloseEvidenceGame($game);
    $connection = $game->getConnection();
    $callbacks = new ReflectionProperty($connection, 'beforeExecutingCallbacks');
    $before = $callbacks->getValue($connection);
    $connection->beforeExecuting(function (string $sql): void {
        if (preg_match('/^\s*insert\s+into\s+[`"]?prediction_postgame_enrichments[`"]?\s/i', $sql)) {
            throw new RuntimeException('Synthetic closing receipt failure.');
        }
    });
    try {
        expect(fn () => app(GradePredictions::class)->executeForGame($game->id))->toThrow(RuntimeException::class, 'Synthetic closing receipt failure.');
    } finally {
        $callbacks->setValue($connection, $before);
    }
    expect($prediction->fresh()->graded_at)->toBeNull()
        ->and(PredictionEvaluation::count())->toBe(0)
        ->and(PredictionPostgameEnrichment::count())->toBe(0);
});

it('requires bounded explicit scope and leaves dry runs entirely nonmutating', function () {
    [$game] = cfbClosingEvidenceFixture();
    cfbCloseEvidenceGame($game);
    $this->artisan('cfb:record-closing-market-evidence')->assertExitCode(2);
    $this->artisan('cfb:record-closing-market-evidence', ['--game' => [(string) $game->id, (string) $game->id]])->assertExitCode(2);
    $this->artisan('cfb:record-closing-market-evidence', ['--game' => [(string) $game->id, '999999999'], '--apply' => true])->assertFailed();
    $this->artisan('cfb:record-closing-market-evidence', ['--game' => [(string) $game->id]])
        ->expectsOutputToContain('availability not assessed')->assertSuccessful();
    expect(PredictionPostgameEnrichment::count())->toBe(0);
});

it('reports appended reused and unavailable stored-only command outcomes accurately', function () {
    [$game, , $snapshot] = cfbClosingEvidenceFixture();
    cfbCloseEvidenceGame($game);
    $options = ['--game' => [(string) $game->id], '--apply' => true];
    $this->artisan('cfb:record-closing-market-evidence', $options)->expectsOutputToContain('appended 1, reused 0')->assertSuccessful();
    $this->artisan('cfb:record-closing-market-evidence', $options)->expectsOutputToContain('appended 0, reused 1')->assertSuccessful();
    $metadata = $snapshot->model_metadata;
    unset($metadata['snapshot_integrity']);
    cfbTrustedSnapshotFixtureWrite($snapshot, ['model_metadata' => $metadata]);
    $this->artisan('cfb:record-closing-market-evidence', $options)->expectsOutputToContain('legacy_unverified')->assertExitCode(2);
    expect(PredictionPostgameEnrichment::count())->toBe(2);
});

it('does not let backdated generated time or caller safety flags certify a postgame capture', function () {
    [$game, $prediction, $entry] = cfbClosingEvidenceFixture();
    cfbCloseEvidenceGame($game);
    $late = app(PredictionFeatureSnapshotRecorder::class)->record($prediction, $game, 'cfb', $prediction->attributesToArray(), [
        'features' => $entry->features, 'outputs' => $entry->outputs, 'market_context' => $entry->market_context,
        'model_metadata' => $entry->model_metadata, 'generated_at' => CarbonImmutable::parse('2026-09-04T12:00:00Z'),
        'run_type' => 'historical_reconstruction', 'point_in_time_verified' => true, 'pregame_safe' => true,
        'availability_status' => 'observed_pregame',
    ]);
    $record = app(CfbClosingMarketEvidenceRecorder::class)->recordForPrediction($prediction, $game);
    expect($record->prediction_feature_snapshot_id)->toBe($late->id)
        ->and($record->availability_status)->toBe('unavailable')
        ->and($record->reason_codes)->toContain('entry_not_observed_before_kickoff')
        ->and($late->model_metadata['snapshot_integrity']['captured_at'])->toBe('2026-09-06T01:00:00.000000Z');
});

it('reports missing entries and refuses oversized scopes without changing grades', function () {
    [$game, $prediction, $snapshot] = cfbClosingEvidenceFixture();
    // Trusted missing-history fixture; an actual v2 model deletion must be refused.
    DB::table($snapshot->getTable())->where('id', $snapshot->id)->delete();
    cfbCloseEvidenceGame($game);
    $this->artisan('cfb:record-closing-market-evidence', ['--game' => array_map('strval', range(1, 101)), '--apply' => true])->assertExitCode(2);
    $this->artisan('cfb:record-closing-market-evidence', ['--game' => [(string) $game->id], '--apply' => true])
        ->expectsOutputToContain('1 missing')->assertExitCode(2);
    expect($prediction->fresh()->graded_at)->toBeNull()
        ->and(PredictionPostgameEnrichment::count())->toBe(0);
});

it('refuses candidate rollback without deleting immutable postgame receipts or entry snapshots', function () {
    [$game, $prediction, $snapshot] = cfbClosingEvidenceFixture();
    cfbCloseEvidenceGame($game);
    $receipt = app(CfbClosingMarketEvidenceRecorder::class)->recordForPrediction($prediction, $game);
    $before = $receipt->fresh()->getAttributes();
    $migration = require database_path('migrations/2026_11_26_070000_create_prediction_postgame_enrichments.php');
    expect(fn () => $migration->down())->toThrow(LogicException::class, 'retained');
    expect($receipt->fresh()->getAttributes())->toBe($before)
        ->and($snapshot->fresh())->not->toBeNull();
});

it('CFB v2 refuses an invented cutoff when a captured input omits its time', function () {
    [$game, $prediction, $snapshot] = cfbClosingEvidenceFixture();
    // The stored column is NOT NULL; a partially loaded/stale capture input can still omit it.
    $unknown = clone $game;
    $unknown->setRawAttributes([...$game->getAttributes(), 'game_time' => null], true);
    $capture = app(PredictionFeatureSnapshotRecorder::class)->record($prediction, $unknown, 'cfb', $prediction->attributesToArray(), [
        'features' => $snapshot->features, 'outputs' => $snapshot->outputs, 'market_context' => $snapshot->market_context,
        'model_metadata' => $snapshot->model_metadata,
    ]);
    expect($capture->game_start_at)->toBeNull()
        ->and($capture->pregame_safe)->toBeFalse()
        ->and($capture->availability_status)->toBe('game_start_unknown');
    $proof = app(CfbSnapshotCaptureIntegrity::class)->inspect($capture, $unknown);
    expect($proof['reasons'])->toContain('entry_kickoff_time_unobserved')
        ->and(data_get($capture->model_metadata, 'snapshot_integrity.kickoff_at'))->toBeNull();
});

it('CFB v2 admits only valid captured literal dates and times without normalization guesses', function (string $field, mixed $value, string $reason) {
    [$game, , $snapshot] = cfbClosingEvidenceFixture();
    $badGame = clone $game;
    $badGame->setRawAttributes([...$game->getAttributes(), $field => $value], true);
    $copy = clone $snapshot;
    $metadata = $copy->model_metadata;
    $integrity = app(CfbSnapshotCaptureIntegrity::class);
    $metadata['snapshot_integrity'] = $integrity->marker($badGame, $copy->features, $copy->outputs, $copy->market_context, CarbonImmutable::parse('2026-09-05T19:00:00Z'));
    $copy->model_metadata = $metadata;
    expect($integrity->inspect($copy, $badGame)['reasons'])->toContain($reason);
})->with([
    ['game_date', '2026-02-30', 'entry_kickoff_date_unobserved'],
    ['game_time', 'TBA', 'entry_kickoff_time_unobserved'],
    ['game_time', '25:00:00', 'entry_kickoff_time_unobserved'],
    ['game_time', '', 'entry_kickoff_time_unobserved'],
]);

it('CFB v2 requires positive distinct captured teams', function (string $change) {
    [$game, , $snapshot] = cfbClosingEvidenceFixture();
    $badGame = clone $game;
    $changes = match ($change) {
        'home' => ['home_team_id' => null], 'away' => ['away_team_id' => null],
        'same' => ['away_team_id' => $game->home_team_id],
    };
    $badGame->setRawAttributes([...$game->getAttributes(), ...$changes], true);
    $copy = clone $snapshot;
    $metadata = $copy->model_metadata;
    $integrity = app(CfbSnapshotCaptureIntegrity::class);
    $metadata['snapshot_integrity'] = $integrity->marker($badGame, $copy->features, $copy->outputs, $copy->market_context, CarbonImmutable::parse('2026-09-05T19:00:00Z'));
    $copy->model_metadata = $metadata;
    expect($integrity->inspect($copy, $badGame)['reasons'])->toContain('entry_team_identity_unobserved');
})->with(['home', 'away', 'same']);

it('CFB v2 captured rows reject ordinary quiet numeric and delete mutation APIs', function (string $operation) {
    [, , $snapshot] = cfbClosingEvidenceFixture();
    $before = $snapshot->fresh()->getAttributes();
    expect(fn () => match ($operation) {
        'ordinary' => $snapshot->update(['outputs' => ['changed' => true]]),
        'quiet' => $snapshot->updateQuietly(['model_metadata' => []]),
        'numeric' => $snapshot->incrementQuietly('prediction_id', 1),
        'delete' => $snapshot->deleteQuietly(),
    })->toThrow(LogicException::class);
    expect($snapshot->fresh()->getAttributes())->toBe($before);
})->with(['ordinary', 'quiet', 'numeric', 'delete']);

it('CFB v2 cannot be promoted onto an existing legacy row through normalized dirty metadata', function (string $operation) {
    [, , $snapshot] = cfbClosingEvidenceFixture();
    $marked = $snapshot->model_metadata;
    $legacyMetadata = $marked;
    unset($legacyMetadata['snapshot_integrity']);
    // Trusted historical fixture: model APIs must not perform this removal on a real v2 capture.
    DB::table($snapshot->getTable())->where('id', $snapshot->id)->update(['model_metadata' => json_encode($legacyMetadata, JSON_THROW_ON_ERROR)]);
    $legacy = $snapshot->fresh();
    $before = $legacy->getAttributes();
    expect(fn () => match ($operation) {
        'save' => (function () use ($legacy, $marked) {
            $legacy->fill(['model_metadata' => $marked]);
            expect($legacy->getDirty()['model_metadata'])->toBeString();

            return $legacy->save();
        })(),
        'quiet' => $legacy->updateQuietly(['model_metadata' => $marked]),
        'numeric' => $legacy->incrementQuietly('prediction_id', 0, ['model_metadata' => json_encode($marked, JSON_THROW_ON_ERROR)]),
    })->toThrow(LogicException::class);
    expect($legacy->fresh()->getAttributes())->toBe($before);
})->with(['save', 'quiet', 'numeric']);

it('CFB v2 cannot be acquired by changing the effective sport of an existing row', function (string $sport) {
    [, , $snapshot] = cfbClosingEvidenceFixture();
    // Trusted fixture of an existing non-CFB row bearing foreign marker text.
    DB::table($snapshot->getTable())->where('id', $snapshot->id)->update(['sport' => 'nba']);
    $legacy = $snapshot->fresh();
    expect(fn () => $legacy->updateQuietly(['sport' => $sport]))->toThrow(LogicException::class);
    expect($legacy->fresh()->sport)->toBe('nba');
})->with(['cfb', 'CFB']);

it('CFB v2 provenance backfill skips and reports immutable captured rows', function () {
    [, , $snapshot] = cfbClosingEvidenceFixture();
    $before = $snapshot->fresh()->getAttributes();
    $this->artisan('sports:backfill-snapshot-provenance', ['--sport' => 'cfb', '--limit' => 1])->assertSuccessful();
    expect($snapshot->fresh()->getAttributes())->toBe($before);
});
