<?php

namespace App\Console\Commands\NBA;

use App\Services\NBA\Predictions\NbaCalculationReleaseDefinition;
use App\Services\NBA\Predictions\NbaCalculationReleaseRegistrar;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class RegisterCalculationReleaseCommand extends Command
{
    protected $signature = 'nba:register-calculation-release
        {--release-version=1.0.0 : Semantic version for the release}
        {--draft : Register without approval}
        {--effective-at= : Approval effective timestamp}
        {--actor=artisan : Approval actor}
        {--reason=NBA canonical rules release registration. : Approval reason}';

    protected $description = 'Register the frozen canonical NBA pregame calculation release';

    public function handle(NbaCalculationReleaseRegistrar $registrar): int
    {
        $release = $registrar->register(
            semanticVersion: (string) ($this->option('release-version') ?: NbaCalculationReleaseDefinition::SEMANTIC_VERSION),
            approve: ! $this->option('draft'),
            actor: (string) $this->option('actor'),
            reason: (string) $this->option('reason'),
            effectiveAt: filled($this->option('effective-at'))
                ? CarbonImmutable::parse((string) $this->option('effective-at'))
                : null,
        );

        $this->info("NBA calculation release {$release->semantic_version} is {$release->status}.");

        return self::SUCCESS;
    }
}
