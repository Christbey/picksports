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

/** Only recognized signed range/string-length widening chains qualify, never arbitrary change(). */
function isExplicitCapacityExpansion(string $source): bool
{
    $up = migrationUpMethodSource($source);
    if (destructiveMigrationOperations($up) !== ['in-place column change']
        || preg_match('/@expand-capacity\s+(string\((\d+)\)|tinyInteger|smallInteger|integer)\s*->\s*(string\((\d+)\)|smallInteger|integer|bigInteger)/', $up, $declaration) !== 1) {
        return false;
    }
    $from = $declaration[1];
    $to = $declaration[3];
    $integerRanks = ['tinyInteger' => 1, 'smallInteger' => 2, 'integer' => 3, 'bigInteger' => 4];
    if (str_starts_with($from, 'string(') && str_starts_with($to, 'string(')) {
        $oldLength = (int) $declaration[2];
        $newLength = (int) $declaration[4];
        if ($oldLength < 1 || $newLength <= $oldLength) {
            return false;
        }
        $type = 'string';
        $length = ',\s*'.$newLength;
    } elseif (isset($integerRanks[$from], $integerRanks[$to]) && $integerRanks[$to] > $integerRanks[$from]) {
        $type = $to;
        $length = '';
    } else {
        return false;
    }
    // Reject unsigned conversions, defaults, nullable(false), other type changes and unaccounted changes.
    $chain = '/\$table->'.$type.'\(\s*\x27[a-z_]+\x27'.$length.'\s*\)(?:->nullable\(\))?->change\s*\(\s*\)/';
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
    'wider signed integer' => ['tinyInteger -> smallInteger', '$table->smallInteger(\'rest_days_home\')->nullable()->change();', true],
    'narrower string' => ['string(128) -> string(64)', '$table->string(\'model_version\', 64)->change();', false],
    'narrower integer' => ['integer -> smallInteger', '$table->smallInteger(\'rest_days_home\')->change();', false],
    'false target declaration' => ['string(64) -> string(128)', '$table->string(\'model_version\', 32)->change();', false],
    'unsigned conversion' => ['tinyInteger -> smallInteger', '$table->unsignedSmallInteger(\'rest_days_home\')->change();', false],
    'tightened nullability' => ['string(64) -> string(128)', '$table->string(\'model_version\', 128)->nullable(false)->change();', false],
    'changed default' => ['string(64) -> string(128)', '$table->string(\'model_version\', 128)->default(\'new\')->change();', false],
    'unaccounted change' => ['tinyInteger -> smallInteger', '$table->smallInteger(\'rest_days_home\')->change(); $table->text(\'name\')->change();', false],
    'mixed index drop' => ['tinyInteger -> smallInteger', '$table->smallInteger(\'rest_days_home\')->change(); $table->dropUnique(\'old\');', false],
]);
