<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['cbb_plays', 'wcbb_plays'] as $tableName) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                if (! Schema::hasColumn($tableName, 'is_epa_eligible')) {
                    $table->boolean('is_epa_eligible')->default(false)->after('away_score');
                    $table->index('is_epa_eligible');
                }

                if (! Schema::hasColumn($tableName, 'expected_points_before')) {
                    $table->decimal('expected_points_before', 8, 3)->nullable()->after('is_epa_eligible');
                }

                if (! Schema::hasColumn($tableName, 'expected_points_after')) {
                    $table->decimal('expected_points_after', 8, 3)->nullable()->after('expected_points_before');
                }

                if (! Schema::hasColumn($tableName, 'true_epa')) {
                    $table->decimal('true_epa', 8, 3)->nullable()->after('expected_points_after');
                    $table->index('true_epa');
                }
            });
        }
    }

    public function down(): void
    {
        foreach (['cbb_plays', 'wcbb_plays'] as $tableName) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                foreach (['is_epa_eligible', 'expected_points_before', 'expected_points_after', 'true_epa'] as $column) {
                    if (Schema::hasColumn($tableName, $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
