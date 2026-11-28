<?php

it('does not report success when an explicit stored game scope has no matching final games', function () {
    $this->artisan('cfb:audit-game-data', ['--game' => '999999999'])
        ->expectsOutputToContain('"status": "no_data"')
        ->assertFailed();
});
