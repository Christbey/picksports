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
        Schema::table('wcbb_games', function (Blueprint $table) {
            $table->string('odds_api_event_id')->nullable()->index();
            $table->json('odds_data')->nullable();
            $table->timestamp('odds_updated_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('wcbb_games', function (Blueprint $table) {
            $table->dropColumn(['odds_api_event_id', 'odds_data', 'odds_updated_at']);
        });
    }
};
