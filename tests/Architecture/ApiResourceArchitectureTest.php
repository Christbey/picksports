<?php

function apiResourceSourceFiles(): array
{
    $root = dirname(__DIR__, 2).'/app/Http/Resources';
    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

    foreach ($iterator as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $files[ltrim(str_replace($root, '', $file->getPathname()), DIRECTORY_SEPARATOR)] = file_get_contents($file->getPathname());
    }

    return $files;
}

function knownResourceQueryDebt(): array
{
    return [];
}

function knownResourceCalculationDebt(): array
{
    return [
        'Api/V2/MlbPickCandidateResource.php',
        'CBB/PlayerStatResource.php',
        'CBB/TeamResource.php',
        'MLB/TeamResource.php',
        'NBA/PlayerInjuryResource.php',
        'NBA/PlayerStatResource.php',
        'NFL/GameResource.php',
        'NFL/PlayerStatResource.php',
        'NFL/TeamResource.php',
        'WCBB/TeamResource.php',
        'WNBA/TeamResource.php',
    ];
}

it('keeps database access out of api resources', function () {
    $violations = collect(apiResourceSourceFiles())
        ->filter(fn (string $source): bool => preg_match(
            '/(?:DB|Schema)::|::query\s*\(|->(?:where|whereIn|whereDate|firstOrFail|findOrFail|paginate)\s*\(/',
            $source,
        ) === 1)
        ->keys()
        ->all();

    $unexpected = array_values(array_diff($violations, knownResourceQueryDebt()));

    expect($unexpected)->toBe([], 'API Resources must serialize prepared data and never issue database queries.');
});

it('keeps business calculations out of api resources', function () {
    $violations = collect(apiResourceSourceFiles())
        ->filter(fn (string $source): bool => preg_match(
            '/^use App\\\\Actions\\\\|^use App\\\\(?:Services|Support)\\\\.*(?:Calculator|Scorer|ProjectionService|NarrativeService|ExplanationService|ModelContextService);/m',
            $source,
        ) === 1)
        ->keys()
        ->all();

    $unexpected = array_values(array_diff($violations, knownResourceCalculationDebt()));

    expect($unexpected)->toBe([], 'API Resources must consume prepared values instead of invoking domain calculators.');
});
