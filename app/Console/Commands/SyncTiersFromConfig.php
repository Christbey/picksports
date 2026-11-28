<?php

namespace App\Console\Commands;

use App\Models\SubscriptionTier;
use App\Services\Admin\TierPermissionSyncService;
use Illuminate\Console\Command;

class SyncTiersFromConfig extends Command
{
    protected $signature = 'tiers:sync';

    protected $description = 'Sync subscription tiers from config to database';

    public function __construct(private readonly TierPermissionSyncService $tierPermissionSyncService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->info('Syncing subscription tiers from config...');

        $configTiers = config('subscriptions.tiers');
        if (! is_array($configTiers)) {
            $this->error('Subscription tier configuration must be an array.');

            return Command::FAILURE;
        }

        $syncedNames = (new SubscriptionTier)->getConnection()->transaction(function () use ($configTiers): array {
            $names = [];
            $sortOrder = 0;

            foreach ($configTiers as $slug => $tierData) {
                $tier = SubscriptionTier::updateOrCreate(
                    ['slug' => $slug],
                    [
                        'name' => $tierData['name'],
                        'description' => $tierData['description'],
                        'price_monthly' => $tierData['price']['monthly'],
                        'price_yearly' => $tierData['price']['yearly'],
                        'stripe_price_id_monthly' => $tierData['stripe_price_id']['monthly'],
                        'stripe_price_id_yearly' => $tierData['stripe_price_id']['yearly'],
                        'features' => $tierData['features'],
                        'permissions' => $tierData['permissions'],
                        'data_permissions' => $tierData['data_permissions'] ?? null,
                        'predictions_limit' => $tierData['predictions_limit'] ?? null,
                        'is_default' => $slug === config('subscriptions.default_tier'),
                        'is_active' => true,
                        'sort_order' => $sortOrder++,
                    ]
                );
                $this->tierPermissionSyncService->syncTierRolePermissions($tier);

                $names[] = $tierData['name'];
            }

            return $names;
        });

        foreach ($syncedNames as $name) {
            $this->line("  ✓ {$name} synced");
        }

        $this->newLine();
        $this->info('✓ All tiers synced successfully!');

        return Command::SUCCESS;
    }
}
