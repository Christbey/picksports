<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class SyncUserRoles extends Command
{
    protected $signature = 'users:sync-roles';

    protected $description = 'Sync user roles based on their subscription tiers';

    public function handle(): int
    {
        $this->info('Syncing user roles from subscription tiers...');

        $users = User::all();
        $synced = 0;

        foreach ($users as $user) {
            if ($user->hasFoundingAccess()) {
                $this->line("  ↷ User {$user->id} ({$user->email}) is a founding user, skipping tier role sync");

                continue;
            }

            $tier = $user->subscriptionTier();

            if (! $tier) {
                $this->warn("User {$user->id} has no tier, skipping...");

                continue;
            }

            $user->syncRoleFromTier();

            $synced++;

            $this->line("  ✓ User {$user->id} ({$user->email}) synced to role '{$tier->slug}'");
        }

        $this->newLine();
        $this->info("✓ Synced {$synced} users successfully!");

        return Command::SUCCESS;
    }
}
