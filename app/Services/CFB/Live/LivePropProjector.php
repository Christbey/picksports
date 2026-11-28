<?php

namespace App\Services\CFB\Live;

use App\Models\CFB\Game;
use App\Models\CFB\LivePredictionSnapshot;
use App\Models\CFB\PlayerProp;
use App\Models\CFB\PlayerStat;
use App\Services\BettingRecommendations\CfbPropEligibility;
use Carbon\CarbonInterface;

class LivePropProjector
{
    public const FIELDS = ['player_pass_yds' => 'passing_yards', 'player_rush_yds' => 'rushing_yards', 'player_reception_yds' => 'receiving_yards'];

    public function project(Game $game, ?array $projection, ?array $response, bool $statsObserved, ?CarbonInterface $statsObservedSince = null): array
    {
        $kickoff = CfbPropEligibility::kickoff($game)?->setTimezone(config('app.timezone'));
        if ($kickoff === null) {
            $stats = $statsObserved ? PlayerStat::where('game_id', $game->id)
                ->whereIn('team_id', [$game->home_team_id, $game->away_team_id])
                ->when($statsObservedSince, fn ($query) => $query->where('updated_at', '>=', $statsObservedSince))
                ->get()->keyBy('player_id') : collect();

            return PlayerProp::where('game_id', $game->id)->whereNotNull('player_id')
                ->whereIn('market', array_keys(self::FIELDS))
                ->whereHas('player', fn ($query) => $query->whereIn('team_id', [$game->home_team_id, $game->away_team_id]))
                ->select(['id', 'player_id', 'player_name', 'market'])->orderByDesc('fetched_at')->orderByDesc('id')->get()
                ->unique(fn ($prop) => $prop->player_id.':'.$prop->market)->map(function ($prop) use ($stats, $game): array {
                    $actual = $stats->get($prop->player_id)?->{self::FIELDS[$prop->market]};
                    $final = $game->status === 'STATUS_FINAL' && is_numeric($actual);

                    return ['pregame_prop_id' => null, 'player_id' => $prop->player_id, 'player' => $prop->player_name,
                        'market' => $prop->market, 'pregame_line' => null, 'pregame_mean' => null, 'sample' => 0,
                        'actual' => is_numeric($actual) ? (float) $actual : null, 'projected' => $final ? (float) $actual : null,
                        'status' => $final ? 'final' : 'kickoff_unknown', 'quotes' => []];
                })->values()->all();
        }
        $pregame = PlayerProp::where('game_id', $game->id)->whereNotNull('player_id')
            ->where('fetched_at', '<', $kickoff)
            ->where('id', PlayerProp::query()->from('cfb_player_props as candidate')->select('candidate.id')
                ->whereColumn('candidate.game_id', 'cfb_player_props.game_id')
                ->whereColumn('candidate.player_id', 'cfb_player_props.player_id')
                ->whereColumn('candidate.market', 'cfb_player_props.market')
                ->where('candidate.fetched_at', '<', $kickoff)
                ->orderByDesc('candidate.fetched_at')->orderByDesc('candidate.id')->limit(1))
            ->with(['player' => fn ($query) => $query->withExists([
                'activeInjuries as unavailable' => fn ($injuries) => $injuries->whereRaw("LOWER(status) NOT IN ('active', 'available', 'probable')"),
            ])])->latest('fetched_at')->get();
        $first = LivePredictionSnapshot::where('game_id', $game->id)->where('source', 'live_feed')->oldest('id')->first();
        $baselines = collect($first?->props ?? [])->keyBy(fn ($p) => $p['player_id'].':'.$p['market']);
        $stats = $statsObserved ? PlayerStat::where('game_id', $game->id)->when($statsObservedSince, fn ($q) => $q->where('updated_at', '>=', $statsObservedSince))->get()->keyBy('player_id') : collect();
        $histories = [];
        foreach (self::FIELDS as $market => $field) {
            $needed = $pregame->where('market', $market)->filter(function ($prop) use ($baselines) {
                $baseline = $baselines->get($prop->player_id.':'.$prop->market);

                return $prop->player && ($baseline === null || ! isset($baseline['sample']));
            });
            if ($needed->isEmpty()) {
                continue;
            }
            $histories[$field] = PlayerStat::query()->whereIn('player_id', $needed->pluck('player_id')->unique())
                ->whereIn('team_id', [$game->home_team_id, $game->away_team_id])->whereNotNull($field)
                ->whereHas('game', fn ($query) => $query->where('status', 'STATUS_FINAL')->whereDate('game_date', '<', $game->game_date)
                    ->where('season', '>=', (int) $game->season - 1)->whereIn('season_type', [2, 3]))
                ->select(['player_id', 'team_id'])->selectRaw('COUNT(*) as sample, AVG('.$field.') as mean')
                ->groupBy('player_id', 'team_id')->get()->keyBy(fn ($row) => $row->player_id.':'.$row->team_id);
        }
        $rows = [];
        foreach ($pregame as $prop) {
            $field = self::FIELDS[$prop->market] ?? null;
            if (! $field || ! $prop->player || ! in_array($prop->player->team_id, [$game->home_team_id, $game->away_team_id], true)) {
                continue;
            }
            $baseline = $baselines->get($prop->player_id.':'.$prop->market);
            $history = ($histories[$field] ?? collect())->get($prop->player_id.':'.$prop->player->team_id);
            $sample = (int) ($history?->getAttribute('sample') ?? 0);
            $mean = $baseline !== null ? $baseline['pregame_mean'] : ($sample >= 3 ? round((float) $history->getAttribute('mean'), 2) : null);
            $actual = $stats->get($prop->player_id)?->{$field};
            $row = ['pregame_prop_id' => $prop->id, 'player_id' => $prop->player_id, 'player' => $prop->player_name, 'market' => $prop->market,
                'pregame_line' => (float) $prop->line, 'pregame_mean' => $mean, 'sample' => $baseline['sample'] ?? $sample,
                'actual' => is_numeric($actual) ? (float) $actual : null, 'projected' => null, 'status' => 'missing_live_stats', 'quotes' => []];
            $unavailable = (bool) $prop->player->getAttribute('unavailable');
            if ($game->status === 'STATUS_FINAL' && is_numeric($actual)) {
                $row['projected'] = (float) $actual;
                $row['status'] = 'final';
            } elseif ($unavailable) {
                $row['status'] = 'availability_uncertain';
            } elseif ($mean === null) {
                $row['status'] = 'insufficient_history';
            } elseif ((int) $game->period > 4) {
                $row['status'] = 'overtime_player_projection_unavailable';
            } elseif (! $projection) {
                $row['status'] = 'game_projection_unavailable';
            } elseif (is_numeric($actual)) {
                $fraction = max(0, min(1, 1 - $projection['seconds_remaining'] / 3600));
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
                        $difference = $fresh && $row['status'] === 'experimental_projection' && is_numeric($over['price'] ?? null) && is_numeric($under['price'] ?? null) && abs($over['price']) >= 100 && abs($under['price']) >= 100
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
