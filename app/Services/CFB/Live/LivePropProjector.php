<?php

namespace App\Services\CFB\Live;

use App\Models\CFB\Game;
use App\Models\CFB\LivePredictionSnapshot;
use App\Models\CFB\PlayerProp;
use App\Models\CFB\PlayerStat;
use App\Services\BettingRecommendations\CfbPropEligibility;

class LivePropProjector
{
    public const FIELDS = ['player_pass_yds' => 'passing_yards', 'player_rush_yds' => 'rushing_yards', 'player_reception_yds' => 'receiving_yards'];

    public function project(Game $game, ?array $projection, ?array $response, bool $statsObserved): array
    {
        $pregame = PlayerProp::where('game_id', $game->id)->whereNotNull('player_id')
            ->where('fetched_at', '<', CfbPropEligibility::kickoff($game)->setTimezone(config('app.timezone')))
            ->with('player')->latest('fetched_at')->get()->unique(fn ($p) => $p->player_id.':'.$p->market);
        $first = LivePredictionSnapshot::where('game_id', $game->id)->where('source', 'live_feed')->oldest('id')->first();
        $baselines = collect($first?->props ?? [])->keyBy(fn ($p) => $p['player_id'].':'.$p['market']);
        $stats = $statsObserved ? PlayerStat::where('game_id', $game->id)->get()->keyBy('player_id') : collect();
        $rows = [];
        foreach ($pregame as $prop) {
            $field = self::FIELDS[$prop->market] ?? null;
            if (! $field || ! $prop->player || ! in_array($prop->player->team_id, [$game->home_team_id, $game->away_team_id], true)) {
                continue;
            }
            $baseline = $baselines->get($prop->player_id.':'.$prop->market);
            $history = PlayerStat::where('player_id', $prop->player_id)->where('team_id', $prop->player->team_id)->whereNotNull($field)
                ->whereHas('game', fn ($q) => $q->where('status', 'STATUS_FINAL')->whereDate('game_date', '<', $game->game_date)
                    ->where('season', '>=', (int) $game->season - 1)->whereIn('season_type', [2, 3]))->get();
            $mean = $baseline['pregame_mean'] ?? ($history->count() >= 3 ? round((float) $history->avg($field), 2) : null);
            $actual = $stats->get($prop->player_id)?->{$field};
            $row = ['pregame_prop_id' => $prop->id, 'player_id' => $prop->player_id, 'player' => $prop->player_name, 'market' => $prop->market,
                'pregame_line' => (float) $prop->line, 'pregame_mean' => $mean, 'sample' => $baseline['sample'] ?? $history->count(),
                'actual' => is_numeric($actual) ? (float) $actual : null, 'projected' => null, 'status' => 'missing_live_stats', 'quotes' => []];
            $unavailable = $prop->player->activeInjuries()->whereRaw("LOWER(status) NOT IN ('active', 'available', 'probable')")->exists();
            if ($game->status === 'STATUS_FINAL' && is_numeric($actual)) {
                $row['projected'] = (float) $actual;
                $row['status'] = 'final';
            } elseif ($unavailable) {
                $row['status'] = 'availability_uncertain';
            } elseif ($mean === null) {
                $row['status'] = 'insufficient_history';
            } elseif (! $projection) {
                $row['status'] = 'game_projection_unavailable';
            } elseif (is_numeric($actual)) {
                $fraction = 1 - $projection['seconds_remaining'] / 3600;
                if ($fraction < 1 / 12) {
                    $row['status'] = 'early_game';
                } else {
                    // Blend observed production rate with the frozen prior, then add remaining regulation production.
                    $pace = (float) $actual / $fraction;
                    $weight = min(.65, $fraction);
                    $row['projected'] = round((float) $actual + max(0, ($mean * (1 - $weight) + $pace * $weight) * (1 - $fraction)), 1);
                    $row['status'] = 'experimental_projection';
                }
            }
            foreach ($response['bookmakers'] ?? [] as $book) {
                foreach ($book['markets'] ?? [] as $market) {
                    if (($market['key'] ?? '') !== $prop->market) {
                        continue;
                    }
                    $matched = collect($market['outcomes'] ?? [])->filter(fn ($o) => CfbPropEligibility::normalize($o['description'] ?? '') === CfbPropEligibility::normalize($prop->player_name));
                    foreach ($matched->filter(fn ($o) => is_numeric($o['point'] ?? null))->groupBy(fn ($o) => (string) $o['point']) as $line => $outcomes) {
                        $over = $outcomes->firstWhere('name', 'Over');
                        $under = $outcomes->firstWhere('name', 'Under');
                        $fresh = app(LiveMarketComparison::class)->fresh($market['last_update'] ?? null, $response['commence_time']);
                        $difference = $fresh && $row['status'] === 'experimental_projection' && is_numeric($over['price'] ?? null) && is_numeric($under['price'] ?? null) && $over['price'] != 0 && $under['price'] != 0
                            ? round($row['projected'] - (float) $line, 1) : null;
                        $row['quotes'][] = ['bookmaker' => $book['key'], 'line' => (float) $line, 'over_price' => $over['price'] ?? null,
                            'under_price' => $under['price'] ?? null, 'updated_at' => $market['last_update'] ?? null, 'fresh' => $fresh,
                            'difference' => $difference, 'lean' => $difference > 0 ? 'Over' : ($difference < 0 ? 'Under' : null)];
                    }
                }
            }
            $rows[] = $row;
        }

        return $rows;
    }
}
