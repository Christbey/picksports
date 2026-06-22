<?php

namespace App\Services\MLB\Picks;

use App\Models\MLB\PickCandidate;

class MlbPickExplanationService
{
    public function explain(PickCandidate $candidate): string
    {
        $groups = collect((array) data_get($candidate->feature_snapshot, 'signal_layer.signal_groups', []))
            ->filter(fn (mixed $group): bool => is_array($group));

        $support = $groups
            ->filter(fn (array $group): bool => in_array((string) ($group['status'] ?? ''), ['positive', 'neutral'], true))
            ->pluck('summary')
            ->filter()
            ->take(3)
            ->implode(', ');

        $risks = $groups
            ->filter(fn (array $group): bool => in_array((string) ($group['status'] ?? ''), ['warning', 'risk'], true))
            ->pluck('summary')
            ->filter()
            ->take(2)
            ->implode(', ');

        $text = 'Tracking-only candidate';
        if ($support !== '') {
            $text .= ' supported by '.$support;
        }
        if ($risks !== '') {
            $text .= '. Watchouts: '.$risks;
        }

        return $text.'. MLB public promotion is still validation-gated.';
    }
}
