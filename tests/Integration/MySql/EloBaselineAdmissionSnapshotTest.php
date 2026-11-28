<?php

use App\Models\NFL\Team;
use App\Models\SportEloEntityHead;
use App\Models\SportEloSeasonInitialization;
use App\Models\SportEloWriterGate;
use App\Services\Sports\Elo\EloBaselineAdmission;
use App\Services\Sports\Elo\EloSportRegistry;
use App\Services\Sports\Elo\EloWriterCapabilities;
use App\Services\Sports\Elo\EloWriteTargetIdentity;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    expect(DB::connection()->getDriverName())->toBe('mysql');
    $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
    config(['database.connections.elo_admission_peer' => config('database.connections.mysql'), 'elo_integrity.enabled' => true]);
    app()->instance(EloWriterCapabilities::class, new class extends EloWriterCapabilities
    {
        public function supports(string $sport, int $protocol): bool
        {
            return $sport === 'nfl' && $protocol === 1;
        }
    });
    $definition = app(EloSportRegistry::class)->resolve('nfl', 'team');
    SportEloWriterGate::whereKey('nfl')->update(['mode' => 'admitting', 'calculation_version' => $definition['version'],
        'configuration_hash' => $definition['configuration_hash'], 'drain_reference' => 'synthetic-drained-fixture', 'drain_verified_at' => now()->subMinute()]);
    $this->team = Team::factory()->create(['elo_rating' => 1500]);
});

afterEach(function () {
    foreach ([DB::connection(), DB::connection('elo_admission_peer')] as $connection) {
        while ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }
    }
    DB::purge('elo_admission_peer');
});

it('refuses current projection drift behind old default and named-connection snapshots', function (bool $namedReader) {
    $reader = $namedReader ? DB::connection('elo_admission_peer') : DB::connection();
    $writer = $namedReader ? DB::connection() : DB::connection('elo_admission_peer');
    $service = app(EloBaselineAdmission::class);
    $report = $service->inspect('nfl', 2026, 'team', [$this->team->id], $reader->getName());
    $reader->beginTransaction();
    $reader->table('nfl_teams')->where('id', $this->team->id)->first();
    $writer->table('nfl_teams')->where('id', $this->team->id)->update(['elo_rating' => 1600]);
    expect(fn () => $service->apply('nfl', 2026, 'team', [$this->team->id], $report['fingerprint'], 'review-fixture', $reader->getName()))
        ->toThrow(RuntimeException::class, 'source changed or contains unverified');
    $reader->rollBack();
    expect($writer->table('sport_elo_season_initializations')->count())->toBe(0)
        ->and($writer->table('sport_elo_entity_heads')->count())->toBe(0)
        ->and((int) $writer->table('nfl_teams')->where('id', $this->team->id)->value('elo_rating'))->toBe(1600);
})->with([false, true]);

it('reuses a named writer admission committed behind an old empty receipt snapshot', function () {
    $service = app(EloBaselineAdmission::class);
    $report = $service->inspect('nfl', 2026, 'team', [$this->team->id]);
    DB::beginTransaction();
    DB::table('sport_elo_season_initializations')->get();
    $other = $service->apply('nfl', 2026, 'team', [$this->team->id], $report['fingerprint'], 'named-writer-review', 'elo_admission_peer');
    $receipt = SportEloSeasonInitialization::on('elo_admission_peer')->sole()->getAttributes();
    $again = $service->apply('nfl', 2026, 'team', [$this->team->id], $report['fingerprint'], 'reader-review');
    DB::commit();
    expect($again['receipt_ids'])->toBe($other['receipt_ids'])
        ->and(SportEloSeasonInitialization::sole()->getAttributes())->toBe($receipt)
        ->and(SportEloEntityHead::count())->toBe(1);
});

it('keeps chronology datetime precision and all new parent receipt foreign keys restrictive', function () {
    $database = DB::connection()->getDatabaseName();
    $columns = DB::table('information_schema.columns')->where('table_schema', $database)
        ->where(function ($query): void {
            $query->where(fn ($q) => $q->where('table_name', 'sport_elo_applications')->where('column_name', 'played_at'))
                ->orWhere(fn ($q) => $q->where('table_name', 'sport_elo_entity_heads')->where('column_name', 'last_played_at'));
        })->get(['DATA_TYPE as data_type', 'DATETIME_PRECISION as datetime_precision']);
    expect($columns)->toHaveCount(2);
    foreach ($columns as $column) {
        expect($column->data_type)->toBe('datetime')->and((int) $column->datetime_precision)->toBe(6);
    }
    $foreignKeys = DB::table('information_schema.referential_constraints')->where('constraint_schema', $database)
        ->whereIn('table_name', ['sport_elo_season_initializations', 'sport_elo_applications', 'sport_elo_application_effects', 'sport_elo_entity_heads'])
        ->get(['DELETE_RULE as delete_rule']);
    expect($foreignKeys)->toHaveCount(9);
    foreach ($foreignKeys as $foreignKey) {
        expect($foreignKey->delete_rule)->toBe('RESTRICT');
    }
});

it('binds actual MySQL approval metadata to the same physical target across aliases', function () {
    $targets = app(EloWriteTargetIdentity::class);
    $primary = $targets->describe(DB::connection());
    expect($primary)->toBe($targets->describe(DB::connection('elo_admission_peer')))
        ->and($primary['scope'])->toBe('physical_database')
        ->and($primary['portable_approval'])->toBeTrue();
});
