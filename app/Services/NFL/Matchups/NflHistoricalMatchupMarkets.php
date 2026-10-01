<?php

namespace App\Services\NFL\Matchups;

use App\Models\GameOddsSnapshot;
use App\Services\Sports\SportsDateWindowService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Retrospective closing-line records, never an as-known-at-kickoff backtest. */
final class NflHistoricalMatchupMarkets
{
    public function forGames(Collection $games): array
    {
        $markets = [];
        $games = $games->keyBy('id');
        foreach (GameOddsSnapshot::query()->where('sport', 'nfl')->where('game_table', 'nfl_games')
            ->where('source', 'nflverse')->where('bookmaker_key', 'nflverse_closing')
            ->whereIn('game_id', $games->keys())->orderByDesc('id')->get() as $snapshot) {
            if (isset($markets[$snapshot->game_id])) {
                continue;
            }
            $context = $snapshot->market_context ?? [];
            if (($context['source'] ?? null) !== 'nflverse_schedules'
                || ($context['line_type'] ?? null) !== 'closing'
                || ($context['normalization_version'] ?? null) !== 'nflverse_schedule_v2'
                || ($context['source_spread_convention'] ?? null) !== 'home_margin_positive_home_favored'
                || ($context['capture_time_is_synthetic'] ?? null) !== true) {
                continue;
            }
            $game = $games->get($snapshot->game_id);
            $kickoff = app(SportsDateWindowService::class)->gameDateTimeUtc($game->getRawOriginal('game_date'), $game->game_time);
            if (! $kickoff || ($context['source_gameday'] ?? null) !== $kickoff->copy()->setTimezone('America/New_York')->toDateString()
                || ! $snapshot->commence_time || ! $snapshot->commence_time->equalTo($kickoff)) {
                continue;
            }
            $spread = $context['spread_line'] ?? null;
            $total = $context['total_line'] ?? null;
            $markets[$snapshot->game_id] = [
                'home_handicap' => is_numeric($spread) && abs((float) $spread) <= 60 ? -(float) $spread : null,
                'total' => is_numeric($total) && (float) $total > 0 && (float) $total < 150 ? (float) $total : null,
                'snapshot_id' => $snapshot->id,
                'source' => 'nflverse_closing_archive',
                'mode' => 'retrospective_closing_line_record',
                'observed_at' => $snapshot->created_at?->toIso8601String(),
            ];
        }

        foreach (DB::table('nfl_matchup_schedule_evidence')->whereIn('game_id', $games->keys())->orderBy('id')->get() as $row) {
            $evidence = json_decode($row->evidence, true);
            if (($evidence['source'] ?? null) !== 'nflverse_schedule_verified') {
                continue;
            }
            $markets[$row->game_id] = [...$evidence, 'evidence_id' => $row->id, 'source_sha256' => $row->source_sha256, 'observed_at' => $row->observed_at];
        }

        return $markets;
    }
}
