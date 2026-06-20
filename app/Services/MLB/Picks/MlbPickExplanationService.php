<?php

namespace App\Services\MLB\Picks;

use App\Models\MLB\PickCandidate;

class MlbPickExplanationService
{
    public function explain(PickCandidate $candidate): string
    {
        $reasons = collect($candidate->reason_codes ?? [])
            ->map(fn (string $code): string => str_replace('_', ' ', $code))
            ->take(3)
            ->implode(', ');
        $risks = collect($candidate->risk_flags ?? [])
            ->map(fn (string $code): string => str_replace('_', ' ', $code))
            ->take(2)
            ->implode(', ');

        $text = 'Tracking-only candidate';
        if ($reasons !== '') {
            $text .= ' supported by '.$reasons;
        }
        if ($risks !== '') {
            $text .= '. Watch risks: '.$risks;
        }

        return $text.'. MLB public promotion is still validation-gated.';
    }
}
