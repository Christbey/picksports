<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('game_odds_snapshots', function (Blueprint $table) {
            $table->id();
            $table->string('sport', 16);
            $table->string('game_table', 64);
            $table->unsignedBigInteger('game_id');
            $table->string('odds_api_event_id', 128)->nullable();
            $table->string('bookmaker_key', 64)->nullable();
            $table->string('bookmaker_title', 128)->nullable();
            $table->string('source', 32)->default('odds_api');
            $table->timestamp('commence_time')->nullable();
            $table->timestamp('captured_at');
            $table->string('payload_hash', 64);
            $table->json('odds_data');
            $table->json('market_context')->nullable();
            $table->timestamps();

            $table->index(['sport', 'game_table', 'game_id'], 'game_odds_snapshots_game_lookup');
            $table->index(['sport', 'captured_at'], 'game_odds_snapshots_sport_captured_lookup');
            $table->index(['odds_api_event_id', 'captured_at'], 'game_odds_snapshots_event_captured_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_odds_snapshots');
    }
};
