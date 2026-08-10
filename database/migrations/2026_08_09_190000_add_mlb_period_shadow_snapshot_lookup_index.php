<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prediction_feature_snapshots', function (Blueprint $table): void {
            $table->index(
                [
                    'sport',
                    'prediction_table',
                    'pregame_safe',
                    'availability_status',
                    'game_start_at',
                    'game_id',
                    'generated_at',
                ],
                'prediction_snapshots_mlb_period_shadow_lookup',
            );
        });
    }

    public function down(): void
    {
        Schema::table('prediction_feature_snapshots', function (Blueprint $table): void {
            $table->dropIndex('prediction_snapshots_mlb_period_shadow_lookup');
        });
    }
};
