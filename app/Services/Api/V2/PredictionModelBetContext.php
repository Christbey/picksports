<?php

namespace App\Services\Api\V2;

use App\Models\CanonicalPrediction;
use App\Models\MarketQuote;
use App\Models\OddsApiTeamMapping;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Market references for model directions, independent of bet approval. */
class PredictionModelBetContext
{
    private const ODDS_SPORT_KEYS = [
        'cbb' => 'basketball_ncaab',
        'wcbb' => 'basketball_wncaab',
        'nba' => 'basketball_nba',
        'wnba' => 'basketball_wnba',
        'cfb' => 'americanfootball_ncaaf',
        'nfl' => 'americanfootball_nfl',
        'mlb' => 'baseball_mlb',
    ];

    public const EMPTY_CONTEXT = ['home_spread' => null, 'total' => null, 'captured_at' => null];

    public function forPrediction(CanonicalPrediction $prediction, ?Model $game): array
    {
        $kickoff = $prediction->sportEvent?->starts_at?->copy()->setTimezone(config('app.timezone'));
        if (! $game || ! $kickoff) {
            return self::EMPTY_CONTEXT;
        }
        $quotes = $this->quoteQuery($prediction->sport, $game->getTable())
            ->where('game_id', $game->getKey())->where('captured_at', '<', $kickoff)->limit(200)->get();

        return $this->marketContext($prediction->sport, $game, $quotes, $this->teamAliases($prediction->sport, $quotes));
    }

    /** @param Collection<int, CanonicalPrediction> $predictions */
    public function forDashboardBatch(string $sport, Collection $predictions): array
    {
        $games = [];
        $kickoffs = [];
        foreach ($predictions as $prediction) {
            $event = $prediction->sportEvent;
            $game = $event?->getRelation($sport.'Game');
            if ($game && $event->starts_at) {
                $games[$game->getKey()] = $game;
                $kickoffs[$game->getKey()] = $event->starts_at->setTimezone(config('app.timezone'));
            }
        }

        if ($games === []) {
            return [];
        }

        $quotes = $this->quoteQuery($sport, reset($games)->getTable())
            ->where(function ($query) use ($kickoffs): void {
                foreach ($kickoffs as $gameId => $kickoff) {
                    $query->orWhere(fn ($gameQuery) => $gameQuery->where('game_id', $gameId)->where('captured_at', '<', $kickoff));
                }
            })
            ->groupLimit(200, 'game_id')
            ->get(['id', 'game_id', 'game_odds_snapshot_id', 'market_key', 'side', 'line', 'participant', 'captured_at'])
            ->groupBy('game_id');

        $aliases = $this->teamAliases($sport, $quotes->flatten(1));
        $contexts = [];
        foreach ($quotes as $gameId => $gameQuotes) {
            $contexts[$gameId] = $this->marketContext($sport, $games[$gameId], $gameQuotes, $aliases);
        }

        return $contexts;
    }

    private function quoteQuery(string $sport, string $table): Builder
    {
        return MarketQuote::where('sport', $sport)->where('game_table', $table)->where('is_pregame', true)
            ->whereIn('market_key', ['spreads', 'totals'])->where('captured_at', '<=', now())
            ->orderByDesc('captured_at')->orderByDesc('id');
    }

    private function teamAliases(string $sport, Collection $quotes): array
    {
        $sportKey = self::ODDS_SPORT_KEYS[$sport] ?? null;
        $participants = $quotes->where('market_key', 'spreads')->pluck('participant')
            ->filter()->map(fn (string $name): string => strtolower(trim($name)))->unique()->values();

        if ($sportKey === null || $participants->isEmpty()) {
            return [];
        }

        return OddsApiTeamMapping::query()->where('sport', $sportKey)
            ->whereNotNull('espn_team_name')->whereIn(DB::raw('LOWER(odds_api_team_name)'), $participants)
            ->get(['odds_api_team_name', 'espn_team_name'])
            ->groupBy(fn (OddsApiTeamMapping $mapping): string => strtolower(trim($mapping->odds_api_team_name)))
            ->map(fn (Collection $mappings): array => $mappings->pluck('espn_team_name')
                ->map(fn (string $name): string => strtolower(trim($name)))->unique()->values()->all())
            ->all();
    }

    private function matchesTeam(string $sport, ?string $participant, array $expected, array $opponent, array $aliases): bool
    {
        $name = strtolower(trim((string) $participant));
        $mappedNames = $aliases[$name] ?? null;
        if ($mappedNames !== null) {
            return count($mappedNames) === 1
                && in_array($mappedNames[0], $expected, true)
                && ! in_array($mappedNames[0], $opponent, true);
        }

        if (in_array($name, $expected, true) && ! in_array($name, $opponent, true)) {
            return true;
        }

        if ($sport !== 'wcbb') {
            return false;
        }

        // A school's "State" suffix can appear as "St" before the mascot.
        $normalizeState = fn (string $value): string => preg_replace('/(?<= )st\.?(?= |$)/', 'state', $value);
        $normalized = $normalizeState($name);

        return in_array($normalized, array_map($normalizeState, $expected), true)
            && ! in_array($normalized, array_map($normalizeState, $opponent), true);
    }

    private function marketContext(string $sport, Model $game, Collection $quotes, array $aliases): array
    {
        $context = self::EMPTY_CONTEXT;
        $names = fn ($team) => collect([
            $team?->display_name,
            $team?->name,
            $team?->school,
            $team?->short_display_name,
            trim(($team?->school ?? '').' '.($team?->mascot ?? '')),
            $team?->location && $team?->name ? $team->location.' '.$team->name : null,
        ])->filter()->map(fn ($name) => strtolower(trim($name)))->all();
        $homeNames = $names($game->homeTeam);
        $awayNames = $names($game->awayTeam);
        foreach ($quotes->groupBy('game_odds_snapshot_id') as $snapshot) {
            $home = $snapshot->first(fn ($q) => $q->market_key === 'spreads' && $q->side === 'home');
            $away = $snapshot->first(fn ($q) => $q->market_key === 'spreads' && $q->side === 'away');
            if (! $home || ! $away || ! is_numeric($home->line) || ! is_numeric($away->line)
                || abs((float) $home->line + (float) $away->line) > 0.001
                || ! $this->matchesTeam($sport, $home->participant, $homeNames, $awayNames, $aliases)
                || ! $this->matchesTeam($sport, $away->participant, $awayNames, $homeNames, $aliases)) {
                continue;
            }
            $context['home_spread'] ??= (float) $home->line;
            $context['captured_at'] ??= $home->captured_at?->toIso8601String();
            $over = $snapshot->first(fn ($q) => $q->market_key === 'totals' && $q->side === 'over');
            $under = $snapshot->first(fn ($q) => $q->market_key === 'totals' && $q->side === 'under');
            if ($over && $under && is_numeric($over->line) && is_numeric($under->line)
                && (float) $over->line === (float) $under->line) {
                $context['total'] ??= (float) $over->line;
            }
            if ($context['total'] !== null) {
                break;
            }
        }

        return $context;
    }
}
