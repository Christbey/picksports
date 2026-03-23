<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * @var array<int, string>
     */
    private array $tables = [
        'nba_player_props',
        'nfl_player_props',
        'mlb_player_props',
        'cbb_player_props',
    ];

    public function up(): void
    {
        foreach ($this->tables as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                if (! Schema::hasColumn($tableName, 'narrative_json')) {
                    $table->json('narrative_json')->nullable()->after('confidence_decomposition');
                }

                if (! Schema::hasColumn($tableName, 'narrative_provider')) {
                    $table->string('narrative_provider', 32)->nullable()->after('narrative_json');
                }

                if (! Schema::hasColumn($tableName, 'narrative_model')) {
                    $table->string('narrative_model', 64)->nullable()->after('narrative_provider');
                }

                if (! Schema::hasColumn($tableName, 'narrative_input_hash')) {
                    $table->string('narrative_input_hash', 64)->nullable()->after('narrative_model');
                }

                if (! Schema::hasColumn($tableName, 'narrative_latency_ms')) {
                    $table->unsignedInteger('narrative_latency_ms')->nullable()->after('narrative_input_hash');
                }

                if (! Schema::hasColumn($tableName, 'narrative_generated_at')) {
                    $table->timestamp('narrative_generated_at')->nullable()->after('narrative_latency_ms');
                }
            });

            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                if (! $this->hasIndex($tableName, 'narrative_input_hash')) {
                    $table->index('narrative_input_hash');
                }

                if (! $this->hasIndex($tableName, 'narrative_generated_at')) {
                    $table->index('narrative_generated_at');
                }
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                if ($this->hasIndex($tableName, 'narrative_input_hash')) {
                    $table->dropIndex(['narrative_input_hash']);
                }

                if ($this->hasIndex($tableName, 'narrative_generated_at')) {
                    $table->dropIndex(['narrative_generated_at']);
                }

                $columns = array_values(array_filter([
                    Schema::hasColumn($tableName, 'narrative_json') ? 'narrative_json' : null,
                    Schema::hasColumn($tableName, 'narrative_provider') ? 'narrative_provider' : null,
                    Schema::hasColumn($tableName, 'narrative_model') ? 'narrative_model' : null,
                    Schema::hasColumn($tableName, 'narrative_input_hash') ? 'narrative_input_hash' : null,
                    Schema::hasColumn($tableName, 'narrative_latency_ms') ? 'narrative_latency_ms' : null,
                    Schema::hasColumn($tableName, 'narrative_generated_at') ? 'narrative_generated_at' : null,
                ]));

                if ($columns !== []) {
                    $table->dropColumn($columns);
                }
            });
        }
    }

    private function hasIndex(string $tableName, string $column): bool
    {
        $indexName = "{$tableName}_{$column}_index";

        return collect(Schema::getIndexes($tableName))
            ->contains(fn (array $index) => ($index['name'] ?? null) === $indexName);
    }
};
