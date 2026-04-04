<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sports_futures_odds_snapshots')) {
            return;
        }

        Schema::create('sports_futures_odds_snapshots', function (Blueprint $table) {
            $table->id();
            $table->string('snapshot_key', 40)->unique();
            $table->string('row_key', 40)->index();
            $table->string('sport', 32)->index();
            $table->unsignedSmallInteger('season')->nullable()->index();
            $table->string('odds_api_sport_key', 80)->index();
            $table->string('event_id')->nullable()->index();
            $table->string('event_name')->nullable();
            $table->timestamp('commence_time')->nullable()->index();
            $table->foreignId('nba_team_id')->nullable()->constrained('nba_teams')->nullOnDelete();
            $table->foreignId('mlb_team_id')->nullable()->constrained('mlb_teams')->nullOnDelete();
            $table->foreignId('nfl_team_id')->nullable()->constrained('nfl_teams')->nullOnDelete();
            $table->foreignId('nfl_player_id')->nullable()->constrained('nfl_players')->nullOnDelete();
            $table->foreignId('cbb_team_id')->nullable()->constrained('cbb_teams')->nullOnDelete();
            $table->foreignId('wcbb_team_id')->nullable()->constrained('wcbb_teams')->nullOnDelete();
            $table->string('bookmaker', 40)->index();
            $table->string('market_key', 80)->index();
            $table->timestamp('market_last_update')->nullable();
            $table->string('outcome_name');
            $table->string('outcome_description')->nullable();
            $table->decimal('outcome_point', 8, 3)->nullable();
            $table->integer('price')->nullable();
            $table->decimal('implied_probability', 8, 6)->nullable();
            $table->json('raw_data')->nullable();
            $table->timestamp('captured_at')->index();
            $table->timestamps();

            $table->index(['sport', 'season', 'captured_at'], 'sfos_sport_season_captured_idx');
            $table->index(['sport', 'season', 'market_key', 'captured_at'], 'sfos_market_captured_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sports_futures_odds_snapshots');
    }
};
