<?php

namespace App\Console\Commands\Betting;

use App\Models\UserBet;
use App\Services\Betting\UserBetPredictionReferenceResolver;
use Illuminate\Console\Command;

class BackfillUserBetPredictionReferencesCommand extends Command
{
    protected $signature = 'user-bets:backfill-prediction-references
        {--write : Persist normalized references; omitted by default for a dry run}
        {--chunk=500 : Number of user bets to inspect per chunk}';

    protected $description = 'Backfill allowlisted sport and canonical event references for legacy user bets';

    public function handle(UserBetPredictionReferenceResolver $resolver): int
    {
        $write = (bool) $this->option('write');
        $chunkSize = max(1, (int) $this->option('chunk'));
        $counts = [
            'scanned' => 0,
            'resolvable' => 0,
            'updated' => 0,
            'unrecognized' => 0,
            'event_linked' => 0,
        ];

        UserBet::query()
            ->whereNotNull('prediction_id')
            ->where(function ($query): void {
                $query->whereNull('prediction_sport')
                    ->orWhereNull('sport_event_id');
            })
            ->chunkById($chunkSize, function ($bets) use ($resolver, $write, &$counts): void {
                foreach ($bets as $bet) {
                    $counts['scanned']++;
                    $reference = $resolver->fromStoredBet($bet);

                    if ($reference === null) {
                        $counts['unrecognized']++;

                        continue;
                    }

                    $counts['resolvable']++;
                    $counts['event_linked'] += $reference->sportEventId === null ? 0 : 1;

                    if (! $write) {
                        continue;
                    }

                    $bet->forceFill($reference->persistenceAttributes())->saveQuietly();
                    $counts['updated']++;
                }
            });

        $this->table(['mode', ...array_keys($counts)], [[
            $write ? 'write' : 'dry-run',
            ...array_values($counts),
        ]]);

        if (! $write) {
            $this->comment('Dry run only. Re-run with --write to persist normalized references.');
        }

        return self::SUCCESS;
    }
}
