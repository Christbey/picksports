<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calculation_releases', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('sport', 16);
            $table->string('phase', 16);
            $table->string('calculator_name', 120);
            $table->string('release_type', 16);
            $table->string('semantic_version', 50);
            $table->string('code_revision', 64);
            $table->char('configuration_hash', 64);
            $table->string('input_schema_version', 100);
            $table->json('configuration');
            $table->string('status', 20)->default('draft');
            $table->timestamp('effective_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('retired_at')->nullable();
            $table->timestamp('invalidated_at')->nullable();
            $table->string('approved_by', 120)->nullable();
            $table->text('approval_reason')->nullable();
            $table->text('invalidation_reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(
                ['sport', 'phase', 'calculator_name', 'semantic_version'],
                'calculation_releases_identity_unique',
            );
            $table->index(
                ['sport', 'phase', 'status', 'effective_at'],
                'calculation_releases_selection_idx',
            );
        });

        Schema::create('calculation_release_components', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('calculation_release_id')
                ->constrained('calculation_releases')
                ->cascadeOnDelete();
            $table->foreignUuid('model_artifact_id')
                ->nullable()
                ->constrained('model_artifacts', indexName: 'calculation_release_components_artifact_fk')
                ->restrictOnDelete();
            $table->string('component_type', 24);
            $table->string('role', 50);
            $table->string('market_type', 24)->nullable();
            $table->decimal('weight', 8, 6)->nullable();
            $table->json('configuration')->nullable();
            $table->timestamps();

            $table->unique(
                ['calculation_release_id', 'role', 'market_type'],
                'calculation_release_components_scope_unique',
            );
        });

        Schema::create('event_input_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('sport_event_id')->constrained('sport_events')->restrictOnDelete();
            $table->string('sport', 16);
            $table->string('phase', 16);
            $table->string('schema_version', 100);
            $table->timestamp('captured_at');
            $table->timestamp('cutoff_at')->nullable();
            $table->timestamp('latest_source_available_at')->nullable();
            $table->json('source_timestamps')->nullable();
            $table->json('inputs')->nullable();
            $table->string('object_uri', 2048)->nullable();
            $table->char('content_hash', 64);
            $table->string('pregame_safety_status', 24)->default('unknown');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(
                ['sport_event_id', 'phase', 'schema_version', 'content_hash'],
                'event_input_snapshots_content_unique',
            );
            $table->index(
                ['sport', 'phase', 'captured_at'],
                'event_input_snapshots_sport_phase_captured_idx',
            );
        });

        Schema::create('calculation_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('sport_event_id')->constrained('sport_events')->restrictOnDelete();
            $table->foreignId('event_input_snapshot_id')
                ->constrained('event_input_snapshots')
                ->restrictOnDelete();
            $table->foreignId('calculation_release_id')
                ->constrained('calculation_releases')
                ->restrictOnDelete();
            $table->string('phase', 16);
            $table->string('trigger', 40);
            $table->char('idempotency_key', 64)->unique();
            $table->string('status', 20)->default('pending');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->char('output_hash', 64)->nullable();
            $table->json('diagnostics')->nullable();
            $table->string('failure_code', 100)->nullable();
            $table->text('failure_message')->nullable();
            $table->timestamps();

            $table->index(
                ['sport_event_id', 'phase', 'status'],
                'calculation_runs_event_phase_status_idx',
            );
            $table->index(
                ['calculation_release_id', 'status', 'completed_at'],
                'calculation_runs_release_status_completed_idx',
            );
        });

        Schema::table('predictions', function (Blueprint $table): void {
            $table->foreignUuid('calculation_run_id')
                ->nullable()
                ->unique()
                ->after('sport_event_id')
                ->constrained('calculation_runs', indexName: 'predictions_calculation_run_fk')
                ->restrictOnDelete();
            $table->unsignedInteger('revision')->default(1)->after('sport');
            $table->foreignId('supersedes_prediction_id')
                ->nullable()
                ->after('revision')
                ->constrained('predictions', indexName: 'predictions_supersedes_fk')
                ->restrictOnDelete();
            $table->string('phase', 16)->default('pregame')->after('supersedes_prediction_id');
            $table->string('publication_state', 20)->default('legacy')->after('phase');
            $table->char('output_hash', 64)->nullable()->after('publication_state');
            $table->json('output_metadata')->nullable()->after('output_hash');
            $table->timestamp('withdrawn_at')->nullable()->after('published_at');
            $table->timestamp('superseded_at')->nullable()->after('withdrawn_at');

            $table->unique(
                ['sport_event_id', 'phase', 'revision'],
                'predictions_event_phase_revision_unique',
            );
            $table->index(
                ['sport_event_id', 'phase', 'publication_state', 'published_at'],
                'predictions_current_revision_idx',
            );
        });

        // @expand-nullability Native canonical revisions deliberately have no legacy-table identity.
        // This relaxation preserves every existing value and is not a contract-column removal.
        Schema::table('predictions', function (Blueprint $table): void {
            $table->string('detail_source', 32)->nullable()->change();
            $table->string('detail_sport', 16)->nullable()->change();
            $table->unsignedBigInteger('detail_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('predictions', function (Blueprint $table): void {
            $table->dropForeign('predictions_supersedes_fk');
            $table->dropForeign('predictions_calculation_run_fk');
            $table->dropUnique('predictions_event_phase_revision_unique');
            $table->dropIndex('predictions_current_revision_idx');
            $table->dropColumn([
                'calculation_run_id',
                'revision',
                'supersedes_prediction_id',
                'phase',
                'publication_state',
                'output_hash',
                'output_metadata',
                'withdrawn_at',
                'superseded_at',
            ]);
        });

        Schema::dropIfExists('calculation_runs');
        Schema::dropIfExists('event_input_snapshots');
        Schema::dropIfExists('calculation_release_components');
        Schema::dropIfExists('calculation_releases');
    }
};
