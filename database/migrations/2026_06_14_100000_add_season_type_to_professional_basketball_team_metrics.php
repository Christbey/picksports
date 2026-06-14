<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * @var array<string, array{legacy_unique:string,team_index:string,season_type_unique:string,config:string}>
     */
    private array $tables = [
        'nba_team_metrics' => [
            'legacy_unique' => 'nba_team_metrics_team_id_season_unique',
            'team_index' => 'nba_team_metrics_team_id_idx',
            'season_type_unique' => 'nba_team_metrics_team_id_season_season_type_unique',
            'config' => 'nba',
        ],
        'wnba_team_metrics' => [
            'legacy_unique' => 'wnba_team_metrics_team_id_season_unique',
            'team_index' => 'wnba_team_metrics_team_id_idx',
            'season_type_unique' => 'wnba_team_metrics_team_id_season_season_type_unique',
            'config' => 'wnba',
        ],
    ];

    public function up(): void
    {
        foreach ($this->tables as $table => $indexes) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            if (! Schema::hasColumn($table, 'season_type')) {
                Schema::table($table, function (Blueprint $blueprint) {
                    $blueprint->string('season_type')->nullable()->after('season');
                });
            }

            if (! $this->hasIndex($table, $indexes['team_index'])) {
                Schema::table($table, function (Blueprint $blueprint) use ($indexes) {
                    $blueprint->index('team_id', $indexes['team_index']);
                });
            }

            DB::table($table)
                ->whereNull('season_type')
                ->update(['season_type' => (string) config("{$indexes['config']}.season.types.regular", 2)]);

            if ($this->hasIndex($table, $indexes['legacy_unique'])) {
                Schema::table($table, function (Blueprint $blueprint) use ($indexes) {
                    $blueprint->dropUnique($indexes['legacy_unique']);
                });
            }

            if (! $this->hasIndex($table, $indexes['season_type_unique'])) {
                Schema::table($table, function (Blueprint $blueprint) use ($indexes) {
                    $blueprint->unique(['team_id', 'season', 'season_type'], $indexes['season_type_unique']);
                });
            }
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table => $indexes) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            if ($this->hasIndex($table, $indexes['season_type_unique'])) {
                Schema::table($table, function (Blueprint $blueprint) use ($indexes) {
                    $blueprint->dropUnique($indexes['season_type_unique']);
                });
            }

            if (! $this->hasIndex($table, $indexes['legacy_unique'])) {
                Schema::table($table, function (Blueprint $blueprint) use ($indexes) {
                    $blueprint->unique(['team_id', 'season'], $indexes['legacy_unique']);
                });
            }

            if ($this->hasIndex($table, $indexes['team_index'])) {
                Schema::table($table, function (Blueprint $blueprint) use ($indexes) {
                    $blueprint->dropIndex($indexes['team_index']);
                });
            }

            if (Schema::hasColumn($table, 'season_type')) {
                Schema::table($table, function (Blueprint $blueprint) {
                    $blueprint->dropColumn('season_type');
                });
            }
        }
    }

    private function hasIndex(string $table, string $index): bool
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            $indexes = DB::select("PRAGMA index_list('{$table}')");

            return collect($indexes)->contains(
                fn (object $item): bool => (string) ($item->name ?? '') === $index
            );
        }

        return DB::table('information_schema.statistics')
            ->whereRaw('table_schema = database()')
            ->where('table_name', $table)
            ->where('index_name', $index)
            ->exists();
    }
};
