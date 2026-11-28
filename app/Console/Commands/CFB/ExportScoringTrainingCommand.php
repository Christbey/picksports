<?php

namespace App\Console\Commands\CFB;

use App\Models\CFB\Game;
use App\Models\CFB\GameDataCheck;
use App\Models\CFB\TeamSeasonAffiliation;
use App\Models\DatasetExportManifest;
use App\Models\EventInputSnapshot;
use App\Models\MarketQuote;
use App\Services\CFB\Data\CfbDataReadiness;
use App\Services\CFB\Data\CfbDriveBuilder;
use App\Services\CFB\Data\CfbSourceAssessmentRecorder;
use App\Services\CFB\Predictions\CfbStoredPregameQuote;
use App\Services\CFB\Scoring\CfbScoringFeatures;
use App\Services\CFB\Scoring\CfbTrainingSourceProvenance;
use App\Services\CFB\Signals\CfbFootballSignalCatalog;
use App\Services\CFB\Signals\CfbFootballSignalJointModel;
use App\Services\Predictions\CfbSnapshotInputStorage;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ExportScoringTrainingCommand extends Command
{
    protected $signature = 'cfb:export-scoring-training {--from-season=} {--to-season=} {--as-of=} {--test-season=} {--test-from=} {--test-until=} {--after-game-id=0} {--max-games=100} {--output=}';

    protected $description = 'Export accepted source revisions, frozen context, coverage and real availability times for chronological CFB training';

    public function handle(): int
    {
        $from = (int) $this->option('from-season');
        $to = (int) $this->option('to-season');
        if ($from < 2000 || $to < $from || ! $this->option('as-of') || ! $this->option('output')) {
            return self::FAILURE;
        }
        $asOf = CarbonImmutable::parse($this->option('as-of'));
        $testSeason = $this->option('test-season') ? (int) $this->option('test-season') : null;
        if ($testSeason !== null && ($testSeason < $from || $testSeason > $to || $asOf->lt(CarbonImmutable::create($testSeason + 1, 3, 1)))) {
            $this->error('The test season must be in the export range and complete before the as-of date.');

            return self::FAILURE;
        }
        $testFrom = $this->option('test-from');
        $testUntil = $this->option('test-until');
        if (($testFrom || $testUntil) && (! $testFrom || ! $testUntil || $testSeason !== null)) {
            $this->error('Use either a complete test season or both explicit test-window boundaries.');

            return self::FAILURE;
        }
        if ($testFrom && $testUntil) {
            $start = CarbonImmutable::parse($testFrom)->utc();
            $end = CarbonImmutable::parse($testUntil)->utc();
            if ($start->gte($end) || $end->gt($asOf) || $start->dayOfWeekIso !== 1 || $end->dayOfWeekIso !== 1
                || $start->format('H:i:s') !== '00:00:00' || $end->format('H:i:s') !== '00:00:00') {
                $this->error('Use completed kickoff weeks bounded by Monday 00:00 UTC, ending before as-of.');

                return self::FAILURE;
            }
            $testFrom = $start->toIso8601String();
            $testUntil = $end->toIso8601String();
        }
        $after = max(0, (int) $this->option('after-game-id'));
        $scope = (string) $this->option('max-games');
        if (! preg_match('/^[1-9][0-9]*$/', $scope) || (int) $scope > 1000) {
            $this->error('max-games must be between 1 and 1000; resume using after-game-id.');

            return self::FAILURE;
        }
        $maximum = (int) $scope;
        $scanned = 0;
        $lastScannedId = $after;
        $path = $this->option('output');
        if (file_exists($path)) {
            $this->error('Exports are immutable; choose a new output path');

            return self::FAILURE;
        }
        $file = fopen($path, 'xb');
        $coverage = ['included' => 0, 'excluded' => [], 'frozen_context' => 0, 'retrospective_labels' => 0];
        $maxId = 0;
        $catalog = app(CfbScoringFeatures::class)->independentCatalog(CfbFootballSignalCatalog::all());
        try {
            foreach (Game::with('sportEvent')->whereBetween('season', [$from, $to])->where('status', 'STATUS_FINAL')->where('id', '>', $after)->lazyById(50)->chunk(50) as $games) {
                $games = $games->collect()->take($maximum - $scanned);
                if ($games->isEmpty()) {
                    break;
                }
                $accepted = $this->acceptedChecks($games, $asOf);
                $affiliations = TeamSeasonAffiliation::query()->whereIn('season', $games->pluck('season')->unique())
                    ->whereIn('team_id', $games->flatMap(fn (Game $game): array => [$game->home_team_id, $game->away_team_id])->unique())->get()
                    ->keyBy(fn (TeamSeasonAffiliation $row): string => $row->season.':'.$row->team_id);
                foreach ($games as $game) {
                    if ($maximum && $scanned >= $maximum) {
                        break 2;
                    }
                    $scanned++;
                    $lastScannedId = $game->id;
                    $kickoff = $game->sportEvent?->starts_at;
                    if (! $kickoff || $kickoff->gte($asOf) || $game->updated_at->gt($asOf)) {
                        $coverage['excluded']['missing_or_future_kickoff'] = ($coverage['excluded']['missing_or_future_kickoff'] ?? 0) + 1;

                        continue;
                    }
                    $checks = ['boxscore' => $accepted->get($game->id)?->get('boxscore'), 'plays' => $accepted->get($game->id)?->get('plays')];
                    if (! $checks['boxscore'] || ! $checks['plays']) {
                        $coverage['excluded']['unverified_sources'] = ($coverage['excluded']['unverified_sources'] ?? 0) + 1;

                        continue;
                    }
                    $receipts = array_map(fn ($check) => app(CfbSourceAssessmentRecorder::class)->acceptanceReceipt($check, $asOf), $checks);
                    if (in_array(null, $receipts, true)) {
                        $coverage['excluded']['unverified_sources'] = ($coverage['excluded']['unverified_sources'] ?? 0) + 1;

                        continue;
                    }
                    $drives = DB::table('cfb_drives')->where('game_id', $game->id)->where('source_revision', $checks['plays']->source_hash)->orderBy('id')->get();
                    if ($drives->isEmpty() || $drives->contains(fn ($r) => $r->quality !== 'complete'
                        || data_get(json_decode($r->data, true), 'normalizer_version') !== CfbDriveBuilder::VERSION
                        || data_get(json_decode($r->data, true), 'score_ledger_revision') !== $checks['boxscore']->source_hash
                        || data_get(json_decode($r->data, true), 'play_assessment_receipt_id') !== $receipts['plays']->id
                        || data_get(json_decode($r->data, true), 'boxscore_assessment_receipt_id') !== $receipts['boxscore']->id)) {
                        $coverage['excluded']['unverified_drives'] = ($coverage['excluded']['unverified_drives'] ?? 0) + 1;

                        continue;
                    }
                    // One original pregame snapshot per game, never rewritten using today's context.
                    $snapshotId = EventInputSnapshot::where('sport_event_id', $game->sport_event_id)->where('sport', 'cfb')->where('phase', 'pregame')
                        ->where('captured_at', '<', $kickoff)->where('created_at', '<', $kickoff)
                        ->where(fn ($q) => $q->whereNull('latest_source_available_at')->orWhere('latest_source_available_at', '<', $kickoff))->latest('captured_at')->latest('id')->value('id');
                    $columns = ['id', 'content_hash', 'captured_at'];
                    $paths = ['event', 'historical_signals', 'signal_context'];
                    foreach (['home', 'away'] as $side) {
                        foreach (['elo', 'metrics', 'prior_metrics', 'personnel', 'injuries', 'large_spread_evidence'] as $field) {
                            $paths[] = $side.'.'.$field;
                        }
                    }
                    foreach ($paths as $inputPath) {
                        $columns[] = 'inputs->'.str_replace('.', '->', $inputPath).' as '.str_replace('.', '_', $inputPath);
                    }
                    $snapshot = $snapshotId ? EventInputSnapshot::select($columns)->find($snapshotId) : null;
                    $inputs = [];
                    if ($snapshot) {
                        foreach ($paths as $inputPath) {
                            $value = $snapshot->getAttribute(str_replace('.', '_', $inputPath));
                            data_set($inputs, $inputPath, app(CfbSnapshotInputStorage::class)->resolveProjectedSection($value));
                        }
                    }
                    $signals = ['spread' => [], 'total' => []];
                    $signalMissing = [];
                    if ($snapshot) {
                        $coverage['frozen_context']++;
                        $signals = CfbFootballSignalJointModel::features($inputs, ['catalog' => $catalog, 'feature_policy' => 'observed_only']);
                        $oriented = [];
                        foreach (['home', 'away'] as $side) {
                            $oriented[$side] = CfbFootballSignalCatalog::features($inputs, $side, 'observed_only');
                        }
                        foreach ($catalog as $id => $rule) {
                            $states = [];
                            foreach (['home', 'away'] as $side) {
                                $states[] = CfbFootballSignalCatalog::evaluate($rule, $oriented[$side]);
                            }
                            $signalMissing[$id] = in_array(null, $states, true);
                        }
                    }
                    $available = max($checks['boxscore']->accepted_at, $checks['plays']->accepted_at, $receipts['boxscore']->recorded_at, $receipts['plays']->recorded_at, CarbonImmutable::parse($drives->max('updated_at')), CarbonImmutable::parse($game->updated_at));
                    if ($available->gt($asOf)) {
                        $coverage['excluded']['derived_after_cutoff'] = ($coverage['excluded']['derived_after_cutoff'] ?? 0) + 1;

                        continue;
                    }
                    $forecastAt = $snapshot?->captured_at ?? $kickoff->copy()->subHour();
                    $quotes = MarketQuote::where('sport', 'cfb')->where('game_id', $game->id)->where('is_pregame', true)
                        ->where('created_at', '<=', $forecastAt)->where('captured_at', '<=', $forecastAt)
                        ->where('created_at', '<', $kickoff)->where('captured_at', '<', $kickoff)->orderByDesc('id')->get()
                        ->unique(fn ($q) => CfbStoredPregameQuote::identity($q))
                        ->map(fn ($q) => ['quote_id' => $q->id, 'market' => $q->market_key, 'side' => $q->side, 'participant' => data_get($q->metadata, 'participant_side'),
                            'line' => $q->line === null ? null : (float) $q->line, 'price' => $q->price, 'bookmaker' => $q->bookmaker_key])->values()->all();
                    $row = ['schema' => 'cfb-scoring-v1', 'game_id' => $game->id, 'season' => $game->season, 'week' => $game->week, 'kickoff' => $kickoff->toIso8601String(),
                        'source_available_at' => $available->toIso8601String(), 'evidence' => 'retrospective_accepted_sources',
                        'source_hashes' => array_map(fn ($c) => $c->source_hash, $checks),
                        'source_assessment_receipt_ids' => array_map(fn ($receipt) => $receipt->id, $receipts),
                        'source_assessments' => array_map(fn ($receipt) => [
                            'check_id' => $receipt->game_data_check_id, 'receipt_id' => $receipt->id,
                            'context_hash' => $receipt->context_hash, 'source_hash' => $receipt->projection_snapshot['source_hash'],
                            'game_feature_hash' => app(CfbTrainingSourceProvenance::class)->gameFeatureHash($game),
                        ], $receipts), 'home_id' => (string) $game->home_team_id, 'away_id' => (string) $game->away_team_id,
                        'home_score' => (int) $game->home_score, 'away_score' => (int) $game->away_score, 'neutral' => (bool) $game->neutral_site,
                        'subdivisions' => collect([$game->home_team_id, $game->away_team_id])->mapWithKeys(fn (int $id): array => ($row = $affiliations->get($game->season.':'.$id)) ? [$id => $row->subdivision] : [])->all(),
                        'snapshot_hash' => $snapshot?->content_hash, 'snapshot_available_at' => $snapshot?->captured_at?->toIso8601String(),
                        'fpi' => ['home' => data_get($inputs, 'home.metrics.fpi'), 'away' => data_get($inputs, 'away.metrics.fpi')],
                        'venue' => data_get($inputs, 'event.venue') ?: ($game->venue_name ?: $game->venue),
                        'venue_source' => data_get($inputs, 'event.venue') ? 'original_pregame_snapshot' : 'retrospective_game_metadata', 'context' => app(CfbScoringFeatures::class)->capture($inputs),
                        'signals' => $signals, 'signal_missing' => $signalMissing, 'quotes' => $quotes, 'forecast_at' => $forecastAt->toIso8601String(),
                        'drives' => $drives->map(fn ($r) => ['offense_id' => (string) $r->offense_team_id, ...json_decode($r->data, true)])->all()];
                    fwrite($file, json_encode($row, JSON_THROW_ON_ERROR)."\n");
                    $coverage['included']++;
                    $coverage['retrospective_labels']++;
                    $maxId = max($maxId, $game->id);
                }
            }
        } finally {
            fclose($file);
        }
        $hash = hash_file('sha256', $path);
        $hasMore = Game::whereBetween('season', [$from, $to])->where('status', 'STATUS_FINAL')->where('id', '>', $lastScannedId)->exists();
        $complete = $after === 0 && ! $hasMore;
        $manifest = ['schema' => 'cfb-scoring-v1', 'sha256' => $hash, 'data_path' => realpath($path), 'as_of' => $asOf->toIso8601String(), 'from_season' => $from, 'to_season' => $to,
            'scan' => ['after_game_id' => $after, 'scanned_games' => $scanned, 'last_game_id' => $lastScannedId,
                'has_more' => $hasMore, 'complete' => $complete, 'limit_hit' => $hasMore && $scanned >= $maximum,
                'max_games' => $maximum, 'chunk_size' => 50],
            'test_season' => $testSeason, 'test_from' => $testFrom, 'test_until' => $testUntil, 'coverage' => $coverage, 'signal_families' => array_map(fn ($r) => $r['family'], $catalog), 'feature_policy' => CfbScoringFeatures::POLICY, 'availability_policy' => 'strict_recorded_time; retrospective replay must be labeled separately'];
        $manifestJson = json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        if (@file_put_contents($path.'.manifest.json', $manifestJson) !== strlen($manifestJson)) {
            $this->error('Unable to write the completed local export manifest.');

            return self::FAILURE;
        }
        if ($coverage['included'] === 0) {
            $this->line(json_encode($manifest, JSON_THROW_ON_ERROR));

            return 2;
        }
        $manifestHash = hash('sha256', $manifestJson);
        $key = 'cfb/training/'.$hash.'.jsonl';
        $disk = config('cfb.scoring.artifact_disk', 'local');
        $identity = ['dataset' => 'cfb_scoring', 'sport' => 'cfb', 'season' => $to, 'format' => 'jsonl', 'sha256' => $hash];
        $existing = DatasetExportManifest::query()->where($identity)->first();
        $manifestKey = $key.'.'.$manifestHash.'.manifest.json';
        if ($existing !== null && $existing->manifest_key === $key.'.manifest.json') {
            $manifestKey = $existing->manifest_key; // Exact compatible legacy retry; never rewrite its identity.
        }
        $values = ['content_type' => 'application/x-ndjson', 'disk' => $disk, 'object_key' => $key,
            'manifest_key' => $manifestKey, 'uri' => $disk.':'.$key, 'manifest_sha256' => $manifestHash,
            'schema_hash' => hash('sha256', 'cfb-scoring-v1'), 'row_count' => $coverage['included'],
            'size_bytes' => filesize($path), 'source_table' => 'cfb_games', 'source_max_id' => $maxId,
            'exported_at' => now(), 'metadata' => $manifest];
        if ($existing !== null && ! $this->registryMatches($existing, [...$identity, ...$values])) {
            $this->error('Existing immutable export registry does not match this publication.');

            return self::FAILURE;
        }
        try {
            $storage = Storage::disk($disk);
            $manifestExists = $storage->exists($manifestKey);
            $dataExists = $storage->exists($key);
            if (($manifestExists && ! $this->storedHashMatches($storage, $manifestKey, $manifestHash, strlen($manifestJson)))
                || ($dataExists && ! $this->storedHashMatches($storage, $key, $hash, $values['size_bytes']))) {
                $this->error('Refusing to overwrite differing immutable export objects.');

                return self::FAILURE;
            }
            if (! $dataExists) {
                $stream = @fopen($path, 'rb');
                if (! is_resource($stream)) {
                    $this->error('Unable to open the completed export for publication.');

                    return self::FAILURE;
                }
                try {
                    $written = $storage->put($key, $stream);
                } finally {
                    if (is_resource($stream)) {
                        fclose($stream);
                    }
                }
                if ($written !== true) {
                    $this->error('Export data publication failed; local output retained.');

                    return self::FAILURE;
                }
            }
            if (! $manifestExists && $storage->put($manifestKey, $manifestJson) !== true) {
                $this->error('Export manifest publication failed; local output retained.');

                return self::FAILURE;
            }
        } catch (\Throwable) {
            $this->error('Export publication failed; local output retained.');

            return self::FAILURE;
        }
        $registered = $this->registerExport($identity, $values);
        if (! $registered->exists || ! $this->registryMatches($registered, [...$identity, ...$values])) {
            $this->error('A different immutable export registry won publication; local output retained.');

            return self::FAILURE;
        }
        $this->line(json_encode($manifest, JSON_THROW_ON_ERROR));

        return $coverage['included'] && $complete ? self::SUCCESS : 2;
    }

    protected function registerExport(array $identity, array $values): DatasetExportManifest
    {
        return DatasetExportManifest::firstOrCreate($identity, $values);
    }

    private function registryMatches(DatasetExportManifest $record, array $expected): bool
    {
        foreach (array_diff_key($expected, ['exported_at' => true, 'metadata' => true]) as $field => $value) {
            if ($record->getAttribute($field) !== $value) {
                return false;
            }
        }

        return true;
    }

    private function storedHashMatches(FilesystemAdapter $disk, string $key, string $expected, int $expectedBytes): bool
    {
        if ($expectedBytes < 0 || $expectedBytes >= PHP_INT_MAX) {
            return false;
        }
        $stream = $disk->readStream($key);
        if (! is_resource($stream)) {
            return false;
        }
        try {
            $context = hash_init('sha256');
            $remaining = $expectedBytes + 1;
            $read = 0;
            while ($remaining > 0 && ! feof($stream)) {
                $chunkSize = min(8192, $remaining);
                stream_set_chunk_size($stream, $chunkSize);
                $chunk = fread($stream, $chunkSize);
                if ($chunk === false || ($chunk === '' && ! feof($stream))) {
                    return false;
                }
                $read += strlen($chunk);
                $remaining -= strlen($chunk);
                hash_update($context, $chunk);
            }

            return $read === $expectedBytes && feof($stream) && hash_equals($expected, hash_final($context));
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /**
     * @param  Collection<int, Game>  $games
     * @return Collection<int, Collection<string, GameDataCheck>>
     */
    private function acceptedChecks(Collection $games, CarbonImmutable $asOf): Collection
    {
        $readiness = app(CfbDataReadiness::class);

        return $games->mapWithKeys(function (Game $game) use ($readiness, $asOf): array {
            $checks = collect(['boxscore', 'plays'])->mapWithKeys(fn (string $component): array => [
                $component => $readiness->acceptedCheck($game, $component, $asOf),
            ])->filter();

            return $checks->count() === 2 ? [$game->id => $checks] : [];
        });
    }
}
