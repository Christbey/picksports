<?php

namespace App\Console\Commands\WCBB;

use App\Services\WCBB\Predictions\WcbbCalculationReleaseDefinition;
use App\Services\WCBB\Predictions\WcbbCalculationReleaseRegistrar;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class RegisterCalculationReleaseCommand extends Command
{
    protected $signature = 'wcbb:register-calculation-release
        {--release-version=1.0.0 : Semantic version for the release}
        {--draft : Register without approval}
        {--effective-at= : Approval effective timestamp}
        {--actor=artisan : Approval actor}
        {--reason=WCBB canonical rules release registration. : Approval reason}';

    protected $description = 'Register the frozen canonical WCBB pregame calculation release';

    public function handle(WcbbCalculationReleaseRegistrar $registrar): int
    {
        $release = $registrar->register(
            semanticVersion: (string) ($this->option('release-version') ?: WcbbCalculationReleaseDefinition::SEMANTIC_VERSION),
            approve: ! $this->option('draft'),
            actor: (string) $this->option('actor'),
            reason: (string) $this->option('reason'),
            effectiveAt: filled($this->option('effective-at'))
                ? CarbonImmutable::parse((string) $this->option('effective-at'))
                : null,
        );

        $this->info("WCBB calculation release {$release->semantic_version} is {$release->status}.");

        return self::SUCCESS;
    }
}
