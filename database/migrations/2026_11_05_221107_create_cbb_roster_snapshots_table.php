<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cbb_roster_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->constrained('cbb_teams');
            $table->unsignedInteger('season');
            $table->string('source', 30)->default('espn_season_roster');
            $table->timestamp('observed_at', 6);
            $table->string('content_hash', 64);
            $table->json('provider_metadata');
            $table->timestamps();
            $table->index(['team_id', 'season', 'observed_at'], 'cbb_roster_lookup');
            $table->unique(['team_id', 'season', 'observed_at', 'content_hash'], 'cbb_roster_observation');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cbb_roster_snapshots');
    }
};
