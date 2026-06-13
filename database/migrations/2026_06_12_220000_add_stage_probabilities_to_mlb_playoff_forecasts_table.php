<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mlb_playoff_forecasts', function (Blueprint $table): void {
            if (! Schema::hasColumn('mlb_playoff_forecasts', 'division_win_probability')) {
                $table->decimal('division_win_probability', 6, 5)->default(0)->after('playoff_make_probability');
            }

            if (! Schema::hasColumn('mlb_playoff_forecasts', 'division_series_probability')) {
                $table->decimal('division_series_probability', 6, 5)->default(0)->after('division_win_probability');
            }

            if (! Schema::hasColumn('mlb_playoff_forecasts', 'league_championship_series_probability')) {
                $table->decimal('league_championship_series_probability', 6, 5)->default(0)->after('division_series_probability');
            }

            if (! Schema::hasColumn('mlb_playoff_forecasts', 'pennant_probability')) {
                $table->decimal('pennant_probability', 6, 5)->default(0)->after('league_championship_probability');
            }
        });
    }

    public function down(): void
    {
        Schema::table('mlb_playoff_forecasts', function (Blueprint $table): void {
            $columns = [];

            foreach ([
                'division_win_probability',
                'division_series_probability',
                'league_championship_series_probability',
                'pennant_probability',
            ] as $column) {
                if (Schema::hasColumn('mlb_playoff_forecasts', $column)) {
                    $columns[] = $column;
                }
            }

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
