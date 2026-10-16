<?php

namespace App\Console\Commands\NFL;

use App\Models\NFL\PlayerInjurySnapshot;
use App\Services\ProviderData\CompressedJsonPayloadStorage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class ArchiveInjurySnapshotPayloadsCommand extends Command
{
    protected $signature = 'nfl:archive-injury-snapshot-payloads
        {--through-id= : Required inclusive snapshot ID boundary}
        {--after-id=0 : Resume after the last successfully processed snapshot ID}
        {--limit=50 : Maximum records per invocation (1–100)}
        {--dry-run : Preview only; this is also the default}
        {--apply : Upload, verify and persist archive manifests; retain inline payloads}';

    protected $description = 'Archive a bounded batch of NFL injury reports without removing inline data';

    public function handle(CompressedJsonPayloadStorage $archives): int
    {
        $through = filter_var($this->option('through-id'), FILTER_VALIDATE_INT);
        $after = filter_var($this->option('after-id'), FILTER_VALIDATE_INT);
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if ($through === false || $through < 1 || $after === false || $after < 0 || $after > $through
            || $limit === false || $limit < 1 || $limit > 100 || ($this->option('apply') && $this->option('dry-run'))) {
            $this->error('Provide --through-id, an ordered nonnegative --after-id, and --limit=1..100; choose preview or --apply.');

            return self::INVALID;
        }

        $summary = ['mode' => $this->option('apply') ? 'apply' : 'dry-run', 'through_id' => $through,
            'after_id' => $after, 'last_successful_id' => $after, 'last_previewed_id' => $after, 'selected' => 0, 'processed' => 0,
            'manifests_added' => 0, 'existing_manifests_verified' => 0, 'inline_bytes_selected' => 0,
            'inline_payloads_removed' => 0];

        try {
            $candidates = PlayerInjurySnapshot::query()->where('id', '>', $after)->where('id', '<=', $through)
                ->whereNotNull('raw_payload')->orderBy('id')->limit($limit)
                ->select('id')->selectRaw('LENGTH(raw_payload) as inline_bytes')->get();
            $summary['selected'] = $candidates->count();
            foreach ($candidates as $candidate) {
                $summary['inline_bytes_selected'] += (int) $candidate->inline_bytes;
                if ($this->option('apply')) {
                    if ($candidate->inline_bytes > max(1, (int) config('provider-data.payloads.max_json_bytes'))) {
                        throw new RuntimeException('Snapshot '.$candidate->id.' exceeds the archive input limit.');
                    }

                    $snapshot = PlayerInjurySnapshot::query()->findOrFail($candidate->id);
                    if ($snapshot->raw_payload === null) {
                        throw new RuntimeException('Snapshot '.$candidate->id.' changed after selection.');
                    }
                    $existing = $snapshot->payload_archive;
                    $manifest = $existing ?? $archives->archive($snapshot->raw_payload);
                    if ($archives->read($manifest) !== $snapshot->raw_payload) {
                        throw new RuntimeException('Snapshot '.$candidate->id.' archive differs from inline data.');
                    }

                    DB::transaction(function () use ($snapshot, $manifest): void {
                        $locked = PlayerInjurySnapshot::query()->lockForUpdate()->findOrFail($snapshot->id);
                        if ($locked->getRawOriginal('raw_payload') !== $snapshot->getRawOriginal('raw_payload')
                            || $locked->payload_hash !== $snapshot->payload_hash
                            || $locked->payload_archive !== $snapshot->payload_archive) {
                            throw new RuntimeException('Snapshot '.$snapshot->id.' changed during archive verification.');
                        }

                        if ($locked->payload_archive === null) {
                            DB::table('nfl_player_injury_snapshots')->where('id', $locked->id)
                                ->update(['payload_archive' => json_encode($manifest, JSON_THROW_ON_ERROR)]);
                        }
                    });
                    $summary[$existing === null ? 'manifests_added' : 'existing_manifests_verified']++;
                }

                $summary['processed']++;
                $summary[$this->option('apply') ? 'last_successful_id' : 'last_previewed_id'] = (int) $candidate->id;
            }
        } catch (Throwable $exception) {
            $summary['error'] = $exception->getMessage();
            $this->line(json_encode($summary, JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }

        $this->line(json_encode($summary, JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
