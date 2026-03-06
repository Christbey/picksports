<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('epa_state_baselines')) {
            return;
        }

        Schema::create('epa_state_baselines', function (Blueprint $table) {
            $table->id();
            $table->string('sport', 16);
            $table->unsignedSmallInteger('season');
            $table->unsignedSmallInteger('source_season')->nullable();
            $table->string('state_key', 120);
            $table->decimal('expected_points', 8, 4);
            $table->unsignedInteger('sample_size')->default(0);
            $table->timestamps();

            $table->unique(['sport', 'season', 'state_key'], 'epa_state_baselines_unique');
            $table->index(['sport', 'season'], 'epa_state_baselines_sport_season_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('epa_state_baselines');
    }
};
