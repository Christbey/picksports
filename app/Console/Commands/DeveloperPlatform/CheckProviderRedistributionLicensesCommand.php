<?php

namespace App\Console\Commands\DeveloperPlatform;

use Illuminate\Console\Command;

class CheckProviderRedistributionLicensesCommand extends Command
{
    /** @var array<int, string> */
    private const STATUSES = ['confirmed', 'unconfirmed', 'not_required', 'restricted'];

    protected $signature = 'developer-platform:check-redistribution-licenses {--json : Emit machine-readable inventory results}';

    protected $description = 'Check configured provider redistribution review statuses for public API readiness.';

    public function handle(): int
    {
        $configured = config('provider-redistribution.providers', []);

        if (! is_array($configured) || $configured === []) {
            $this->error('Provider redistribution inventory is missing or empty.');

            return self::FAILURE;
        }

        $results = [];
        $blocking = [];

        foreach ($configured as $code => $provider) {
            if (! is_string($code) || ! is_array($provider)) {
                $blocking[] = (string) $code;
                $results[] = ['code' => (string) $code, 'ready' => false, 'reason' => 'invalid_configuration'];

                continue;
            }

            $status = strtolower(trim((string) ($provider['status'] ?? 'unconfirmed')));
            $required = (bool) ($provider['required_for_public_api'] ?? false);
            $valid = in_array($status, self::STATUSES, true);
            $ready = $valid && (! $required || $status === 'confirmed');
            $reason = ! $valid ? 'invalid_status' : (($required && $status !== 'confirmed') ? 'required_status_not_confirmed' : null);

            if (! $ready) {
                $blocking[] = $code;
            }

            $results[] = [
                'code' => $code,
                'label' => (string) ($provider['label'] ?? $code),
                'required_for_public_api' => $required,
                'status' => $status,
                'ready' => $ready,
                'reason' => $reason,
                'evidence_reference' => $provider['evidence_reference'] ?? null,
                'reviewed_at' => $provider['reviewed_at'] ?? null,
                'owner' => $provider['owner'] ?? null,
                'notes' => $provider['notes'] ?? null,
            ];
        }

        if ((bool) $this->option('json')) {
            $this->line(json_encode([
                'ready' => $blocking === [],
                'disclaimer' => 'Configuration readiness only; this check does not make a legal determination.',
                'blocking_providers' => $blocking,
                'providers' => $results,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } else {
            $this->line('This is an operational inventory check, not a legal determination.');
            $this->table(
                ['Provider', 'Required', 'Configured status', 'Ready'],
                array_map(fn (array $result): array => [
                    $result['code'],
                    ($result['required_for_public_api'] ?? false) ? 'yes' : 'no',
                    $result['status'] ?? 'invalid',
                    ($result['ready'] ?? false) ? 'yes' : 'no',
                ], $results),
            );

            if ($blocking !== []) {
                $this->error('Public API readiness is blocked by unconfirmed or invalid required-provider review status: '.implode(', ', $blocking));
            } else {
                $this->info('All required provider review statuses are configured as confirmed.');
            }
        }

        return $blocking === [] ? self::SUCCESS : self::FAILURE;
    }
}
