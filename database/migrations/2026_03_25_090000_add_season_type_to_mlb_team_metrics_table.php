<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'mlb_team_metrics';

    private const LEGACY_UNIQUE = 'mlb_team_metrics_team_id_season_unique';

    private const TEAM_ID_INDEX = 'mlb_team_metrics_team_id_idx';

    private const SEASON_TYPE_UNIQUE = 'mlb_team_metrics_team_id_season_season_type_unique';

    public function up(): void
    {
        if (! Schema::hasColumn(self::TABLE, 'season_type')) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->string('season_type')->nullable()->after('season');
            });
        }

        if (! $this->hasIndex(self::TABLE, self::TEAM_ID_INDEX)) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->index('team_id', self::TEAM_ID_INDEX);
            });
        }

        DB::table(self::TABLE)
            ->whereNull('season_type')
            ->update(['season_type' => (string) config('mlb.season.types.regular', 2)]);

        Schema::table(self::TABLE, function (Blueprint $table) {
            $table->string('season_type')
                ->default((string) config('mlb.season.types.regular', 2))
                ->nullable(false)
                ->change();
        });

        if ($this->hasIndex(self::TABLE, self::LEGACY_UNIQUE)) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->dropUnique(self::LEGACY_UNIQUE);
            });
        }

        if (! $this->hasIndex(self::TABLE, self::SEASON_TYPE_UNIQUE)) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->unique(['team_id', 'season', 'season_type'], self::SEASON_TYPE_UNIQUE);
            });
        }
    }

    public function down(): void
    {
        if ($this->hasIndex(self::TABLE, self::SEASON_TYPE_UNIQUE)) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->dropUnique(self::SEASON_TYPE_UNIQUE);
            });
        }

        if (! $this->hasIndex(self::TABLE, self::LEGACY_UNIQUE)) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->unique(['team_id', 'season'], self::LEGACY_UNIQUE);
            });
        }

        if ($this->hasIndex(self::TABLE, self::TEAM_ID_INDEX)) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->dropIndex(self::TEAM_ID_INDEX);
            });
        }

        if (Schema::hasColumn(self::TABLE, 'season_type')) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->dropColumn('season_type');
            });
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
