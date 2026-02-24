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
        Schema::table('odds_api_team_mappings', function (Blueprint $table) {
            // Drop the old unique constraint
            $table->dropUnique(['espn_team_name', 'sport']);

            // Make espn_team_name nullable
            $table->string('espn_team_name')->nullable()->change();

            // Add new unique constraint on odds_api_team_name + sport
            // (Each Odds API team should only appear once per sport)
            $table->unique(['odds_api_team_name', 'sport']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('odds_api_team_mappings', function (Blueprint $table) {
            // Drop the new unique constraint
            $table->dropUnique(['odds_api_team_name', 'sport']);

            // Make espn_team_name not nullable
            $table->string('espn_team_name')->nullable(false)->change();

            // Restore original unique constraint
            $table->unique(['espn_team_name', 'sport']);
        });
    }
};
