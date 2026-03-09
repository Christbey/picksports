<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sports_futures_odds', function (Blueprint $table) {
            $table->id();
            $table->string('row_key', 40)->unique();
            $table->string('sport', 32)->index();
            $table->unsignedSmallInteger('season')->nullable()->index();
            $table->string('odds_api_sport_key', 80)->index();
            $table->string('event_id')->nullable()->index();
            $table->string('event_name')->nullable();
            $table->timestamp('commence_time')->nullable()->index();
            $table->string('bookmaker', 40)->index();
            $table->string('market_key', 80)->index();
            $table->timestamp('market_last_update')->nullable();
            $table->string('outcome_name');
            $table->string('outcome_description')->nullable();
            $table->decimal('outcome_point', 8, 3)->nullable();
            $table->integer('price')->nullable();
            $table->decimal('implied_probability', 8, 6)->nullable();
            $table->json('raw_data')->nullable();
            $table->timestamp('fetched_at')->index();
            $table->timestamps();

            $table->index(['sport', 'season', 'market_key']);
            $table->index(['sport', 'season', 'outcome_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sports_futures_odds');
    }
};
