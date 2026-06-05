<?php

use Illuminate\Support\Str;

test('vue product data consumers do not call legacy api v1 endpoints directly', function () {
    $root = resource_path('js');
    $violations = [];
    $ignoredDirectories = [
        DIRECTORY_SEPARATOR.'actions'.DIRECTORY_SEPARATOR,
        DIRECTORY_SEPARATOR.'routes'.DIRECTORY_SEPARATOR,
    ];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if (! $file instanceof SplFileInfo || ! $file->isFile()) {
            continue;
        }

        $path = $file->getPathname();

        if (! Str::of($path)->endsWith(['.vue', '.ts', '.js'])) {
            continue;
        }

        foreach ($ignoredDirectories as $ignoredDirectory) {
            if (str_contains($path, $ignoredDirectory)) {
                continue 2;
            }
        }

        $contents = file_get_contents($path);
        if ($contents === false || ! str_contains($contents, '/api/v1/')) {
            continue;
        }

        $violations[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $path);
    }

    expect($violations)->toBeEmpty();
});
