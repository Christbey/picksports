<?php

namespace App\Console\Commands\NFL;

use App\Console\Commands\Sports\Canonical\AbstractRegisterCalculationReleaseCommand;
use App\Models\CalculationRelease;
use App\Services\NFL\Predictions\NflCalculationReleaseRegistrar;
use App\Services\Predictions\CalculationReleaseManager;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class RegisterCalculationReleaseCommand extends AbstractRegisterCalculationReleaseCommand
{
    protected $signature = 'nfl:register-calculation-release
        {--release-version=1.1.0}
        {--draft}
        {--replace-active : Atomically retire active NFL pregame releases when approving this release}
        {--effective-at=}
        {--actor=artisan}
        {--reason=NFL canonical rules release registration.}';

    protected $description = 'Register the frozen canonical NFL pregame calculation release';

    protected function registrarClass(): string
    {
        return NflCalculationReleaseRegistrar::class;
    }

    protected function sportLabel(): string
    {
        return 'NFL';
    }

    public function handle(): int
    {
        if (! $this->option('replace-active')) {
            return parent::handle();
        }

        if ($this->option('draft')) {
            $this->error('--replace-active cannot be combined with --draft.');

            return self::FAILURE;
        }

        if (! filled($this->option('effective-at'))) {
            $this->error('--replace-active requires an explicit --effective-at timestamp.');

            return self::FAILURE;
        }

        $effectiveAt = CarbonImmutable::parse((string) $this->option('effective-at'));
        $registrar = app(NflCalculationReleaseRegistrar::class);
        $release = DB::transaction(function () use ($effectiveAt, $registrar): CalculationRelease {
            $release = $registrar->register(
                semanticVersion: (string) $this->option('release-version'),
                approve: false,
            );

            $activationAt = $release->status === 'draft'
                ? $effectiveAt
                : $release->effective_at?->toImmutable();
            if ($activationAt === null) {
                throw new \LogicException('An existing approved NFL release must have an effective timestamp.');
            }

            $active = CalculationRelease::query()
                ->where('sport', 'nfl')
                ->where('phase', 'pregame')
                ->where('status', 'approved')
                ->whereNotNull('effective_at')
                ->where('effective_at', '<=', $activationAt)
                ->whereNull('retired_at')
                ->whereKeyNot($release->getKey())
                ->lockForUpdate()
                ->get();
            foreach ($active as $existing) {
                app(CalculationReleaseManager::class)->retire($existing, $activationAt);
            }

            if ($release->status !== 'draft') {
                return $release;
            }

            return $registrar->register(
                semanticVersion: (string) $this->option('release-version'),
                approve: true,
                actor: (string) $this->option('actor'),
                reason: (string) $this->option('reason'),
                effectiveAt: $activationAt,
            );
        });

        $this->info("NFL calculation release {$release->semantic_version} is {$release->status}.");

        return self::SUCCESS;
    }
}
