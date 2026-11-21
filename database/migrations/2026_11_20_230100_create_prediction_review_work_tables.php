<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analysis_work_items', function (Blueprint $table): void {
            $table->id();
            $table->string('work_key', 64)->unique();
            $table->string('sport', 16);
            $table->foreignId('sport_event_id')->nullable()->constrained('sport_events');
            $table->string('game_table', 64);
            $table->unsignedBigInteger('game_id');
            $table->string('stage', 32)->default('prediction_review');
            $table->string('desired_fingerprint', 64);
            $table->json('input_payload');
            $table->json('execution_spec');
            $table->json('evidence');
            $table->string('state', 24)->default('pending');
            $table->string('reason_code', 128)->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('deadline_at')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->string('lease_token', 64)->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('last_dispatched_at')->nullable();
            $table->timestamps();
            $table->index(['state', 'next_attempt_at', 'deadline_at'], 'analysis_work_due');
            $table->index(['sport', 'stage', 'state'], 'analysis_work_sport_state');
        });
        Schema::create('prediction_review_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('analysis_work_item_id')->constrained('analysis_work_items');
            $table->foreignId('sport_event_id')->nullable()->constrained('sport_events');
            $table->foreignId('canonical_prediction_id')->nullable()->constrained('predictions');
            $table->foreignId('ai_generation_id')->nullable()->unique()->constrained('ai_generations');
            $table->string('sport', 16);
            $table->string('prediction_table', 64);
            $table->unsignedBigInteger('prediction_id');
            $table->string('desired_fingerprint', 64);
            $table->string('input_hash', 64);
            $table->string('execution_hash', 64);
            $table->string('evidence_hash', 64);
            $table->json('input_payload');
            $table->json('execution_spec');
            $table->json('output');
            $table->json('decision');
            $table->string('applicability', 24)->default('current');
            $table->timestamp('expires_at');
            $table->timestamp('created_at');
            $table->index(['analysis_work_item_id', 'desired_fingerprint'], 'review_work_fingerprint');
            $table->index(['sport', 'prediction_table', 'prediction_id'], 'review_source_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prediction_review_revisions');
        Schema::dropIfExists('analysis_work_items');
    }
};
