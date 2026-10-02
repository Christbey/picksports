<?php

namespace App\Services\NFL\Matchups;

use App\Models\NFL\Game;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class NflMatchupWinRates
{
    public const COLUMNS = ['PRWR' => ['pass_rush_win_rate', 'defense'], 'RSWR' => ['run_stop_win_rate', 'defense'], 'PBWR' => ['pass_block_win_rate', 'offense'], 'RBWR' => ['run_block_win_rate', 'offense']];

    public function parse(string $html, int $season): array
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML($html);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $xpath = new DOMXPath($document);
        $title = $xpath->query('//h1')->item(0)?->textContent ?? '';
        if (! str_contains($title, (string) $season) || ! str_contains(strtolower($title), 'win rate')) {
            throw new RuntimeException('Win-rate source season/title does not match.');
        }
        $updated = [];
        foreach ($xpath->query('//p') as $paragraph) {
            $text = trim($paragraph->textContent);
            if (str_starts_with($text, 'Last updated:')) {
                $updated[] = $text;
            }
        }
        if (count($updated) !== 1 || ! preg_match('/^Last updated: Through Week (\d{1,2}) games, ([A-Za-z.]+) (\d{1,2}) at (\d{1,2})(?::(\d{2}))? ([ap])\.m\. ET$/', $updated[0], $date)) {
            throw new RuntimeException('Win-rate source has no unambiguous covered week and publication time.');
        }
        $week = (int) $date[1];
        $month = ['Sep' => 9, 'Oct' => 10, 'Nov' => 11, 'Dec' => 12, 'Jan' => 1][substr($date[2], 0, 3)] ?? null;
        if ($week < 1 || $week > 18 || $month === null || (int) $date[4] < 1 || (int) $date[4] > 12) {
            throw new RuntimeException('Invalid regular-season win-rate period.');
        }
        $timestamp = CarbonImmutable::createSafe($season + ($month === 1 ? 1 : 0), $month, (int) $date[3], (int) $date[4] % 12 + ($date[6] === 'p' ? 12 : 0), (int) ($date[5] ?: 0), 0, 'America/New_York')->utc();
        if ($timestamp->isFuture()) {
            throw new RuntimeException('Win-rate source publication time is in the future.');
        }
        $tables = [];
        foreach ($xpath->query('//table') as $table) {
            $headers = [];
            foreach ($xpath->query('.//tr[1]/th', $table) as $cell) {
                $headers[] = strtoupper(trim($cell->textContent));
            }
            if ($headers === ['TEAM', ...array_keys(self::COLUMNS)]) {
                $tables[] = $table;
            }
        }
        if (count($tables) !== 1) {
            throw new RuntimeException('Expected exactly one team win-rate table.');
        }
        $teams = [];
        foreach ($xpath->query('.//tr[td]', $tables[0]) as $row) {
            $cells = [];
            foreach ($xpath->query('./td', $row) as $cell) {
                $cells[] = trim($cell->textContent);
            }
            if (count($cells) !== 5 || isset($teams[$cells[0]]) || $cells[0] === '') {
                throw new RuntimeException('Invalid or duplicate win-rate team row.');
            }
            $values = [];
            foreach (array_keys(self::COLUMNS) as $index => $column) {
                if (! preg_match('/^(\d+(?:\.\d+)?)%\s*\((\d+)\)$/', $cells[$index + 1], $match) || (float) $match[1] > 100 || (int) $match[2] < 1 || (int) $match[2] > 32) {
                    throw new RuntimeException('Invalid win-rate percentage or rank.');
                }
                $values[$column] = ['value' => (float) $match[1] / 100, 'rank' => (int) $match[2]];
            }
            $teams[$cells[0]] = $values;
        }
        if (count($teams) !== 32) {
            throw new RuntimeException('Win-rate rankings require exactly 32 teams.');
        }
        foreach (array_keys(self::COLUMNS) as $column) {
            $groups = collect($teams)->groupBy(fn ($row) => $row[$column]['rank'])->sortKeys();
            $nextRank = 1;
            $previousValue = 1.0;
            foreach ($groups as $rank => $group) {
                $values = $group->map(fn ($row) => $row[$column]['value']);
                if ((int) $rank !== $nextRank || $values->max() > $previousValue || $values->min() !== $values->max()) {
                    throw new RuntimeException('Win-rate ranks are incomplete or inconsistent with percentages.');
                }
                $previousValue = $values->min();
                $nextRank += $group->count();
            }
        }

        return ['season' => $season, 'through_week' => $week, 'source_updated_at' => $timestamp->toDateTimeString(), 'teams' => $teams];
    }

    public function metrics(Collection $games, ?CarbonImmutable $cutoff, int $season): array
    {
        if ($cutoff === null || $games->isEmpty()) {
            return [];
        }
        $asOf = $cutoff->min(CarbonImmutable::now())->utc();
        $snapshot = DB::table('nfl_matchup_win_rate_snapshots')->where('season', $season)
            ->where('through_week', (int) $games->max('week'))->where('source_updated_at', '<', $asOf)
            ->where('observed_at', '<', $asOf)->where('source_updated_at', '>=', $asOf->subDays(14))
            ->orderByDesc('source_updated_at')->orderByDesc('id')->first();
        if ($snapshot === null) {
            return [];
        }
        $scheduled = Game::query()->where('season', $season)->whereIn('season_type', ['2', 'regular', 'REG'])
            ->whereBetween('week', [1, $snapshot->through_week])->pluck('id');
        if ($scheduled->diff($games->keys())->isNotEmpty() || $games->contains(fn ($game) => CarbonImmutable::parse($game->game_date)->gte(CarbonImmutable::parse($snapshot->source_updated_at, 'UTC')))) {
            return [];
        }
        $teams = json_decode($snapshot->teams, true, flags: JSON_THROW_ON_ERROR);
        $expected = [];
        foreach ($games as $game) {
            foreach ([$game->home_team_id, $game->away_team_id] as $team) {
                $expected[$team][] = (int) $game->id;
            }
        }
        if (count($teams) !== 32 || count($expected) !== 32 || array_diff(array_keys($teams), array_keys($expected)) || collect($expected)->contains(fn ($ids) => count($ids) < 2)) {
            return [];
        }
        $result = [];
        foreach (self::COLUMNS as $column => [$metric, $side]) {
            $ranks = array_count_values(array_map(fn ($row) => $row[$column]['rank'], $teams));
            foreach ($teams as $team => $values) {
                $sample = $values[$column];
                $result[$metric][$side][$team] = [...$sample, 'rank_end' => $sample['rank'] + $ranks[$sample['rank']] - 1,
                    'eligible' => true, 'league_teams' => 32, 'games' => count($expected[$team]), 'game_ids' => $expected[$team], 'plays' => null,
                    'through_week' => (int) $snapshot->through_week, 'source_updated_at' => $snapshot->source_updated_at,
                    'observed_at' => $snapshot->observed_at, 'source_url' => $snapshot->source_url,
                    'display_value' => number_format($sample['value'] * 100, 0).'% • through Week '.$snapshot->through_week.' ('.CarbonImmutable::parse($snapshot->source_updated_at, 'UTC')->format('M j').')'];
            }
        }

        return $result;
    }
}
