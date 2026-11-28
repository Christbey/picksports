<?php

namespace App\Console\Commands\Sports;

use App\Models\PredictionPublicationEffect;
use App\Services\Predictions\PublicationEffectDelivery;
use Illuminate\Console\Command;
use Throwable;

class ReconcilePublicationEffectsCommand extends Command
{
    protected $signature = 'sports:reconcile-publication-effects
        {--limit= : Maximum existing delivery intents to admit; defaults to configured batch size}
        {--dry-run : List due intent IDs without writes or dispatch}';

    protected $description = 'Recover bounded durable publication effects without creating new analysis admission';

    public function handle(PublicationEffectDelivery $delivery): int
    {
        $limit = min(500, max(1, (int) ($this->option('limit') ?? config('prediction_lifecycle.publication_effects.batch_size', 100))));
        $ids = $delivery->dueQuery()->orderBy('id')->limit($limit)->pluck('id');
        $admitted = 0;
        $failures = 0;
        foreach ($ids as $id) {
            if ($this->option('dry-run')) {
                $this->line('Due publication effect '.$id);

                continue;
            }
            try {
                $admitted += (int) $delivery->dispatch((int) $id);
            } catch (Throwable $exception) {
                $failures++;
                $this->error('Publication effect '.$id.' could not be admitted ('.$exception::class.').');
            }
        }
        if (! $this->option('dry-run')) {
            $failures += PredictionPublicationEffect::query()->whereIn('id', $ids)->whereIn('state', ['failed', 'exhausted'])->count();
        }
        $this->info(sprintf('%d due; %d admitted; %d delivery errors. Handler completion is recorded separately.', $ids->count(), $admitted, $failures));

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
