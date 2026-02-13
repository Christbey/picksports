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
            if (! Schema::hasColumn('odds_api_team_mappings', 'espn_team_name')) {
                $table->string('espn_team_name')->nullable()->index();
            }
            if (! Schema::hasColumn('odds_api_team_mappings', 'odds_api_team_name')) {
                $table->string('odds_api_team_name')->index();
            }
            if (! Schema::hasColumn('odds_api_team_mappings', 'sport')) {
                $table->string('sport')->default('basketball_ncaab')->index();
            }
        });

        // Add unique constraint separately, wrapped in try-catch to handle existing constraint
        try {
            Schema::table('odds_api_team_mappings', function (Blueprint $table) {
                $table->unique(['espn_team_name', 'sport']);
            });
        } catch (\Exception $e) {
            // Constraint already exists, ignore
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('odds_api_team_mappings', function (Blueprint $table) {
            $table->dropUnique(['espn_team_name', 'sport']);
            $table->dropColumn(['espn_team_name', 'odds_api_team_name', 'sport']);
        });
    }
};
