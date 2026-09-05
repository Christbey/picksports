<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sport_event_results', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('sport_event_id')->constrained('sport_events')->restrictOnDelete();
            $table->unsignedInteger('revision');
            $table->foreignId('supersedes_sport_event_result_id')
                ->nullable()
                ->constrained('sport_event_results', indexName: 'sport_event_results_supersedes_fk')
                ->restrictOnDelete();
            $table->string('status', 20)->default('official');
            $table->unsignedInteger('home_score');
            $table->unsignedInteger('away_score');
            $table->string('source', 50);
            $table->string('source_reference', 150)->nullable();
            $table->char('result_hash', 64)->unique();
            $table->timestamp('observed_at');
            $table->timestamp('finalized_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(
                ['sport_event_id', 'revision'],
                'sport_event_results_event_revision_unique',
            );
            $table->index(
                ['sport_event_id', 'status', 'revision'],
                'sport_event_results_current_idx',
            );
        });

        Schema::table('prediction_evaluations', function (Blueprint $table): void {
            $table->foreignId('canonical_prediction_id')
                ->nullable()
                ->after('id')
                ->constrained('predictions', indexName: 'prediction_evaluations_canonical_prediction_fk')
                ->restrictOnDelete();
            $table->foreignId('sport_event_id')
                ->nullable()
                ->after('canonical_prediction_id')
                ->constrained('sport_events', indexName: 'prediction_evaluations_sport_event_fk')
                ->restrictOnDelete();
            $table->foreignId('sport_event_result_id')
                ->nullable()
                ->after('sport_event_id')
                ->constrained('sport_event_results', indexName: 'prediction_evaluations_event_result_fk')
                ->restrictOnDelete();
            $table->unsignedInteger('evaluation_revision')->default(1)->after('sport_event_result_id');
            $table->foreignId('supersedes_prediction_evaluation_id')
                ->nullable()
                ->after('evaluation_revision')
                ->constrained('prediction_evaluations', indexName: 'prediction_evaluations_supersedes_fk')
                ->restrictOnDelete();
            $table->string('prediction_phase', 16)->nullable()->after('sport');
            $table->string('scoring_version', 50)->nullable()->after('prediction_phase');
            $table->char('evaluation_hash', 64)->nullable()->unique()->after('scoring_version');

            $table->unique(
                ['canonical_prediction_id', 'evaluation_revision'],
                'prediction_evaluations_canonical_revision_unique',
            );
            $table->index(
                ['sport_event_id', 'prediction_phase', 'evaluated_at'],
                'prediction_evaluations_event_phase_evaluated_idx',
            );
        });

        // @expand-nullability Canonical evaluations use enforced foreign keys, not legacy table-name references.
        Schema::table('prediction_evaluations', function (Blueprint $table): void {
            $table->string('prediction_table', 64)->nullable()->change();
            $table->unsignedBigInteger('prediction_id')->nullable()->change();
            $table->unsignedBigInteger('game_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('prediction_evaluations', function (Blueprint $table): void {
            $table->dropForeign('prediction_evaluations_supersedes_fk');
            $table->dropForeign('prediction_evaluations_event_result_fk');
            $table->dropForeign('prediction_evaluations_sport_event_fk');
            $table->dropForeign('prediction_evaluations_canonical_prediction_fk');
            $table->dropUnique('prediction_evaluations_canonical_revision_unique');
            $table->dropUnique('prediction_evaluations_evaluation_hash_unique');
            $table->dropIndex('prediction_evaluations_event_phase_evaluated_idx');
            $table->dropColumn([
                'canonical_prediction_id',
                'sport_event_id',
                'sport_event_result_id',
                'evaluation_revision',
                'supersedes_prediction_evaluation_id',
                'prediction_phase',
                'scoring_version',
                'evaluation_hash',
            ]);
        });

        Schema::dropIfExists('sport_event_results');
    }
};
