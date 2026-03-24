<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('mlb_games')
            ->where('season_type', 'Regular Season')
            ->update(['season_type' => (string) config('mlb.season.types.regular', 2)]);

        DB::table('mlb_games')
            ->where('season_type', 'Regular')
            ->update(['season_type' => (string) config('mlb.season.types.regular', 2)]);

        DB::table('mlb_games')
            ->where('season_type', 'Preseason')
            ->update(['season_type' => (string) config('mlb.season.types.spring_training', 1)]);

        DB::table('mlb_games')
            ->where('season_type', 'Spring Training')
            ->update(['season_type' => (string) config('mlb.season.types.spring_training', 1)]);
    }

    public function down(): void
    {
        // Irreversible data normalization.
    }
};
