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
        Schema::table('nflverse_pbp_plays', function (Blueprint $table) {
            $table->boolean('participation_pressure')->nullable();
            $table->unsignedTinyInteger('participation_rushers')->nullable();
            $table->string('participation_offense_package', 5)->nullable();
            $table->unsignedTinyInteger('participation_defense_dbs')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('nflverse_pbp_plays', function (Blueprint $table) {
            $table->dropColumn(['participation_pressure', 'participation_rushers', 'participation_offense_package', 'participation_defense_dbs']);
        });
    }
};
