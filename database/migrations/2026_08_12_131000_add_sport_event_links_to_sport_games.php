<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * @var list<string>
     */
    private const GAME_TABLES = [
        'nfl_games',
        'mlb_games',
        'nba_games',
        'wnba_games',
        'cbb_games',
        'wcbb_games',
        'cfb_games',
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach (self::GAME_TABLES as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                $table->foreignId('sport_event_id')
                    ->nullable()
                    ->after('id')
                    ->constrained('sport_events')
                    ->nullOnDelete();
                $table->unique('sport_event_id', "{$tableName}_sport_event_unique");
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (array_reverse(self::GAME_TABLES) as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                $table->dropUnique("{$tableName}_sport_event_unique");
                $table->dropConstrainedForeignId('sport_event_id');
            });
        }
    }
};
