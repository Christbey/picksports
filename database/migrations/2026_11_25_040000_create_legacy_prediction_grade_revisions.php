<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legacy_prediction_grade_revisions', function (Blueprint $table): void {
            $table->id();
            // Explicit legacy identities; sport-specific tables have overlapping IDs.
            $table->string('sport', 16);
            $table->string('prediction_table', 64);
            $table->unsignedBigInteger('prediction_id');
            $table->string('game_table', 64);
            $table->unsignedBigInteger('game_id');
            $table->unsignedInteger('revision');
            $table->foreignId('supersedes_id')->nullable()->constrained('legacy_prediction_grade_revisions')->restrictOnDelete();
            $table->string('scoring_version', 64);
            $table->string('evidence_kind', 32);
            $table->char('result_hash', 64)->nullable();
            $table->char('prediction_hash', 64);
            $table->char('receipt_hash', 64);
            $table->json('result_snapshot')->nullable();
            $table->json('prediction_snapshot');
            $table->json('grade_snapshot');
            $table->json('evaluation_snapshot')->nullable();
            $table->timestamp('original_graded_at')->nullable();
            $table->timestamp('recorded_at');
            $table->unique(['prediction_table', 'prediction_id', 'revision'], 'legacy_grade_prediction_revision_unique');
            $table->index(['sport', 'game_id'], 'legacy_grade_game_idx');
        });
    }

    public function down(): void
    {
        throw new LogicException('Retain legacy grading audit receipts; roll back compatible code without dropping history.');
    }
};
