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
        Schema::table('wnba_predictions', function (Blueprint $table) {
            $table->decimal('actual_spread', 5, 1)->nullable()->after('confidence_score');
            $table->decimal('actual_total', 5, 1)->nullable()->after('actual_spread');
            $table->decimal('spread_error', 5, 1)->nullable()->after('actual_total');
            $table->decimal('total_error', 5, 1)->nullable()->after('spread_error');
            $table->boolean('winner_correct')->nullable()->after('total_error');
            $table->timestamp('graded_at')->nullable()->after('winner_correct');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('wnba_predictions', function (Blueprint $table) {
            $table->dropColumn([
                'actual_spread',
                'actual_total',
                'spread_error',
                'total_error',
                'winner_correct',
                'graded_at',
            ]);
        });
    }
};
