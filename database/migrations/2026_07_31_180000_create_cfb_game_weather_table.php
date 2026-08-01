<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cfb_game_weather', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('game_id')->unique()->constrained('cfb_games')->cascadeOnDelete();
            $table->decimal('latitude', 9, 6)->nullable();
            $table->decimal('longitude', 9, 6)->nullable();
            $table->string('location_source', 64)->nullable();
            $table->string('provider', 64)->default('open_meteo');
            $table->timestamp('observed_at')->nullable();
            $table->decimal('temperature_f', 6, 2)->nullable();
            $table->decimal('feels_like_f', 6, 2)->nullable();
            $table->decimal('wind_speed_mph', 6, 2)->nullable();
            $table->decimal('wind_gust_mph', 6, 2)->nullable();
            $table->unsignedSmallInteger('wind_direction_degrees')->nullable();
            $table->decimal('precipitation_probability', 5, 2)->nullable();
            $table->decimal('precipitation_inches', 7, 3)->nullable();
            $table->decimal('humidity_percent', 5, 2)->nullable();
            $table->string('condition_code', 64)->nullable();
            $table->boolean('is_indoor')->default(false);
            $table->json('raw_payload')->nullable();
            $table->timestamps();

            $table->index(['provider', 'observed_at']);
            $table->index(['is_indoor', 'wind_speed_mph']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cfb_game_weather');
    }
};
