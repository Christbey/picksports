<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $tables = [
            'nba_team_metrics',
            'cbb_team_metrics',
            'wcbb_team_metrics',
            'wnba_team_metrics',
            'mlb_team_metrics',
            'nfl_team_metrics',
            'cfb_team_metrics',
        ];

        $driver = Schema::getConnection()->getDriverName();

        foreach ($tables as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            if ($driver === 'mysql') {
                DB::statement("ALTER TABLE `{$table}` MODIFY `strength_of_schedule` DECIMAL(7,3) NULL");

                continue;
            }

            if ($driver === 'pgsql') {
                DB::statement("ALTER TABLE \"{$table}\" ALTER COLUMN \"strength_of_schedule\" TYPE DECIMAL(7,3)");

                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->decimal('strength_of_schedule', 7, 3)->nullable()->change();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $tables = [
            'nba_team_metrics' => [6, 2],
            'cbb_team_metrics' => [6, 2],
            'wcbb_team_metrics' => [6, 2],
            'wnba_team_metrics' => [6, 2],
            'mlb_team_metrics' => [6, 2],
            'nfl_team_metrics' => [6, 3],
            'cfb_team_metrics' => [6, 3],
        ];

        $driver = Schema::getConnection()->getDriverName();

        foreach ($tables as $table => [$precision, $scale]) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            if ($driver === 'mysql') {
                DB::statement("ALTER TABLE `{$table}` MODIFY `strength_of_schedule` DECIMAL({$precision},{$scale}) NULL");

                continue;
            }

            if ($driver === 'pgsql') {
                DB::statement("ALTER TABLE \"{$table}\" ALTER COLUMN \"strength_of_schedule\" TYPE DECIMAL({$precision},{$scale})");

                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($precision, $scale) {
                $blueprint->decimal('strength_of_schedule', $precision, $scale)->nullable()->change();
            });
        }
    }
};
