<?php

namespace App\Services\NFL;

use App\Models\GameOddsSnapshot;
use App\Models\NFL\Game;

/** Read-only manifest. Never rewrites quotes, canonical events, forecasts or grades. */
final class NflverseArchiveRepairPlanner
{
    public function run(int $from, int $to, bool $detailed = false): array
    {
        if ($from < 2000 || $to > 2100 || $to < $from) {
            throw new \InvalidArgumentException('Invalid season range');
        }
        $report = ['version' => 'nflverse_archive_repair_plan_v1', 'from_season' => $from, 'to_season' => $to,
            'generated_at' => now()->toIso8601String(), 'production_modified' => false, 'apply_allowed' => false,
            'snapshots' => 0, 'statuses' => [], 'timezone_requires_original_source' => 0, 'items' => [],
            'next_step' => 'Review original schedule CSV, backup originals, reconcile kickoff/market dependencies, and approve a separately implemented transactional repair. Do not regenerate historical predictions.'];
        GameOddsSnapshot::query()->where('sport', 'nfl')->where('game_table', 'nfl_games')->where('source', 'nflverse')
            ->whereIn('game_id', Game::query()->select('id')->whereBetween('season', [$from, $to]))
            ->chunkById(100, function ($snapshots) use (&$report, $detailed) {
                foreach ($snapshots as $snapshot) {
                    $item = $this->inspect($snapshot);
                    $report['snapshots']++;
                    $report['statuses'][$item['status']] = ($report['statuses'][$item['status']] ?? 0) + 1;
                    $report['timezone_requires_original_source'] += (int) $item['timezone_requires_original_source'];
                    if ($detailed) {
                        $report['items'][] = $item;
                    }
                }
            });

        return $report;
    }

    public function inspect(GameOddsSnapshot $snapshot): array
    {
        $context = $snapshot->market_context ?? [];
        $payload = $snapshot->odds_data ?? [];
        $item = ['snapshot_id' => $snapshot->id, 'game_id' => $snapshot->game_id, 'status' => 'blocked_invalid_source',
            'expected_original_payload_hash' => $snapshot->payload_hash,
            'original' => ['odds_data' => $payload, 'market_context' => $context,
                'captured_at' => $snapshot->captured_at?->toIso8601String(),
                'commence_time' => $snapshot->commence_time?->toIso8601String()],
            'timezone_requires_original_source' => ($context['normalization_version'] ?? null) !== 'nflverse_schedule_v2'];
        $source = $context['spread_line'] ?? null;
        if ($snapshot->sport !== 'nfl' || $snapshot->game_table !== 'nfl_games' || $snapshot->source !== 'nflverse'
            || $snapshot->bookmaker_key !== 'nflverse_closing' || ! is_numeric($source) || ! is_finite((float) $source)) {
            return $item;
        }
        $books = $payload['bookmakers'] ?? [];
        if (count($books) !== 1 || ($books[0]['key'] ?? null) !== 'nflverse_closing') {
            $item['status'] = 'blocked_ambiguous_bookmaker';

            return $item;
        }
        $spreads = array_filter($books[0]['markets'] ?? [], fn ($market) => ($market['key'] ?? null) === 'spreads');
        if (count($spreads) !== 1) {
            $item['status'] = 'blocked_missing_or_duplicate_spread';

            return $item;
        }
        $marketIndex = array_key_first($spreads);
        $outcomes = $spreads[$marketIndex]['outcomes'] ?? [];
        $home = $payload['home_team'] ?? null;
        $away = $payload['away_team'] ?? null;
        $homeIndex = array_keys(array_filter($outcomes, fn ($outcome) => ($outcome['name'] ?? null) === $home));
        $awayIndex = array_keys(array_filter($outcomes, fn ($outcome) => ($outcome['name'] ?? null) === $away));
        if (! $home || ! $away || $home === $away || count($outcomes) !== 2 || count($homeIndex) !== 1 || count($awayIndex) !== 1) {
            $item['status'] = 'blocked_ambiguous_teams';

            return $item;
        }
        $homePoint = $outcomes[$homeIndex[0]]['point'] ?? null;
        $awayPoint = $outcomes[$awayIndex[0]]['point'] ?? null;
        if (! is_numeric($homePoint) || ! is_numeric($awayPoint) || ! is_finite((float) $homePoint) || ! is_finite((float) $awayPoint)
            || abs((float) $homePoint + (float) $awayPoint) > 0.00001
            || abs(abs((float) $homePoint) - abs((float) $source)) > 0.00001) {
            $item['status'] = 'blocked_conflicting_spread';

            return $item;
        }
        $correctHome = -(float) $source;
        $needsSign = abs((float) $homePoint - $correctHome) > 0.00001;
        $item['status'] = $needsSign ? 'spread_correction_required'
            : ($item['timezone_requires_original_source'] ? 'provenance_review_required' : 'already_normalized');
        $payload['bookmakers'][0]['markets'][$marketIndex]['outcomes'][$homeIndex[0]]['point'] = $correctHome;
        $payload['bookmakers'][0]['markets'][$marketIndex]['outcomes'][$awayIndex[0]]['point'] = (float) $source;
        $item['proposed_spread_payload'] = $payload;
        $item['proposed_home_handicap'] = $correctHome;
        $item['source_home_margin'] = (float) $source;

        // Timestamp corrections cannot be inferred safely from synthetic old capture times.
        // No write or executable apply mode exists in this planner.
        return $item;
    }
}
