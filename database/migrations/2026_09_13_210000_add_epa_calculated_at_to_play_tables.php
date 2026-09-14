<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * @var list<string>
     */
    private array $tables = [
        'nba_plays',
        'nfl_plays',
        'cbb_plays',
        'cfb_plays',
        'wcbb_plays',
    ];

    public function up(): void
    {
        foreach ($this->tables as $tableName) {
            if (! Schema::hasTable($tableName) || Schema::hasColumn($tableName, 'epa_calculated_at')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table): void {
                $table->timestamp('epa_calculated_at')->nullable()->after('true_epa')->index();
            });

            DB::table($tableName)
                ->where(function ($query): void {
                    $query->whereNotNull('true_epa')
                        ->orWhereNotNull('expected_points_before')
                        ->orWhereNotNull('expected_points_after');
                })
                ->update(['epa_calculated_at' => DB::raw('updated_at')]);
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $tableName) {
            if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'epa_calculated_at')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropColumn('epa_calculated_at');
            });
        }
    }
};
