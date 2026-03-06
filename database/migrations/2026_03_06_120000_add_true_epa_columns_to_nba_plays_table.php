<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('nba_plays')) {
            return;
        }

        Schema::table('nba_plays', function (Blueprint $table) {
            if (! Schema::hasColumn('nba_plays', 'is_epa_eligible')) {
                $table->boolean('is_epa_eligible')->default(false)->after('is_foul');
                $table->index('is_epa_eligible');
            }

            if (! Schema::hasColumn('nba_plays', 'expected_points_before')) {
                $table->decimal('expected_points_before', 8, 3)->nullable()->after('is_epa_eligible');
            }

            if (! Schema::hasColumn('nba_plays', 'expected_points_after')) {
                $table->decimal('expected_points_after', 8, 3)->nullable()->after('expected_points_before');
            }

            if (! Schema::hasColumn('nba_plays', 'true_epa')) {
                $table->decimal('true_epa', 8, 3)->nullable()->after('expected_points_after');
                $table->index('true_epa');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('nba_plays')) {
            return;
        }

        Schema::table('nba_plays', function (Blueprint $table) {
            foreach (['is_epa_eligible', 'expected_points_before', 'expected_points_after', 'true_epa'] as $column) {
                if (Schema::hasColumn('nba_plays', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
