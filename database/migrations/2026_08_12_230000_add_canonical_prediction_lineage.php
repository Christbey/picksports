<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feature_schemas', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('sport', 16);
            $table->string('version', 100);
            $table->char('schema_hash', 64);
            $table->json('definition')->nullable();
            $table->string('source', 64)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(
                ['sport', 'version', 'schema_hash'],
                'feature_schemas_sport_version_hash_unique',
            );
            $table->index(['sport', 'version'], 'feature_schemas_sport_version_idx');
        });

        Schema::table('predictions', function (Blueprint $table): void {
            $table->foreignId('feature_schema_id')
                ->nullable()
                ->after('sport_event_id')
                ->constrained('feature_schemas', indexName: 'predictions_feature_schema_fk')
                ->nullOnDelete();
            $table->foreignId('dataset_export_manifest_id')
                ->nullable()
                ->after('feature_schema_id')
                ->constrained('dataset_export_manifests', indexName: 'predictions_dataset_manifest_fk')
                ->nullOnDelete();
            $table->foreignUuid('model_run_id')
                ->nullable()
                ->after('dataset_export_manifest_id')
                ->constrained('model_runs', indexName: 'predictions_model_run_fk')
                ->nullOnDelete();
            $table->foreignUuid('model_artifact_id')
                ->nullable()
                ->after('model_run_id')
                ->constrained('model_artifacts', indexName: 'predictions_model_artifact_fk')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('predictions', function (Blueprint $table): void {
            $table->dropForeign('predictions_model_artifact_fk');
            $table->dropForeign('predictions_model_run_fk');
            $table->dropForeign('predictions_dataset_manifest_fk');
            $table->dropForeign('predictions_feature_schema_fk');
            $table->dropColumn([
                'model_artifact_id',
                'model_run_id',
                'dataset_export_manifest_id',
                'feature_schema_id',
            ]);
        });

        Schema::dropIfExists('feature_schemas');
    }
};
