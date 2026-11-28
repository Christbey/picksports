<?php

use App\Services\MLB\MlbTabularFeatureSchema;

function mlbSchemaBindingFixture(string $yaml, string $json, Closure $test): void
{
    $root = sys_get_temp_dir().'/picksports-mlb-schema-'.bin2hex(random_bytes(8));
    mkdir($root, 0700);
    file_put_contents($root.'/requested.yaml', $yaml);
    file_put_contents($root.'/returned.json', $json);
    $requested = ['bytes' => strlen($yaml), 'sha256' => hash('sha256', $yaml)];
    $returned = ['bytes' => strlen($json), 'sha256' => hash('sha256', $json)];
    try {
        $test($root, $requested, $returned);
    } finally {
        unlink($root.'/requested.yaml');
        unlink($root.'/returned.json');
        rmdir($root);
    }
}

it('binds the actual compiled MLB YAML to Python-produced ordered JSON', function () {
    $base = dirname(__DIR__, 2);
    $yaml = file_get_contents($base.'/ml/mlb/config/feature_schema.yaml');
    $json = file_get_contents($base.'/tests/Fixtures/mlb/feature-schema-python.json');
    mlbSchemaBindingFixture($yaml, $json, function ($root, $requested, $returned) {
        (new MlbTabularFeatureSchema)->assertMatches($root.'/requested.yaml', $requested, $root.'/returned.json', $returned, 'mlb-pregame-ml-v1');
        expect(true)->toBeTrue();
    });
});

it('refuses reordered model features even when all mapping values match', function () {
    $base = dirname(__DIR__, 2);
    $yaml = file_get_contents($base.'/ml/mlb/config/feature_schema.yaml');
    $schema = json_decode(file_get_contents($base.'/tests/Fixtures/mlb/feature-schema-python.json'), false, 32, JSON_THROW_ON_ERROR);
    $schema->features = (object) array_reverse(get_object_vars($schema->features), true);
    mlbSchemaBindingFixture($yaml, json_encode($schema, JSON_THROW_ON_ERROR), function ($root, $requested, $returned) {
        expect(fn () => (new MlbTabularFeatureSchema)->assertMatches($root.'/requested.yaml', $requested, $root.'/returned.json', $returned, 'mlb-pregame-ml-v1'))
            ->toThrow(RuntimeException::class, 'feature order');
    });
});

it('preserves scalar and empty-container distinctions across schema serialization', function (string $yaml, string $json) {
    mlbSchemaBindingFixture($yaml, $json, function ($root, $requested, $returned) {
        expect(fn () => (new MlbTabularFeatureSchema)->assertMatches($root.'/requested.yaml', $requested, $root.'/returned.json', $returned, 'synthetic-v1'))
            ->toThrow(RuntimeException::class);
    });
})->with([
    ['schema_version: synthetic-v1'."\nfeatures: {feature_x: {nullable: true}}\n", '{"schema_version":"synthetic-v1","features":{"feature_x":{"nullable":"true"}}}'],
    ['schema_version: synthetic-v1'."\nfeatures: {}\nextra: {}\n", '{"schema_version":"synthetic-v1","features":{},"extra":[]}'],
    ['schema_version: synthetic-v1'."\nfeatures: {}\nnumber: 9007199254740993\n", '{"schema_version":"synthetic-v1","features":{},"number":9007199254740993}'],
]);

it('admits exact safe integer and float equivalents without changing feature order', function () {
    mlbSchemaBindingFixture("schema_version: synthetic-v1\nfeatures: {feature_x: {default: 1}}\n", '{"schema_version":"synthetic-v1","features":{"feature_x":{"default":1.0}}}', function ($root, $requested, $returned) {
        (new MlbTabularFeatureSchema)->assertMatches($root.'/requested.yaml', $requested, $root.'/returned.json', $returned, 'synthetic-v1');
        expect(true)->toBeTrue();
    });
});

it('refuses YAML tags before parsing or object construction', function () {
    mlbSchemaBindingFixture("schema_version: synthetic-v1\nfeatures: !custom {}\n", '{}', function ($root, $requested, $returned) {
        expect(fn () => (new MlbTabularFeatureSchema)->assertMatches($root.'/requested.yaml', $requested, $root.'/returned.json', $returned, 'synthetic-v1'))
            ->toThrow(RuntimeException::class, 'tags and alias');
    });
});

it('refuses returned schema growth against its exact byte descriptor', function () {
    mlbSchemaBindingFixture("schema_version: synthetic-v1\nfeatures: {}\n", '{}', function ($root, $requested, $returned) {
        file_put_contents($root.'/returned.json', ' ', FILE_APPEND);
        expect(fn () => (new MlbTabularFeatureSchema)->assertMatches($root.'/requested.yaml', $requested, $root.'/returned.json', $returned, 'synthetic-v1'))
            ->toThrow(RuntimeException::class, 'consumed bytes');
    });
});
