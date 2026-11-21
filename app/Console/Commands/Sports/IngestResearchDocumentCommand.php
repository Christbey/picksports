<?php

namespace App\Console\Commands\Sports;

use App\Models\ResearchSource;
use App\Services\Api\V2\SportContextResolver;
use App\Services\Research\ResearchDocumentStore;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class IngestResearchDocumentCommand extends Command
{
    protected $signature = 'sports:ingest-research-document
        {sport : Supported sport slug}
        {source-key : Stable source catalog key within this sport}
        {--register-source : Explicitly register a new source catalog entry}
        {--register-only : Register a source without ingesting a document}
        {--url= : Source/document HTTPS URL}
        {--allowed-host=* : Exact approved host; required when registering}
        {--team-id= : Sport-specific team identity for a registered source}
        {--event-id= : Optional canonical event identity for this document}
        {--kind=official : Source kind for registration}
        {--enable-source : Explicitly allow this source for ingestion}
        {--disable-source : Explicitly disable an existing source}
        {--file= : Local UTF-8 document body; never fetched implicitly}
        {--claims= : Local JSON array of exact-excerpt claims with topic and optional stance}
        {--observed-at= : Required original observation timestamp with timezone}
        {--published-at= : Optional original publication timestamp with timezone}
        {--fetch : Explicitly fetch an existing allowlisted HTTPS source instead of --file}';

    protected $description = 'Register an approved research source or ingest an explicit document with grounded excerpts';

    public function handle(SportContextResolver $sports, ResearchDocumentStore $documents): int
    {
        $transactionLevel = DB::transactionLevel();
        DB::beginTransaction();
        try {
            if ($this->option('enable-source') && $this->option('disable-source')) {
                throw new \InvalidArgumentException('Select only one source activation action.');
            }
            if (! Schema::hasTable('research_sources') || ! Schema::hasTable('research_documents')) {
                throw new \InvalidArgumentException('Shared research storage is not installed.');
            }
            $sport = strtolower(trim((string) $this->argument('sport')));
            $context = $sports->find($sport);
            if ($context === null || ! is_array(config("research.sports.{$sport}"))) {
                throw new \InvalidArgumentException('Unsupported research sport.');
            }
            $source = ResearchSource::query()->where('sport', $sport)->where('source_key', $this->argument('source-key'))->first();
            if ($this->option('register-source')) {
                if ($source !== null) {
                    throw new \InvalidArgumentException('This source already exists; registration does not overwrite catalog policy.');
                }
                $teamId = $this->option('team-id');
                if ($teamId !== null && ! $context->models['team']::query()->whereKey((int) $teamId)->exists()) {
                    throw new \InvalidArgumentException('Source team does not exist in this sport.');
                }
                $source = new ResearchSource([
                    'sport' => $sport, 'source_key' => $this->argument('source-key'),
                    'team_id' => $teamId !== null ? (int) $teamId : null, 'kind' => $this->option('kind'),
                    'url' => $this->option('url'), 'allowed_hosts' => array_values(array_unique((array) $this->option('allowed-host'))),
                    'enabled' => (bool) $this->option('enable-source'),
                ]);
                // Validate the URL even when registering a disabled staged source.
                $validation = clone $source;
                $validation->enabled = true;
                if (! is_string($source->url) || ! $documents->allowed($validation, $source->url)) {
                    throw new \InvalidArgumentException('Registration requires an approved HTTPS URL and exact --allowed-host.');
                }
                $source->save();
            }
            if ($source === null) {
                throw new \InvalidArgumentException('Register this source explicitly before ingestion.');
            }
            if (! $this->option('register-source') && ($this->option('enable-source') || $this->option('disable-source'))) {
                $source->update(['enabled' => (bool) $this->option('enable-source')]);
            }
            if ($this->option('register-only')) {
                $this->info('Registered research source '.$source->id.'. No document or provider request was created.');
                DB::commit();

                return self::SUCCESS;
            }
            if (! $this->option('observed-at') || ! $this->option('claims')
                || ((bool) $this->option('fetch') === (bool) $this->option('file'))) {
                throw new \InvalidArgumentException('Provide --observed-at, --claims, and exactly one of --file or explicit --fetch.');
            }
            $claimsPath = (string) $this->option('claims');
            if (! is_file($claimsPath) || ! is_readable($claimsPath)) {
                throw new \InvalidArgumentException('Claims file is unavailable.');
            }
            foreach (['observed-at', 'published-at'] as $option) {
                if ($this->option($option) && ! preg_match('/(?:Z|[+-]\d{2}:\d{2})$/', (string) $this->option($option))) {
                    throw new \InvalidArgumentException('Provenance timestamps require an explicit timezone.');
                }
            }
            $claims = json_decode(file_get_contents($claimsPath), true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($claims) || ! array_is_list($claims)) {
                throw new \InvalidArgumentException('Claims must be a JSON array.');
            }
            $file = (string) $this->option('file');
            if (! $this->option('fetch') && (! is_file($file) || ! is_readable($file))) {
                throw new \InvalidArgumentException('Document file is unavailable.');
            }
            if (! $this->option('fetch') && filesize($file) > (int) config('research.maximum_document_bytes', 250000)) {
                throw new \InvalidArgumentException('Research source document exceeds the size limit.');
            }
            $url = (string) ($this->option('url') ?: $source->url);
            $body = $this->option('fetch') ? $documents->fetch($source, $url) : file_get_contents($file);
            $document = $documents->store(
                $source, $url, $body, $claims,
                CarbonImmutable::parse((string) $this->option('observed-at')),
                $this->option('published-at') ? CarbonImmutable::parse((string) $this->option('published-at')) : null,
                $this->option('event-id') !== null ? (int) $this->option('event-id') : null,
            );
            $this->info('Stored research document '.$document->id.'. No prediction or approval was changed.');
            DB::commit();

            return self::SUCCESS;
        } catch (Throwable $exception) {
            while (DB::transactionLevel() > $transactionLevel) {
                DB::rollBack();
            }
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
