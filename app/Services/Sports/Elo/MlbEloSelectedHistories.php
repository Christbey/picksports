<?php

namespace App\Services\Sports\Elo;

use App\Exceptions\EloAdmissionRefused;

/** Internal typed requests; historical numeric equality is never admission evidence. */
final class MlbEloSelectedHistories
{
    public static function policy(): array
    {
        return ['version' => 'mlb-exact-selected-history-v1', 'members' => 128, 'pitchers' => 64,
            'linkage' => 'retained_native_effect_or_exact_reviewed_baseline', 'unknown' => 'refuse'];
    }

    public static function normalize(string $sport, array $requests): array
    {
        $policy = self::policy();
        $refuse = static fn (string $reason): never => throw new EloAdmissionRefused($reason,
            ['selected_histories' => count($requests)], $policy);
        if (count($requests) > $policy['members']) {
            $refuse('selected_history_member_budget');
        }
        if ($requests !== [] && $sport !== 'mlb') {
            $refuse('selected_history_sport_unsupported');
        }
        $selected = [];
        $pitchers = [];
        foreach ($requests as $request) {
            if (! is_array($request) || count($request) !== 3
                || ! in_array($request['entity_type'] ?? null, ['team', 'pitcher'], true)
                || ! is_int($request['entity_id'] ?? null) || $request['entity_id'] < 1
                || ! is_int($request['history_id'] ?? null) || $request['history_id'] < 1) {
                $refuse('selected_history_identity_invalid');
            }
            $key = $request['entity_type'].':'.$request['entity_id'].':'.$request['history_id'];
            if (isset($selected[$key])) {
                $refuse('selected_history_duplicate');
            }
            $selected[$key] = $request;
            if ($request['entity_type'] === 'pitcher') {
                $pitchers[$request['entity_id']] = true;
                if (count($pitchers) > $policy['pitchers']) {
                    $refuse('selected_history_pitcher_budget');
                }
            }
        }

        return $selected;
    }
}
