<?php

namespace App\Support;

class NflModelVersion
{
    public static function current(string $profile = 'pregame'): string
    {
        $definition = self::definition($profile);

        return strlen($definition) <= 128 ? $definition : substr($definition, 0, 111).'-'.substr(hash('sha256', $definition), 0, 16);
    }

    public static function definition(string $profile = 'pregame'): string
    {
        $suffix = in_array($profile, ['configured', 'pregame'], true) ? '' : '-'.$profile;

        $adaptationSuffix = config('nfl.predictions.preseason_signal.season_adaptation.enabled', true) ? '-season-adaptive-v1' : '';

        return (string) config('nfl.predictions.model_version', 'nfl-historical-elo-v2')
            .'-career-regular-v2-ats-v3'.$adaptationSuffix.'-integrity-v4'.$suffix;
    }
}
