<?php

namespace App\Console\Commands\NFL;

use App\Models\NFL\Game;
use App\Models\NFL\GameContextFact;
use App\Models\NFL\Team;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

class ImportGameContextCommand extends Command
{
    protected $signature = 'nfl:import-game-context
        {dataset : schedules, injuries, or evidence}
        {file : Local CSV (nflverse) or JSON (curated evidence)}
        {--schedules= : nflverse schedule CSV required for injury/evidence matching}
        {--source-url= : Source URL for CSV evidence}
        {--from-season=2023}
        {--to-season=}
        {--dry-run}';

    protected $description = 'Append sourced historical game coaches and injury reports without changing scores, rosters, or projections';

    public function handle(): int
    {
        try {
            $dataset = $this->argument('dataset');
            if (! in_array($dataset, ['schedules', 'injuries', 'evidence'], true)) {
                throw new RuntimeException('Unknown dataset.');
            }
            $from = (int) $this->option('from-season');
            $to = $this->option('to-season') ? (int) $this->option('to-season') : (int) now()->year;
            if ($from < 2023 || $to < $from) {
                throw new RuntimeException('Use a valid season range starting in 2023 or later.');
            }
            $url = $this->option('source-url');
            if ($dataset !== 'evidence' && ! filter_var($url, FILTER_VALIDATE_URL)) {
                throw new RuntimeException('--source-url is required for CSV evidence.');
            }
            $schedules = $this->csv($dataset === 'schedules' ? $this->argument('file') : (string) $this->option('schedules'));
            $teams = Team::query()->get()->keyBy(fn ($team) => $this->team($team->abbreviation));
            $games = Game::query()->whereBetween('season', [$from, $to])->get();
            $matches = [];
            $facts = [];
            $unmatched = 0;
            foreach ($schedules as $row) {
                if ((int) $row['season'] < $from || (int) $row['season'] > $to) {
                    continue;
                }
                $home = $teams->get($this->team($row['home_team']));
                $away = $teams->get($this->team($row['away_team']));
                if (! $home || ! $away) {
                    $unmatched++;

                    continue;
                }
                $candidates = $games->filter(function ($game) use ($row, $home, $away) {
                    if ((int) $game->season !== (int) $row['season'] || (int) $game->home_team_id !== $home->id || (int) $game->away_team_id !== $away->id) {
                        return false;
                    }
                    // Both UTC dates and US-local dates occur in historical imports.
                    $day = substr($game->getRawOriginal('game_date'), 0, 10);

                    return in_array($day, [$row['gameday'], CarbonImmutable::parse($row['gameday'])->addDay()->toDateString()], true);
                });
                if ($candidates->count() !== 1) {
                    $unmatched++;

                    continue;
                }
                $game = $candidates->first();
                foreach (['home' => $home, 'away' => $away] as $side => $team) {
                    $key = $this->key($row['season'], $row['game_type'], $row['week'], $team->abbreviation);
                    if (isset($matches[$key])) {
                        throw new RuntimeException("Ambiguous schedule key: {$key}");
                    }
                    $matches[$key] = [$game, $team];
                    if ($dataset === 'schedules' && trim($row[$side.'_coach'] ?? '') !== '') {
                        $facts[] = $this->fact($game, $team->id, [
                            'kind' => 'head_coach', 'subject' => $row[$side.'_coach'], 'source_url' => $url,
                        ], $row);
                    }
                }
            }
            if ($dataset !== 'schedules') {
                $rows = $dataset === 'injuries' ? $this->csv($this->argument('file')) : json_decode(file_get_contents($this->argument('file')), true, flags: JSON_THROW_ON_ERROR);
                foreach ($rows as $row) {
                    if ((int) ($row['season'] ?? 0) < $from || (int) ($row['season'] ?? 0) > $to) {
                        continue;
                    }
                    $match = $matches[$this->key($row['season'], $row['game_type'], $row['week'], $row['team'])] ?? null;
                    if (! $match) {
                        $unmatched++;

                        continue;
                    }
                    [$game, $team] = $match;
                    $values = $dataset === 'evidence' ? $row : [
                        'kind' => 'injury_report', 'subject' => $row['full_name'], 'gsis_id' => $row['gsis_id'] ?: null,
                        'position' => $row['position'], 'designation' => $row['report_status'] ?: null,
                        'injury' => ($row['report_primary_injury'] ?: $row['practice_primary_injury']) ?: null,
                        'published_at' => $row['date_modified'] ?: null, 'source_url' => $url,
                    ];
                    $facts[] = $this->fact($game, $team->id, $values, $row);
                }
            }
            $added = 0;
            DB::transaction(function () use ($facts, &$added) {
                foreach ($facts as $fact) {
                    if ($this->option('dry-run')) {
                        $added += (int) ! GameContextFact::where('evidence_hash', $fact['evidence_hash'])->exists();
                    } else {
                        $added += (int) GameContextFact::firstOrCreate(['evidence_hash' => $fact['evidence_hash']], $fact)->wasRecentlyCreated;
                    }
                }
            });
            $this->info(count($facts)." matched facts; {$added} new; {$unmatched} unmatched rows".($this->option('dry-run') ? ' (dry run)' : ''));
            $this->warn('Injury records are partial evidence, not a complete availability roster. Missing records mean unknown.');

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    private function fact(Game $game, int $teamId, array $values, array $evidence): array
    {
        Validator::make($values, [
            'kind' => 'required|in:head_coach,injury_report,participation',
            'subject' => 'required|string|max:255', 'source_url' => 'required|url',
            'published_at' => 'nullable|date', 'designation' => 'nullable|string|max:255',
            'participation' => 'nullable|in:played,did_not_play,inactive,emergency_qb',
            'key_reason' => 'nullable|string|max:255',
        ])->validate();
        if (($values['kind'] === 'participation') !== ! empty($values['participation'])) {
            throw new RuntimeException('Participation must be a separate sourced fact.');
        }
        $fact = ['game_id' => $game->id, 'team_id' => $teamId];
        foreach (['kind', 'subject', 'gsis_id', 'position', 'designation', 'injury', 'participation', 'key_reason', 'source_url'] as $field) {
            $fact[$field] = $values[$field] ?? null;
        }
        $fact['published_at'] = empty($values['published_at']) ? null : CarbonImmutable::parse($values['published_at'], 'UTC')->setTimezone(config('app.timezone'))->toDateTimeString();
        $fact['evidence'] = $evidence;
        $fact['evidence_hash'] = hash('sha256', json_encode($fact, JSON_THROW_ON_ERROR));
        $fact['recorded_at'] = now();

        return $fact;
    }

    private function csv(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException("CSV file not readable: {$path}");
        }
        $handle = fopen($path, 'r');
        try {
            $headers = fgetcsv($handle, escape: '');
            if (! is_array($headers)) {
                throw new RuntimeException('Missing CSV header.');
            }
            $rows = [];
            while (($values = fgetcsv($handle, escape: '')) !== false) {
                if ($values === [null]) {
                    continue;
                }
                if (count($headers) !== count($values)) {
                    throw new RuntimeException('Malformed CSV row.');
                }
                $rows[] = array_combine($headers, $values);
            }

            return $rows;
        } finally {
            fclose($handle);
        }
    }

    private function key($season, $type, $week, $team): string
    {
        return implode('|', [$season, strtoupper($type), $week, $this->team($team)]);
    }

    private function team(string $team): string
    {
        return match (strtoupper(trim($team))) {
            'WAS' => 'WSH', 'ARZ' => 'ARI', 'LA', 'STL' => 'LAR', 'JAC' => 'JAX', 'OAK' => 'LV', 'SD' => 'LAC', default => strtoupper(trim($team)),
        };
    }
}
