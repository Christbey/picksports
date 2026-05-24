<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sports_ai_prediction_analyses', function (Blueprint $table) {
            $table->id();
            $table->string('sport', 16);
            $table->unsignedBigInteger('game_id');
            $table->unsignedBigInteger('prediction_id');
            $table->date('game_date')->nullable();
            $table->date('as_of_date');
            $table->string('market', 24)->default('game');
            $table->string('provider', 32)->nullable();
            $table->string('model', 64)->nullable();
            $table->string('input_hash', 64);
            $table->json('raw_payload');
            $table->string('recommendation', 32);
            $table->unsignedTinyInteger('ai_confidence');
            $table->unsignedTinyInteger('analysis_confidence');
            $table->string('bet_classification', 24);
            $table->text('summary');
            $table->json('key_factors')->nullable();
            $table->json('risk_flags')->nullable();
            $table->json('reason_codes')->nullable();
            $table->json('market_notes')->nullable();
            $table->json('calculated_edge')->nullable();
            $table->json('metadata')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->timestamps();

            $table->unique(['sport', 'prediction_id', 'market', 'as_of_date'], 'sports_ai_prediction_unique_daily');
            $table->index(['sport', 'game_date', 'bet_classification'], 'sports_ai_prediction_slate_lookup');
            $table->index(['sport', 'as_of_date'], 'sports_ai_prediction_as_of_lookup');
            $table->index('input_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sports_ai_prediction_analyses');
    }
};
