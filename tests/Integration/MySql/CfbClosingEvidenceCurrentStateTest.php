<?php

use App\Models\CFB\Game;
use App\Models\CFB\Prediction;
use App\Models\CFB\Team;
use App\Models\MarketQuote;
use App\Models\PredictionPostgameEnrichment;
use App\Services\Api\V2\CfbClosingMarketEvidenceQuery;
use App\Services\CFB\CfbClosingMarketEvidenceRecorder;
use App\Services\CFB\CfbSnapshotCaptureIntegrity;
use App\Services\OddsApi\GameOddsSnapshotRecorder;
use App\Services\Predictions\PredictionFeatureSnapshotRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    expect(DB::connection()->getDriverName())->toBe('mysql');
    $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
    $this->previousPhpTimezone = date_default_timezone_get();
    date_default_timezone_set('UTC');
    config(['app.timezone' => 'UTC', 'database.connections.closing_writer' => config('database.connections.mysql')]);
    DB::statement("SET SESSION time_zone = '+00:00'");
    $this->travelTo(CarbonImmutable::parse('2026-09-04T12:00:00.111222Z'));
    Http::preventStrayRequests();
});

afterEach(function () {
    DB::setDefaultConnection('mysql');
    foreach ([DB::connection(), DB::connection('closing_writer')] as $connection) {
        while ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }
    }
    DB::statement("SET SESSION time_zone = '+00:00'");
    DB::purge('closing_writer');
    date_default_timezone_set($this->previousPhpTimezone);
});

function mysqlCfbClosingFixture(): array
{
    $home = Team::factory()->create(['school' => 'Synthetic Home', 'division' => 'FBS']);
    $away = Team::factory()->create(['school' => 'Synthetic Away', 'division' => 'FBS']);
    $game = Game::factory()->create(['season' => 2026, 'week' => 1, 'season_type' => 'regular',
        'home_team_id' => $home->id, 'away_team_id' => $away->id, 'status' => 'STATUS_SCHEDULED',
        'game_date' => '2026-09-05', 'game_time' => '19:00:00', 'home_score' => null, 'away_score' => null]);
    $prediction = Prediction::create(['game_id' => $game->id, 'home_elo' => 1500, 'away_elo' => 1500,
        'predicted_spread' => 8.5, 'predicted_total' => 50, 'win_probability' => .6, 'confidence_score' => 55,
        'model_version' => 'synthetic-v1', 'feature_version' => 'synthetic-v1', 'blend_version' => 'baseline-v1']);
    $entry = ['model_pick_side' => 'home', 'current_home_margin' => 6.5, 'current_bookmaker_home_line' => -6.5];
    $snapshot = app(PredictionFeatureSnapshotRecorder::class)->record($prediction, $game, 'cfb', $prediction->attributesToArray(), [
        'features' => ['long_key' => 1, 'a' => 2], 'outputs' => ['predicted_spread' => 8.5, 'predicted_total' => 50],
        'market_context' => ['cfb_market_movement' => $entry], 'model_metadata' => ['cfb_market_movement' => $entry]]);
    test()->travelTo(CarbonImmutable::parse('2026-09-05T18:45:00Z'));
    app(GameOddsSnapshotRecorder::class)->record('cfb', $game,
        ['id' => 'synthetic-closing', 'commence_time' => '2026-09-05T19:00:00Z'],
        ['home_team' => $home->school, 'away_team' => $away->school, 'bookmakers' => [['key' => 'synthetic',
            'title' => 'Synthetic', 'markets' => [['key' => 'spreads', 'outcomes' => [
                ['name' => $home->school, 'price' => -110, 'point' => -7.5], ['name' => $away->school, 'price' => -110, 'point' => 7.5],
            ]]]]]], Carbon::parse(now()->toIso8601String()));
    test()->travelTo(CarbonImmutable::parse('2026-09-06T01:00:00.123456Z'));
    $game->update(['status' => 'STATUS_FINAL', 'home_score' => 31, 'away_score' => 20]);

    return [$game, $prediction, $snapshot];
}

it('retains canonical JSON capture hashes and UTC receipt microseconds under changed app and SQL timezones', function () {
    [$game, $prediction, $snapshot] = mysqlCfbClosingFixture();
    $proof = app(CfbSnapshotCaptureIntegrity::class)->inspect($snapshot->fresh(), $game->fresh());
    expect($proof['status'])->toBe('verified_capture_v2')->and($proof['reasons'])->toBe([]);
    $record = app(CfbClosingMarketEvidenceRecorder::class)->recordForPrediction($prediction, $game);
    $expected = $record->observed_at->format('Y-m-d\TH:i:s.u\Z');
    DB::statement("SET SESSION time_zone = '+05:30'");
    config(['app.timezone' => 'America/Los_Angeles']);
    date_default_timezone_set('Pacific/Auckland');
    $fresh = $record->fresh();
    $raw = DB::table('prediction_postgame_enrichments')->where('id', $record->id)->value('observed_at');
    $result = app(CfbClosingMarketEvidenceQuery::class)->forPredictions(collect([$prediction->fresh(['game'])]), collect([$prediction->id => $snapshot->fresh()]));
    expect($raw)->toBe('2026-09-06 01:00:00.123456')
        ->and($fresh->observed_at->format('Y-m-d\TH:i:s.u\Z'))->toBe($expected)
        ->and($fresh->toArray()['observed_at'])->toBe('2026-09-06T01:00:00.123456Z')
        ->and($result[$prediction->id]['availability_status'])->toBe('available');
});

it('serializes concurrent duplicate observations and appends A B A as three revisions', function () {
    [$game, $prediction] = mysqlCfbClosingFixture();
    $recorder = app(CfbClosingMarketEvidenceRecorder::class);
    $writer = DB::connection('closing_writer');
    $writer->statement("SET SESSION time_zone = '+00:00'");
    $timeout = (int) DB::selectOne('SELECT @@SESSION.innodb_lock_wait_timeout AS value')->value;
    DB::statement('SET SESSION innodb_lock_wait_timeout = 1');
    try {
        DB::setDefaultConnection('closing_writer');
        $writer->beginTransaction();
        $a = $recorder->recordForPrediction(Prediction::on('closing_writer')->findOrFail($prediction->id), Game::on('closing_writer')->findOrFail($game->id));
        DB::setDefaultConnection('mysql');
        try {
            $recorder->recordForPrediction($prediction, $game);
            test()->fail('Concurrent observation must wait for the owning game/snapshot transaction.');
        } catch (QueryException $exception) {
            expect((int) ($exception->errorInfo[1] ?? 0))->toBe(1205);
        }
        $writer->commit();
        $same = $recorder->recordForPrediction($prediction, $game);
        expect($same->id)->toBe($a->id)->and(PredictionPostgameEnrichment::count())->toBe(1);
        $quote = MarketQuote::where('side', 'home')->sole();
        DB::table('market_quotes')->where('id', $quote->id)->update(['line' => -8.5, 'bookmaker_home_line' => -8.5]);
        $b = $recorder->recordForPrediction($prediction, $game);
        DB::table('market_quotes')->where('id', $quote->id)->update(['line' => -7.5, 'bookmaker_home_line' => -7.5]);
        $again = $recorder->recordForPrediction($prediction, $game);
        expect($again->revision)->toBe(3)->and($again->supersedes_enrichment_id)->toBe($b->id)
            ->and($again->semantic_hash)->toBe($a->semantic_hash)->and($again->id)->not->toBe($a->id);
    } finally {
        DB::setDefaultConnection('mysql');
        if ($writer->transactionLevel() > 0) {
            $writer->rollBack();
        }
        DB::statement('SET SESSION innodb_lock_wait_timeout = '.$timeout);
    }
});

it('selects current stored closing values behind an inherited repeatable-read snapshot', function () {
    [$game, $prediction] = mysqlCfbClosingFixture();
    DB::beginTransaction();
    $old = MarketQuote::where('side', 'home')->sole();
    DB::connection('closing_writer')->table('market_quotes')->where('id', $old->id)->update(['line' => -8.5, 'bookmaker_home_line' => -8.5]);
    $record = app(CfbClosingMarketEvidenceRecorder::class)->recordForPrediction($prediction, $game);
    DB::commit();
    expect((float) $record->output['closing_line_value_points'])->toBe(2.0)
        ->and((float) $record->output['closing_bookmaker_home_line'])->toBe(-8.5)
        ->and($record->availability_status)->toBe('available');
});

it('CFB v2 refresh discovers current final games and forecasts behind an old snapshot', function () {
    [$game, $prediction] = mysqlCfbClosingFixture();
    $attributes = $prediction->fresh()->getAttributes();
    $prediction->delete();
    $game->update(['status' => 'STATUS_SCHEDULED', 'home_score' => null, 'away_score' => null]);
    DB::beginTransaction();
    expect(Game::whereKey($game->id)->firstOrFail()->status)->toBe('STATUS_SCHEDULED');
    expect(Prediction::where('game_id', $game->id)->count())->toBe(0);
    $writer = DB::connection('closing_writer');
    $writer->transaction(function () use ($writer, $game, $attributes): void {
        $writer->table('cfb_games')->where('id', $game->id)->update(['status' => 'STATUS_FINAL', 'home_score' => 31, 'away_score' => 20]);
        $writer->table('cfb_predictions')->insert($attributes);
    });
    expect(Game::whereKey($game->id)->lockForUpdate()->firstOrFail()->status)->toBe('STATUS_FINAL');
    $records = app(CfbClosingMarketEvidenceRecorder::class)->recordForGame($game->id);
    DB::commit();
    expect($records)->toHaveCount(1)->and($records[0]->availability_status)->toBe('available')
        ->and(Prediction::findOrFail($prediction->id)->graded_at)->toBeNull();
});
