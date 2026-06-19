<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    private const TABLE = 'prediction_feature_snapshots';

    private const LEGACY_UNIQUE = 'prediction_feature_snapshots_unique';

    private const RUN_ID_UNIQUE = 'prediction_feature_snapshots_run_id_unique';

    private const RUN_LOOKUP = 'prediction_feature_snapshots_run_lookup';

    public function up(): void
    {
        if (! Schema::hasColumn(self::TABLE, 'snapshot_run_id')) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->string('snapshot_run_id', 36)->nullable()->after('game_id');
            });
        }

        DB::table(self::TABLE)
            ->whereNull('snapshot_run_id')
            ->orderBy('id')
            ->chunkById(500, function ($rows): void {
                foreach ($rows as $row) {
                    DB::table(self::TABLE)
                        ->where('id', $row->id)
                        ->update(['snapshot_run_id' => (string) Str::uuid()]);
                }
            });

        if ($this->hasIndex(self::TABLE, self::LEGACY_UNIQUE)) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->dropUnique(self::LEGACY_UNIQUE);
            });
        }

        if (! $this->hasIndex(self::TABLE, self::RUN_ID_UNIQUE)) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->unique('snapshot_run_id', self::RUN_ID_UNIQUE);
            });
        }

        if (! $this->hasIndex(self::TABLE, self::RUN_LOOKUP)) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->index(
                    ['prediction_table', 'prediction_id', 'model_version', 'feature_version', 'blend_version', 'generated_at'],
                    self::RUN_LOOKUP
                );
            });
        }
    }

    public function down(): void
    {
        if ($this->hasIndex(self::TABLE, self::RUN_LOOKUP)) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->dropIndex(self::RUN_LOOKUP);
            });
        }

        if ($this->hasIndex(self::TABLE, self::RUN_ID_UNIQUE)) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->dropUnique(self::RUN_ID_UNIQUE);
            });
        }

        if (! $this->hasIndex(self::TABLE, self::LEGACY_UNIQUE)) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->unique(
                    ['prediction_table', 'prediction_id', 'model_version', 'feature_version', 'blend_version'],
                    self::LEGACY_UNIQUE
                );
            });
        }

        if (Schema::hasColumn(self::TABLE, 'snapshot_run_id')) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->dropColumn('snapshot_run_id');
            });
        }
    }

    private function hasIndex(string $table, string $index): bool
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            return collect(DB::select("PRAGMA index_list('{$table}')"))
                ->contains(fn (object $item): bool => (string) ($item->name ?? '') === $index);
        }

        return DB::table('information_schema.statistics')
            ->whereRaw('table_schema = database()')
            ->where('table_name', $table)
            ->where('index_name', $index)
            ->exists();
    }
};
