<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cbb_games', function (Blueprint $table): void {
            $table->boolean('is_ncaa_tournament')->default(false)->after('short_name');
            $table->unsignedInteger('tournament_id')->nullable()->after('is_ncaa_tournament');
            $table->string('tournament_note')->nullable()->after('tournament_id');
            $table->string('tournament_round')->nullable()->after('tournament_note');
            $table->string('tournament_region')->nullable()->after('tournament_round');

            $table->index(['season', 'season_type', 'is_ncaa_tournament'], 'cbb_games_season_tournament_idx');
            $table->index(['tournament_round', 'tournament_region'], 'cbb_games_round_region_idx');
        });
    }

    public function down(): void
    {
        Schema::table('cbb_games', function (Blueprint $table): void {
            $table->dropIndex('cbb_games_season_tournament_idx');
            $table->dropIndex('cbb_games_round_region_idx');
            $table->dropColumn([
                'is_ncaa_tournament',
                'tournament_id',
                'tournament_note',
                'tournament_round',
                'tournament_region',
            ]);
        });
    }
};
