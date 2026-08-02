<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mlb_games', function (Blueprint $table): void {
            $table->string('projected_home_pitcher_espn_id', 50)->nullable()->after('probable_away_pitcher_espn_id');
            $table->string('projected_away_pitcher_espn_id', 50)->nullable()->after('projected_home_pitcher_espn_id');
            $table->decimal('projected_home_pitcher_confidence', 5, 4)->nullable()->after('projected_away_pitcher_espn_id');
            $table->decimal('projected_away_pitcher_confidence', 5, 4)->nullable()->after('projected_home_pitcher_confidence');
            $table->json('pitcher_projection_metadata')->nullable()->after('projected_away_pitcher_confidence');
            $table->timestamp('pitcher_projection_generated_at')->nullable()->after('pitcher_projection_metadata');

            $table->index('projected_home_pitcher_espn_id');
            $table->index('projected_away_pitcher_espn_id');
        });
    }

    public function down(): void
    {
        Schema::table('mlb_games', function (Blueprint $table): void {
            $table->dropIndex(['projected_home_pitcher_espn_id']);
            $table->dropIndex(['projected_away_pitcher_espn_id']);
            $table->dropColumn([
                'projected_home_pitcher_espn_id',
                'projected_away_pitcher_espn_id',
                'projected_home_pitcher_confidence',
                'projected_away_pitcher_confidence',
                'pitcher_projection_metadata',
                'pitcher_projection_generated_at',
            ]);
        });
    }
};
