<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;

it('registers only sport pages that exist in the frontend', function () {
    $missing = collect(config('sports.domains'))
        ->flatMap(function (array $definition, string $sport): array {
            $web = (array) ($definition['web'] ?? []);
            $pages = (array) ($web['pages'] ?? []);
            $predictionPage = (string) ($web['predictions_page'] ?? '');

            if ($predictionPage !== '') {
                $pages = ['predictions' => $predictionPage, ...$pages];
            }

            return collect($pages)
                ->mapWithKeys(fn (string $component, string $route): array => [
                    "{$sport}/{$route}" => $component,
                ])
                ->all();
        })
        ->reject(fn (string $component): bool => file_exists(
            resource_path("js/pages/{$component}.vue")
        ));

    expect($missing->all())->toBe([]);
});

it('registers only literal inertia components that exist in the frontend', function () {
    $sourceFiles = collect([
        ...File::allFiles(app_path('Http/Controllers')),
        ...File::allFiles(app_path('Providers')),
        ...File::allFiles(base_path('routes')),
    ])->filter(fn (SplFileInfo $file): bool => $file->getExtension() === 'php');

    $components = $sourceFiles
        ->flatMap(function (SplFileInfo $file): array {
            preg_match_all(
                '/(?:Inertia::render|render(?:Id|Form|Resource)Page)\(\s*[\'\"]([^\'\"]+)[\'\"]/',
                File::get($file->getPathname()),
                $matches,
            );

            return $matches[1];
        })
        ->unique();

    $missing = $components->reject(fn (string $component): bool => file_exists(
        resource_path("js/pages/{$component}.vue")
    ));

    expect($missing->values()->all())->toBe([]);
});

it('assigns unique names to registered routes', function () {
    $duplicates = collect(Route::getRoutes())
        ->map(fn ($route): ?string => $route->getName())
        ->filter()
        ->duplicates()
        ->values();

    expect($duplicates->all())->toBe([]);
});
