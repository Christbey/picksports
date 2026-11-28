<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // @expand-nullability Preserve observed clocks and admit unknown kickoff as NULL without guessing midnight.
        $schema = Schema::connection($this->getConnection());
        $connection = $schema->getConnection();
        if ($connection->getDriverName() === 'sqlite') {
            $schema->table('nfl_games', function (Blueprint $table): void {
                $table->time('game_time')->nullable()->change();
            });

            return;
        }
        if ($connection->getDriverName() !== 'mysql') {
            throw new RuntimeException('Unexpected NFL game_time schema: unsupported database driver.');
        }
        // Read the migration writer, never an asynchronously replicated schema.
        $column = $connection->selectOne(
            'SELECT DATA_TYPE AS data_type, DATETIME_PRECISION AS datetime_precision, COLUMN_DEFAULT AS column_default, COLUMN_COMMENT AS column_comment, IS_NULLABLE AS is_nullable, EXTRA AS extra, GENERATION_EXPRESSION AS generation_expression FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            ['nfl_games', 'game_time'],
            false,
        );
        $precision = $column === null ? false : filter_var($column->datetime_precision, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 6]]);
        if ($column === null || $column->data_type !== 'time' || $precision === false
            || trim((string) $column->extra) !== '' || trim((string) $column->generation_expression) !== ''
            || ! in_array($column->is_nullable, ['YES', 'NO'], true)) {
            throw new RuntimeException('Unexpected NFL game_time schema: expected a stored TIME column with literal defaults.');
        }
        if ($column->is_nullable === 'YES') {
            return;
        }
        $schema->table('nfl_games', function (Blueprint $table) use ($column, $precision): void {
            $table->time('game_time', $precision)->nullable()
                ->default($column->column_default)->comment($column->column_comment)->change();
        });
    }

    public function down(): void
    {
        throw new LogicException('Forward-only migration: unknown NFL kickoff clocks must remain NULL. Restoring NOT NULL would require inventing timestamps; use a reviewed compatible forward repair.');
    }
};
