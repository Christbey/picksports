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
            $table->string('participation_man_zone', 20)->nullable();
            $table->string('participation_coverage', 20)->nullable();
            $table->timestamp('participation_observed_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('nflverse_pbp_plays', function (Blueprint $table) {
            $table->dropColumn(['participation_man_zone', 'participation_coverage', 'participation_observed_at']);
        });
    }
};
