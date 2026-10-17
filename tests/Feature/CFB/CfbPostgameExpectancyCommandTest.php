<?php

use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

it('runs postgame diagnostics with structured arguments and never overwrites a report', function () {
    $manifest = tempnam(sys_get_temp_dir(), 'cfb-manifest-');
    $artifact = base_path('tests/Fixtures/cfb-efficiency-parity.json');
    $output = sys_get_temp_dir().'/cfb-pgwe-'.Str::uuid().'.json';
    Process::fake(['*' => Process::result(output: '{"evaluated_games":1,"excluded_games":0}')]);
    try {
        $this->artisan('cfb:report-postgame-expectancy', ['--artifact' => $artifact, '--manifest' => $manifest, '--output' => $output])->assertSuccessful();
        Process::assertRan(fn ($process) => is_array($process->command) && in_array(base_path('ml/cfb/postgame.py'), $process->command, true)
            && in_array($output, $process->command, true));
        file_put_contents($output, 'original');
        $this->artisan('cfb:report-postgame-expectancy', ['--artifact' => $artifact, '--manifest' => $manifest, '--output' => $output])->assertFailed();
        expect(file_get_contents($output))->toBe('original');
    } finally {
        @unlink($manifest);
        @unlink($output);
    }
});
