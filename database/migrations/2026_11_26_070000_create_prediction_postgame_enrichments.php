<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prediction_postgame_enrichments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('prediction_feature_snapshot_id');
            $table->foreign('prediction_feature_snapshot_id', 'postgame_enrichment_snapshot_fk')->references('id')->on('prediction_feature_snapshots')->restrictOnDelete();
            $table->string('sport', 16);
            $table->string('prediction_table', 64);
            $table->unsignedBigInteger('prediction_id');
            $table->string('game_table', 64);
            $table->unsignedBigInteger('game_id');
            $table->string('evidence_type', 64);
            $table->unsignedInteger('revision');
            $table->foreignId('supersedes_enrichment_id')->nullable();
            $table->foreign('supersedes_enrichment_id', 'postgame_enrichment_supersedes_fk')->references('id')->on('prediction_postgame_enrichments')->restrictOnDelete();
            $table->char('source_feature_hash', 64)->nullable();
            $table->char('entry_payload_hash', 64);
            $table->char('game_context_hash', 64);
            $table->char('quote_context_hash', 64);
            $table->char('semantic_hash', 64);
            $table->string('source_integrity_status', 32);
            $table->string('availability_status', 24);
            $table->json('reason_codes');
            $table->json('entry_context');
            $table->json('source_quotes');
            $table->json('output');
            $table->char('evidence_hash', 64);
            $table->dateTime('observed_at', 6);
            $table->dateTime('source_max_captured_at', 6)->nullable();
            $table->unique(['prediction_feature_snapshot_id', 'evidence_type', 'revision'], 'postgame_enrichment_revision_unique');
            $table->index(['prediction_feature_snapshot_id', 'evidence_type', 'id'], 'postgame_enrichment_latest');
            $table->index(['sport', 'prediction_table', 'prediction_id'], 'postgame_enrichment_prediction');
            $table->index(['game_table', 'game_id'], 'postgame_enrichment_game');
        });
    }

    public function down(): void
    {
        throw new LogicException('Postgame evidence receipts are retained; use a reviewed forward migration.');
    }
};
