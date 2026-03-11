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
        Schema::table('odds_api_player_mappings', function (Blueprint $table) {
            $table->unsignedBigInteger('espn_player_id')->nullable()->after('espn_player_name')->index();
            $table->string('suggested_espn_player_name')->nullable()->after('odds_api_player_name');
            $table->unsignedBigInteger('suggested_player_id')->nullable()->after('suggested_espn_player_name')->index();
            $table->unsignedTinyInteger('suggested_match_quality_score')->nullable()->after('suggested_player_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('odds_api_player_mappings', function (Blueprint $table) {
            $table->dropColumn([
                'espn_player_id',
                'suggested_espn_player_name',
                'suggested_player_id',
                'suggested_match_quality_score',
            ]);
        });
    }
};
