<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * @var array<int, string>
     */
    private array $tables = [
        'nba_player_props',
        'cbb_player_props',
        'nfl_player_props',
        'mlb_player_props',
        'wnba_player_props',
    ];

    public function up(): void
    {
        foreach ($this->tables as $tableName) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                if (! $this->hasIndex($tableName, "{$tableName}_board_game_rec_conf_idx")) {
                    $table->index(['game_id', 'recommended_side', 'confidence_score'], "{$tableName}_board_game_rec_conf_idx");
                }

                if (! $this->hasIndex($tableName, "{$tableName}_board_market_rec_conf_idx")) {
                    $table->index(['market', 'recommended_side', 'confidence_score'], "{$tableName}_board_market_rec_conf_idx");
                }
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $tableName) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                foreach ([
                    "{$tableName}_board_game_rec_conf_idx",
                    "{$tableName}_board_market_rec_conf_idx",
                ] as $indexName) {
                    if ($this->hasIndex($tableName, $indexName)) {
                        $table->dropIndex($indexName);
                    }
                }
            });
        }
    }

    private function hasIndex(string $table, string $index): bool
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            return collect(DB::select("PRAGMA index_list('{$table}')"))
                ->contains(fn ($row): bool => ($row->name ?? null) === $index);
        }

        return collect(DB::select("SHOW INDEX FROM {$table}"))
            ->contains(fn ($row): bool => ($row->Key_name ?? null) === $index);
    }
};
