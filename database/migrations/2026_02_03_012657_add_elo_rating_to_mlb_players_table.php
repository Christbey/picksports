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
        Schema::table('mlb_players', function (Blueprint $table) {
            $table->integer('elo_rating')->nullable()->after('headshot_url');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('mlb_players', function (Blueprint $table) {
            $table->dropColumn('elo_rating');
        });
    }
};
