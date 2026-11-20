<?php

namespace App\Services\MLB;

class MlbRecommendationPresentation
{
    /** @param array<string|int, mixed> $payload @return array<string|int, mixed> */
    public function sanitize(array $payload): array
    {
        $sanitized = [];

        foreach ($payload as $key => $value) {
            $publicKey = match ($key) {
                'raw_implied_probability' => 'market_implied_probability',
                'raw_edge' => 'market_edge',
                default => is_string($key) ? $key : (string) $key,
            };

            if ($this->isRawPayloadKey($publicKey)) {
                continue;
            }

            $sanitized[$publicKey] = is_array($value)
                ? $this->sanitize($value)
                : $value;
        }

        return $sanitized;
    }

    private function isRawPayloadKey(string $key): bool
    {
        return $key === 'raw' || str_starts_with($key, 'raw_') || str_contains($key, '_raw');
    }

    /** @param array<string, mixed>|null $recommendation @return array<string, mixed>|null */
    public function summary(?array $recommendation): ?array
    {
        if ($recommendation === null) {
            return null;
        }

        $public = (array) ($recommendation['public'] ?? $recommendation);
        $promotion = (array) ($recommendation['promotion'] ?? []);
        $type = (string) ($public['recommendation_type'] ?? 'no_play');

        return [
            'type' => $type,
            'label' => $type === 'no_play' ? 'No betting recommendation' : $this->recommendationLabel($type),
            'is_bet' => ($public['is_bet'] ?? false) === true,
            'is_lean' => $type === 'lean' && ($public['prediction_phase'] ?? null) === 'pregame',
            'promotion_blocked' => ($promotion['status'] ?? null) === 'blocked',
            'block_reasons' => array_values((array) ($promotion['block_reasons'] ?? [])),
        ];
    }

    private function recommendationLabel(string $type): string
    {
        return match ($type) {
            'bet' => 'Model bet',
            'lean' => 'Model lean',
            'monitor' => 'Live monitor',
            default => str($type)->replace('_', ' ')->title()->toString(),
        };
    }
}
