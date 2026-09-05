<?php

namespace App\Enums;

use App\Models\NBA\Prediction;
use Illuminate\Database\Eloquent\Model;

enum PredictionSport: string
{
    case NBA = 'nba';
    case WNBA = 'wnba';
    case MLB = 'mlb';
    case NFL = 'nfl';
    case CBB = 'cbb';
    case WCBB = 'wcbb';
    case CFB = 'cfb';

    /**
     * @return class-string<Model>
     */
    public function predictionModelClass(): string
    {
        return match ($this) {
            self::NBA => Prediction::class,
            self::WNBA => \App\Models\WNBA\Prediction::class,
            self::MLB => \App\Models\MLB\Prediction::class,
            self::NFL => \App\Models\NFL\Prediction::class,
            self::CBB => \App\Models\CBB\Prediction::class,
            self::WCBB => \App\Models\WCBB\Prediction::class,
            self::CFB => \App\Models\CFB\Prediction::class,
        };
    }

    public static function fromLegacyModelClass(?string $modelClass): ?self
    {
        if ($modelClass === null || $modelClass === '') {
            return null;
        }

        foreach (self::cases() as $sport) {
            if ($sport->predictionModelClass() === $modelClass) {
                return $sport;
            }
        }

        return null;
    }
}
