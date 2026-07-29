<?php

namespace App\Services\NFL;

use Illuminate\Support\Facades\Config;

class NflPredictionProfileConfigurator
{
    /**
     * @return list<string>
     */
    public function profiles(): array
    {
        return [
            'elo-only',
            'rolling-efficiency',
            'qb-form',
            'line-matchup',
            'contextual',
            'full-historical',
            'configured',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function overrides(string $profile): array
    {
        $this->validate($profile);

        if ($profile === 'configured') {
            return ['nfl.predictions.historical_profile' => 'configured'];
        }

        return [
            'nfl.predictions.historical_profile' => $profile,
            'nfl.predictions.true_epa.enabled' => false,
            'nfl.predictions.preseason_signal.enabled' => false,
            'nfl.predictions.market_blend.enabled' => false,
            'nfl.predictions.depth_chart_injuries.enabled' => false,
            'nfl.predictions.rolling_efficiency.enabled' => in_array($profile, ['rolling-efficiency', 'full-historical'], true),
            'nfl.predictions.opponent_adjusted_efficiency.enabled' => false,
            'nfl.predictions.total_environment.enabled' => false,
            'nfl.predictions.actual_weather.enabled' => false,
            'nfl.predictions.adaptive_point_calibration.enabled' => false,
            'nfl.predictions.adaptive_win_probability_calibration.enabled' => false,
            'nfl.predictions.automated_calibration_tweaks.enabled' => false,
            'nfl.predictions.qb_form.enabled' => in_array($profile, ['qb-form', 'full-historical'], true),
            'nfl.predictions.line_matchup.enabled' => in_array($profile, ['line-matchup', 'full-historical'], true),
            'nfl.predictions.contextual_factors.enabled' => in_array($profile, ['contextual', 'full-historical'], true),
        ];
    }

    public function apply(string $profile): void
    {
        Config::set($this->overrides($profile));
    }

    public function withProfile(string $profile, callable $callback): mixed
    {
        $overrides = $this->overrides($profile);
        $original = [];

        foreach (array_keys($overrides) as $key) {
            $original[$key] = Config::get($key);
        }

        Config::set($overrides);

        try {
            return $callback();
        } finally {
            Config::set($original);
        }
    }

    private function validate(string $profile): void
    {
        if (! in_array($profile, $this->profiles(), true)) {
            throw new \InvalidArgumentException(
                'The NFL prediction profile must be '.implode(', ', $this->profiles()).'.'
            );
        }
    }
}
