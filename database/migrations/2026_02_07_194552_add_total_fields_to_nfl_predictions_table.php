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
        Schema::table('nfl_predictions', function (Blueprint $table) {
            $table->decimal('predicted_total', 5, 1)->nullable()->after('predicted_spread');
            $table->decimal('actual_total', 5, 1)->nullable()->after('actual_spread');
            $table->decimal('total_error', 5, 1)->nullable()->after('spread_error');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('nfl_predictions', function (Blueprint $table) {
            $table->dropColumn(['predicted_total', 'actual_total', 'total_error']);
        });
    }
};
