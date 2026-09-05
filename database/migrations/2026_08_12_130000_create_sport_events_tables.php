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
        Schema::create('sport_events', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('sport', 16);
            $table->unsignedSmallInteger('season')->nullable();
            $table->string('season_type', 20)->nullable();
            $table->unsignedSmallInteger('week')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->string('name')->nullable();
            $table->string('short_name')->nullable();
            $table->string('status', 50)->nullable();
            $table->boolean('neutral_site')->nullable();
            $table->timestamps();

            $table->index(['sport', 'starts_at'], 'sport_events_sport_starts_at_idx');
            $table->index(['sport', 'season'], 'sport_events_sport_season_idx');
        });

        Schema::create('sport_event_provider_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sport_event_id')->constrained('sport_events')->cascadeOnDelete();
            $table->string('provider', 32);
            $table->string('provider_event_id', 100);
            $table->string('provider_uid', 150)->nullable();
            $table->timestamps();

            $table->unique(
                ['provider', 'provider_event_id'],
                'sport_event_provider_event_unique',
            );
            $table->unique(
                ['provider', 'provider_uid'],
                'sport_event_provider_uid_unique',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sport_event_provider_mappings');
        Schema::dropIfExists('sport_events');
    }
};
