<?php

namespace App\Services\MLB;

use App\Models\GameOddsSnapshot;
use App\Models\MLB\Game;
use App\Models\MLB\Prediction;
use App\Support\Odds\AmericanOdds;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

class MlbMarketAwareProjectionService
{
    private const MODEL_WEIGHT = 0.25;

    private const MARKET_WEIGHT = 0.75;

    /**
     * @return array<string,mixed>
     */
    public function forPrediction(Prediction $prediction): array
    {
        $prediction->loadMissing('game.homeTeam', 'game.awayTeam');

        $game = $prediction->game;
        $homeModelProbability = $this->boundedProbability($prediction->win_probability);
        $awayModelProbability = $homeModelProbability === null ? null : round(1 - $homeModelProbability, 4);
        $modelPickSide = $homeModelProbability === null ? null : $this->sideFromHomeProbability($homeModelProbability);
        $oddsContext = $this->oddsContext($prediction);
        $prices = $this->extractH2hPrices($prediction, $oddsContext['odds_data']);
        $marketProbabilities = AmericanOdds::noVigProbabilities($prices['home'], $prices['away']);
        $homeMarketProbability = $this->roundedProbability($marketProbabilities['home']);
        $awayMarketProbability = $this->roundedProbability($marketProbabilities['away']);
        $marketPickSide = $this->marketPickSide($marketProbabilities, $prices);
        $homeBlendedProbability = $homeModelProbability !== null && $homeMarketProbability !== null
            ? $this->roundedProbability(($homeModelProbability * self::MODEL_WEIGHT) + ($homeMarketProbability * self::MARKET_WEIGHT))
            : null;
        $awayBlendedProbability = $homeBlendedProbability === null ? null : round(1 - $homeBlendedProbability, 4);
        $projectionPickSide = $homeBlendedProbability === null ? null : $this->sideFromHomeProbability($homeBlendedProbability);
        $pointInTimeReasons = $this->pointInTimeReasons($prediction, $oddsContext, $prices);
        $agreementStatus = $this->agreementStatus($modelPickSide, $marketPickSide);

        return [
            'status' => 'tracking_only',
            'label' => 'Market-aware projection',
            'is_bet' => false,
            'is_lean' => false,
            'model_probability' => $this->sideProbability($projectionPickSide, $homeModelProbability, $awayModelProbability),
            'market_probability' => $this->sideProbability($projectionPickSide, $homeMarketProbability, $awayMarketProbability),
            'blended_probability' => $this->sideProbability($projectionPickSide, $homeBlendedProbability, $awayBlendedProbability),
            'home_model_probability' => $homeModelProbability,
            'away_model_probability' => $awayModelProbability,
            'home_market_probability' => $homeMarketProbability,
            'away_market_probability' => $awayMarketProbability,
            'home_blended_probability' => $homeBlendedProbability,
            'away_blended_probability' => $awayBlendedProbability,
            'blend' => [
                'model_weight' => self::MODEL_WEIGHT,
                'market_weight' => self::MARKET_WEIGHT,
                'version' => 'mlb_market_aware_shadow_v1',
            ],
            'model_pick' => $this->pickPayload($game, $modelPickSide),
            'market_pick' => $this->pickPayload($game, $marketPickSide),
            'projection_pick' => $this->pickPayload($game, $projectionPickSide),
            'agreement_status' => $agreementStatus,
            'point_in_time_status' => $pointInTimeReasons === [] ? 'safe' : 'unsafe',
            'point_in_time_reasons' => $pointInTimeReasons,
            'risk_label' => $this->riskLabel($agreementStatus, $pointInTimeReasons, $marketPickSide),
            'reason' => $this->reason($agreementStatus, $pointInTimeReasons, $marketPickSide),
            'market_odds' => [
                'home_price' => $prices['home'],
                'away_price' => $prices['away'],
                'source' => $oddsContext['source'],
                'snapshot_id' => $oddsContext['snapshot_id'],
                'captured_at' => $this->serializeDate($oddsContext['captured_at']),
            ],
        ];
    }

    /**
     * @return array{odds_data:?array<string,mixed>,captured_at:?CarbonInterface,source:string,snapshot_id:?int}
     */
    private function oddsContext(Prediction $prediction): array
    {
        $game = $prediction->game;
        $start = $this->gameStartAt($prediction);

        if ($game !== null && $start !== null) {
            $snapshot = GameOddsSnapshot::query()
                ->where('sport', 'mlb')
                ->where('game_table', $game->getTable())
                ->where('game_id', (int) $game->id)
                ->whereNotNull('captured_at')
                ->where('captured_at', '<', $start)
                ->latest('captured_at')
                ->latest('id')
                ->first();

            if ($snapshot !== null) {
                return [
                    'odds_data' => is_array($snapshot->odds_data) ? $snapshot->odds_data : null,
                    'captured_at' => $snapshot->captured_at,
                    'source' => 'pregame_odds_snapshot',
                    'snapshot_id' => (int) $snapshot->id,
                ];
            }
        }

        return [
            'odds_data' => is_array($game?->odds_data) ? $game->odds_data : null,
            'captured_at' => $game?->odds_updated_at,
            'source' => 'game_current_odds',
            'snapshot_id' => null,
        ];
    }

    /**
     * @param  array{odds_data:?array<string,mixed>,captured_at:?CarbonInterface,source:string,snapshot_id:?int}  $oddsContext
     * @param  array{home:?int,away:?int}  $prices
     * @return list<string>
     */
    private function pointInTimeReasons(Prediction $prediction, array $oddsContext, array $prices): array
    {
        $game = $prediction->game;
        $reasons = [];
        $start = $this->gameStartAt($prediction);
        $capturedAt = $oddsContext['captured_at'];

        if ($prices['home'] === null || $prices['away'] === null) {
            $reasons[] = 'missing_market_odds';
        }

        if ($capturedAt === null) {
            $reasons[] = 'missing_market_odds_timestamp';
        }

        if ($start === null) {
            $reasons[] = 'missing_game_start_time';
        }

        if ($this->predictionGeneratedAt($prediction) === null) {
            $reasons[] = 'missing_prediction_timestamp';
        }

        if ($start !== null && $capturedAt !== null && Carbon::parse($capturedAt)->gt($start)) {
            $reasons[] = 'odds_after_first_pitch';
        }

        if ($capturedAt !== null && $this->oddsAreStale(Carbon::parse($capturedAt), $start)) {
            $reasons[] = 'stale_odds';
        }

        if ($prediction->live_win_probability !== null || $prediction->live_updated_at !== null) {
            $reasons[] = 'live_only_row';
        }

        if (in_array((string) $game?->status, [
            (string) config('mlb.statuses.postponed'),
            (string) config('mlb.statuses.suspended'),
            (string) config('mlb.statuses.canceled'),
        ], true)) {
            $reasons[] = 'postponed_suspended_cancelled';
        }

        return array_values(array_unique($reasons));
    }

    private function predictionGeneratedAt(Prediction $prediction): ?CarbonInterface
    {
        $generatedAt = data_get($prediction->model_metadata, 'point_in_time.generated_at')
            ?? data_get($prediction->model_metadata, 'market_context.safety.prediction_generated_at')
            ?? $prediction->created_at;

        return $generatedAt instanceof CarbonInterface ? $generatedAt : ($generatedAt === null ? null : Carbon::parse($generatedAt));
    }

    private function oddsAreStale(CarbonInterface $oddsUpdatedAt, ?Carbon $start): bool
    {
        $staleHours = (int) config('mlb.signals.odds_stale_hours', 12);

        if ($start !== null) {
            return $oddsUpdatedAt->lt($start->copy()->subHours($staleHours));
        }

        return $oddsUpdatedAt->lt(now()->subHours($staleHours));
    }

    private function gameStartAt(Prediction $prediction): ?Carbon
    {
        $game = $prediction->game;
        if (! $game?->game_date || ! $game->game_time) {
            return null;
        }

        return Carbon::parse($game->game_date->toDateString().' '.$game->game_time, config('app.timezone'));
    }

    /**
     * @param  array<string,mixed>|null  $oddsData
     * @return array{home:?int,away:?int}
     */
    private function extractH2hPrices(Prediction $prediction, ?array $oddsData): array
    {
        if (! is_array($oddsData)) {
            return ['home' => null, 'away' => null];
        }

        $homeTeam = $this->teamName($prediction->game, 'home');
        $awayTeam = $this->teamName($prediction->game, 'away');
        $prices = ['home' => null, 'away' => null];

        foreach (($oddsData['bookmakers'] ?? []) as $bookmaker) {
            foreach (($bookmaker['markets'] ?? []) as $market) {
                if (($market['key'] ?? null) !== 'h2h') {
                    continue;
                }

                foreach (($market['outcomes'] ?? []) as $outcome) {
                    if (! is_numeric($outcome['price'] ?? null)) {
                        continue;
                    }

                    $name = $this->normalizeTeamName((string) ($outcome['name'] ?? ''));
                    if ($this->teamNameMatches($homeTeam, $name)) {
                        $prices['home'] = (int) $outcome['price'];
                    }
                    if ($this->teamNameMatches($awayTeam, $name)) {
                        $prices['away'] = (int) $outcome['price'];
                    }
                }
            }
        }

        return $prices;
    }

    private function teamName(?Game $game, string $side): string
    {
        $team = $side === 'home' ? $game?->homeTeam : $game?->awayTeam;

        return $this->normalizeTeamName((string) ($team?->display_name ?: trim(((string) $team?->location).' '.((string) $team?->name))));
    }

    private function teamNameMatches(string $teamName, string $outcomeName): bool
    {
        return $teamName !== ''
            && $outcomeName !== ''
            && ($teamName === $outcomeName || str_contains($teamName, $outcomeName) || str_contains($outcomeName, $teamName));
    }

    /**
     * @param  array{home:?float,away:?float}  $marketProbabilities
     * @param  array{home:?int,away:?int}  $prices
     */
    private function marketPickSide(array $marketProbabilities, array $prices): ?string
    {
        if ($marketProbabilities['home'] !== null && $marketProbabilities['away'] !== null) {
            if (abs((float) $marketProbabilities['home'] - (float) $marketProbabilities['away']) < 0.0001) {
                return null;
            }

            return $marketProbabilities['home'] >= $marketProbabilities['away'] ? 'home' : 'away';
        }

        if ($prices['home'] !== null && $prices['away'] !== null) {
            if ((int) $prices['home'] === (int) $prices['away']) {
                return null;
            }

            return $prices['home'] <= $prices['away'] ? 'home' : 'away';
        }

        return null;
    }

    private function sideFromHomeProbability(float $homeProbability): string
    {
        return $homeProbability >= 0.5 ? 'home' : 'away';
    }

    private function agreementStatus(?string $modelPickSide, ?string $marketPickSide): string
    {
        if ($modelPickSide === null) {
            return 'model_missing';
        }

        if ($marketPickSide === null) {
            return 'market_missing';
        }

        return $modelPickSide === $marketPickSide ? 'agrees' : 'disagrees';
    }

    /**
     * @return array{side:?string,team_id:int|null,team_abbreviation:string|null,label:string|null}
     */
    private function pickPayload(?Game $game, ?string $side): array
    {
        $team = $side === 'home' ? $game?->homeTeam : ($side === 'away' ? $game?->awayTeam : null);

        return [
            'side' => $side,
            'team_id' => $team?->id,
            'team_abbreviation' => $team?->abbreviation,
            'label' => $team?->abbreviation ?? $team?->display_name,
        ];
    }

    private function riskLabel(string $agreementStatus, array $pointInTimeReasons, ?string $marketPickSide): string
    {
        if ($marketPickSide === null) {
            return 'market_unavailable';
        }

        if ($pointInTimeReasons !== []) {
            return 'point_in_time_unsafe';
        }

        if ($agreementStatus === 'disagrees') {
            return 'model_market_disagreement';
        }

        return 'calibration_unvalidated';
    }

    private function reason(string $agreementStatus, array $pointInTimeReasons, ?string $marketPickSide): string
    {
        if ($marketPickSide === null) {
            return 'Market moneyline was unavailable, so the projection cannot calculate a market-aware probability.';
        }

        if ($pointInTimeReasons !== []) {
            return 'Market-aware projection is not point-in-time safe and remains tracking-only.';
        }

        if ($agreementStatus === 'disagrees') {
            return 'Model and market disagree; keep this as tracking context until the shadow blend is validated.';
        }

        return 'Model and market agree, but MLB recommendation promotion remains blocked until calibration is validated.';
    }

    private function sideProbability(?string $side, ?float $home, ?float $away): ?float
    {
        return match ($side) {
            'home' => $home,
            'away' => $away,
            default => null,
        };
    }

    private function boundedProbability(mixed $value): ?float
    {
        return is_numeric($value) ? $this->roundedProbability(min(1.0, max(0.0, (float) $value))) : null;
    }

    private function roundedProbability(?float $value): ?float
    {
        return $value === null ? null : round(min(1.0, max(0.0, $value)), 4);
    }

    private function normalizeTeamName(string $value): string
    {
        return strtolower(preg_replace('/[^a-z0-9]+/i', '', $value) ?? '');
    }

    private function serializeDate(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_object($value) && method_exists($value, 'toIso8601String')) {
            return $value->toIso8601String();
        }

        return (string) $value;
    }
}
