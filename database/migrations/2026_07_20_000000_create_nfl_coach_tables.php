<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nfl_coaches', function (Blueprint $table) {
            $table->id();
            $table->string('espn_id', 50)->unique();
            $table->string('uid', 100)->nullable();
            $table->string('first_name', 100)->nullable();
            $table->string('last_name', 100)->nullable();
            $table->string('display_name', 150)->nullable();
            $table->string('short_name', 100)->nullable();
            $table->unsignedSmallInteger('experience')->nullable();
            $table->json('career_records')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamps();
        });

        Schema::create('nfl_team_coach_seasons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coach_id')->constrained('nfl_coaches')->cascadeOnDelete();
            $table->foreignId('team_id')->constrained('nfl_teams')->cascadeOnDelete();
            $table->unsignedSmallInteger('season');
            $table->string('role', 80)->default('head_coach');
            $table->unsignedSmallInteger('experience')->nullable();
            $table->json('regular_season_record')->nullable();
            $table->string('source_ref')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamps();

            $table->unique(['season', 'team_id', 'role']);
            $table->index(['coach_id', 'season']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nfl_team_coach_seasons');
        Schema::dropIfExists('nfl_coaches');
    }
};
