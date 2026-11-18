<?php

namespace App\Services;

use App\Models\NotificationTemplate;
use App\Models\User;
use App\Notifications\BettingValueAlertChannelResolver;

class BettingAlertEligibility
{
    public function reason(User $user, string $sport, float $edge, array $selection = [], ?NotificationTemplate $template = null, ?string $channel = null): ?string
    {
        $preference = $user->alertPreference;
        if (! $preference?->enabled) {
            return 'alerts_disabled';
        }
        if ($preference->digest_mode !== 'realtime') {
            return 'realtime_disabled';
        }
        if (! $preference->isInterestedInSport($sport)) {
            return 'sport_not_selected';
        }
        if (! $user->hasTierFeature('email_alerts') || ! $user->canAccessSport($sport)) {
            return 'tier_restricted';
        }
        if (! $preference->isWithinTimeWindow()) {
            return 'outside_delivery_window';
        }
        if ($template !== null && (! $template->active || ! $preference->shouldReceiveTemplate($template->id))) {
            return 'template_disabled';
        }
        $unit = $selection['edge_unit'] ?? match ($selection['type'] ?? null) {
            'spread', 'total' => 'points', 'moneyline' => 'percentage_points', default => null,
        };
        if ($unit === 'percentage_points') {
            $threshold = (float) $preference->minimum_edge;
        } else {
            $threshold = config('alerts.opportunity.unit_thresholds.'.($unit ?? 'unknown'));
            if (! is_numeric($threshold)) {
                return 'edge_threshold_unit_unconfigured';
            }
        }
        if ($edge <= 0 || $edge < (float) $threshold) {
            return 'below_edge_threshold';
        }
        $channels = app(BettingValueAlertChannelResolver::class)->resolve($user);
        if ($channel !== null ? ! in_array($channel, $channels, true) : $channels === []) {
            return 'channel_disabled';
        }

        return null;
    }
}
