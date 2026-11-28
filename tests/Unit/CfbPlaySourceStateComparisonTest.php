<?php

use App\Actions\ESPN\CFB\SyncPlays;

it('compares CFB raw play states without object key order while retaining value distinctions', function (array $stored, array $incoming, bool $same) {
    $action = (new ReflectionClass(SyncPlays::class))->newInstanceWithoutConstructor();
    $canonical = new ReflectionMethod(SyncPlays::class, 'canonicalPlaySourceState');
    expect($canonical->invoke($action, $stored) === $canonical->invoke($action, $incoming))->toBe($same);
})->with([
    'MySQL nested object ordering' => [['id' => 'td', 'type' => ['text' => 'TD', 'id' => 1]], ['type' => ['id' => 1, 'text' => 'TD'], 'id' => 'td'], true],
    'changed nested scalar' => [['type' => ['id' => 1]], ['type' => ['id' => 2]], false],
    'numeric string remains different' => [['score' => 1], ['score' => '1'], false],
    'floating point type remains different' => [['score' => 1], ['score' => 1.0], false],
    'boolean remains different' => [['score' => 0], ['score' => false], false],
    'list order remains different' => [['participants' => ['home', 'away']], ['participants' => ['away', 'home']], false],
    'missing key remains different' => [['clock' => null], [], false],
    'nested objects inside ordered list' => [['events' => [['a' => 1, 'b' => 2], ['id' => 3]]], ['events' => [['b' => 2, 'a' => 1], ['id' => 3]]], true],
]);
