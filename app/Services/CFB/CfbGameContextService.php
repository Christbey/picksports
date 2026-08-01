<?php

namespace App\Services\CFB;

use App\Models\CFB\Game;
use App\Models\CFB\GameContextSignal;
use App\Models\CFB\GameWeather;
use App\Models\CFB\Team;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

class CfbGameContextService
{
    /**
     * @return array{0:float,1:float,2:array<string,mixed>}
     */
    public function apply(Game $game, float $predictedSpread, float $predictedTotal): array
    {
        $metadata = $this->emptyMetadata();
        if (! (bool) config('cfb.predictions.game_context.enabled', true)) {
            $metadata['enabled'] = false;

            return [$predictedSpread, $predictedTotal, $metadata];
        }

        $weather = $this->weatherContext($game);
        $venue = $this->venueContext($game);
        $schedule = $this->scheduleContext($game, $predictedSpread);
        $persisted = $this->persistedContext($game);

        $spreadAdjustment = (float) $venue['spread_adjustment']
            + (float) $schedule['spread_adjustment']
            + (float) $persisted['spread_adjustment'];
        $totalAdjustment = (float) $weather['total_adjustment']
            + (float) $venue['total_adjustment']
            + (float) $schedule['total_adjustment']
            + (float) $persisted['total_adjustment'];
        $confidencePenalty = (float) $weather['confidence_penalty']
            + (float) $venue['confidence_penalty']
            + (float) $schedule['confidence_penalty']
            + (float) $persisted['confidence_penalty'];

        $spreadAdjustment = $this->clamp(
            $spreadAdjustment,
            (float) config('cfb.predictions.game_context.max_spread_adjustment', 2.0)
        );
        $totalAdjustment = $this->clamp(
            $totalAdjustment,
            (float) config('cfb.predictions.game_context.max_total_adjustment', 4.0)
        );
        $confidencePenalty = max(
            0.0,
            min(
                (float) config('cfb.predictions.game_context.max_confidence_penalty', 5.0),
                $confidencePenalty
            )
        );

        $metadata = [
            'enabled' => true,
            'spread_adjustment' => round($spreadAdjustment, 3),
            'total_adjustment' => round($totalAdjustment, 3),
            'confidence_penalty' => round($confidencePenalty, 2),
            'risk_flags' => array_values(array_unique([
                ...(array) ($weather['risk_flags'] ?? []),
                ...(array) ($venue['risk_flags'] ?? []),
                ...(array) ($schedule['risk_flags'] ?? []),
                ...(array) ($persisted['risk_flags'] ?? []),
            ])),
            'weather' => $weather,
            'venue' => $venue,
            'schedule' => $schedule,
            'persisted_signals' => $persisted,
        ];

        return [
            round($predictedSpread + $spreadAdjustment, 1),
            round($predictedTotal + $totalAdjustment, 1),
            $metadata,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function persistedContext(Game $game): array
    {
        $signal = $this->contextSignalForGame($game);

        if (! $signal) {
            return [
                'available' => false,
                'spread_adjustment' => 0.0,
                'total_adjustment' => 0.0,
                'confidence_penalty' => 0.0,
                'risk_flags' => [],
                'families' => [],
            ];
        }

        $families = [
            'player_availability' => [
                'spread_adjustment' => (float) $signal->player_availability_spread_adjustment,
                'total_adjustment' => (float) $signal->player_availability_total_adjustment,
                'confidence_penalty' => 0.0,
                'payload' => $signal->player_availability_payload,
            ],
            'rating_consensus' => [
                'spread_adjustment' => (float) $signal->rating_consensus_spread_adjustment,
                'total_adjustment' => 0.0,
                'confidence_penalty' => 0.0,
                'payload' => $signal->rating_consensus_payload,
            ],
            'explosiveness_success' => [
                'spread_adjustment' => (float) $signal->explosiveness_spread_adjustment,
                'total_adjustment' => (float) $signal->explosiveness_total_adjustment,
                'confidence_penalty' => 0.0,
                'payload' => $signal->explosiveness_payload,
            ],
            'line_qb_environment' => [
                'spread_adjustment' => (float) $signal->line_qb_spread_adjustment,
                'total_adjustment' => 0.0,
                'confidence_penalty' => 0.0,
                'payload' => $signal->line_qb_payload,
            ],
            'market_movement' => [
                'spread_adjustment' => (float) $signal->market_movement_spread_adjustment,
                'total_adjustment' => 0.0,
                'confidence_penalty' => (float) $signal->market_confidence_penalty,
                'payload' => $signal->market_movement_payload,
            ],
            'schedule_context' => [
                'spread_adjustment' => (float) $signal->schedule_context_spread_adjustment,
                'total_adjustment' => (float) $signal->schedule_context_total_adjustment,
                'confidence_penalty' => (float) $signal->schedule_confidence_penalty,
                'payload' => $signal->schedule_context_payload,
            ],
            'coach_scheme' => [
                'spread_adjustment' => (float) $signal->scheme_spread_adjustment,
                'total_adjustment' => (float) $signal->scheme_total_adjustment,
                'confidence_penalty' => (float) $signal->scheme_confidence_penalty,
                'payload' => $signal->scheme_payload,
            ],
            'special_teams' => [
                'spread_adjustment' => (float) $signal->special_teams_spread_adjustment,
                'total_adjustment' => (float) $signal->special_teams_total_adjustment,
                'confidence_penalty' => 0.0,
                'payload' => $signal->special_teams_payload,
            ],
        ];

        $enabledFamilies = [];
        $spreadAdjustment = 0.0;
        $totalAdjustment = 0.0;
        $confidencePenalty = 0.0;

        $appliedFamilyNames = (array) config('cfb.predictions.game_context.persisted.applied_families', ['market_movement']);

        foreach ($families as $name => $family) {
            if (! (bool) data_get($family, 'payload.available', false)) {
                continue;
            }

            $enabledFamilies[$name] = $family;
            if (! in_array($name, $appliedFamilyNames, true)) {
                continue;
            }

            $spreadAdjustment += (float) $family['spread_adjustment'];
            $totalAdjustment += (float) $family['total_adjustment'];
            $confidencePenalty += (float) $family['confidence_penalty'];
        }

        $spreadAdjustment = $this->clamp(
            $spreadAdjustment,
            (float) config('cfb.predictions.game_context.persisted.max_spread_adjustment', 3.0)
        );
        $totalAdjustment = $this->clamp(
            $totalAdjustment,
            (float) config('cfb.predictions.game_context.persisted.max_total_adjustment', 4.0)
        );
        $confidencePenalty = max(
            0.0,
            min(
                (float) config('cfb.predictions.game_context.persisted.max_confidence_penalty', 5.0),
                $confidencePenalty
            )
        );

        return [
            'available' => $enabledFamilies !== [],
            'source' => 'cfb_game_context_signals',
            'signal_id' => (int) $signal->id,
            'synced_at' => $signal->synced_at?->toIso8601String(),
            'spread_adjustment' => round($spreadAdjustment, 3),
            'total_adjustment' => round($totalAdjustment, 3),
            'confidence_penalty' => round($confidencePenalty, 2),
            'applied_families' => array_values(array_intersect(array_keys($enabledFamilies), $appliedFamilyNames)),
            'risk_flags' => abs($spreadAdjustment) > 0.0 || abs($totalAdjustment) > 0.0 || $confidencePenalty > 0.0
                ? ['persisted_context_signals_applied']
                : [],
            'families' => $enabledFamilies,
        ];
    }

    private function contextSignalForGame(Game $game): ?GameContextSignal
    {
        if ($game->relationLoaded('contextSignal')) {
            return $game->contextSignal;
        }

        if (! Schema::hasTable('cfb_game_context_signals')) {
            return null;
        }

        return GameContextSignal::query()->where('game_id', $game->id)->first();
    }

    /**
     * @return array<string,mixed>
     */
    private function weatherContext(Game $game): array
    {
        $weather = $this->weatherForGame($game);
        $indoor = $this->isIndoorVenue($game, $weather);
        $metadata = [
            'available' => $weather !== null,
            'provider' => $weather?->provider,
            'observed_at' => $weather?->observed_at?->toIso8601String(),
            'is_indoor' => $indoor,
            'signal' => $weather === null ? 'weather_not_available' : 'neutral',
            'total_adjustment' => 0.0,
            'confidence_penalty' => 0.0,
            'risk_flags' => [],
            'inputs' => [],
        ];

        if ($indoor) {
            $metadata['signal'] = 'weather_protected';
            $metadata['total_adjustment'] = round((float) config('cfb.predictions.game_context.weather.indoor_total_adjustment', 0.4), 3);

            return $metadata;
        }

        if ($weather === null) {
            return $metadata;
        }

        $temperature = $this->nullableFloat($weather->temperature_f);
        $wind = $this->nullableFloat($weather->wind_speed_mph);
        $gust = $this->nullableFloat($weather->wind_gust_mph);
        $precipitation = $this->nullableFloat($weather->precipitation_inches);
        $precipitationProbability = $this->nullableFloat($weather->precipitation_probability);
        $totalAdjustment = 0.0;
        $signals = [];
        $riskFlags = [];

        if ($wind !== null) {
            $threshold = (float) config('cfb.predictions.game_context.weather.wind_under_threshold_mph', 15.0);
            if ($wind >= $threshold) {
                $excess = $wind - $threshold;
                $totalAdjustment += $excess * (float) config('cfb.predictions.game_context.weather.wind_total_weight', -0.08);
                $signals[] = 'wind_under_signal';
                $riskFlags[] = 'wind_total_suppression';
            }
        }

        if ($gust !== null) {
            $threshold = (float) config('cfb.predictions.game_context.weather.gust_under_threshold_mph', 24.0);
            if ($gust >= $threshold) {
                $excess = $gust - $threshold;
                $totalAdjustment += $excess * (float) config('cfb.predictions.game_context.weather.gust_total_weight', -0.04);
                $signals[] = 'gust_under_signal';
                $riskFlags[] = 'wind_total_suppression';
            }
        }

        if ($precipitation !== null) {
            $threshold = (float) config('cfb.predictions.game_context.weather.precip_under_threshold_inches', 0.03);
            if ($precipitation >= $threshold) {
                $totalAdjustment += $precipitation * (float) config('cfb.predictions.game_context.weather.precip_total_weight', -18.0);
                $signals[] = 'precipitation_under_signal';
                $riskFlags[] = 'weather_turnover_risk';
            }
        }

        if ($precipitationProbability !== null) {
            $threshold = (float) config('cfb.predictions.game_context.weather.precip_probability_threshold', 55.0);
            if ($precipitationProbability >= $threshold) {
                $totalAdjustment += (float) config('cfb.predictions.game_context.weather.precip_probability_total_adjustment', -0.5);
                $signals[] = 'precipitation_probability_under_signal';
                $riskFlags[] = 'weather_turnover_risk';
            }
        }

        if ($temperature !== null) {
            if ($temperature <= (float) config('cfb.predictions.game_context.weather.cold_under_threshold_f', 32.0)) {
                $totalAdjustment += (float) config('cfb.predictions.game_context.weather.cold_total_adjustment', -0.8);
                $signals[] = 'cold_weather_under_signal';
            } elseif ($temperature >= (float) config('cfb.predictions.game_context.weather.heat_under_threshold_f', 88.0)) {
                $totalAdjustment += (float) config('cfb.predictions.game_context.weather.heat_total_adjustment', -0.4);
                $signals[] = 'heat_weather_fatigue_signal';
            }
        }

        $maxTotalAdjustment = (float) config('cfb.predictions.game_context.weather.max_total_adjustment', 4.0);
        $metadata['signal'] = $signals === [] ? 'neutral' : implode(',', array_unique($signals));
        $metadata['total_adjustment'] = round($this->clamp($totalAdjustment, $maxTotalAdjustment), 3);
        $metadata['confidence_penalty'] = $riskFlags === []
            ? 0.0
            : round((float) config('cfb.predictions.game_context.weather.adverse_weather_confidence_penalty', 1.0), 2);
        $metadata['risk_flags'] = array_values(array_unique($riskFlags));
        $metadata['inputs'] = [
            'temperature_f' => $temperature,
            'wind_speed_mph' => $wind,
            'wind_gust_mph' => $gust,
            'precipitation_inches' => $precipitation,
            'precipitation_probability' => $precipitationProbability,
            'humidity_percent' => $this->nullableFloat($weather->humidity_percent),
            'condition_code' => $weather->condition_code,
        ];

        return $metadata;
    }

    /**
     * @return array<string,mixed>
     */
    private function venueContext(Game $game): array
    {
        $neutralSite = (bool) ($game->neutral_site ?? false);
        $rivalry = $this->isConfiguredRivalry($game);
        $riskFlags = [];
        $totalAdjustment = 0.0;

        if ($neutralSite) {
            $riskFlags[] = 'neutral_site';
        }

        if ($rivalry) {
            $riskFlags[] = 'rivalry_game';
            $totalAdjustment += (float) config('cfb.predictions.game_context.venue.rivalry_total_adjustment', -0.3);
        }

        return [
            'neutral_site' => $neutralSite,
            'conference_game' => (bool) ($game->conference_game ?? false),
            'rivalry_game' => $rivalry,
            'venue_name' => $game->venue_name,
            'venue_city' => $game->venue_city,
            'venue_state' => $game->venue_state,
            'spread_adjustment' => 0.0,
            'total_adjustment' => round($totalAdjustment, 3),
            'confidence_penalty' => $rivalry
                ? round((float) config('cfb.predictions.game_context.venue.rivalry_confidence_penalty', 0.5), 2)
                : 0.0,
            'risk_flags' => $riskFlags,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function scheduleContext(Game $game, float $predictedSpread): array
    {
        $homeTeamId = (int) ($game->home_team_id ?? 0);
        $awayTeamId = (int) ($game->away_team_id ?? 0);
        $homePrevious = $this->previousGame($game, $homeTeamId);
        $awayPrevious = $this->previousGame($game, $awayTeamId);
        $homeNext = $this->nextGame($game, $homeTeamId);
        $awayNext = $this->nextGame($game, $awayTeamId);
        $homeRest = $this->restDays($homePrevious, $game);
        $awayRest = $this->restDays($awayPrevious, $game);
        $spreadAdjustment = 0.0;
        $totalAdjustment = 0.0;
        $confidencePenalty = 0.0;
        $riskFlags = [];

        if ($homeRest !== null && $awayRest !== null) {
            $restDiff = max(-4.0, min(4.0, (float) ($homeRest - $awayRest)));
            $spreadAdjustment += $restDiff * (float) config('cfb.predictions.game_context.schedule.rest_diff_weight', 0.08);
        }

        foreach ([['home', $homeRest], ['away', $awayRest]] as [$side, $rest]) {
            if ($rest === null) {
                continue;
            }

            if ($rest <= (int) config('cfb.predictions.game_context.schedule.short_rest_days', 5)) {
                $riskFlags[] = "{$side}_short_rest";
                $spreadAdjustment += $side === 'home'
                    ? (float) config('cfb.predictions.game_context.schedule.short_rest_spread_penalty', -0.25)
                    : -(float) config('cfb.predictions.game_context.schedule.short_rest_spread_penalty', -0.25);
                $totalAdjustment += (float) config('cfb.predictions.game_context.schedule.short_rest_total_adjustment', -0.2);
                $confidencePenalty += (float) config('cfb.predictions.game_context.schedule.short_rest_confidence_penalty', 0.5);
            } elseif ($rest >= (int) config('cfb.predictions.game_context.schedule.extra_rest_days', 9)) {
                $riskFlags[] = "{$side}_extra_rest";
                $spreadAdjustment += $side === 'home'
                    ? (float) config('cfb.predictions.game_context.schedule.extra_rest_spread_bonus', 0.15)
                    : -(float) config('cfb.predictions.game_context.schedule.extra_rest_spread_bonus', 0.15);
            }
        }

        if (! (bool) ($game->neutral_site ?? false)) {
            $awayRoadTripNumber = $this->roadTripGameNumber($game, $awayTeamId);
            if ($awayRoadTripNumber >= 2) {
                $riskFlags[] = 'away_consecutive_road';
                $spreadAdjustment += min(0.6, ($awayRoadTripNumber - 1) * (float) config('cfb.predictions.game_context.schedule.consecutive_road_spread_penalty', 0.25));
                $confidencePenalty += (float) config('cfb.predictions.game_context.schedule.consecutive_road_confidence_penalty', 0.5);
            }
        }

        $homeLookahead = $this->lookaheadSpot($game, $homeTeamId, $homeNext, $game->awayTeam);
        if ($homeLookahead !== null) {
            $riskFlags[] = 'home_lookahead_spot';
            $spreadAdjustment -= (float) config('cfb.predictions.game_context.schedule.lookahead_spread_penalty', 0.25);
            $confidencePenalty += (float) config('cfb.predictions.game_context.schedule.lookahead_confidence_penalty', 0.5);
        }

        $awayLookahead = $this->lookaheadSpot($game, $awayTeamId, $awayNext, $game->homeTeam);
        if ($awayLookahead !== null) {
            $riskFlags[] = 'away_lookahead_spot';
            $spreadAdjustment += (float) config('cfb.predictions.game_context.schedule.lookahead_spread_penalty', 0.25);
            $confidencePenalty += (float) config('cfb.predictions.game_context.schedule.lookahead_confidence_penalty', 0.5);
        }

        $homeLetdown = $this->letdownSpot($game, $homeTeamId, $homePrevious, $game->awayTeam);
        if ($homeLetdown !== null && $predictedSpread > 0) {
            $riskFlags[] = 'home_letdown_spot';
            $spreadAdjustment -= (float) config('cfb.predictions.game_context.schedule.letdown_spread_penalty', 0.2);
            $confidencePenalty += (float) config('cfb.predictions.game_context.schedule.letdown_confidence_penalty', 0.5);
        }

        $awayLetdown = $this->letdownSpot($game, $awayTeamId, $awayPrevious, $game->homeTeam);
        if ($awayLetdown !== null && $predictedSpread < 0) {
            $riskFlags[] = 'away_letdown_spot';
            $spreadAdjustment += (float) config('cfb.predictions.game_context.schedule.letdown_spread_penalty', 0.2);
            $confidencePenalty += (float) config('cfb.predictions.game_context.schedule.letdown_confidence_penalty', 0.5);
        }

        return [
            'spread_adjustment' => round($this->clamp(
                $spreadAdjustment,
                (float) config('cfb.predictions.game_context.schedule.max_spread_adjustment', 1.25)
            ), 3),
            'total_adjustment' => round($this->clamp(
                $totalAdjustment,
                (float) config('cfb.predictions.game_context.schedule.max_total_adjustment', 1.0)
            ), 3),
            'confidence_penalty' => round(max(0.0, $confidencePenalty), 2),
            'risk_flags' => array_values(array_unique($riskFlags)),
            'inputs' => [
                'home_rest_days' => $homeRest,
                'away_rest_days' => $awayRest,
                'home_previous_game_id' => $homePrevious?->id,
                'away_previous_game_id' => $awayPrevious?->id,
                'home_next_game_id' => $homeNext?->id,
                'away_next_game_id' => $awayNext?->id,
                'away_road_trip_game_number' => isset($awayRoadTripNumber) ? $awayRoadTripNumber : null,
                'home_lookahead_gap' => $homeLookahead,
                'away_lookahead_gap' => $awayLookahead,
                'home_letdown_gap' => $homeLetdown,
                'away_letdown_gap' => $awayLetdown,
            ],
        ];
    }

    private function weatherForGame(Game $game): ?GameWeather
    {
        if ($game->relationLoaded('weather')) {
            return $game->weather;
        }

        if (! Schema::hasTable('cfb_game_weather')) {
            return null;
        }

        return GameWeather::query()->where('game_id', $game->id)->first();
    }

    private function isIndoorVenue(Game $game, ?GameWeather $weather = null): bool
    {
        if ((bool) ($weather?->is_indoor ?? false)) {
            return true;
        }

        $venue = strtolower((string) ($game->venue_name ?? ''));
        foreach ((array) config('cfb.predictions.game_context.indoor_venue_keywords', []) as $keyword) {
            if ($keyword !== '' && str_contains($venue, strtolower((string) $keyword))) {
                return true;
            }
        }

        return false;
    }

    private function previousGame(Game $game, int $teamId): ?Game
    {
        $gameDate = $this->gameDateTime($game);
        if (! $gameDate || $teamId <= 0) {
            return null;
        }

        return Game::query()
            ->with(['homeTeam', 'awayTeam'])
            ->where('season', (int) $game->season)
            ->whereKeyNot($game->id)
            ->whereDate('game_date', '<', $gameDate->toDateString())
            ->where(function ($query) use ($teamId): void {
                $query->where('home_team_id', $teamId)
                    ->orWhere('away_team_id', $teamId);
            })
            ->orderByDesc('game_date')
            ->orderByDesc('game_time')
            ->first();
    }

    private function nextGame(Game $game, int $teamId): ?Game
    {
        $gameDate = $this->gameDateTime($game);
        if (! $gameDate || $teamId <= 0) {
            return null;
        }

        return Game::query()
            ->with(['homeTeam', 'awayTeam'])
            ->where('season', (int) $game->season)
            ->whereKeyNot($game->id)
            ->whereDate('game_date', '>', $gameDate->toDateString())
            ->where(function ($query) use ($teamId): void {
                $query->where('home_team_id', $teamId)
                    ->orWhere('away_team_id', $teamId);
            })
            ->orderBy('game_date')
            ->orderBy('game_time')
            ->first();
    }

    private function restDays(?Game $previousGame, Game $game): ?int
    {
        $previousDate = $previousGame ? $this->gameDateTime($previousGame) : null;
        $gameDate = $this->gameDateTime($game);
        if (! $previousDate || ! $gameDate) {
            return null;
        }

        return max(0, $previousDate->diffInDays($gameDate) - 1);
    }

    private function roadTripGameNumber(Game $game, int $teamId): int
    {
        if ($teamId <= 0 || (int) ($game->away_team_id ?? 0) !== $teamId || (bool) ($game->neutral_site ?? false)) {
            return 0;
        }

        $number = 1;
        $previousGames = Game::query()
            ->where('season', (int) $game->season)
            ->whereKeyNot($game->id)
            ->whereDate('game_date', '<', $this->gameDateTime($game)?->toDateString())
            ->where(function ($query) use ($teamId): void {
                $query->where('home_team_id', $teamId)
                    ->orWhere('away_team_id', $teamId);
            })
            ->orderByDesc('game_date')
            ->limit(4)
            ->get();

        foreach ($previousGames as $previousGame) {
            if ((bool) ($previousGame->neutral_site ?? false) || (int) $previousGame->away_team_id !== $teamId) {
                break;
            }

            $number++;
        }

        return $number;
    }

    private function lookaheadSpot(Game $game, int $teamId, ?Game $nextGame, ?Team $currentOpponent): ?float
    {
        if (! $nextGame || ! $currentOpponent) {
            return null;
        }

        $gameDate = $this->gameDateTime($game);
        $nextDate = $this->gameDateTime($nextGame);
        if (! $gameDate || ! $nextDate) {
            return null;
        }

        if ($gameDate->diffInDays($nextDate) > (int) config('cfb.predictions.game_context.schedule.lookahead_window_days', 9)) {
            return null;
        }

        $nextOpponent = $this->opponentForTeam($nextGame, $teamId);
        if (! $nextOpponent) {
            return null;
        }

        $gap = $this->teamElo($nextOpponent) - $this->teamElo($currentOpponent);

        return $gap >= (float) config('cfb.predictions.game_context.schedule.lookahead_elo_gap', 120.0)
            ? round($gap, 1)
            : null;
    }

    private function letdownSpot(Game $game, int $teamId, ?Game $previousGame, ?Team $currentOpponent): ?float
    {
        if (! $previousGame || ! $currentOpponent || ! $this->teamWon($previousGame, $teamId)) {
            return null;
        }

        $previousOpponent = $this->opponentForTeam($previousGame, $teamId);
        if (! $previousOpponent) {
            return null;
        }

        $gap = $this->teamElo($previousOpponent) - $this->teamElo($currentOpponent);

        return $gap >= (float) config('cfb.predictions.game_context.schedule.letdown_elo_gap', 120.0)
            ? round($gap, 1)
            : null;
    }

    private function opponentForTeam(Game $game, int $teamId): ?Team
    {
        if ((int) $game->home_team_id === $teamId) {
            return $game->awayTeam;
        }

        if ((int) $game->away_team_id === $teamId) {
            return $game->homeTeam;
        }

        return null;
    }

    private function teamWon(Game $game, int $teamId): bool
    {
        if (! is_numeric($game->home_score) || ! is_numeric($game->away_score)) {
            return false;
        }

        return ((int) $game->home_team_id === $teamId && (int) $game->home_score > (int) $game->away_score)
            || ((int) $game->away_team_id === $teamId && (int) $game->away_score > (int) $game->home_score);
    }

    private function isConfiguredRivalry(Game $game): bool
    {
        $pairs = (array) config('cfb.predictions.game_context.venue.rivalry_pairs', []);
        if ($pairs === []) {
            return false;
        }

        $homeKeys = $this->teamKeys($game->homeTeam);
        $awayKeys = $this->teamKeys($game->awayTeam);

        foreach ($homeKeys as $homeKey) {
            foreach ($awayKeys as $awayKey) {
                $pair = collect([$homeKey, $awayKey])->sort()->implode('|');
                if (in_array($pair, $pairs, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return array<int,string>
     */
    private function teamKeys(?Team $team): array
    {
        if (! $team) {
            return [];
        }

        return collect([
            $team->id,
            $team->abbreviation,
            $team->school,
            $team->display_name,
            $team->name,
        ])
            ->filter(fn (mixed $value): bool => $value !== null && trim((string) $value) !== '')
            ->map(fn (mixed $value): string => strtolower(trim((string) $value)))
            ->unique()
            ->values()
            ->all();
    }

    private function teamElo(?Team $team): float
    {
        return (float) ($team?->elo_rating ?? config('cfb.elo.default', 1500));
    }

    private function gameDateTime(Game $game): ?Carbon
    {
        if (! $game->game_date) {
            return null;
        }

        return Carbon::parse($game->game_date->toDateString().' '.($game->game_time ?? '12:00:00'));
    }

    private function nullableFloat(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private function clamp(float $value, float $maxAbsoluteValue): float
    {
        $maxAbsoluteValue = abs($maxAbsoluteValue);

        return max(-$maxAbsoluteValue, min($maxAbsoluteValue, $value));
    }

    /**
     * @return array<string,mixed>
     */
    private function emptyMetadata(): array
    {
        return [
            'enabled' => true,
            'spread_adjustment' => 0.0,
            'total_adjustment' => 0.0,
            'confidence_penalty' => 0.0,
            'risk_flags' => [],
            'weather' => null,
            'venue' => null,
            'schedule' => null,
        ];
    }
}
