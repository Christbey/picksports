<?php

namespace App\Console\Commands\WNBA;

use App\Services\WNBA\Predictions\WnbaCalculationReleaseDefinition;
use App\Services\WNBA\Predictions\WnbaCalculationReleaseRegistrar;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class RegisterCalculationReleaseCommand extends Command
{
    protected $signature = 'wnba:register-calculation-release
        {--release-version=1.0.0 : Semantic version for this immutable release}
        {--draft : Register without approving the release}
        {--effective-at= : ISO-8601 activation time; defaults to now}
        {--actor=artisan : Approval actor}
        {--reason=Initial canonical WNBA rules release. : Approval reason}';

    protected $description = 'Register an immutable WNBA calculation release for canonical prediction generation';

    public function handle(WnbaCalculationReleaseRegistrar $registrar): int
    {
        $effectiveAt = filled($this->option('effective-at'))
            ? CarbonImmutable::parse((string) $this->option('effective-at'))
            : null;
        $release = $registrar->register(
            semanticVersion: (string) ($this->option('release-version') ?: WnbaCalculationReleaseDefinition::SEMANTIC_VERSION),
            approve: ! $this->option('draft'),
            actor: (string) $this->option('actor'),
            reason: (string) $this->option('reason'),
            effectiveAt: $effectiveAt,
        );

        $this->table(['Field', 'Value'], [
            ['Public ID', $release->public_id],
            ['Sport / phase', "{$release->sport} / {$release->phase}"],
            ['Calculator', $release->calculator_name],
            ['Version', $release->semantic_version],
            ['Status', $release->status],
            ['Code revision', $release->code_revision],
            ['Configuration hash', $release->configuration_hash],
            ['Input schema', $release->input_schema_version],
            ['Effective at', $release->effective_at?->toIso8601String() ?? 'not active'],
        ]);

        return self::SUCCESS;
    }
}
