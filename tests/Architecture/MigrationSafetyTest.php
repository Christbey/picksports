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

        if (! $isExplicitContractMigration && ! $isExplicitNullabilityExpansion) {
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
