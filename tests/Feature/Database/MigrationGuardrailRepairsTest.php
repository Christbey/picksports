<?php

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** All migration mutations in these tests target a separate disposable SQLite connection. */
function withGuardrailFixture(callable $test): void
{
    $original = DB::getDefaultConnection();
    config(['database.connections.guardrail_fixture' => [
        'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
    ]]);
    DB::setDefaultConnection('guardrail_fixture');
    Schema::clearResolvedInstance('db.schema');
    try {
        $test();
    } finally {
        DB::setDefaultConnection($original);
        Schema::clearResolvedInstance('db.schema');
        DB::purge('guardrail_fixture');
    }
}

it('preserves version identifiers, indexes and nullability while expanding all six version columns', function () {
    withGuardrailFixture(function () {
        $nullable = ['nfl_predictions', 'prediction_evaluations'];
        $tables = [...$nullable, 'prediction_feature_snapshots', 'nfl_signal_observations', 'model_runs', 'model_artifacts'];
        foreach ($tables as $name) {
            Schema::create($name, function (Blueprint $table) use ($name, $nullable) {
                $table->id();
                $table->string('model_version', 64)->nullable(in_array($name, $nullable, true))->index();
            });
            DB::table($name)->insert(['model_version' => str_repeat('v', 64)]);
        }
        (require database_path('migrations/2026_10_20_190000_expand_nfl_prediction_model_versions.php'))->up();
        foreach ($tables as $name) {
            expect(DB::table($name)->value('model_version'))->toBe(str_repeat('v', 64));
            $column = collect(Schema::getColumns($name))->firstWhere('name', 'model_version');
            expect($column['nullable'])->toBe(in_array($name, $nullable, true))
                ->and(Schema::hasIndex($name, ['model_version']))->toBeTrue();
            DB::table($name)->insert(['model_version' => str_repeat('n', 128)]);
            expect(DB::table($name)->count())->toBe(2);
        }
    });
});

it('preserves unpriced settlements and rejects a lossy nullability rollback', function () {
    withGuardrailFixture(function () {
        Schema::create('bet_settlements', function (Blueprint $table) {
            $table->id();
            $table->decimal('profit_units', 10, 4);
        });
        DB::table('bet_settlements')->insert(['profit_units' => 1.2345]);
        $migration = require database_path('migrations/2026_09_19_200000_allow_unknown_bet_settlement_returns.php');
        $migration->up();
        DB::table('bet_settlements')->insert(['profit_units' => null]);
        expect((float) DB::table('bet_settlements')->value('profit_units'))->toBe(1.2345)
            ->and(fn () => $migration->down())->toThrow(RuntimeException::class)
            ->and(DB::table('bet_settlements')->count())->toBe(2);
    });
});

it('preserves known transfer values while allowing unknown values and the QB usage payload', function () {
    withGuardrailFixture(function () {
        $columns = ['incoming_transfer_value', 'outgoing_transfer_value', 'transfer_net_value',
            'transfer_qb_net_value', 'transfer_ol_net_value', 'transfer_dl_net_value',
            'transfer_wr_net_value', 'transfer_cb_net_value'];
        Schema::create('cfb_preseason_team_signals', function (Blueprint $table) use ($columns) {
            $table->id();
            foreach ($columns as $column) {
                $table->decimal($column, 8, 3)->default(0);
            }
        });
        DB::table('cfb_preseason_team_signals')->insert(['transfer_net_value' => 12.345]);
        (require database_path('migrations/2026_09_19_210000_add_cfb_qb_season_usage_payload.php'))->up();
        DB::table('cfb_preseason_team_signals')->insert(array_fill_keys($columns, null));
        expect((float) DB::table('cfb_preseason_team_signals')->where('id', 1)->value('transfer_net_value'))->toBe(12.345)
            ->and(DB::table('cfb_preseason_team_signals')->where('id', 2)->value('transfer_net_value'))->toBeNull()
            ->and(Schema::hasColumn('cfb_preseason_team_signals', 'qb_season_usage_payload'))->toBeTrue();
    });
});

it('keeps the legacy snapshot foreign key when adding nullable native shadow lineage', function () {
    withGuardrailFixture(function () {
        Schema::create('prediction_feature_snapshots', fn (Blueprint $table) => $table->id());
        Schema::create('predictions', fn (Blueprint $table) => $table->id());
        Schema::create('shadow_model_outputs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('prediction_feature_snapshot_id')->constrained()->cascadeOnDelete();
            $table->uuid('model_artifact_id')->nullable();
            $table->string('market_type');
        });
        DB::table('prediction_feature_snapshots')->insert(['id' => 1]);
        DB::table('predictions')->insert(['id' => 1]);
        DB::table('shadow_model_outputs')->insert(['prediction_feature_snapshot_id' => 1, 'market_type' => 'spread']);
        $migration = require database_path('migrations/2026_10_28_195953_add_canonical_prediction_to_shadow_model_outputs.php');
        $migration->up();
        DB::table('shadow_model_outputs')->insert(['prediction_feature_snapshot_id' => null, 'canonical_prediction_id' => 1, 'market_type' => 'total']);
        $foreign = collect(Schema::getForeignKeys('shadow_model_outputs'))->firstWhere('columns', ['prediction_feature_snapshot_id']);
        expect($foreign['foreign_table'])->toBe('prediction_feature_snapshots')
            ->and(strtolower($foreign['on_delete']))->toBe('cascade')
            ->and(fn () => $migration->down())->toThrow(LogicException::class)
            ->and(DB::table('shadow_model_outputs')->count())->toBe(2);
    });
});

it('retires legacy Elo indexes only after expansion and preserves multiple rating versions', function () {
    withGuardrailFixture(function () {
        Schema::create('cfb_elo_ratings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('team_id');
            $table->integer('season');
            $table->integer('week');
            $table->string('season_type');
            $table->decimal('elo_rating', 10, 2);
            $table->unique(['team_id', 'season', 'week', 'season_type'], 'cfb_elo_ratings_team_id_season_week_season_type_unique');
        });
        DB::table('cfb_elo_ratings')->insert(['team_id' => 1, 'season' => 2026, 'week' => 1, 'season_type' => '2', 'elo_rating' => 1500]);
        $contract = require database_path('migrations/2026_11_19_220000_contract_cfb_elo_legacy_uniqueness.php');
        expect(fn () => $contract->up())->toThrow(RuntimeException::class)
            ->and(Schema::hasIndex('cfb_elo_ratings', 'cfb_elo_ratings_team_id_season_week_season_type_unique'))->toBeTrue();
        (require database_path('migrations/2026_09_19_180000_version_cfb_elo_integrity.php'))->up();
        expect(Schema::hasIndex('cfb_elo_ratings', 'cfb_elo_ratings_team_id_season_week_season_type_unique'))->toBeTrue();
        $contract->up();
        DB::table('cfb_elo_ratings')->where('id', 1)->update(['game_id' => 7, 'active_slot' => null]);
        DB::table('cfb_elo_ratings')->insert(['team_id' => 1, 'season' => 2026, 'week' => 1, 'season_type' => '2', 'game_id' => 7, 'elo_rating' => 1510, 'active_slot' => 1]);
        $contract->up(); // Also safe where the original migration already retired the legacy indexes.
        expect(DB::table('cfb_elo_ratings')->count())->toBe(2)
            ->and((float) DB::table('cfb_elo_ratings')->where('id', 1)->value('elo_rating'))->toBe(1500.0)
            ->and(fn () => $contract->down())->toThrow(RuntimeException::class);
        expect(fn () => DB::table('cfb_elo_ratings')->insert(['team_id' => 1, 'season' => 2026, 'week' => 1, 'season_type' => '2', 'game_id' => 7, 'elo_rating' => 1520, 'active_slot' => 1]))->toThrow(QueryException::class);
    });
});

it('generates wider MySQL version columns without tightening existing nullability', function () {
    $original = DB::getDefaultConnection();
    DB::setDefaultConnection('mysql');
    Schema::clearResolvedInstance('db.schema');
    try {
        $migration = require database_path('migrations/2026_10_20_190000_expand_nfl_prediction_model_versions.php');
        $queries = DB::connection('mysql')->pretend(fn () => $migration->up());
    } finally {
        DB::setDefaultConnection($original);
        Schema::clearResolvedInstance('db.schema');
    }
    expect($queries)->toHaveCount(6);
    foreach (['nfl_predictions', 'prediction_evaluations', 'prediction_feature_snapshots', 'nfl_signal_observations', 'model_runs', 'model_artifacts'] as $name) {
        $sql = collect($queries)->pluck('query')->first(fn (string $query): bool => str_contains($query, "alter table `{$name}`"));
        $nullable = in_array($name, ['nfl_predictions', 'prediction_evaluations'], true) ? 'null' : 'not null';
        expect($sql)->toContain("`model_version` varchar(128) {$nullable}");
    }
});

it('widens MySQL recent-form precision while preserving scale and nullability', function () {
    $original = DB::getDefaultConnection();
    DB::setDefaultConnection('mysql');
    Schema::clearResolvedInstance('db.schema');
    try {
        $migration = require database_path('migrations/2026_11_03_164000_widen_cbb_prediction_recent_form.php');
        $queries = DB::connection('mysql')->pretend(fn () => $migration->up());
    } finally {
        DB::setDefaultConnection($original);
        Schema::clearResolvedInstance('db.schema');
    }
    expect($queries)->toHaveCount(2);
    foreach (['home_recent_form', 'away_recent_form'] as $column) {
        expect(collect($queries)->pluck('query')->implode('; '))->toContain("`{$column}` decimal(7, 3) null");
    }
});
