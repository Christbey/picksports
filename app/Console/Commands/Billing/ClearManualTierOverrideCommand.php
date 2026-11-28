<?php

namespace App\Console\Commands\Billing;

use App\Models\User;
use App\Services\Billing\BillingRoleEntitlements;
use Illuminate\Console\Command;
use Throwable;

class ClearManualTierOverrideCommand extends Command
{
    protected $signature = 'billing:clear-manual-tier-override {--user=* : Exact unique User IDs, maximum100} {--reason= : Explicit operator reason, maximum256 characters} {--apply : Apply the preflighted local changes}';

    protected $description = 'Inspect or explicitly remove recorded manual tier overrides without provider work';

    public function handle(BillingRoleEntitlements $entitlements): int
    {
        $ids = $this->option('user');
        $reason = $this->option('reason');
        if (count($ids) < 1 || count($ids) > 100 || count(array_unique($ids)) !== count($ids)
            || collect($ids)->contains(fn ($id) => ! is_string($id) || preg_match('/\A[1-9][0-9]{0,17}\z/', $id) !== 1)
            || ! is_string($reason) || trim($reason) === '' || mb_strlen($reason) > 256 || preg_match('/[\x00-\x1f\x7f]/', $reason)) {
            $this->error('Require1–100 exact unique User IDs and a bounded explicit reason.');

            return self::INVALID;
        }
        $users = User::query()->whereIn('id', $ids)->orderBy('id')->get();
        if ($users->count() !== count($ids)) {
            $this->error('Every requested User must exist; nothing applied.');

            return self::INVALID;
        }
        try {
            foreach ($users as $user) {
                if ($entitlements->manualOverrideTier($user) === null) {
                    $this->error('Every requested User must have an explicit unambiguous manual override; nothing applied.');

                    return self::INVALID;
                }
            }
        } catch (Throwable) {
            $this->error('Manual override preflight refused; nothing applied.');

            return self::FAILURE;
        }
        if (! $this->option('apply')) {
            $this->info('Dry run: '.$users->count().' Users eligible; no grants or provider state changed.');

            return self::SUCCESS;
        }
        $applied = $changed = $failed = 0;
        foreach ($users as $user) {
            try {
                $count = $entitlements->clearManualOverride($user, $reason);
                $applied++;
                $changed += $count > 0 ? 1 : 0;
            } catch (Throwable) {
                $failed++;
            }
        }
        $this->info("Requested={$users->count()} applied={$applied} changed={$changed} failed={$failed}. No provider or reconciliation was invoked.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
