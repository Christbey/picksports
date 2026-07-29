<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('nfl_signal_observations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('prediction_feature_snapshot_id')
                ->constrained('prediction_feature_snapshots')
                ->restrictOnDelete();
            $table->foreignUuid('model_run_id')
                ->constrained('model_runs')
                ->restrictOnDelete();
            $table->unsignedBigInteger('prediction_id');
            $table->unsignedBigInteger('game_id');
            $table->unsignedSmallInteger('season');
            $table->unsignedTinyInteger('week')->nullable();
            $table->uuid('snapshot_run_id');
            $table->string('model_version', 64);
            $table->string('feature_version', 64);
            $table->string('blend_version', 64);
            $table->string('config_hash', 64);
            $table->string('feature_hash', 64);
            $table->string('signal_type', 32);
            $table->string('signal_key', 160);
            $table->string('label', 255)->nullable();
            $table->string('market_type', 32)->nullable();
            $table->string('direction', 24)->nullable();
            $table->string('action', 24)->nullable();
            $table->boolean('is_actionable')->default(false);
            $table->boolean('is_diagnostic')->default(false);
            $table->boolean('requires_market')->default(false);
            $table->boolean('pregame_safe')->default(false);
            $table->string('availability_status', 48);
            $table->json('signal_payload')->nullable();
            $table->string('definition_hash', 64);
            $table->string('observation_hash', 64)->unique();
            $table->timestamp('observed_at');
            $table->timestamp('game_start_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(
                ['prediction_feature_snapshot_id', 'signal_type', 'signal_key'],
                'nfl_signal_observations_snapshot_signal_unique'
            );
            $table->index(
                ['signal_type', 'signal_key', 'season'],
                'nfl_signal_observations_report_lookup'
            );
            $table->index(
                ['game_id', 'prediction_feature_snapshot_id'],
                'nfl_signal_observations_game_lookup'
            );
            $table->index(
                ['pregame_safe', 'season'],
                'nfl_signal_observations_safety_lookup'
            );
        });

        Schema::create('nfl_signal_grades', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('nfl_signal_observation_id')
                ->constrained('nfl_signal_observations')
                ->cascadeOnDelete();
            $table->foreignId('bet_decision_id')
                ->nullable()
                ->constrained('bet_decisions')
                ->nullOnDelete();
            $table->foreignId('bet_settlement_id')
                ->nullable()
                ->constrained('bet_settlements')
                ->nullOnDelete();
            $table->string('evaluation_key', 80);
            $table->string('evaluation_source', 24);
            $table->string('market_type', 32);
            $table->string('direction', 24)->nullable();
            $table->string('result_status', 24);
            $table->boolean('hit')->nullable();
            $table->decimal('model_probability', 10, 7)->nullable();
            $table->decimal('baseline_probability', 10, 7)->nullable();
            $table->decimal('actual_probability', 3, 2)->nullable();
            $table->decimal('line', 10, 3)->nullable();
            $table->decimal('model_value', 10, 3)->nullable();
            $table->decimal('actual_value', 10, 3)->nullable();
            $table->decimal('absolute_error', 10, 5)->nullable();
            $table->decimal('baseline_error', 10, 5)->nullable();
            $table->decimal('error_lift', 10, 5)->nullable();
            $table->decimal('brier_score', 12, 9)->nullable();
            $table->decimal('baseline_brier_score', 12, 9)->nullable();
            $table->decimal('calibration_lift', 12, 9)->nullable();
            $table->integer('price')->nullable();
            $table->decimal('profit_units', 10, 4)->nullable();
            $table->decimal('shadow_profit_units', 10, 4)->nullable();
            $table->decimal('clv', 10, 6)->nullable();
            $table->boolean('is_actual_bet')->default(false);
            $table->timestamp('graded_at');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(
                ['nfl_signal_observation_id', 'evaluation_key'],
                'nfl_signal_grades_observation_evaluation_unique'
            );
            $table->index(
                ['evaluation_source', 'market_type', 'result_status'],
                'nfl_signal_grades_market_report_lookup'
            );
            $table->index(
                ['bet_settlement_id', 'is_actual_bet'],
                'nfl_signal_grades_settlement_lookup'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('nfl_signal_grades');
        Schema::dropIfExists('nfl_signal_observations');
    }
};
