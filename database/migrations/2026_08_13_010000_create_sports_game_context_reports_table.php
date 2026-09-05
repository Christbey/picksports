<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sports_game_context_reports', function (Blueprint $table) {
            $table->id();
            $table->string('sport', 16);
            $table->unsignedBigInteger('game_id');
            $table->date('game_date')->nullable();
            $table->string('status', 24)->default('ready');
            $table->string('provider', 32)->nullable();
            $table->string('model', 64)->nullable();
            $table->string('prompt_version', 64);
            $table->string('input_hash', 64);
            $table->unsignedTinyInteger('confidence')->default(0);
            $table->text('summary')->nullable();
            $table->json('team_context')->nullable();
            $table->json('situational_context')->nullable();
            $table->json('market_snapshot')->nullable();
            $table->json('facts')->nullable();
            $table->json('sources')->nullable();
            $table->json('risk_flags')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamp('researched_at');
            $table->timestamp('expires_at')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->timestamps();

            $table->index(['sport', 'game_id', 'researched_at'], 'game_context_latest_lookup');
            $table->index(['sport', 'game_date', 'status'], 'game_context_slate_lookup');
            $table->index('input_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sports_game_context_reports');
    }
};
