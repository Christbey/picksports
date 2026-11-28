<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // @expand-capacity string(255) -> string(512)
        // @expand-case-sensitive-identity utf8mb4_unicode_ci -> utf8mb4_bin
        // Credential IDs are canonical base64url, so case is identity. This does
        // not claim arbitrary byte equality for the PAD SPACE legacy bin collation.
        $schema = Schema::connection($this->getConnection());
        $connection = $schema->getConnection();
        if ($connection->getDriverName() === 'sqlite') {
            // SQLite records the declared capacity but does not enforce VARCHAR length.
            $schema->table('passkeys', function (Blueprint $table): void {
                $table->string('credential_id', 512)->collation('BINARY')->change();
            });

            return;
        }
        if ($connection->getDriverName() !== 'mysql') {
            throw new RuntimeException('Passkey credential expansion requires MySQL or SQLite.');
        }
        $tableName = $connection->getTablePrefix().'passkeys';
        $column = $connection->selectOne(
            'SELECT DATA_TYPE AS data_type, CHARACTER_MAXIMUM_LENGTH AS capacity, CHARACTER_SET_NAME AS character_set_name, COLLATION_NAME AS collation_name, COLUMN_DEFAULT AS column_default, COLUMN_COMMENT AS column_comment, IS_NULLABLE AS is_nullable, EXTRA AS extra, GENERATION_EXPRESSION AS generation_expression FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$tableName, 'credential_id'],
            false,
        );
        $capacity = $column === null ? false : filter_var($column->capacity, FILTER_VALIDATE_INT);
        if ($column === null || $column->data_type !== 'varchar' || ! in_array($capacity, [255, 512], true)
            || $column->character_set_name !== 'utf8mb4'
            || ! in_array($column->collation_name, ['utf8mb4_unicode_ci', 'utf8mb4_bin'], true)
            || $column->is_nullable !== 'NO' || $column->column_default !== null
            || trim((string) $column->extra) !== '' || trim((string) $column->generation_expression) !== '') {
            throw new RuntimeException('Unexpected passkey credential schema; expansion requires the declared stored UTF8MB4 identity contract.');
        }
        $indexes = $connection->select('SHOW INDEX FROM '.$connection->getQueryGrammar()->wrapTable('passkeys'), [], false);
        $fullUnique = false;
        foreach ($indexes as $index) {
            if ((int) $index->Non_unique === 0 && $index->Column_name === 'credential_id'
                && (int) $index->Seq_in_index === 1 && $index->Sub_part === null && ($index->Expression ?? null) === null
                && count(array_filter($indexes, fn ($member) => $member->Key_name === $index->Key_name)) === 1) {
                $fullUnique = true;
            }
        }
        if (! $fullUnique) {
            throw new RuntimeException('Passkey credential expansion requires its complete unique identity index.');
        }
        if ($capacity === 512 && $column->collation_name === 'utf8mb4_bin') {
            return;
        }
        $schema->table('passkeys', function (Blueprint $table) use ($column): void {
            $table->string('credential_id', 512)->charset($column->character_set_name)
                ->collation('utf8mb4_bin')->comment($column->column_comment)->change();
        });
    }

    public function down(): void
    {
        throw new LogicException('Forward-only identity expansion: shrinking or case-folding may discard or merge admitted credentials.');
    }
};
