<?php

namespace App\Console\Commands;

use App\Services\AI\AiProviderRateLimitCircuitBreaker;
use Illuminate\Console\Command;

class AiStatusCommand extends Command
{
    protected $signature = 'ai:status
        {--json : Output machine-readable JSON}';

    protected $description = 'Show operational AI settings and provider status without exposing credentials';

    /**
     * @var list<string>
     */
    private const FEATURES = [
        'sports_prediction_narratives',
        'player_prop_narratives',
        'validation_review_summary',
        'daily_digest_summary',
        'daily_prediction_analysis',
        'nfl_game_context_research',
        'data_freshness_review',
        'market_readiness_review',
        'model_audit_review',
        'publishing_guardrail_review',
    ];

    public function handle(AiProviderRateLimitCircuitBreaker $circuitBreaker): int
    {
        $report = $this->report($circuitBreaker);

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->renderReport($report);

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private function report(AiProviderRateLimitCircuitBreaker $circuitBreaker): array
    {
        $features = collect(self::FEATURES)
            ->mapWithKeys(function (string $name): array {
                $config = (array) config("ai.features.{$name}", []);

                $status = [
                    'enabled' => array_key_exists('enabled', $config) ? (bool) $config['enabled'] : true,
                    'provider' => (string) ($config['provider'] ?? ''),
                    'model' => (string) ($config['model'] ?? ''),
                    'timeout_seconds' => isset($config['timeout_seconds']) ? (int) $config['timeout_seconds'] : null,
                ];

                if ($name === 'nfl_game_context_research') {
                    $status += [
                        'max_searches' => (int) ($config['max_searches'] ?? 0),
                        'freshness_minutes' => (int) ($config['freshness_minutes'] ?? 0),
                        'require_provider_citations' => (bool) ($config['require_provider_citations'] ?? false),
                    ];
                }

                if ($name === 'publishing_guardrail_review') {
                    $status['enforced'] = (bool) ($config['enforced'] ?? false);
                }

                return [$name => $status];
            })
            ->all();

        $providers = collect($features)
            ->pluck('provider')
            ->push((string) config('ai.default', ''))
            ->filter(fn (mixed $provider): bool => is_string($provider) && trim($provider) !== '')
            ->map(fn (string $provider): string => strtolower(trim($provider)))
            ->unique()
            ->sort()
            ->mapWithKeys(function (string $provider) use ($circuitBreaker): array {
                $retryAfter = $provider === 'template' ? 0 : $circuitBreaker->retryAfterSeconds($provider);

                return [$provider => [
                    'credential_required' => $provider !== 'template',
                    'credential_configured' => $provider === 'template'
                        ? null
                        : filled(config("ai.providers.{$provider}.key")),
                    'circuit_status' => $retryAfter > 0 ? 'cooldown' : 'ready',
                    'retry_after_seconds' => $retryAfter,
                ]];
            })
            ->all();

        return [
            'schema_version' => 'ai_status_v1',
            'generated_at' => now()->toIso8601String(),
            'default_provider' => (string) config('ai.default', ''),
            'rate_limits' => [
                'cooldown_seconds' => (int) config('ai.rate_limits.cooldown_seconds', 900),
                'quota_cooldown_seconds' => (int) config('ai.rate_limits.quota_cooldown_seconds', 86400),
            ],
            'providers' => $providers,
            'features' => $features,
        ];
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function renderReport(array $report): void
    {
        $this->info('AI Operational Status');
        $this->line('Default provider: '.$report['default_provider']);
        $this->line(sprintf(
            'Cooldowns: rate limit %ds | exhausted quota %ds',
            $report['rate_limits']['cooldown_seconds'],
            $report['rate_limits']['quota_cooldown_seconds'],
        ));

        $this->newLine();
        $this->table(
            ['Provider', 'Credential', 'Circuit', 'Retry after'],
            collect($report['providers'])->map(
                fn (array $status, string $provider): array => [
                    $provider,
                    $status['credential_required']
                        ? ($status['credential_configured'] ? 'configured' : 'missing')
                        : 'not required',
                    $status['circuit_status'],
                    $status['retry_after_seconds'].'s',
                ]
            )->values()->all(),
        );

        $this->table(
            ['Feature', 'Enabled', 'Provider', 'Model', 'Timeout'],
            collect($report['features'])->map(
                fn (array $status, string $feature): array => [
                    $feature,
                    $status['enabled'] ? 'yes' : 'no',
                    $status['provider'],
                    $status['model'],
                    $status['timeout_seconds'] !== null ? $status['timeout_seconds'].'s' : '-',
                ]
            )->values()->all(),
        );
    }
}
