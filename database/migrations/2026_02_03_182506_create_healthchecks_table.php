<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('healthchecks', function (Blueprint $table) {
            $table->id();
            $table->string('sport', 20); // mlb, nba, nfl, cbb, cfb, wcbb, wnba
            $table->string('check_type', 50); // data_freshness, missing_games, stale_predictions, elo_status
            $table->string('status', 20); // passing, warning, failing
            $table->text('message');
            $table->json('metadata')->nullable();
            $table->timestamp('checked_at');
            $table->timestamps();

            $table->index(['sport', 'check_type', 'checked_at']);
            $table->index(['status', 'checked_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('healthchecks');
    }
};
