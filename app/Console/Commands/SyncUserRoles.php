<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Billing\BillingRoleEntitlements;
use Illuminate\Console\Command;

class SyncUserRoles extends Command
{
    protected $signature = 'users:sync-roles';

    protected $description = 'Sync user roles based on their subscription tiers';

    public function handle(): int
    {
        $this->info('Syncing user roles from subscription tiers...');

        $users = User::query()->with('subscriptions')->lazyById(250);
        $synced = 0;
        $managed = 0;

        foreach ($users as $user) {
            if (app(BillingRoleEntitlements::class)->hasRecorded($user)) {
                $managed++;
                $this->line("  ↷ User {$user->id} has recorded entitlement provenance; use admitted billing or manual reconciliation.");

                continue;
            }

            if ($user->hasFoundingAccess()) {
                $this->line("  ↷ User {$user->id} ({$user->email}) is a founding user, skipping tier role sync");

                continue;
            }

            $tier = $user->subscriptionTier();

            if (! $tier) {
                $this->warn("User {$user->id} has no tier, skipping...");

                continue;
            }

            $user->syncRoleFromTier($tier);

            $synced++;

            $this->line("  ✓ User {$user->id} ({$user->email}) synced to role '{$tier->slug}'");
        }

        $this->newLine();
        $this->info("✓ Synced {$synced} users successfully!");

        if ($managed > 0) {
            $this->warn("Skipped {$managed} users with recorded entitlement provenance; their grants were preserved.");
        }

        return Command::SUCCESS;
    }
}
