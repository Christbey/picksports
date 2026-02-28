<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use RuntimeException;

class WebPushGenerateKeysCommand extends Command
{
    protected $signature = 'webpush:generate-keys
                            {--subject= : Optional VAPID subject (e.g. mailto:support@example.com)}
                            {--write : Persist generated values into .env}';

    protected $description = 'Generate VAPID keys for Web Push notifications';

    public function handle(): int
    {
        try {
            $privatePem = $this->generatePrivateKeyPem();
            $publicKey = $this->publicKeyFromPrivatePem($privatePem);
        } catch (\Throwable $e) {
            $this->error("Failed to generate VAPID keys: {$e->getMessage()}");

            return self::FAILURE;
        }

        $subject = (string) ($this->option('subject') ?: 'mailto:support@example.com');
        $privateInline = str_replace("\n", '\n', trim($privatePem));

        $pairs = [
            'WEB_PUSH_VAPID_SUBJECT' => $subject,
            'WEB_PUSH_VAPID_PUBLIC_KEY' => $publicKey,
            'WEB_PUSH_VAPID_PRIVATE_KEY' => "\"{$privateInline}\"",
        ];

        $this->newLine();
        foreach ($pairs as $key => $value) {
            $this->line("{$key}={$value}");
        }
        $this->newLine();

        if ((bool) $this->option('write')) {
            $this->writeToEnv($pairs);
            $this->info('Saved WEB_PUSH_VAPID_* values to .env');
        } else {
            $this->comment('Use --write to persist values into .env automatically.');
        }

        $this->comment('Keep WEB_PUSH_VAPID_PRIVATE_KEY secret.');

        return self::SUCCESS;
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
     * @param array<string, string> $pairs
     */
    private function writeToEnv(array $pairs): void
    {
        $envPath = base_path('.env');
        $contents = file_exists($envPath) ? (string) file_get_contents($envPath) : '';

        foreach ($pairs as $key => $value) {
            $line = "{$key}={$value}";
            $pattern = "/^".preg_quote($key, '/')."=.*/m";

            if (preg_match($pattern, $contents) === 1) {
                $contents = (string) preg_replace($pattern, $line, $contents);
            } else {
                $contents .= ($contents === '' || str_ends_with($contents, "\n") ? '' : "\n").$line."\n";
            }
        }

        file_put_contents($envPath, $contents);
    }
}
