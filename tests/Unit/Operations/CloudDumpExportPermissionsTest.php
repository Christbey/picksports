<?php

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->exportRoot = sys_get_temp_dir().'/picksports-dump-permissions-'.bin2hex(random_bytes(8));
    mkdir($this->exportRoot, 0700);
    mkdir($this->exportRoot.'/bin', 0700);
    file_put_contents($this->exportRoot.'/bin/mysqldump', "#!/bin/sh\nprintf '%s\\n' '-- synthetic dump only'\n");
    chmod($this->exportRoot.'/bin/mysqldump', 0700);

    $this->exportDump = function (string $output, array $environment = []): Process {
        $process = new Process([
            PHP_BINARY,
            '-r',
            'umask(0000); array_shift($argv); require $argv[0];',
            base_path('scripts/export-laravel-cloud-dump.php'),
            $output,
        ], base_path(), array_replace([
            'APP_ENV' => 'testing',
            'APP_DEBUG' => 'false',
            'APP_CONFIG_CACHE' => $this->exportRoot.'/no-config-cache.php',
            'DB_CONNECTION' => 'mysql',
            'DB_URL' => '',
            'DB_HOST' => '127.0.0.1',
            'DB_PORT' => '1',
            'DB_DATABASE' => 'synthetic_dump',
            'DB_USERNAME' => 'synthetic_user',
            'DB_PASSWORD' => '',
            'PATH' => $this->exportRoot.'/bin:/usr/bin:/bin',
        ], $environment));
        $process->setTimeout(15);
        $process->run();

        return $process;
    };
});

afterEach(function () {
    File::deleteDirectory($this->exportRoot);
});

it('creates private dump directories and files under a permissive caller umask', function () {
    $directory = $this->exportRoot.'/new/private';
    $output = $directory.'/synthetic.sql.gz';

    $process = ($this->exportDump)($output);

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput())
        ->and(fileperms(dirname($directory)) & 0777)->toBe(0700)
        ->and(fileperms($directory) & 0777)->toBe(0700)
        ->and(fileperms($output) & 0777)->toBe(0600)
        ->and(gzdecode(file_get_contents($output)))->toBe("-- synthetic dump only\n");
});

it('refuses an existing shared directory without changing its permissions', function () {
    $directory = $this->exportRoot.'/shared';
    mkdir($directory, 0700);
    chmod($directory, 0755);
    $output = $directory.'/synthetic.sql.gz';

    $process = ($this->exportDump)($output);

    expect($process->getExitCode())->toBe(2)
        ->and($process->getErrorOutput())->toContain('directory accessible by other users')
        ->and(file_exists($output))->toBeFalse()
        ->and(fileperms($directory) & 0777)->toBe(0755);
});

it('retains an existing dump unchanged rather than overwriting it', function () {
    $output = $this->exportRoot.'/existing.sql.gz';
    file_put_contents($output, 'existing synthetic evidence');
    chmod($output, 0600);

    $process = ($this->exportDump)($output);

    expect($process->getExitCode())->toBe(2)
        ->and($process->getErrorOutput())->toContain('Refusing to overwrite existing file')
        ->and(file_get_contents($output))->toBe('existing synthetic evidence')
        ->and(fileperms($output) & 0777)->toBe(0600);
});

it('passes URL transport and credentials to the fake client without secret command arguments', function () {
    $capture = $this->exportRoot.'/client.json';
    $fake = $this->exportRoot.'/fake-client.php';
    file_put_contents($fake, '<?php file_put_contents('.var_export($capture, true).', json_encode(["argv" => $argv, "password" => getenv("MYSQL_PWD")])); echo "-- synthetic dump only\n";');
    file_put_contents($this->exportRoot.'/bin/mysqldump', "#!/bin/sh\nexec ".escapeshellarg(PHP_BINARY).' '.escapeshellarg($fake).' "$@"'."\n");
    $output = $this->exportRoot.'/url.sql.gz';

    $process = ($this->exportDump)($output, ['DB_URL' => 'mysql://url_user:synthetic%40password@db.example.invalid:3311/url_database']);
    $observed = json_decode(file_get_contents($capture), true, flags: JSON_THROW_ON_ERROR);

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput())
        ->and($observed['argv'])->toContain('--host=db.example.invalid', '--port=3311', '--user=url_user', '--ssl-mode=VERIFY_IDENTITY', 'url_database')
        ->and(implode(' ', $observed['argv']))->not->toContain('synthetic@password')
        ->and($observed['password'])->toBe('synthetic@password')
        ->and($process->getOutput().$process->getErrorOutput())->not->toContain('synthetic@password');
});

it('refuses unsupported import transport before opening a database connection', function () {
    $input = $this->exportRoot.'/synthetic.sql';
    file_put_contents($input, '-- synthetic input only');
    $process = new Process([PHP_BINARY, base_path('scripts/import-mysql-dump.php'), $input, 'synthetic_target'], base_path(), [
        'APP_ENV' => 'testing', 'APP_CONFIG_CACHE' => $this->exportRoot.'/no-config-cache.php',
        'DB_CONNECTION' => 'mysql', 'DB_URL' => 'mysql://synthetic:synthetic_secret@unreachable.example.invalid/database?sslmode=disabled',
    ]);
    $process->setTimeout(15);
    $process->run();

    expect($process->getExitCode())->toBe(2)
        ->and($process->getErrorOutput())->toContain('unsupported native-tool settings')
        ->and($process->getErrorOutput())->not->toContain('synthetic_secret', 'SQLSTATE');
});
