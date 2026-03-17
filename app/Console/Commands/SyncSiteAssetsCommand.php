<?php

namespace App\Console\Commands;

use App\Services\SiteAssetStorage;
use Illuminate\Console\Command;

class SyncSiteAssetsCommand extends Command
{
    protected $signature = 'site-assets:sync';

    protected $description = 'Mirror branded site icons and share images to the configured storage disk.';

    public function handle(SiteAssetStorage $siteAssetStorage): int
    {
        $synced = $siteAssetStorage->syncAll();

        foreach ($synced as $key => $url) {
            $this->line("{$key}: {$url}");
        }

        $this->info('Site assets synced.');

        return self::SUCCESS;
    }
}
