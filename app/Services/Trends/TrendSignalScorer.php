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

                $percentage = $this->extractPercentage($message);
                $hasRateEvidence = $percentage !== null;
                $direction = $this->directionForMessage($message, $category, $percentage);
                $extractedSample = $this->extractSampleSize($message) ?? $sampleSize;

                // Message text has no settled ROI, CLV, or validation-window evidence.
                $quality = 'contextual';
                $score = $this->scoreSignal(
                    $direction,
                    $percentage,
                    $hasRateEvidence,
                    $extractedSample,
                    $sampleSize,
                );

                $signals[] = [
                    'id' => "{$category}_{$index}",
                    'category' => $category,
                    'message' => $message,
                    'quality' => $quality,
                    'direction' => $direction,
                    'tone' => $this->toneForDirection($direction),
                    'score' => $score,
                    'confidence' => $this->confidenceLabel($score, $extractedSample),
                    'sample_size' => $extractedSample,
                    'percentage' => $percentage,
                    'reason_codes' => $this->reasonCodes(
                        $sport,
                        $category,
                        $quality,
                        $direction,
                        $score,
                        $extractedSample,
                        $hasRateEvidence,
                    ),
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

    protected function directionForMessage(string $message, string $category, ?float $percentage): string
    {
        $text = strtolower($message);
        $isTotalsContext = in_array($category, ['totals', 'scoring', 'defensive_performance'], true);

        if ($percentage === null) {
            return $isTotalsContext ? 'total_context' : 'context';
        }

        if (preg_match('/\bover\b/', $text)) {
            return $percentage >= 55 ? 'total_over' : 'total_context';
        }

        if (preg_match('/\bunder\b/', $text)) {
            return $percentage >= 55 ? 'total_under' : 'total_context';
        }

        if ($this->describesPositiveOutcome($text)) {
            return match (true) {
                $percentage >= 55 => 'support',
                $percentage <= 45 => 'risk',
                default => 'context',
            };
        }

        if ($this->describesNegativeOutcome($text)) {
            return $percentage >= 55 ? 'risk' : 'context';
        }

        return $isTotalsContext ? 'total_context' : 'context';
    }

    protected function describesPositiveOutcome(string $text): bool
    {
        return (bool) preg_match(
            '/\b(won|wins|winning|covered|covers|covering|outscored|outscore|held|led|leads|leading)\b|against (?:the )?(?:model )?spread/',
            $text,
        );
    }

    protected function describesNegativeOutcome(string $text): bool
    {
        return (bool) preg_match(
            '/\b(lost|loses|losing|failed|fails|struggle|struggles|blown|blows|allowed|allows|allowing|trailed|trails|trailing|declining)\b/',
            $text,
        );
    }

    protected function toneForDirection(string $direction): string
    {
        if ($direction === 'risk') {
            return 'risk';
        }

        if (str_starts_with($direction, 'total_')) {
            return 'total';
        }

        return 'team';
    }

    protected function extractPercentage(string $message): ?float
    {
        if (preg_match('/(\d+(?:\.\d+)?)%/', $message, $match)) {
            return $this->validPercentage((float) $match[1]);
        }

        $ratio = $this->extractRatio($message);
        if ($ratio === null || $ratio['attempts'] === 0) {
            return null;
        }

        return round(($ratio['successes'] / $ratio['attempts']) * 100, 1);
    }

    protected function validPercentage(float $percentage): ?float
    {
        return $percentage >= 0 && $percentage <= 100 ? $percentage : null;
    }

    protected function extractSampleSize(string $message): ?int
    {
        $ratio = $this->extractRatio($message);
        if ($ratio !== null) {
            return $ratio['attempts'];
        }

        if (preg_match('/\b(?:last|of)\s+(\d+)\s+games\b/i', $message, $match)) {
            return (int) $match[1];
        }

        return null;
    }

    /**
     * @return array{successes: int, attempts: int}|null
     */
    protected function extractRatio(string $message): ?array
    {
        if (preg_match('/\((\d+)\/(\d+)\)/', $message, $match)) {
            return $this->validRatio((int) $match[1], (int) $match[2]);
        }

        if (preg_match('/\b(\d+)\s+of\s+(?:their\s+)?(?:last\s+)?(\d+)\s+games\b/i', $message, $match)) {
            return $this->validRatio((int) $match[1], (int) $match[2]);
        }

        if (preg_match('/\b(\d+)-(\d+)(?:-(\d+))?\b/', $message, $match)) {
            $wins = (int) $match[1];
            $losses = (int) $match[2];
            $ties = isset($match[3]) ? (int) $match[3] : 0;

            return $this->validRatio($wins, $wins + $losses + $ties);
        }

        return null;
    }

    /**
     * @return array{successes: int, attempts: int}|null
     */
    protected function validRatio(int $successes, int $attempts): ?array
    {
        if ($attempts <= 0 || $successes < 0 || $successes > $attempts) {
            return null;
        }

        return [
            'successes' => $successes,
            'attempts' => $attempts,
        ];
    }

    protected function scoreSignal(
        string $direction,
        ?float $percentage,
        bool $hasRateEvidence,
        int $signalSample,
        int $teamSample,
    ): int {
        if (! $hasRateEvidence || $percentage === null) {
            return 35;
        }

        $score = 48 + min(12, abs($percentage - 50) * 0.4);

        if (in_array($direction, ['context', 'total_context'], true)) {
            $score -= 5;
        }

        $sampleRatio = $teamSample > 0
            ? min(1.0, $signalSample / max(1, $teamSample))
            : min(1.0, $signalSample / 20);
        $score += $sampleRatio * 5;

        if ($signalSample < 6) {
            $score -= 12;
        }

        // Unvalidated message evidence can rank context, but cannot become a strong edge.
        return max(1, min(69, (int) round($score)));
    }

    protected function confidenceLabel(int $score, int $sampleSize): string
    {
        if ($sampleSize < 6) {
            return 'thin_sample';
        }

        return $score >= 60 ? 'medium' : 'low';
    }

    /**
     * @return array<int, string>
     */
    protected function reasonCodes(
        string $sport,
        string $category,
        string $quality,
        string $direction,
        int $score,
        int $sampleSize,
        bool $hasRateEvidence,
    ): array {
        $codes = [
            "{$category}_trend",
            "{$quality}_trend_quality",
            "{$direction}_signal",
            $hasRateEvidence ? 'parsed_rate_context' : 'descriptive_trend_context',
            'unvalidated_trend_evidence',
        ];

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
