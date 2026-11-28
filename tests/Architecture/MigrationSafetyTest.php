<?php

function migrationUpMethodSource(string $source): string
{
    if (preg_match(
        '/public\s+function\s+up\s*\([^)]*\)\s*:\s*void\s*\{(?<body>.*)\n\s*public\s+function\s+down\s*\(/s',
        $source,
        $matches,
    ) !== 1) {
        return '';
    }

    return $matches['body'];
}

function destructiveMigrationOperations(string $source): array
{
    $patterns = [
        'drop table' => '/Schema::drop(?:IfExists)?\s*\(/',
        'drop column or constraint' => '/->drop(?:Column|Columns|Foreign|Index|Primary|Unique|FullText|SpatialIndex)\s*\(/',
        'rename table or column' => '/(?:Schema::rename|->renameColumn|->renameIndex)\s*\(/',
        'in-place column change' => '/->change\s*\(/',
    ];

    return collect($patterns)
        ->filter(fn (string $pattern): bool => preg_match($pattern, $source) === 1)
        ->keys()
        ->values()
        ->all();
}

/** Only recognized capacity widening chains qualify, never arbitrary change(). */
function isExplicitCapacityExpansion(string $source): bool
{
    $up = migrationUpMethodSource($source);
    if (destructiveMigrationOperations($up) !== ['in-place column change']
        || preg_match('/@expand-capacity\s+(?<from>string\(\d+\)|decimal\(\d+,\d+\)|tinyInteger|smallInteger|integer)\s*->\s*(?<to>string\(\d+\)|decimal\(\d+,\d+\)|smallInteger|integer|bigInteger)/', $up, $declaration) !== 1) {
        return false;
    }
    $from = $declaration['from'];
    $to = $declaration['to'];
    $nullableChain = '(?:\s*->nullable\(\))?';
    $preservedAttributes = '';
    $integerRanks = ['tinyInteger' => 1, 'smallInteger' => 2, 'integer' => 3, 'bigInteger' => 4];
    if (str_starts_with($from, 'string(') && str_starts_with($to, 'string(')) {
        $oldLength = (int) substr($from, 7, -1);
        $newLength = (int) substr($to, 7, -1);
        if ($oldLength < 1 || $newLength <= $oldLength) {
            return false;
        }
        $type = 'string';
        $length = ',\s*'.$newLength;
        $nullableChain = '(?:\s*->nullable\(\))?(?:\s*->default\(null\))?';
        $preservedAttributes = '(?:\s*->charset\(\$column->character_set_name\)\s*->collation\(\$column->collation_name\)\s*->comment\(\$column->column_comment\))?';
        if (str_contains($up, '@expand-case-sensitive-identity')) {
            // A deliberate equality expansion must name an independently reviewed
            // no-loss pair; this does not permit arbitrary collation or charset changes.
            if (preg_match('/@expand-case-sensitive-identity\s+(\w+)\s*->\s*(\w+)/', $up, $identity) !== 1
                || [$identity[1], $identity[2]] !== ['utf8mb4_unicode_ci', 'utf8mb4_bin']) {
                return false;
            }
            $preservedAttributes = '(?:\s*->collation\(\x27BINARY\x27\)|\s*->charset\(\$column->character_set_name\)\s*->collation\(\x27utf8mb4_bin\x27\)\s*->comment\(\$column->column_comment\))';
        }
    } elseif (preg_match('/\Adecimal\((\d+),(\d+)\)\z/', $from, $oldDecimal) === 1
        && preg_match('/\Adecimal\((\d+),(\d+)\)\z/', $to, $newDecimal) === 1) {
        [$oldPrecision, $oldScale] = [(int) $oldDecimal[1], (int) $oldDecimal[2]];
        [$newPrecision, $newScale] = [(int) $newDecimal[1], (int) $newDecimal[2]];
        if ($oldPrecision < 1 || $newPrecision <= $oldPrecision || $newPrecision > 65
            || $newScale < $oldScale || $newScale > 30 || $oldScale > $oldPrecision
            || ($newPrecision - $newScale) < ($oldPrecision - $oldScale)) {
            return false;
        }
        // Preserve both fractional and integer capacity. Only signed nullable
        // columns with an implicit or explicit NULL default qualify here.
        $type = 'decimal';
        $length = ',\s*'.$newPrecision.',\s*'.$newScale;
        $nullableChain = '\s*->nullable\(\)(?:->default\(null\))?';
    } elseif (isset($integerRanks[$from], $integerRanks[$to]) && $integerRanks[$to] > $integerRanks[$from]) {
        $type = $to;
        $length = '';
    } else {
        return false;
    }
    // Reject unsigned conversions, non-NULL defaults, nullable(false), other type changes and unaccounted changes.
    // A string expansion may copy only the exact writer-metadata attribute chain;
    // its preflight remains subject to source review and native migration controls.
    $chain = '/\$table->'.$type.'\(\s*\x27[a-z_]+\x27'.$length.'\s*\)'.$nullableChain.$preservedAttributes.'\s*->change\s*\(\s*\)/';
    $count = preg_match_all($chain, $up);

    return $count > 0 && $count === preg_match_all('/->change\s*\(/', $up);
}

it('requires expand-first migrations after the platform guardrail date', function () {
    $migrationPaths = glob(dirname(__DIR__, 2).'/database/migrations/*.php') ?: [];
    $violations = [];

    foreach ($migrationPaths as $path) {
        $name = pathinfo($path, PATHINFO_FILENAME);
        if ($name < '2026_08_12_000000') {
            continue;
        }

        $source = file_get_contents($path);
        $operations = destructiveMigrationOperations(migrationUpMethodSource($source));
        if ($operations === []) {
            continue;
        }

        $isExplicitContractMigration = str_contains($name, '_contract_')
            && str_contains($source, '@contract-migration')
            && str_contains($source, '@requires-zero-legacy-usage');
        $isExplicitNullabilityExpansion = $operations === ['in-place column change']
            && str_contains($source, '@expand-nullability');

        if (! $isExplicitContractMigration && ! $isExplicitNullabilityExpansion && ! isExplicitCapacityExpansion($source)) {
            $violations[$name] = $operations;
        }
    }

    expect($violations)->toBe([], 'New migrations must expand first. Destructive operations require a later, explicitly marked contract migration.');
});

it('allows an explicitly documented nullability expansion without allowing destructive operations', function () {
    $migration = <<<'PHP'
<?php
return new class {
    public function up(): void
    {
        // @expand-nullability Native records do not have a legacy identifier.
        Schema::table('predictions', function ($table) {
            $table->string('legacy_id')->nullable()->change();
        });
    }

    public function down(): void
    {
    }
};
PHP;

    $operations = destructiveMigrationOperations(migrationUpMethodSource($migration));

    expect($operations)->toBe(['in-place column change'])
        ->and(str_contains($migration, '@expand-nullability'))->toBeTrue();
});

it('detects destructive migration operations only in the up method', function () {
    $migration = <<<'PHP'
<?php
return new class {
    public function up(): void
    {
        Schema::table('games', function ($table) {
            $table->dropColumn('legacy_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('games');
    }
};
PHP;

    expect(destructiveMigrationOperations(migrationUpMethodSource($migration)))
        ->toBe(['drop column or constraint']);
});

it('accepts only declared capacity widening with fully accounted change chains', function (string $declaration, string $changes, bool $allowed) {
    $source = "<?php\nreturn new class {\npublic function up(): void {\n// @expand-capacity {$declaration}\n{$changes}\n}\npublic function down(): void {}\n};";

    expect(isExplicitCapacityExpansion($source))->toBe($allowed);
})->with([
    'longer string' => ['string(64) -> string(128)', '$table->string(\'model_version\', 128)->nullable()->change();', true],
    'longer string with null default' => ['string(255) -> string(2048)', '$table->string(\'page_url\', 2048)->nullable()->default(null)->change();', true],
    'string preserves writer metadata attributes' => ['string(255) -> string(2048)', '$table->string(\'page_url\', 2048)->nullable()->default(null)->charset($column->character_set_name)->collation($column->collation_name)->comment($column->column_comment)->change();', true],
    'multiline preserved string metadata' => ['string(255) -> string(2048)', '$table->string(\'page_url\', 2048)->nullable()->default(null)'."\n".'    ->charset($column->character_set_name)->collation($column->collation_name)'."\n".'    ->comment($column->column_comment)->change();', true],
    'string changes literal collation' => ['string(255) -> string(2048)', '$table->string(\'page_url\', 2048)->nullable()->collation(\'ascii_bin\')->change();', false],
    'string changes literal comment' => ['string(255) -> string(2048)', '$table->string(\'page_url\', 2048)->nullable()->comment(\'replacement\')->change();', false],
    'string unreviewed metadata expression' => ['string(255) -> string(2048)', '$table->string(\'page_url\', 2048)->nullable()->charset($other->charset)->collation($other->collation)->comment($other->comment)->change();', false],
    'wider signed integer' => ['tinyInteger -> smallInteger', '$table->smallInteger(\'rest_days_home\')->nullable()->change();', true],
    'narrower string' => ['string(128) -> string(64)', '$table->string(\'model_version\', 64)->change();', false],
    'narrower integer' => ['integer -> smallInteger', '$table->smallInteger(\'rest_days_home\')->change();', false],
    'false target declaration' => ['string(64) -> string(128)', '$table->string(\'model_version\', 32)->change();', false],
    'unsigned conversion' => ['tinyInteger -> smallInteger', '$table->unsignedSmallInteger(\'rest_days_home\')->change();', false],
    'tightened nullability' => ['string(64) -> string(128)', '$table->string(\'model_version\', 128)->nullable(false)->change();', false],
    'changed default' => ['string(64) -> string(128)', '$table->string(\'model_version\', 128)->default(\'new\')->change();', false],
    'unaccounted change' => ['tinyInteger -> smallInteger', '$table->smallInteger(\'rest_days_home\')->change(); $table->text(\'name\')->change();', false],
    'wider decimal precision' => ['decimal(5,3) -> decimal(7,3)', "\$table->decimal('home_recent_form', 7, 3)->nullable()->change();", true],
    'changed decimal scale' => ['decimal(5,3) -> decimal(7,2)', "\$table->decimal('home_recent_form', 7, 2)->nullable()->change();", false],
    'narrower decimal precision' => ['decimal(7,3) -> decimal(5,3)', "\$table->decimal('home_recent_form', 5, 3)->change();", false],
    'mismatched decimal target' => ['decimal(5,3) -> decimal(7,3)', "\$table->decimal('home_recent_form', 5, 3)->nullable()->change();", false],
    'mixed index drop' => ['tinyInteger -> smallInteger', '$table->smallInteger(\'rest_days_home\')->change(); $table->dropUnique(\'old\');', false],
    'nullable decimal precision expansion' => ['decimal(5,3) -> decimal(7,3)', '$table->decimal(\'home_recent_form\', 7, 3)->nullable()->change(); $table->decimal(\'away_recent_form\', 7, 3)->nullable()->change();', true],
    'decimal narrowing' => ['decimal(7,3) -> decimal(5,3)', '$table->decimal(\'home_recent_form\', 5, 3)->nullable()->change();', false],
    'decimal nonlossy scale increase' => ['decimal(5,3) -> decimal(7,4)', '$table->decimal(\'home_recent_form\', 7, 4)->nullable()->change();', true],
    'decimal reduced integer capacity' => ['decimal(5,2) -> decimal(6,4)', '$table->decimal(\'win_probability\', 6, 4)->nullable()->change();', false],
    'decimal same integer capacity and explicit null' => ['decimal(5,2) -> decimal(6,3)', '$table->decimal(\'win_probability\', 6, 3)->nullable()->default(null)->change();', true],
    'decimal scale decrease' => ['decimal(5,3) -> decimal(7,2)', '$table->decimal(\'home_recent_form\', 7, 2)->nullable()->change();', false],
    'decimal signedness change' => ['decimal(5,3) -> decimal(7,3)', '$table->decimal(\'home_recent_form\', 7, 3)->unsigned()->nullable()->change();', false],
    'decimal default change' => ['decimal(5,3) -> decimal(7,3)', '$table->decimal(\'home_recent_form\', 7, 3)->default(0)->nullable()->change();', false],
    'decimal missing nullability' => ['decimal(5,3) -> decimal(7,3)', '$table->decimal(\'home_recent_form\', 7, 3)->change();', false],
    'decimal false nullability' => ['decimal(5,3) -> decimal(7,3)', '$table->decimal(\'home_recent_form\', 7, 3)->nullable(false)->change();', false],
    'decimal wrong target precision' => ['decimal(5,3) -> decimal(7,3)', '$table->decimal(\'home_recent_form\', 6, 3)->nullable()->change();', false],
    'decimal wrong target scale' => ['decimal(5,3) -> decimal(7,3)', '$table->decimal(\'home_recent_form\', 7, 2)->nullable()->change();', false],
    'decimal invalid source scale' => ['decimal(3,5) -> decimal(7,5)', '$table->decimal(\'home_recent_form\', 7, 5)->nullable()->change();', false],
    'decimal invalid target capacity' => ['decimal(5,3) -> decimal(99,3)', '$table->decimal(\'home_recent_form\', 99, 3)->nullable()->change();', false],
    'decimal unaccounted change' => ['decimal(5,3) -> decimal(7,3)', '$table->decimal(\'home_recent_form\', 7, 3)->nullable()->change(); $table->text(\'notes\')->change();', false],

]);

it('recognizes only the declared no-loss case sensitive identity capacity expansion', function () {
    $migration = <<<'PHP'
<?php
return new class {
    public function up(): void
    {
        // @expand-capacity string(255) -> string(512)
        // @expand-case-sensitive-identity utf8mb4_unicode_ci -> utf8mb4_bin
        Schema::table('opaque_keys', function ($table) use ($column) {
            $table->string('opaque_id', 512)->charset($column->character_set_name)
                ->collation('utf8mb4_bin')->comment($column->column_comment)->change();
        });
    }
    public function down(): void {}
};
PHP;
    expect(isExplicitCapacityExpansion($migration))->toBeTrue()
        ->and(isExplicitCapacityExpansion(str_replace('utf8mb4_bin', 'utf8mb4_general_ci', $migration)))->toBeFalse()
        ->and(isExplicitCapacityExpansion(str_replace("->collation('utf8mb4_bin')", "->collation('utf8mb4_unicode_ci')", $migration)))->toBeFalse()
        ->and(isExplicitCapacityExpansion(str_replace('string(512)', 'string(128)', $migration)))->toBeFalse()
        ->and(isExplicitCapacityExpansion(str_replace('->charset($column->character_set_name)', "->charset('ascii')", $migration)))->toBeFalse();
});
