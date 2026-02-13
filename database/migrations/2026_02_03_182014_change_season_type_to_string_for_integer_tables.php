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
        $tables = [
            'nba_games',
            'cbb_games',
            'wcbb_games',
            'wnba_games',
            'mlb_games',
        ];

        foreach ($tables as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->string('season_type', 20)->nullable()->change();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $tables = [
            'nba_games',
            'cbb_games',
            'wcbb_games',
            'wnba_games',
            'mlb_games',
        ];

        foreach ($tables as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->integer('season_type')->nullable()->change();
            });
        }
    }
};
