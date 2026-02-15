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
        Schema::table('mlb_predictions', function (Blueprint $table) {
            $table->decimal('live_predicted_spread', 5, 1)->nullable();
            $table->decimal('live_win_probability', 5, 3)->nullable();
            $table->decimal('live_predicted_total', 5, 1)->nullable();
            $table->integer('live_outs_remaining')->nullable(); // MLB uses outs instead of seconds
            $table->dateTime('live_updated_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('mlb_predictions', function (Blueprint $table) {
            $table->dropColumn([
                'live_predicted_spread',
                'live_win_probability',
                'live_predicted_total',
                'live_outs_remaining',
                'live_updated_at',
            ]);
        });
    }
};
