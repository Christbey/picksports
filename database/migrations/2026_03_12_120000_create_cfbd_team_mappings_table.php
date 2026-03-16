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
        Schema::create('cfbd_team_mappings', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('cfbd_team_id')->unique();
            $table->string('cfbd_team_name')->index();
            $table->string('cfbd_abbreviation', 20)->nullable()->index();
            $table->string('espn_team_name')->nullable()->index();
            $table->string('sport')->default('americanfootball_ncaaf')->index();
            $table->string('conference', 100)->nullable();
            $table->string('division', 100)->nullable();
            $table->json('alternate_names')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cfbd_team_mappings');
    }
};
