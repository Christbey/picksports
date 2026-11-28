<?php

namespace App\Console\Commands\CBB;

use App\Services\CBB\Predictions\CbbCalculationReleaseDefinition;
use App\Services\CBB\Predictions\CbbCalculationReleaseRegistrar;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class RegisterCalculationReleaseCommand extends Command
{
    protected $signature = 'cbb:register-calculation-release
        {--release-version=1.0.0 : Semantic version for the release}
        {--neutral-aware-draft : Register fixed 1.1.0 neutral-aware candidate without activation}
        {--draft : Register without approval}
        {--effective-at= : Approval effective timestamp}
        {--actor=artisan : Approval actor}
        {--reason=CBB canonical rules release registration. : Approval reason}';

    protected $description = 'Register the frozen canonical CBB pregame calculation release';

    public function handle(CbbCalculationReleaseRegistrar $registrar): int
    {
        if ($this->option('neutral-aware-draft')) {
            if ($this->input->hasParameterOption('--effective-at')
                || ($this->input->hasParameterOption('--release-version') && $this->option('release-version') !== '1.1.0')) {
                $this->error('Neutral-aware draft uses fixed version 1.1.0 and cannot have an effective-at timestamp.');

                return self::INVALID;
            }
            $release = $registrar->registerNeutralAwareDraft((string) $this->option('actor'), (string) $this->option('reason'));
            $this->info("Neutral-aware calculation release {$release->semantic_version} is {$release->status}; no activation performed.");

            return self::SUCCESS;
        }
        $release = $registrar->register(
            semanticVersion: (string) ($this->option('release-version') ?: CbbCalculationReleaseDefinition::SEMANTIC_VERSION),
            approve: ! $this->option('draft'),
            actor: (string) $this->option('actor'),
            reason: (string) $this->option('reason'),
            effectiveAt: filled($this->option('effective-at'))
                ? CarbonImmutable::parse((string) $this->option('effective-at'))
                : null,
        );

        $this->info("CBB calculation release {$release->semantic_version} is {$release->status}.");

        return self::SUCCESS;
    }
}
