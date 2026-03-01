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
        Schema::table('cbb_tournament_forecasts', function (Blueprint $table) {
            $table->decimal('auto_bid_probability', 6, 5)->default(0)->after('auto_bid');
            $table->decimal('at_large_probability', 6, 5)->default(0)->after('auto_bid_probability');
            $table->decimal('first_four_probability', 6, 5)->default(0)->after('at_large_probability');
            $table->decimal('first_four_auto_probability', 6, 5)->default(0)->after('first_four_probability');
            $table->decimal('first_four_at_large_probability', 6, 5)->default(0)->after('first_four_auto_probability');
            $table->decimal('bid_thief_probability', 6, 5)->default(0)->after('first_four_at_large_probability');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cbb_tournament_forecasts', function (Blueprint $table) {
            $table->dropColumn([
                'auto_bid_probability',
                'at_large_probability',
                'first_four_probability',
                'first_four_auto_probability',
                'first_four_at_large_probability',
                'bid_thief_probability',
            ]);
        });
    }
};
