<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cbb_games', function (Blueprint $table) {
            $table->foreignId('home_team_id')->nullable()->change();
            $table->foreignId('away_team_id')->nullable()->change();
            $table->string('home_team_display_name')->nullable()->after('away_team_id');
            $table->string('away_team_display_name')->nullable()->after('home_team_display_name');
            $table->string('home_team_abbreviation', 20)->nullable()->after('away_team_display_name');
            $table->string('away_team_abbreviation', 20)->nullable()->after('home_team_abbreviation');
        });
    }

    public function down(): void
    {
        Schema::table('cbb_games', function (Blueprint $table) {
            $table->dropColumn([
                'home_team_display_name',
                'away_team_display_name',
                'home_team_abbreviation',
                'away_team_abbreviation',
            ]);
            $table->foreignId('home_team_id')->nullable(false)->change();
            $table->foreignId('away_team_id')->nullable(false)->change();
        });
    }
};
