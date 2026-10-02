<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nfl_matchup_win_rate_snapshots', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('season');
            $table->unsignedTinyInteger('through_week');
            $table->timestamp('source_updated_at');
            $table->timestamp('observed_at');
            $table->string('source_url', 500);
            $table->string('content_hash', 64)->unique();
            $table->json('teams');
            $table->index(['season', 'through_week', 'source_updated_at'], 'nfl_win_rate_period');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nfl_matchup_win_rate_snapshots');
    }
};
