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
        if (! Schema::hasTable('nfl_plays')) {
            return;
        }

        Schema::table('nfl_plays', function (Blueprint $table) {
            if (! Schema::hasColumn('nfl_plays', 'is_epa_eligible')) {
                $table->boolean('is_epa_eligible')->default(false)->after('is_penalty');
                $table->index('is_epa_eligible');
            }

            if (! Schema::hasColumn('nfl_plays', 'expected_points_before')) {
                $table->decimal('expected_points_before', 8, 3)->nullable()->after('is_epa_eligible');
            }

            if (! Schema::hasColumn('nfl_plays', 'expected_points_after')) {
                $table->decimal('expected_points_after', 8, 3)->nullable()->after('expected_points_before');
            }

            if (! Schema::hasColumn('nfl_plays', 'true_epa')) {
                $table->decimal('true_epa', 8, 3)->nullable()->after('expected_points_after');
                $table->index('true_epa');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('nfl_plays')) {
            return;
        }

        Schema::table('nfl_plays', function (Blueprint $table) {
            foreach (['is_epa_eligible', 'expected_points_before', 'expected_points_after', 'true_epa'] as $column) {
                if (Schema::hasColumn('nfl_plays', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};

