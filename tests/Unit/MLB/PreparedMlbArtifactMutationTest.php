<?php

use App\Services\MLB\PreparedMlbArtifactMutation;

it('freezes referenced selection metadata rather than following a caller scalar', function () {
    $label = 'first';
    $prepared = new PreparedMlbArtifactMutation('artifact', 'run', str_repeat('a', 64), 'activate', ['metadata' => ['label' => &$label]]);
    $label = 'second';
    expect($prepared->payload['metadata']['label'])->toBe('first');
});

it('does not let an external reference grow the prepared payload after its budget check', function () {
    $value = 'small';
    $prepared = new PreparedMlbArtifactMutation('artifact', 'run', str_repeat('a', 64), 'activate', ['metadata' => ['value' => &$value]]);
    $value = str_repeat('x', 65537);
    expect($prepared->payload['metadata']['value'])->toBe('small');
});

it('retains exact zero null scalar types and array order in frozen metadata', function () {
    $prepared = new PreparedMlbArtifactMutation('artifact', 'run', str_repeat('a', 64), 'activate',
        ['metadata' => ['zero' => 0, 'float' => 0.0, 'null' => null, 'list' => [false, '0', 1.0]]]);
    expect($prepared->payload)->toBe(['metadata' => ['zero' => 0, 'float' => 0.0, 'null' => null, 'list' => [false, '0', 1.0]]]);
});
