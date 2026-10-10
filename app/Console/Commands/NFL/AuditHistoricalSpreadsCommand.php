<?php

namespace App\Console\Commands\NFL;

use App\Models\NFL\Game;
use App\Models\PredictionFeatureSnapshot;
use App\Models\ProviderSourceFile;
use App\Services\NFL\NflGameQuarterbackIdentitySync;
use App\Services\NFL\NflHistoricalSpreadDiagnostics;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class AuditHistoricalSpreadsCommand extends Command
{
    protected $signature = 'nfl:audit-historical-spreads
        {--source-id= : Archived nflverse games.csv source ID}
        {--evaluation-season=2024 : First evaluation season; earlier seasons train residuals}';

    protected $description = 'Read-only diagnostic of migrated historical forecasts against archived closing lines';

    public function handle(NflHistoricalSpreadDiagnostics $diagnostics, NflGameQuarterbackIdentitySync $identity): int
    {
        $season = filter_var($this->option('evaluation-season'), FILTER_VALIDATE_INT);
        $sourceId = filter_var($this->option('source-id'), FILTER_VALIDATE_INT);
        if ($season === false || $season < 2000 || $season > (int) now()->year || $sourceId === false || $sourceId < 1) {
            $this->error('Supply a valid archive source ID and evaluation season.');

            return self::INVALID;
        }
        $source = ProviderSourceFile::findOrFail($sourceId);
        if ($source->provider !== 'nflverse') {
            $this->error('The source must be an archived nflverse schedule.');

            return self::INVALID;
        }
        $raw = Storage::disk($source->disk)->get($source->object_key);
        if (! hash_equals($source->sha256, hash('sha256', $raw))) {
            $this->error('Archived source checksum mismatch.');

            return self::FAILURE;
        }
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, $raw);
        rewind($stream);
        $header = fgetcsv($stream, escape: '');
        if (! is_array($header) || array_diff(['game_id', 'espn', 'season', 'game_type', 'week', 'gameday', 'home_team', 'away_team', 'home_score', 'away_score', 'spread_line'], $header) !== []) {
            fclose($stream);
            $this->error('The archive lacks required schedule columns.');

            return self::INVALID;
        }
        $sourceRows = $sourceRowsByNflverseId = [];
        while (($values = fgetcsv($stream, escape: '')) !== false) {
            if (count($values) === count($header)) {
                $row = array_combine($header, $values);
                $sourceRows[$row['espn']][] = $row;
                $sourceRowsByNflverseId[$row['game_id']][] = $row;
            }
        }
        fclose($stream);
        $rows = $excluded = [];
        $clockMismatches = 0;
        $seen = [];
        PredictionFeatureSnapshot::query()->where('sport', 'nfl')->where('prediction_table', 'nfl_predictions')
            ->where('availability_status', 'verified_reconstruction')
            ->select(['id', 'game_id', 'model_version', 'game_start_at', 'outputs->predicted_spread as model_margin'])
            ->orderByDesc('id')->chunk(100, function ($snapshots) use (&$rows, &$excluded, &$clockMismatches, &$seen, $sourceRows, $sourceRowsByNflverseId, $identity): void {
                $games = Game::query()->with(['homeTeam', 'awayTeam', 'sportEvent'])->whereIn('id', $snapshots->pluck('game_id'))->get()->keyBy('id');
                foreach ($snapshots as $snapshot) {
                    $key = $snapshot->model_version.':'.$snapshot->game_id;
                    if (isset($seen[$key])) {
                        continue;
                    }
                    $seen[$key] = true;
                    $game = $games->get($snapshot->game_id);
                    $matches = filled($game?->nflverse_game_id)
                        ? ($sourceRowsByNflverseId[$game->nflverse_game_id] ?? [])
                        : ($sourceRows[(string) $game?->espn_event_id] ?? []);
                    $reason = match (true) {
                        $game === null => 'missing_game',
                        ! in_array((string) $game->season_type, ['2', 'regular'], true) => 'not_regular_season',
                        count($matches) !== 1 => 'missing_or_ambiguous_source',
                        ! $identity->matchesFinalGame($game, $matches[0]) => 'source_identity_or_score_mismatch',
                        ! is_numeric($snapshot->model_margin) || ! is_finite((float) $snapshot->model_margin) => 'missing_model_margin',
                        ! is_numeric($matches[0]['spread_line']) => 'missing_closing_line',
                        ! is_finite((float) $matches[0]['spread_line']) || abs((float) $matches[0]['spread_line'] * 2 - round((float) $matches[0]['spread_line'] * 2)) > 0.00001 => 'invalid_closing_line',
                        default => null,
                    };
                    if ($reason !== null) {
                        $excluded[$reason] = ($excluded[$reason] ?? 0) + 1;

                        continue;
                    }
                    $clockMismatches += (int) ($game->sportEvent?->starts_at !== null && $snapshot->game_start_at?->equalTo($game->sportEvent->starts_at) !== true);
                    $rows[] = ['snapshot_id' => $snapshot->id, 'game_id' => $game->id, 'season' => (int) $game->season,
                        'model_version' => $snapshot->model_version, 'model_margin' => (float) $snapshot->model_margin,
                        'home_line' => -(float) $matches[0]['spread_line'],
                        'actual_margin' => (float) $game->home_score - (float) $game->away_score];
                }
            });
        $report = $diagnostics->evaluate($rows, $season);
        $this->line(json_encode([...$report, 'source_id' => $source->id, 'source_sha256' => $source->sha256,
            'matched_games' => count($rows), 'excluded' => $excluded,
            'snapshot_clock_mismatches' => $clockMismatches,
            'sample_snapshot_ids' => array_slice(array_column($rows, 'snapshot_id'), 0, 10),
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return count($rows) > 0 ? self::SUCCESS : self::FAILURE;
    }
}
