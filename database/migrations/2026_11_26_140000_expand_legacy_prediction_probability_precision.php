<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = ['nfl_predictions', 'cfb_predictions', 'cbb_predictions', 'nba_predictions',
        'mlb_predictions', 'wcbb_predictions', 'wnba_predictions'];

    public function up(): void
    {
        // @expand-capacity decimal(5,2) -> decimal(6,3): retain all three integer digits and increase fractional capacity.
        $schema = Schema::connection($this->getConnection());
        $driver = $schema->getConnection()->getDriverName();
        if ($driver === 'sqlite') {
            // SQLite NUMERIC affinity does not enforce the historical decimal scale.
            // Do not rebuild historical tables or claim a native precision proof here.
            return;
        }
        if ($driver !== 'mysql') {
            throw new RuntimeException('Legacy prediction precision expansion requires reviewed MySQL or SQLite semantics.');
        }
        $column = static function (string $table) use ($schema): array {
            $columns = $schema->getColumns($table);
            $matches = array_values(array_filter($columns, fn (array $column): bool => $column['name'] === 'win_probability'));
            $value = count($matches) === 1 ? $matches[0] : null;
            if (! is_array($value) || ($value['type_name'] ?? null) !== 'decimal'
                || ! in_array(strtolower($value['type'] ?? ''), ['decimal(5,2)', 'decimal(6,3)'], true)
                || ($value['nullable'] ?? null) !== true || ($value['default'] ?? null) !== null
                || ($value['auto_increment'] ?? null) !== false || ($value['generation'] ?? null) !== null
                || ($value['comment'] ?? null) !== null) {
                throw new RuntimeException("Refusing unknown win_probability schema on {$table}; inspect drift before retrying.");
            }

            return $value;
        };
        // MySQL DDL can commit partially. Admit the entire known schema set first,
        // then revalidate each current column before changing it. Known expanded
        // columns are idempotent; no row values or observation times are rewritten.
        foreach (self::TABLES as $table) {
            $column($table);
        }
        foreach (self::TABLES as $table) {
            if ($column($table)['type'] === 'decimal(6,3)') {
                continue;
            }
            $schema->table($table, function (Blueprint $table): void {
                // Retain the original three integer digits, signedness, NULL and default.
                $table->decimal('win_probability', 6, 3)->nullable()->default(null)->change();
            });
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Forward-only: narrowing stored forecast probabilities would destroy precision.');
    }
};
