<?php

namespace App\Services\Trends;

class TrendSignalScorer
{
    /**
     * @param  array<string, array<int, string>>  $trends
     * @return array<int, array<string, mixed>>
     */
    public function score(string $sport, array $trends, int $sampleSize): array
    {
        $signals = [];

        foreach ($trends as $category => $messages) {
            foreach ($messages as $index => $message) {
                $message = trim((string) $message);
                if ($message === '') {
                    continue;
                }

                $quality = $this->qualityForCategory($category);
                $direction = $this->directionForMessage($message, $category);
                $tone = $this->toneForDirection($direction, $quality);
                $extractedSample = $this->extractSampleSize($message) ?? $sampleSize;
                $percentage = $this->extractPercentage($message);
                $score = $this->scoreSignal($category, $quality, $direction, $percentage, $extractedSample, $sampleSize);

                $signals[] = [
                    'id' => "{$category}_{$index}",
                    'category' => $category,
                    'message' => $message,
                    'quality' => $quality,
                    'direction' => $direction,
                    'tone' => $tone,
                    'score' => $score,
                    'confidence' => $this->confidenceLabel($score, $extractedSample),
                    'sample_size' => $extractedSample,
                    'percentage' => $percentage,
                    'reason_codes' => $this->reasonCodes($sport, $category, $quality, $direction, $score, $extractedSample),
                ];
            }
        }

        usort($signals, fn (array $a, array $b) => $b['score'] <=> $a['score']);

        return $signals;
    }

    /**
     * @param  array<int, array<string, mixed>>  $signals
     * @return array<string, mixed>
     */
    public function summarize(array $signals): array
    {
        $counts = [
            'actionable' => 0,
            'contextual' => 0,
            'volatile' => 0,
            'support' => 0,
            'risk' => 0,
            'total' => 0,
            'thin_sample' => 0,
        ];

        foreach ($signals as $signal) {
            $quality = (string) ($signal['quality'] ?? '');
            $direction = (string) ($signal['direction'] ?? '');

            if (array_key_exists($quality, $counts)) {
                $counts[$quality]++;
            }

            if ($direction === 'support') {
                $counts['support']++;
            } elseif ($direction === 'risk') {
                $counts['risk']++;
            } elseif (str_starts_with($direction, 'total_')) {
                $counts['total']++;
            }

            if (($signal['sample_size'] ?? 0) > 0 && ($signal['sample_size'] ?? 0) < 6) {
                $counts['thin_sample']++;
            }
        }

        $top = array_slice($signals, 0, 5);

        return [
            'counts' => $counts,
            'top_signals' => $top,
            'primary_signal' => $top[0] ?? null,
        ];
    }

    protected function qualityForCategory(string $category): string
    {
        return match ($category) {
            'advanced',
            'totals',
            'rest_schedule',
            'opponent_strength',
            'offensive_efficiency',
            'defensive_performance',
            'drive_efficiency' => 'actionable',

            'quarters',
            'halves',
            'conference',
            'streaks',
            'situational',
            'scoring',
            'margins',
            'scoring_patterns',
            'momentum' => 'contextual',

            'first_score',
            'time_based',
            'clutch_performance' => 'volatile',

            default => 'contextual',
        };
    }

    protected function directionForMessage(string $message, string $category): string
    {
        $text = strtolower($message);
        $percentage = $this->extractPercentage($message);

        if (preg_match('/\bover\b/', $text)) {
            return 'total_over';
        }

        if (preg_match('/\bunder\b/', $text)) {
            return 'total_under';
        }

        if (
            str_contains($text, 'allow') ||
            str_contains($text, 'blown') ||
            str_contains($text, 'struggle') ||
            str_contains($text, 'declining') ||
            str_contains($text, 'losing streak') ||
            str_contains($text, 'failed') ||
            ($percentage !== null && $percentage < 45)
        ) {
            return 'risk';
        }

        if (
            str_contains($text, 'won') ||
            str_contains($text, 'winning') ||
            str_contains($text, 'hot') ||
            str_contains($text, 'covered') ||
            str_contains($text, 'clutch') ||
            str_contains($text, 'taking care') ||
            str_contains($text, 'strong') ||
            str_contains($text, 'improving') ||
            ($percentage !== null && $percentage >= 60 && ! str_contains($text, 'allow'))
        ) {
            return 'support';
        }

        if (in_array($category, ['totals', 'scoring', 'defensive_performance'], true)) {
            return 'total_context';
        }

        return 'context';
    }

    protected function toneForDirection(string $direction, string $quality): string
    {
        if ($direction === 'risk') {
            return 'risk';
        }

        if (str_starts_with($direction, 'total_')) {
            return 'total';
        }

        if ($quality === 'volatile') {
            return 'risk';
        }

        return 'team';
    }

    protected function extractPercentage(string $message): ?float
    {
        preg_match_all('/(\d+(?:\.\d+)?)%/', $message, $matches);

        if (empty($matches[1])) {
            return null;
        }

        return max(array_map(static fn (string $value): float => (float) $value, $matches[1]));
    }

    protected function extractSampleSize(string $message): ?int
    {
        if (preg_match('/\((\d+)\/(\d+)\)/', $message, $match)) {
            return (int) $match[2];
        }

        if (preg_match('/\b(?:last|of)\s+(\d+)\s+games\b/i', $message, $match)) {
            return (int) $match[1];
        }

        if (preg_match('/\b(\d+)\s+of\s+(\d+)\s+games\b/i', $message, $match)) {
            return (int) $match[2];
        }

        return null;
    }

    protected function scoreSignal(
        string $category,
        string $quality,
        string $direction,
        ?float $percentage,
        int $signalSample,
        int $teamSample
    ): int {
        $base = match ($quality) {
            'actionable' => 62,
            'contextual' => 50,
            'volatile' => 42,
            default => 48,
        };

        $base += match ($category) {
            'advanced', 'opponent_strength', 'rest_schedule', 'drive_efficiency' => 8,
            'totals', 'offensive_efficiency', 'defensive_performance' => 6,
            'clutch_performance', 'first_score', 'time_based' => -6,
            default => 0,
        };

        if ($percentage !== null) {
            $base += ($percentage - 50) * 0.45;
        }

        $sampleRatio = $teamSample > 0 ? min(1.0, $signalSample / max(1, $teamSample)) : 0.5;
        $base += $sampleRatio * 8;

        if ($signalSample < 6) {
            $base -= 10;
        }

        if ($direction === 'risk') {
            $base += 3;
        }

        return max(1, min(100, (int) round($base)));
    }

    protected function confidenceLabel(int $score, int $sampleSize): string
    {
        if ($sampleSize < 6) {
            return 'thin_sample';
        }

        return match (true) {
            $score >= 75 => 'strong',
            $score >= 60 => 'medium',
            default => 'low',
        };
    }

    /**
     * @return array<int, string>
     */
    protected function reasonCodes(string $sport, string $category, string $quality, string $direction, int $score, int $sampleSize): array
    {
        $codes = [
            "{$category}_trend",
            "{$quality}_trend_quality",
            "{$direction}_signal",
        ];

        if ($score >= 75) {
            $codes[] = 'strong_trend_signal';
        }

        if ($sampleSize < 6) {
            $codes[] = 'thin_sample_trend';
        }

        if ($sport === 'nba' && in_array($category, ['quarters', 'halves', 'clutch_performance'], true)) {
            $codes[] = 'late_game_execution_context';
        }

        if (in_array($category, ['totals', 'scoring', 'defensive_performance'], true)) {
            $codes[] = 'pace_total_context';
        }

        return array_values(array_unique($codes));
    }
}
