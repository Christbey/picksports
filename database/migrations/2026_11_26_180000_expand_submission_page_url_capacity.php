<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // @expand-capacity string(255) -> string(2048)
        $schema = Schema::connection($this->getConnection());
        $connection = $schema->getConnection();
        if ($connection->getDriverName() === 'sqlite') {
            $schema->table('submissions', function (Blueprint $table): void {
                $table->string('page_url', 2048)->nullable()->default(null)->change();
            });

            return;
        }
        if ($connection->getDriverName() !== 'mysql') {
            throw new RuntimeException('Submission context capacity expansion requires MySQL or SQLite.');
        }
        $column = $connection->selectOne(
            'SELECT DATA_TYPE AS data_type, CHARACTER_MAXIMUM_LENGTH AS capacity, CHARACTER_SET_NAME AS character_set_name, COLLATION_NAME AS collation_name, COLUMN_DEFAULT AS column_default, COLUMN_COMMENT AS column_comment, IS_NULLABLE AS is_nullable, EXTRA AS extra, GENERATION_EXPRESSION AS generation_expression FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$connection->getTablePrefix().'submissions', 'page_url'],
            false,
        );
        $capacity = $column === null ? false : filter_var($column->capacity, FILTER_VALIDATE_INT, ['options' => ['min_range' => 255]]);
        if ($column === null || $column->data_type !== 'varchar' || $capacity === false
            || $column->is_nullable !== 'YES' || $column->column_default !== null
            || trim((string) $column->extra) !== '' || trim((string) $column->generation_expression) !== ''
            || preg_match('/^[A-Za-z0-9_]+$/D', (string) $column->character_set_name) !== 1
            || preg_match('/^[A-Za-z0-9_]+$/D', (string) $column->collation_name) !== 1) {
            throw new RuntimeException('Unexpected submission context schema: expected a nullable stored VARCHAR with a NULL default.');
        }
        if ($capacity >= 2048) {
            return;
        }
        if ($capacity !== 255) {
            throw new RuntimeException('Unexpected submission context capacity: expected 255 characters.');
        }
        $schema->table('submissions', function (Blueprint $table) use ($column): void {
            $table->string('page_url', 2048)->nullable()->default(null)
                ->charset($column->character_set_name)->collation($column->collation_name)
                ->comment($column->column_comment)->change();
        });
    }

    public function down(): void
    {
        throw new LogicException('Forward-only capacity expansion: shrinking submission context could discard admitted feedback evidence.');
    }
};
