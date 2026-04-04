<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'mlb_predictions';

    public function up(): void
    {
        Schema::table(self::TABLE, function (Blueprint $table): void {
            if (! Schema::hasColumn(self::TABLE, 'season')) {
                $table->unsignedSmallInteger('season')->nullable()->after('game_id');
                $table->index('season');
            }

            if (! Schema::hasColumn(self::TABLE, 'season_type')) {
                $table->string('season_type', 20)->nullable()->after('season');
                $table->index(['season', 'season_type'], 'mlb_predictions_season_season_type_index');
            }
        });

        DB::table(self::TABLE)
            ->join('mlb_games', 'mlb_predictions.game_id', '=', 'mlb_games.id')
            ->select('mlb_predictions.id', 'mlb_games.season', 'mlb_games.season_type')
            ->orderBy('mlb_predictions.id')
            ->chunkById(500, function ($rows): void {
                foreach ($rows as $row) {
                    DB::table(self::TABLE)
                        ->where('id', $row->id)
                        ->update([
                            'season' => $row->season,
                            'season_type' => $row->season_type,
                        ]);
                }
            }, 'mlb_predictions.id', 'id');
    }

    public function down(): void
    {
        Schema::table(self::TABLE, function (Blueprint $table): void {
            if (Schema::hasColumn(self::TABLE, 'season_type')) {
                $table->dropIndex('mlb_predictions_season_season_type_index');
                $table->dropColumn('season_type');
            }

            if (Schema::hasColumn(self::TABLE, 'season')) {
                $table->dropIndex(['season']);
                $table->dropColumn('season');
            }
        });
    }
};
