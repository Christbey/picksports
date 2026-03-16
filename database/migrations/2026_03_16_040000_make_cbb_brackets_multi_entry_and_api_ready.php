<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('cbb_brackets', 'public_id')) {
            Schema::table('cbb_brackets', function (Blueprint $table): void {
                $table->uuid('public_id')->nullable()->after('id');
            });
        }

        DB::table('cbb_brackets')
            ->select('id')
            ->orderBy('id')
            ->each(function (object $row): void {
                DB::table('cbb_brackets')
                    ->where('id', $row->id)
                    ->update(['public_id' => (string) Str::uuid()]);
            });

        Schema::table('cbb_brackets', function (Blueprint $table): void {
            $table->uuid('public_id')->nullable(false)->change();
        });

        Schema::table('cbb_brackets', function (Blueprint $table): void {
            if (! $this->hasIndex('cbb_brackets', 'cbb_brackets_public_id_unique')) {
                $table->unique('public_id');
            }

            if (! $this->hasIndex('cbb_brackets', 'cbb_brackets_user_id_index')) {
                $table->index('user_id');
            }

            if ($this->hasIndex('cbb_brackets', 'cbb_brackets_user_id_season_unique')) {
                $table->dropUnique('cbb_brackets_user_id_season_unique');
            }

            if (! $this->hasIndex('cbb_brackets', 'cbb_brackets_user_id_season_updated_at_index')) {
                $table->index(['user_id', 'season', 'updated_at']);
            }
        });
    }

    public function down(): void
    {
        Schema::table('cbb_brackets', function (Blueprint $table): void {
            if ($this->hasIndex('cbb_brackets', 'cbb_brackets_user_id_season_updated_at_index')) {
                $table->dropIndex(['user_id', 'season', 'updated_at']);
            }

            if ($this->hasIndex('cbb_brackets', 'cbb_brackets_user_id_index')) {
                $table->dropIndex(['user_id']);
            }

            if (! $this->hasIndex('cbb_brackets', 'cbb_brackets_user_id_season_unique')) {
                $table->unique(['user_id', 'season']);
            }

            if ($this->hasIndex('cbb_brackets', 'cbb_brackets_public_id_unique')) {
                $table->dropUnique(['public_id']);
            }

            if (Schema::hasColumn('cbb_brackets', 'public_id')) {
                $table->dropColumn('public_id');
            }
        });
    }

    private function hasIndex(string $table, string $indexName): bool
    {
        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            $indexes = DB::select("PRAGMA index_list('{$table}')");

            return collect($indexes)->contains(fn (object $index) => ($index->name ?? null) === $indexName);
        }

        $database = DB::getDatabaseName();

        return DB::table('information_schema.statistics')
            ->where('table_schema', $database)
            ->where('table_name', $table)
            ->where('index_name', $indexName)
            ->exists();
    }
};
