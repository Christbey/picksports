<?php

namespace App\Console\Commands\NFL;

use App\Models\NFL\PlayerInjurySnapshot;
use App\Services\ProviderData\CompressedJsonPayloadStorage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class ManageInjurySnapshotInlinePayloadsCommand extends Command
{
    protected $signature = 'nfl:manage-injury-snapshot-inline-payloads
        {--mode= : Required release or restore}
        {--through-id= : Required inclusive snapshot ID boundary}
        {--after-id=0 : Resume after the last successful ID}
        {--limit=10 : Maximum records (1–100)}
        {--max-bytes=5242880 : Maximum source JSON bytes per batch (1–33554432)}
        {--apply : Persist changes; otherwise verify and preview only}';

    protected $description = 'Release verified archived injury payload duplicates or restore their inline copies';

    public function handle(CompressedJsonPayloadStorage $archives): int
    {
        $mode = $this->option('mode');
        $through = filter_var($this->option('through-id'), FILTER_VALIDATE_INT);
        $after = filter_var($this->option('after-id'), FILTER_VALIDATE_INT);
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        $budget = filter_var($this->option('max-bytes'), FILTER_VALIDATE_INT);
        if (! in_array($mode, ['release', 'restore'], true) || $through === false || $through < 1
            || $after === false || $after < 0 || $after > $through || $limit === false || $limit < 1 || $limit > 100
            || $budget === false || $budget < 1 || $budget > 33554432) {
            $this->error('Choose release or restore, an ordered ID boundary, limit 1..100 and byte budget 1..33554432.');

            return self::INVALID;
        }

        $summary = ['mode' => $mode, 'apply' => (bool) $this->option('apply'), 'through_id' => $through,
            'after_id' => $after, 'last_successful_id' => $after, 'last_previewed_id' => $after,
            'processed' => 0, 'source_json_bytes' => 0, 'inline_payloads_removed' => 0,
            'inline_payloads_restored' => 0, 'byte_budget_reached' => false];

        try {
            $query = PlayerInjurySnapshot::query()->where('id', '>', $after)->where('id', '<=', $through)
                ->whereNotNull('payload_archive')->orderBy('id')->limit($limit);
            $mode === 'release' ? $query->whereNotNull('raw_payload') : $query->whereNull('raw_payload');
            $candidates = $query->select(['id', 'payload_archive'])->selectRaw('LENGTH(raw_payload) as inline_bytes')->get();
            foreach ($candidates as $candidate) {
                $bytes = $mode === 'release' ? (int) $candidate->inline_bytes : (int) ($candidate->payload_archive['json_bytes'] ?? 0);
                if ($bytes < 1 || $bytes > (int) config('provider-data.payloads.max_json_bytes', 8388608)) {
                    throw new RuntimeException('Snapshot '.$candidate->id.' has an invalid or oversized payload.');
                }
                if ($budget < $summary['source_json_bytes'] + $bytes) {
                    $summary['byte_budget_reached'] = true;
                    if ($summary['processed'] === 0) {
                        throw new RuntimeException('First eligible payload exceeds the batch byte budget.');
                    }

                    break;
                }

                $snapshot = PlayerInjurySnapshot::query()->findOrFail($candidate->id);
                if ($snapshot->payload_archive !== $candidate->payload_archive
                    || ($mode === 'release' ? $snapshot->raw_payload === null : $snapshot->raw_payload !== null)) {
                    throw new RuntimeException('Snapshot '.$candidate->id.' changed after selection.');
                }
                $payload = $archives->read($snapshot->payload_archive);
                if ($mode === 'release' && $payload !== $snapshot->raw_payload) {
                    throw new RuntimeException('Snapshot '.$candidate->id.' archive differs from inline data.');
                }

                if ($this->option('apply')) {
                    DB::transaction(function () use ($snapshot, $payload, $mode): void {
                        $locked = PlayerInjurySnapshot::query()->lockForUpdate()->findOrFail($snapshot->id);
                        if ($locked->getRawOriginal('raw_payload') !== $snapshot->getRawOriginal('raw_payload')
                            || $locked->payload_archive !== $snapshot->payload_archive || $locked->payload_hash !== $snapshot->payload_hash) {
                            throw new RuntimeException('Snapshot '.$snapshot->id.' changed during archive verification.');
                        }

                        DB::table('nfl_player_injury_snapshots')->where('id', $locked->id)->update([
                            'raw_payload' => $mode === 'release' ? null : json_encode($payload,
                                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION),
                        ]);
                    });
                    $summary[$mode === 'release' ? 'inline_payloads_removed' : 'inline_payloads_restored']++;
                }

                $summary['processed']++;
                $summary['source_json_bytes'] += $bytes;
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
