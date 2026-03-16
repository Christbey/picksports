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
            if (! Schema::hasColumn('odds_api_team_mappings', 'odds_api_team_id')) {
                $table->string('odds_api_team_id')->nullable()->after('odds_api_team_name')->index();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('odds_api_team_mappings', function (Blueprint $table) {
            if (Schema::hasColumn('odds_api_team_mappings', 'odds_api_team_id')) {
                $table->dropColumn('odds_api_team_id');
            }
        });
    }
};
