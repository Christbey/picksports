<?php

namespace App\Console\Commands;

use App\Models\SubscriptionTier;
use Illuminate\Console\Command;

class SyncTiersFromConfig extends Command
{
    protected $signature = 'tiers:sync';

    protected $description = 'Sync subscription tiers from config to database';

    public function handle(): int
    {
        $this->info('Syncing subscription tiers from config...');

        $configTiers = config('subscriptions.tiers');
        $sortOrder = 0;

        foreach ($configTiers as $slug => $tierData) {
            $this->info("Processing tier: {$tierData['name']}");

            SubscriptionTier::updateOrCreate(
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

            $this->line("  ✓ {$tierData['name']} synced");
        }

        $this->newLine();
        $this->info('✓ All tiers synced successfully!');

        return Command::SUCCESS;
    }
}
