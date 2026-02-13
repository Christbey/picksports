<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nfl_predictions', function (Blueprint $table) {
            $table->decimal('actual_spread', 5, 1)->nullable()->after('confidence_score');
            $table->decimal('spread_error', 5, 1)->nullable()->after('actual_spread');
            $table->boolean('winner_correct')->nullable()->after('spread_error');
            $table->timestamp('graded_at')->nullable()->after('winner_correct');
        });
    }

    public function down(): void
    {
        Schema::table('nfl_predictions', function (Blueprint $table) {
            $table->dropColumn(['actual_spread', 'spread_error', 'winner_correct', 'graded_at']);
        });
    }
};
