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
            if (! Schema::hasColumn('nba_playoff_forecasts', 'division_win_probability')) {
                $table->decimal('division_win_probability', 6, 5)->default(0)->after('playoff_make_probability');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('nba_playoff_forecasts')) {
            return;
        }

        if (Schema::hasColumn('nba_playoff_forecasts', 'division_win_probability')) {
            Schema::table('nba_playoff_forecasts', function (Blueprint $table): void {
                $table->dropColumn('division_win_probability');
            });
        }
    }
};
