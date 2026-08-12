<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mlb_period_feature_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('game_id')->constrained('mlb_games')->cascadeOnDelete();
            $table->string('feature_version', 64);
            $table->char('feature_hash', 64);
            $table->json('features');
            $table->timestamp('game_start_at')->nullable();
            $table->timestamp('features_available_at');
            $table->boolean('pregame_safe')->default(false);
            $table->string('availability_status', 48);
            $table->timestamps();

            $table->unique(
                ['game_id', 'feature_version', 'feature_hash'],
                'mlb_period_feature_snapshots_version_hash_unique',
            );
            $table->index(
                ['game_id', 'feature_version', 'features_available_at'],
                'mlb_period_feature_snapshots_latest_lookup',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mlb_period_feature_snapshots');
    }
};
