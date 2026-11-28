<?php

use App\Services\NFL\NflTabularFeatureSchema;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

it('binds returned schema semantics to the exact requested bytes without claiming Python hash parity', function (array $case) {
    $root = storage_path('framework/testing/nfl-schema-'.Str::uuid());
    File::ensureDirectoryExists($root);
    try {
        $yaml = $root.'/requested.yaml';
        $json = $root.'/returned.json';
        File::put($yaml, $case['yaml']);
        File::put($json, $case['json']);
        $descriptor = fn (string $path): array => ['bytes' => filesize($path), 'sha256' => hash_file('sha256', $path)];
        $call = fn () => app(NflTabularFeatureSchema::class)->assertMatches($yaml, $descriptor($yaml), $json, $descriptor($json), $case['version']);
        if ($case['accepted']) {
            $call();
            expect(true)->toBeTrue();
        } else {
            expect($call)->toThrow(RuntimeException::class);
        }
    } finally {
        File::deleteDirectory($root);
    }
})->with(function (): array {
    return array_map(fn (array $case): array => [$case], json_decode(file_get_contents(__DIR__.'/../../Fixtures/nfl-numeric-schema-cases.json'), true, flags: JSON_THROW_ON_ERROR));
});
