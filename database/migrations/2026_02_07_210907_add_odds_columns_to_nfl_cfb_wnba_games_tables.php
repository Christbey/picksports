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
        $tables = ['nfl_games', 'cfb_games', 'wnba_games'];

        foreach ($tables as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->string('odds_api_event_id')->nullable()->index()->after('status');
                $table->json('odds_data')->nullable()->after('odds_api_event_id');
                $table->timestamp('odds_updated_at')->nullable()->after('odds_data');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $tables = ['nfl_games', 'cfb_games', 'wnba_games'];

        foreach ($tables as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropColumn(['odds_api_event_id', 'odds_data', 'odds_updated_at']);
            });
        }
    }
};
