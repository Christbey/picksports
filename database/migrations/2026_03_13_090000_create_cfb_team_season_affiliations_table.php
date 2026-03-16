<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cfb_team_season_affiliations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('cfb_teams')->onDelete('cascade');
            $table->unsignedSmallInteger('season');
            $table->string('subdivision', 50)->nullable();
            $table->string('conference', 100)->nullable();
            $table->string('division', 100)->nullable();
            $table->string('source', 50)->nullable();
            $table->timestamps();

            $table->unique(['team_id', 'season']);
            $table->index(['season', 'subdivision']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cfb_team_season_affiliations');
    }
};
