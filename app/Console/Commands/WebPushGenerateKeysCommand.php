<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use RuntimeException;

class WebPushGenerateKeysCommand extends Command
{
    protected $signature = 'webpush:generate-keys
                            {--subject= : Optional VAPID subject (e.g. mailto:support@example.com)}
                            {--write : Persist generated values into the application environment file}
                            {--rotate : Explicitly replace an existing VAPID keypair with --write}';

    protected $description = 'Generate VAPID keys for Web Push notifications';

    public function handle(): int
    {
        $subject = (string) ($this->option('subject') ?: 'mailto:support@example.com');
        if (! $this->validSubject($subject) || ($this->option('rotate') && ! $this->option('write'))) {
            $this->error('Use a valid mailto or HTTPS subject; --rotate requires --write.');

            return self::FAILURE;
        }

        try {
            $privatePem = $this->generatePrivateKeyPem();
            $publicKey = $this->publicKeyFromPrivatePem($privatePem);
        } catch (\Throwable $e) {
            $this->error("Failed to generate VAPID keys: {$e->getMessage()}");

            return self::FAILURE;
        }

        $privateInline = str_replace("\n", '\n', trim($privatePem));

        $pairs = [
            'WEB_PUSH_VAPID_SUBJECT' => '"'.$subject.'"',
            'WEB_PUSH_VAPID_PUBLIC_KEY' => $publicKey,
            'WEB_PUSH_VAPID_PRIVATE_KEY' => "\"{$privateInline}\"",
        ];

        if ((bool) $this->option('write')) {
            try {
                $this->writeToEnv($pairs);
            } catch (\Throwable) {
                $this->error('VAPID environment write refused or failed. Existing keys require --write --rotate; check the environment file and directory permissions.');

                return self::FAILURE;
            }
            $this->info('Saved WEB_PUSH_VAPID_* values to the application environment file.');
            $this->comment('Reload application configuration through the deployment procedure.');
        } else {
            $this->newLine();
            foreach ($pairs as $key => $value) {
                $this->line("{$key}={$value}");
            }
            $this->newLine();
            $this->comment('Use --write to persist values into .env automatically.');
        }

        $this->comment('Keep WEB_PUSH_VAPID_PRIVATE_KEY secret.');

        return self::SUCCESS;
    }

    private function validSubject(string $subject): bool
    {
        if (strlen($subject) > 2048 || preg_match('/[\x00-\x20\x7f]/', $subject)
            || str_contains($subject, '"') || str_contains($subject, "'")
            || str_contains($subject, '\\') || str_contains($subject, '$')) {
            return false;
        }
        if (str_starts_with($subject, 'mailto:')) {
            return filter_var(substr($subject, 7), FILTER_VALIDATE_EMAIL) !== false;
        }
        $url = parse_url($subject);

        return filter_var($subject, FILTER_VALIDATE_URL) !== false && is_array($url)
            && ($url['scheme'] ?? null) === 'https' && isset($url['host'])
            && ! isset($url['user']) && ! isset($url['pass']);
    }

    private function generatePrivateKeyPem(): string
    {
        $pem = $this->generatePrivateKeyViaPhpOpenSsl();
        if ($pem !== null) {
            return $pem;
        }

        $pem = $this->generatePrivateKeyViaSystemOpenSsl();
        if ($pem !== null) {
            return $pem;
        }

        throw new RuntimeException('Unable to generate prime256v1 key via PHP or system openssl.');
    }

    private function generatePrivateKeyViaPhpOpenSsl(): ?string
    {
        $key = @openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ]);

        if (! $key) {
            return null;
        }

        if (! openssl_pkey_export($key, $privatePem) || ! is_string($privatePem) || $privatePem === '') {
            return null;
        }

        return $privatePem;
    }

    private function generatePrivateKeyViaSystemOpenSsl(): ?string
    {
        $binary = trim((string) @shell_exec('command -v openssl'));
        if ($binary === '') {
            return null;
        }

        $pem = @shell_exec('openssl ecparam -name prime256v1 -genkey -noout 2>/dev/null');
        if (! is_string($pem) || trim($pem) === '') {
            return null;
        }

        return $pem;
    }

    private function publicKeyFromPrivatePem(string $privatePem): string
    {
        $key = openssl_pkey_get_private($privatePem);
        if (! $key) {
            throw new RuntimeException('Generated private key is invalid.');
        }

        $details = openssl_pkey_get_details($key);
        if (! is_array($details) || ! isset($details['ec']['x'], $details['ec']['y'])) {
            throw new RuntimeException('Unable to derive VAPID public key.');
        }

        $raw = "\x04".$details['ec']['x'].$details['ec']['y'];

        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    /**
     * @param  array<string, string>  $pairs
     */
    private function writeToEnv(array $pairs): void
    {
        $envPath = $this->laravel->environmentFilePath();
        if (is_link($envPath) || (file_exists($envPath) && ! is_file($envPath))) {
            throw new RuntimeException('Invalid environment file.');
        }
        $contents = file_exists($envPath) ? file_get_contents($envPath) : '';
        if ($contents === false || strlen($contents) > 1048576) {
            throw new RuntimeException('Environment file is unreadable or oversized.');
        }
        preg_match_all('/^\h*(?:export\h+)?WEB_PUSH_VAPID_(?:PUBLIC|PRIVATE)_KEY\h*=\h*([^\r\n]*)/m', $contents, $matches);
        if (! $this->option('rotate') && array_any($matches[1], fn ($value) => trim($value, " \t\"'") !== '')) {
            throw new RuntimeException('Explicit key rotation is required.');
        }

        foreach ($pairs as $key => $value) {
            $line = "{$key}={$value}";
            $pattern = '/^\h*(?:export\h+)?'.preg_quote($key, '/').'\h*=[^\r\n]*/m';

            if (preg_match($pattern, $contents) === 1) {
                $contents = preg_replace_callback($pattern, fn () => $line, $contents);
            } else {
                $contents .= ($contents === '' || str_ends_with($contents, "\n") ? '' : "\n").$line."\n";
            }
        }

        $this->persistEnvironment($envPath, $contents);
    }

    protected function persistEnvironment(string $envPath, string $contents): void
    {
        $directory = realpath(dirname($envPath));
        if ($directory === false || ! is_writable($directory)) {
            throw new RuntimeException('Environment directory is not writable.');
        }
        $temporary = tempnam($directory, '.vapid-');
        if ($temporary === false) {
            throw new RuntimeException('Unable to create environment replacement.');
        }
        try {
            if (dirname($temporary) !== $directory || ! chmod($temporary, 0600)
                || file_put_contents($temporary, $contents, LOCK_EX) !== strlen($contents)
                || ! rename($temporary, $envPath)) {
                throw new RuntimeException('Unable to persist complete environment replacement.');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }
}
