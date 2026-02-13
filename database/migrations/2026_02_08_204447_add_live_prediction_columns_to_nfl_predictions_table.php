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
            $table->decimal('live_predicted_spread', 5, 1)->nullable()->after('predicted_total');
            $table->decimal('live_win_probability', 5, 3)->nullable()->after('live_predicted_spread');
            $table->decimal('live_predicted_total', 5, 1)->nullable()->after('live_win_probability');
            $table->integer('live_seconds_remaining')->nullable()->after('live_predicted_total');
            $table->dateTime('live_updated_at')->nullable()->after('live_seconds_remaining');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('nfl_predictions', function (Blueprint $table) {
            $table->dropColumn([
                'live_predicted_spread',
                'live_win_probability',
                'live_predicted_total',
                'live_seconds_remaining',
                'live_updated_at',
            ]);
        });
    }
};
