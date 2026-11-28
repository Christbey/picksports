<?php

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

uses(TestCase::class);

it('refuses cached configuration before evaluating the cache file', function () {
    $directory = sys_get_temp_dir().'/picksports-bootstrap-safety-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    $marker = $directory.'/must-not-execute';
    $cache = $directory.'/config.php';
    file_put_contents($cache, '<?php file_put_contents('.var_export($marker, true).', "executed"); return [];');
    try {
        $probe = 'require "vendor/autoload.php"; (new class("probe") extends \\Tests\\TestCase {})->createApplication();';
        $process = new Process([PHP_BINARY, '-r', $probe], base_path(), ['APP_ENV' => 'testing', 'APP_CONFIG_CACHE' => $cache]);
        $process->setTimeout(15);
        $process->run();

        expect($process->isSuccessful())->toBeFalse()
            ->and($process->getErrorOutput().$process->getOutput())->toContain('Test database safety refuses cached application configuration')
            ->and(file_exists($marker))->toBeFalse();
    } finally {
        File::deleteDirectory($directory);
    }
});

it('rejects a remote DB URL during bootstrap before database traits can run', function () {
    $probe = 'require "vendor/autoload.php"; (new class("probe") extends \\Tests\\TestCase {})->createApplication();';
    $process = new Process([PHP_BINARY, '-r', $probe], base_path(), [
        'APP_ENV' => 'testing', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:',
        'DB_URL' => 'mysql://synthetic:do_not_print@unreachable.example.invalid/production',
    ]);
    $process->setTimeout(15);
    $process->run();

    expect($process->isSuccessful())->toBeFalse()
        ->and($process->getErrorOutput().$process->getOutput())->toContain('Test database safety permits only')
        ->and($process->getErrorOutput().$process->getOutput())->not->toContain('do_not_print', 'SQLSTATE');
});
