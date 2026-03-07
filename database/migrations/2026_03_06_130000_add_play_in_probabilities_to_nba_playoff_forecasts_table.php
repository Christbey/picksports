<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('nba_playoff_forecasts')) {
            return;
        }

        Schema::table('nba_playoff_forecasts', function (Blueprint $table): void {
            if (! Schema::hasColumn('nba_playoff_forecasts', 'direct_playoff_probability')) {
                $table->decimal('direct_playoff_probability', 6, 5)->default(0)->after('playoff_make_probability');
            }

            if (! Schema::hasColumn('nba_playoff_forecasts', 'play_in_tournament_probability')) {
                $table->decimal('play_in_tournament_probability', 6, 5)->default(0)->after('direct_playoff_probability');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('nba_playoff_forecasts')) {
            return;
        }

        Schema::table('nba_playoff_forecasts', function (Blueprint $table): void {
            if (Schema::hasColumn('nba_playoff_forecasts', 'play_in_tournament_probability')) {
                $table->dropColumn('play_in_tournament_probability');
            }

            if (Schema::hasColumn('nba_playoff_forecasts', 'direct_playoff_probability')) {
                $table->dropColumn('direct_playoff_probability');
            }
        });
    }
};
