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
        Schema::table('nba_elo_ratings', function (Blueprint $table) {
            $table->date('date')->nullable()->after('season');
            $table->decimal('elo_change', 10, 1)->nullable()->after('elo_rating');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('nba_elo_ratings', function (Blueprint $table) {
            $table->dropColumn(['date', 'elo_change']);
        });
    }
};
