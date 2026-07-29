<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prediction_feature_snapshots', function (Blueprint $table): void {
            $table->timestamp('game_start_at')->nullable()->after('generated_at');
            $table->timestamp('features_available_at')->nullable()->after('game_start_at');
            $table->boolean('pregame_safe')->default(false)->after('features_available_at')->index();
            $table->string('availability_status', 48)->default('unverified')->after('pregame_safe')->index();
            $table->json('source_timestamps')->nullable()->after('availability_status');
            $table->json('lineage_metadata')->nullable()->after('source_timestamps');
        });

        Schema::create('model_artifacts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('training_run_id')->constrained('model_runs')->cascadeOnDelete();
            $table->string('sport', 16)->index();
            $table->string('market_type', 40)->index();
            $table->string('model_type', 96);
            $table->string('model_version', 64);
            $table->string('feature_version', 64);
            $table->string('dataset_hash', 64);
            $table->string('artifact_path');
            $table->string('artifact_hash', 64)->unique();
            $table->string('status', 32)->default('challenger')->index();
            $table->json('metrics')->nullable();
            $table->string('evaluation_report_path')->nullable();
            $table->string('evaluation_report_hash', 64)->nullable();
            $table->json('promotion_criteria')->nullable();
            $table->json('promotion_decision')->nullable();
            $table->timestamp('promoted_at')->nullable();
            $table->timestamps();

            $table->index(['sport', 'market_type', 'status'], 'model_artifacts_promotion_lookup');
        });

        Schema::create('shadow_model_outputs', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('inference_run_id')->constrained('model_runs')->cascadeOnDelete();
            $table->foreignUuid('model_artifact_id')->constrained('model_artifacts')->cascadeOnDelete();
            $table->foreignId('prediction_feature_snapshot_id')->constrained()->cascadeOnDelete();
            $table->string('sport', 16)->index();
            $table->string('game_table', 64);
            $table->unsignedBigInteger('game_id');
            $table->string('prediction_table', 64);
            $table->unsignedBigInteger('prediction_id');
            $table->string('market_type', 40);
            $table->decimal('baseline_output', 12, 6);
            $table->decimal('challenger_output', 12, 6);
            $table->decimal('output_delta', 12, 6);
            $table->string('status', 32)->default('shadow')->index();
            $table->json('explanation')->nullable();
            $table->timestamp('generated_at');
            $table->timestamps();

            $table->unique(
                ['model_artifact_id', 'prediction_feature_snapshot_id', 'market_type'],
                'shadow_model_outputs_artifact_snapshot_unique'
            );
            $table->index(['sport', 'game_table', 'game_id', 'generated_at'], 'shadow_model_outputs_game_lookup');
        });

        Schema::table('bet_decisions', function (Blueprint $table): void {
            $table->foreignUuid('model_artifact_id')
                ->nullable()
                ->after('model_run_id')
                ->constrained('model_artifacts')
                ->nullOnDelete();
            $table->foreignId('shadow_model_output_id')
                ->nullable()
                ->after('model_artifact_id')
                ->constrained('shadow_model_outputs')
                ->nullOnDelete();
            $table->json('explanation')->nullable()->after('reason_codes');
        });
    }

    public function down(): void
    {
        Schema::table('bet_decisions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('shadow_model_output_id');
            $table->dropConstrainedForeignId('model_artifact_id');
            $table->dropColumn('explanation');
        });

        Schema::dropIfExists('shadow_model_outputs');
        Schema::dropIfExists('model_artifacts');

        Schema::table('prediction_feature_snapshots', function (Blueprint $table): void {
            $table->dropColumn([
                'game_start_at',
                'features_available_at',
                'pregame_safe',
                'availability_status',
                'source_timestamps',
                'lineage_metadata',
            ]);
        });
    }
};
