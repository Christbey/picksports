<?php

namespace App\Services\CFB\Predictions;

use App\Models\CanonicalPrediction;
use App\Models\CFB\Game;
use App\Models\PredictionMarket;
use App\Services\CFB\CfbMarketMovementSignalService;
use Carbon\CarbonImmutable;

class CfbCanonicalSpreadValueSignalService
{
    public function __construct(private readonly CfbMarketMovementSignalService $marketMovement) {}

    /** @return array<string, mixed>|null */
    public function forPrediction(CanonicalPrediction $prediction, Game $game): ?array
    {
        if (! (bool) config('cfb.predictions.spread_value.enabled', true)
            || ! in_array($game->status, ['STATUS_SCHEDULED', 'STATUS_DELAYED'], true)) {
            return null;
        }

        $homeSpread = $prediction->markets->first(
            fn (PredictionMarket $market): bool => $market->market_type === 'spread'
                && $market->selection === 'home',
        );
        $modelHomeLine = $this->number($homeSpread?->projected_line);
        if ($modelHomeLine === null) {
            return null;
        }

        $modelHomeMargin = -$modelHomeLine;
        $market = $this->marketMovement->spreadContext($game, $modelHomeMargin);
        if ($market === null) {
            return null;
        }

        $marketHomeLine = $this->number($market['current_bookmaker_home_line'] ?? null);
        $marketHomeMargin = $this->number($market['current_home_margin'] ?? null);
        if ($marketHomeLine === null || $marketHomeMargin === null) {
            return null;
        }

        $signedHomeEdge = $modelHomeMargin - $marketHomeMargin;
        $side = $signedHomeEdge >= 0 ? 'home' : 'away';
        $edgePoints = round(abs($signedHomeEdge), 1);
        $minimumEdge = (float) config('cfb.predictions.spread_value.minimum_edge_points', 3.0);
        $keyEdge = (float) config('cfb.predictions.spread_value.key_edge_points', 7.0);
        if ($edgePoints < $minimumEdge) {
            return [
                'has_playable_value' => false,
                'play_count' => 0,
                'best' => null,
            ];
        }

        $support = $this->statisticalSupport($prediction);
        $marketHealth = $this->marketHealth($market);
        $isPlayable = $edgePoints >= $minimumEdge && $support['supported'] && $marketHealth['supported'];
        $isKeyEdge = $isPlayable && $edgePoints >= $keyEdge;
        $team = $side === 'home' ? $game->homeTeam : $game->awayTeam;
        $teamLabel = $team?->abbreviation ?: $team?->display_name ?: $team?->school ?: ucfirst($side);
        $marketSideLine = $side === 'home' ? $marketHomeLine : -$marketHomeLine;
        $modelSideLine = $side === 'home' ? $modelHomeLine : -$modelHomeLine;
        $action = $isPlayable ? 'Bet' : 'Watch';
        $riskFlags = array_values(array_unique([
            ...$support['risk_flags'],
            ...$marketHealth['risk_flags'],
            ...($edgePoints >= (float) config('cfb.predictions.spread_value.extreme_edge_points', 14.0)
                ? ['extreme_model_market_disagreement']
                : []),
        ]));

        return [
            'has_playable_value' => $isPlayable,
            'play_count' => $isPlayable ? 1 : 0,
            'best' => [
                'type' => 'spread',
                'label' => sprintf('%s %s %s to cover', $action, $teamLabel, $this->formatLine($marketSideLine)),
                'side' => $side,
                'edge' => $edgePoints,
                'market_line' => round($marketSideLine, 1),
                'model_line' => round($modelSideLine, 1),
                'market_home_line' => round($marketHomeLine, 1),
                'model_home_line' => round($modelHomeLine, 1),
                'grade' => $isKeyEdge ? 'Key' : ($isPlayable ? 'Playable' : 'Watch'),
                'risk_level' => $riskFlags === [] ? 'low' : 'medium',
                'is_key_edge' => $isKeyEdge,
                'stats_supported' => $support['supported'],
                'reason' => $this->reason(
                    $game,
                    $side,
                    $modelHomeLine,
                    $marketHomeLine,
                    $edgePoints,
                    $support['supported'],
                ),
                'risk_flags' => $riskFlags,
                'statistical_support' => $support,
                'market_evidence' => [
                    'source' => $market['source'] ?? null,
                    'captured_at' => $market['current_captured_at'] ?? null,
                    'book_count' => (int) ($market['current_book_count'] ?? 0),
                    'bookmaker_home_line_range' => $this->number($market['current_bookmaker_home_line_range'] ?? null),
                ],
            ],
        ];
    }

    /** @return array{supported:bool,home_sample_games:int,away_sample_games:int,minimum_sample_games:int,metric_reliability:float,minimum_metric_reliability:float,home_record_season:int|null,away_record_season:int|null,risk_flags:list<string>} */
    private function statisticalSupport(CanonicalPrediction $prediction): array
    {
        $inputs = (array) ($prediction->calculationRun?->inputSnapshot?->inputs ?? []);
        $diagnostics = (array) ($prediction->calculationRun?->diagnostics ?? []);
        $homeMetrics = (array) data_get($inputs, 'home.metrics', []);
        $awayMetrics = (array) data_get($inputs, 'away.metrics', []);
        $homeSample = (int) data_get($homeMetrics, 'wins', 0) + (int) data_get($homeMetrics, 'losses', 0);
        $awaySample = (int) data_get($awayMetrics, 'wins', 0) + (int) data_get($awayMetrics, 'losses', 0);
        $minimumSample = (int) config('cfb.predictions.spread_value.minimum_sample_games', 6);
        $minimumReliability = (float) config('cfb.predictions.spread_value.minimum_metric_reliability', 0.75);
        $reliability = (float) data_get($diagnostics, 'metric_reliability', 0.0);
        $riskFlags = [];

        if ($homeSample < $minimumSample || $awaySample < $minimumSample) {
            $riskFlags[] = 'insufficient_team_metric_sample';
        }
        if ($reliability < $minimumReliability) {
            $riskFlags[] = 'insufficient_metric_reliability';
        }

        return [
            'supported' => $riskFlags === [],
            'home_sample_games' => $homeSample,
            'away_sample_games' => $awaySample,
            'minimum_sample_games' => $minimumSample,
            'metric_reliability' => round($reliability, 3),
            'minimum_metric_reliability' => $minimumReliability,
            'home_record_season' => $this->integer(data_get($homeMetrics, 'record_season')),
            'away_record_season' => $this->integer(data_get($awayMetrics, 'record_season')),
            'risk_flags' => $riskFlags,
        ];
    }

    /** @param array<string, mixed> $market @return array{supported:bool,risk_flags:list<string>} */
    private function marketHealth(array $market): array
    {
        $riskFlags = [];
        $minimumBooks = (int) config('cfb.predictions.spread_value.minimum_books', 1);
        $bookCount = (int) ($market['current_book_count'] ?? 0);
        $bookRange = $this->number($market['current_bookmaker_home_line_range'] ?? null);
        $maximumRange = (float) config('cfb.predictions.spread_value.maximum_book_line_range', 2.5);
        $capturedAt = $market['current_captured_at'] ?? null;
        $maximumAgeHours = (int) config('cfb.predictions.spread_value.maximum_quote_age_hours', 6);

        if ($bookCount < $minimumBooks) {
            $riskFlags[] = 'thin_market_consensus';
        }
        if ($bookRange !== null && $bookRange > $maximumRange) {
            $riskFlags[] = 'wide_bookmaker_line_range';
        }
        if (! $this->hasFreshTimestamp($capturedAt, $maximumAgeHours)) {
            $riskFlags[] = 'stale_market_quote';
        }

        return [
            'supported' => $riskFlags === [],
            'risk_flags' => $riskFlags,
        ];
    }

    private function reason(Game $game, string $side, float $modelHomeLine, float $marketHomeLine, float $edgePoints, bool $supported): string
    {
        $home = $game->homeTeam?->abbreviation ?: $game->homeTeam?->school ?: 'Home';
        $away = $game->awayTeam?->abbreviation ?: $game->awayTeam?->school ?: 'Away';
        $candidate = $side === 'home' ? $home : $away;
        $support = $supported
            ? 'The stored team-metric samples and calculation reliability clear the configured support gates.'
            : 'The numerical edge is visible, but the stored statistical sample does not clear the promotion gates.';

        return sprintf(
            'Model: %s %s; market: %s %s. %s has a %.1f-point ATS edge. %s',
            $home,
            $this->formatLine($modelHomeLine),
            $home,
            $this->formatLine($marketHomeLine),
            $candidate,
            $edgePoints,
            $support,
        );
    }

    private function formatLine(float $line): string
    {
        $formatted = rtrim(rtrim(number_format(abs($line), 1, '.', ''), '0'), '.');

        return ($line >= 0 ? '+' : '-').$formatted;
    }

    private function number(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private function integer(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function hasFreshTimestamp(mixed $capturedAt, int $maximumAgeHours): bool
    {
        if (! is_string($capturedAt) || trim($capturedAt) === '') {
            return false;
        }

        try {
            return CarbonImmutable::parse($capturedAt)->gte(now()->subHours($maximumAgeHours));
        } catch (\Throwable) {
            return false;
        }
    }
}
