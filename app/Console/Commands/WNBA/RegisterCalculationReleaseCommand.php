<?php

namespace App\Console\Commands\WNBA;

use App\Console\Commands\Sports\Canonical\AbstractRegisterCalculationReleaseCommand;
use App\Models\CalculationRelease;
use App\Services\WNBA\Predictions\WnbaCalculationReleaseDefinition;
use App\Services\WNBA\Predictions\WnbaCalculationReleaseRegistrar;

class RegisterCalculationReleaseCommand extends AbstractRegisterCalculationReleaseCommand
{
    protected $signature = 'wnba:register-calculation-release
        {--release-version=1.0.0 : Semantic version for this immutable release}
        {--draft : Register without approving the release}
        {--effective-at= : ISO-8601 activation time; defaults to now}
        {--actor=artisan : Approval actor}
        {--reason=Initial canonical WNBA rules release. : Approval reason}';

    protected $description = 'Register an immutable WNBA calculation release for canonical prediction generation';

    protected function registrarClass(): string
    {
        return WnbaCalculationReleaseRegistrar::class;
    }

    protected function sportLabel(): string
    {
        return 'WNBA';
    }

    protected function releaseVersion(): string
    {
        return (string) ($this->option('release-version') ?: WnbaCalculationReleaseDefinition::SEMANTIC_VERSION);
    }

    protected function displayRelease(CalculationRelease $release): void
    {
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
    }
}
