<?php

namespace App\Console\Commands\NFL;

use App\Models\NFL\ResearchRevision;
use App\Services\ProviderData\CompressedJsonPayloadStorage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class ArchiveResearchRevisionsCommand extends Command
{
    protected $signature = 'nfl:archive-research-revisions
        {--before= : Exclusive revision creation date (YYYY-MM-DD)}
        {--through-id= : Required inclusive revision ID boundary}
        {--after-id=0 : Resume after this revision ID}
        {--limit=10 : Maximum revisions (1–50)}
        {--apply : Archive payloads and replace the inline copies after verification}
        {--restore : Restore inline copies for already archived revisions}';

    protected $description = 'Preview, archive, or restore a bounded batch of older NFL research payloads';

    public function handle(CompressedJsonPayloadStorage $storage): int
    {
        $before = $this->option('before');
        $through = filter_var($this->option('through-id'), FILTER_VALIDATE_INT);
        $after = filter_var($this->option('after-id'), FILTER_VALIDATE_INT);
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        $apply = $this->option('apply');
        $restore = $this->option('restore');
        if (! is_string($before) || ! preg_match('/\A(\d{4})-(\d{2})-(\d{2})\z/', $before, $date)
            || ! checkdate((int) $date[2], (int) $date[3], (int) $date[1])
            || $before > now()->subDays(30)->toDateString()
            || $through === false || $through < 1 || $after === false || $after < 0 || $after >= $through
            || $limit === false || $limit < 1 || $limit > 50 || ($apply && $restore)) {
            $this->error('Supply a cutoff at least 30 days old, an ordered ID range, a limit of 1–50, and at most one write mode.');

            return self::INVALID;
        }

        if (app()->isProduction() && ($apply || $restore)
            && config('filesystems.disks.'.config('provider-data.storage.disk').'.driver') !== 's3') {
            $this->error('Production research archives require the configured private S3-compatible disk.');

            return self::FAILURE;
        }

        $eligible = ResearchRevision::query()->where('id', '>', $after)->where('id', '<=', $through)
            ->where('created_at', '<', $before)
            ->when($restore, fn ($query) => $query->whereNotNull('payload_archive'), fn ($query) => $query
                ->whereNull('payload_archive')
                ->whereNotNull('graded_at')
                ->whereHas('game', fn ($game) => $game->where('status', 'STATUS_FINAL'))
                ->whereExists(fn ($newer) => $newer->from('nfl_research_revisions as newer')->select('newer.id')
                    ->whereColumn('newer.game_id', 'nfl_research_revisions.game_id')
                    ->whereColumn('newer.id', '>', 'nfl_research_revisions.id')));
        $ids = (clone $eligible)->orderBy('id')->limit($limit)->pluck('id');
        $summary = ['mode' => $apply ? 'archive' : ($restore ? 'restore' : 'preview'), 'selected_ids' => $ids->all(),
            'processed_ids' => [], 'json_bytes' => 0, 'compressed_bytes' => 0];

        try {
            foreach ($ids as $id) {
                if (! $apply && ! $restore) {
                    break;
                }
                $columns = ['id', 'brief', 'evidence', 'payload_archive'];
                $revision = (clone $eligible)->findOrFail($id, $columns);
                $inline = ['brief' => $revision->getRawOriginal('brief'), 'evidence' => $revision->getRawOriginal('evidence')];
                $manifest = $restore ? $revision->payload_archive : $storage->archive($inline);
                $payload = $restore ? $storage->read($manifest) : ['brief' => 'null', 'evidence' => 'null'];
                if ($restore && $inline !== ['brief' => 'null', 'evidence' => 'null']) {
                    throw new RuntimeException('Research revision '.$id.' already contains inline payload data.');
                }

                DB::transaction(function () use ($eligible, $columns, $id, $revision, $inline, $manifest, $payload, $restore): void {
                    $locked = (clone $eligible)->lockForUpdate()->findOrFail($id, $columns);
                    if ($locked->getRawOriginal('brief') !== $inline['brief']
                        || $locked->getRawOriginal('evidence') !== $inline['evidence']
                        || $locked->payload_archive !== $revision->payload_archive) {
                        throw new RuntimeException('Research revision '.$id.' changed during archive verification.');
                    }

                    DB::table('nfl_research_revisions')->where('id', $id)->update([
                        ...$payload, 'payload_archive' => $restore ? null : json_encode($manifest, JSON_THROW_ON_ERROR),
                    ]);
                });
                $summary['processed_ids'][] = $id;
                $summary['json_bytes'] += $manifest['json_bytes'];
                $summary['compressed_bytes'] += $manifest['compressed_bytes'];
            }
        } catch (Throwable $exception) {
            report($exception);
            $summary['failed_id'] = $id ?? null;
            $this->line(json_encode($summary, JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }

        $this->line(json_encode($summary, JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
