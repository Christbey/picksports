<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection($this->getConnection())->table('mlb_games', function (Blueprint $table): void {
            $table->unsignedSmallInteger('start_time_version')->nullable();
            $table->uuid('start_observation_id')->nullable();
            $table->bigInteger('start_epoch')->nullable();
            $table->string('start_source_hash', 64)->nullable();
            $table->string('start_context_hash', 64)->nullable();
            $table->dateTime('start_observed_at', 6)->nullable();
            $table->index('start_epoch', 'mlb_start_epoch_idx');
        });
    }

    public function down(): void
    {
        throw new RuntimeException('MLB observed start provenance is forward-only; retain source observation evidence.');
    }
};
