<?php

namespace App\Console\Commands\CFB;

use App\Models\CFB\Game;
use App\Models\CFB\GameDataCheck;
use App\Models\CFB\TeamSeasonAffiliation;
use App\Models\DatasetExportManifest;
use App\Models\EventInputSnapshot;
use App\Models\MarketQuote;
use App\Services\CFB\Predictions\CfbStoredPregameQuote;
use App\Services\CFB\Signals\CfbFootballSignalCatalog;
use App\Services\CFB\Signals\CfbFootballSignalJointModel;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ExportScoringTrainingCommand extends Command
{
    protected $signature = 'cfb:export-scoring-training {--from-season=} {--to-season=} {--as-of=} {--test-season=} {--output=}';

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
        $path = $this->option('output');
        if (file_exists($path)) {
            $this->error('Exports are immutable; choose a new output path');

            return self::FAILURE;
        }
        $file = fopen($path, 'xb');
        $coverage = ['included' => 0, 'excluded' => [], 'frozen_context' => 0, 'retrospective_labels' => 0];
        $maxId = 0;
        $catalog = CfbFootballSignalCatalog::all();
        try {
            foreach (Game::with('sportEvent')->whereBetween('season', [$from, $to])->where('status', 'STATUS_FINAL')->lazyById(50) as $game) {
                $kickoff = $game->sportEvent?->starts_at;
                if (! $kickoff || $kickoff->gte($asOf) || $game->updated_at->gt($asOf)) {
                    $coverage['excluded']['missing_or_future_kickoff'] = ($coverage['excluded']['missing_or_future_kickoff'] ?? 0) + 1;

                    continue;
                }
                $checks = [];
                foreach (['boxscore', 'plays'] as $component) {
                    $checks[$component] = GameDataCheck::where('game_id', $game->id)->where('component', $component)
                        ->whereNotNull('accepted_at')->where('accepted_at', '<=', $asOf)->latest('accepted_at')->latest('id')->first();
                }
                if (! $checks['boxscore'] || ! $checks['plays']) {
                    $coverage['excluded']['unverified_sources'] = ($coverage['excluded']['unverified_sources'] ?? 0) + 1;

                    continue;
                }
                $drives = DB::table('cfb_drives')->where('game_id', $game->id)->where('source_revision', $checks['plays']->source_hash)->orderBy('id')->get();
                if ($drives->isEmpty() || $drives->contains(fn ($r) => $r->quality !== 'complete')) {
                    $coverage['excluded']['unverified_drives'] = ($coverage['excluded']['unverified_drives'] ?? 0) + 1;

                    continue;
                }
                // One original pregame snapshot per game, never rewritten using today's context.
                $snapshot = EventInputSnapshot::where('sport_event_id', $game->sport_event_id)->where('sport', 'cfb')->where('phase', 'pregame')
                    ->where('captured_at', '<', $kickoff)->where('created_at', '<', $kickoff)
                    ->where(fn ($q) => $q->whereNull('latest_source_available_at')->orWhere('latest_source_available_at', '<', $kickoff))->latest('captured_at')->first();
                $inputs = $snapshot?->inputs ?? [];
                $signals = ['spread' => [], 'total' => []];
                $signalMissing = [];
                if ($snapshot) {
                    $coverage['frozen_context']++;
                    $signals = CfbFootballSignalJointModel::features($inputs, ['catalog' => $catalog, 'feature_policy' => 'observed_only']);
                    foreach ($catalog as $id => $rule) {
                        $states = [];
                        foreach (['home', 'away'] as $side) {
                            $states[] = CfbFootballSignalCatalog::evaluate($rule, CfbFootballSignalCatalog::features($inputs, $side, 'observed_only'));
                        }
                        $signalMissing[$id] = in_array(null, $states, true);
                    }
                }
                $available = max($checks['boxscore']->accepted_at, $checks['plays']->accepted_at, CarbonImmutable::parse($drives->max('updated_at')));
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
                    'source_hashes' => array_map(fn ($c) => $c->source_hash, $checks), 'home_id' => (string) $game->home_team_id, 'away_id' => (string) $game->away_team_id,
                    'home_score' => (int) $game->home_score, 'away_score' => (int) $game->away_score, 'neutral' => (bool) $game->neutral_site,
                    'subdivisions' => TeamSeasonAffiliation::where('season', $game->season)->whereIn('team_id', [$game->home_team_id, $game->away_team_id])->pluck('subdivision', 'team_id')->all(),
                    'snapshot_hash' => $snapshot?->content_hash, 'snapshot_available_at' => $snapshot?->captured_at?->toIso8601String(),
                    'fpi' => ['home' => data_get($inputs, 'home.metrics.fpi'), 'away' => data_get($inputs, 'away.metrics.fpi')],
                    'signals' => $signals, 'signal_missing' => $signalMissing, 'quotes' => $quotes, 'forecast_at' => $forecastAt->toIso8601String(),
                    'drives' => $drives->map(fn ($r) => ['offense_id' => (string) $r->offense_team_id, ...json_decode($r->data, true)])->all()];
                fwrite($file, json_encode($row, JSON_THROW_ON_ERROR)."\n");
                $coverage['included']++;
                $coverage['retrospective_labels']++;
                $maxId = max($maxId, $game->id);
            }
        } finally {
            fclose($file);
        }
        $hash = hash_file('sha256', $path);
        $manifest = ['schema' => 'cfb-scoring-v1', 'sha256' => $hash, 'data_path' => realpath($path), 'as_of' => $asOf->toIso8601String(), 'from_season' => $from, 'to_season' => $to,
            'test_season' => $testSeason, 'coverage' => $coverage, 'signal_families' => array_map(fn ($r) => $r['family'], $catalog), 'availability_policy' => 'strict_recorded_time; retrospective replay must be labeled separately'];
        file_put_contents($path.'.manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        $key = 'cfb/training/'.$hash.'.jsonl';
        $disk = config('cfb.scoring.artifact_disk', 'local');
        $stream = fopen($path, 'rb');
        Storage::disk($disk)->put($key, $stream);
        fclose($stream);
        Storage::disk($disk)->put($key.'.manifest.json', file_get_contents($path.'.manifest.json'));
        DatasetExportManifest::firstOrCreate(['dataset' => 'cfb_scoring', 'sport' => 'cfb', 'season' => $to, 'format' => 'jsonl', 'sha256' => $hash],
            ['content_type' => 'application/x-ndjson', 'disk' => $disk, 'object_key' => $key, 'manifest_key' => $key.'.manifest.json', 'uri' => $disk.':'.$key,
                'manifest_sha256' => hash_file('sha256', $path.'.manifest.json'), 'schema_hash' => hash('sha256', 'cfb-scoring-v1'),
                'row_count' => $coverage['included'], 'size_bytes' => filesize($path), 'source_table' => 'cfb_games', 'source_max_id' => $maxId, 'exported_at' => now(), 'metadata' => $manifest]);
        $this->line(json_encode($manifest, JSON_THROW_ON_ERROR));

        return $coverage['included'] ? self::SUCCESS : 2;
    }
}
