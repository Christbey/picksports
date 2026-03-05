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
        foreach (['nba', 'wnba', 'nfl', 'cfb', 'cbb', 'wcbb', 'mlb'] as $sport) {
            $table = "{$sport}_player_injuries";

            if (! Schema::hasTable($table) || Schema::hasColumn($table, 'injury_date')) {
                continue;
            }

            Schema::table($table, function (Blueprint $table): void {
                $table->date('injury_date')->nullable()->after('type');
                $table->index('injury_date');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['nba', 'wnba', 'nfl', 'cfb', 'cbb', 'wcbb', 'mlb'] as $sport) {
            $table = "{$sport}_player_injuries";

            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'injury_date')) {
                continue;
            }

            Schema::table($table, function (Blueprint $table): void {
                $table->dropIndex(['injury_date']);
                $table->dropColumn('injury_date');
            });
        }
    }
};
