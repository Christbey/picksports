<?php

use App\Console\Commands\WebPushGenerateKeysCommand;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\TestCase;

uses(TestCase::class);

it('refuses a VAPID subject that can inject an environment assignment', function () {
    $command = new WebPushGenerateKeysCommand;
    $command->setLaravel($this->app);
    $tester = new CommandTester($command);
    // No --write: before-proof never touches an environment file or sends a push.
    $status = $tester->execute(['--subject' => "mailto:support@example.com\nUNRELATED_SETTING=injected"]);
    expect($status)->toBe(1);
});

function isolatedVapidCommand(array $options, ?Closure $setup = null, ?WebPushGenerateKeysCommand $command = null): array
{
    $app = app();
    $previousPath = $app->environmentPath();
    $previousFile = $app->environmentFile();
    $directory = sys_get_temp_dir().'/picksports-vapid-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    $app->useEnvironmentPath($directory)->loadEnvironmentFrom('.env');
    try {
        $setup?->__invoke($directory);
        $command ??= new WebPushGenerateKeysCommand;
        $command->setLaravel($app);
        $tester = new CommandTester($command);
        $status = $tester->execute($options);
        clearstatcache();

        return ['status' => $status, 'output' => $tester->getDisplay(),
            'contents' => is_file($directory.'/.env') ? file_get_contents($directory.'/.env') : null,
            'mode' => is_file($directory.'/.env') ? fileperms($directory.'/.env') & 0777 : null,
            'target' => is_file($directory.'/target.env') ? file_get_contents($directory.'/target.env') : null,
            'temporary_files' => count(glob($directory.'/.vapid-*'))];
    } finally {
        $app->useEnvironmentPath($previousPath)->loadEnvironmentFrom($previousFile);
        foreach (new DirectoryIterator($directory) as $entry) {
            if (! $entry->isDot()) {
                if ($entry->isDir() && ! $entry->isLink()) {
                    rmdir($entry->getPathname());
                } else {
                    unlink($entry->getPathname());
                }
            }
        }
        rmdir($directory);
    }
}

it('rejects unsafe subjects without displaying generated key material', function (string $subject) {
    $result = isolatedVapidCommand(['--subject' => $subject]);
    expect($result['status'])->toBe(1)
        ->and(str_contains($result['output'], 'PRIVATE KEY'))->toBeFalse()
        ->and($result['contents'])->toBeNull();
})->with(['https://example.com/path"', "mailto:a@example.com\rAPP_DEBUG=true", 'mailto:${SECRET}@example.com',
    'http://example.com', 'https://user:password@example.com', 'invalid', 'mailto:']);

it('writes a private complete dotenv keypair and preserves unrelated values without printing the private key', function (string $subject) {
    $result = isolatedVapidCommand(['--write' => true, '--subject' => $subject], function ($directory) {
        file_put_contents($directory.'/.env', "UNRELATED=retained\nWEB_PUSH_VAPID_PUBLIC_KEY=\nWEB_PUSH_VAPID_PRIVATE_KEY=\n");
    });
    $parsed = Dotenv\Dotenv::parse($result['contents']);
    $private = openssl_pkey_get_private($parsed['WEB_PUSH_VAPID_PRIVATE_KEY']);
    $details = openssl_pkey_get_details($private);
    $public = rtrim(strtr(base64_encode("\x04".$details['ec']['x'].$details['ec']['y']), '+/', '-_'), '=');
    expect($result['status'])->toBe(0)->and($result['mode'])->toBe(0600)
        ->and($parsed['UNRELATED'])->toBe('retained')->and($parsed['WEB_PUSH_VAPID_SUBJECT'])->toBe($subject)
        ->and($parsed['WEB_PUSH_VAPID_PUBLIC_KEY'] === $public)->toBeTrue()
        ->and(str_contains($result['output'], 'PRIVATE KEY'))->toBeFalse()
        ->and($result['temporary_files'])->toBe(0);
})->with(['mailto:support@example.com', 'https://example.com/push?source=operator&env=test']);

it('refuses replacement of either existing key unless rotation is explicit', function (string $existing) {
    $result = isolatedVapidCommand(['--write' => true], fn ($directory) => file_put_contents($directory.'/.env', $existing));
    expect($result['status'])->toBe(1)->and($result['contents'])->toBe($existing)
        ->and(str_contains($result['output'], 'PRIVATE KEY'))->toBeFalse();
})->with(["WEB_PUSH_VAPID_PUBLIC_KEY=old-public\n", "WEB_PUSH_VAPID_PUBLIC_KEY=\nWEB_PUSH_VAPID_PRIVATE_KEY=old-private\n",
    "export WEB_PUSH_VAPID_PRIVATE_KEY = 'old-private'\n"]);

it('performs an explicit rotation while retaining unrelated environment values', function () {
    $result = isolatedVapidCommand(['--write' => true, '--rotate' => true], fn ($directory) => file_put_contents($directory.'/.env', "UNRELATED=retained\nWEB_PUSH_VAPID_PRIVATE_KEY=old-private\n"));
    $parsed = Dotenv\Dotenv::parse($result['contents']);
    expect($result['status'])->toBe(0)->and($parsed['UNRELATED'])->toBe('retained')
        ->and($parsed['WEB_PUSH_VAPID_PRIVATE_KEY'] !== 'old-private')->toBeTrue();
});

it('refuses a rotation flag without persistence', function () {
    $result = isolatedVapidCommand(['--rotate' => true]);
    expect($result['status'])->toBe(1)->and($result['contents'])->toBeNull();
});

it('refuses symlink environment targets and leaves their contents unchanged', function () {
    $result = isolatedVapidCommand(['--write' => true, '--rotate' => true], function ($directory) {
        file_put_contents($directory.'/target.env', 'UNRELATED=retained');
        symlink($directory.'/target.env', $directory.'/.env');
    });
    expect($result['status'])->toBe(1)->and($result['target'])->toBe('UNRELATED=retained');
});

it('reports a persistence failure without success or private-key output', function () {
    $command = new class extends WebPushGenerateKeysCommand
    {
        protected function persistEnvironment(string $envPath, string $contents): void
        {
            throw new RuntimeException('Synthetic persistence failure');
        }
    };
    $result = isolatedVapidCommand(['--write' => true], fn ($directory) => file_put_contents($directory.'/.env', 'UNRELATED=retained'), $command);
    expect($result['status'])->toBe(1)->and($result['contents'])->toBe('UNRELATED=retained')
        ->and(str_contains($result['output'], 'Saved WEB_PUSH'))->toBeFalse()
        ->and(str_contains($result['output'], 'PRIVATE KEY'))->toBeFalse();
});
