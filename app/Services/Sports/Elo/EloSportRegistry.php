<?php

namespace App\Services\Sports\Elo;

use App\Models\SportEloWriterGate;
use App\Services\Predictions\CanonicalPayloadHasher;
use InvalidArgumentException;

class EloSportRegistry
{
    public function resolve(string $sport, string $entityType): array
    {
        if (! in_array($sport, SportEloWriterGate::SPORTS, true) || ! in_array($entityType, ['team', 'pitcher'], true)
            || ($entityType === 'pitcher' && $sport !== 'mlb')) {
            throw new InvalidArgumentException('Unsupported Elo sport or entity type.');
        }
        $namespace = 'App\\Models\\'.strtoupper($sport).'\\';
        $version = config('elo_integrity.calculation_versions.'.$sport);
        if (! is_string($version) || preg_match('/\A[a-zA-Z0-9_.:-]{1,64}\z/', $version) !== 1) {
            throw new InvalidArgumentException('An explicit Elo calculation version is required.');
        }

        $eligibility = app(EloEligibilityPolicy::class)->specification($sport);

        return ['eligibility' => $eligibility, 'entity_model' => $namespace.($entityType === 'team' ? 'Team' : 'Player'),
            'history_model' => $namespace.($entityType === 'team' ? 'EloRating' : 'PitcherEloRating'),
            'game_model' => $namespace.'Game', 'entity_key' => $entityType === 'team' ? 'team_id' : 'player_id',
            'history_date' => $sport === 'wcbb' ? 'game_date' : 'date', 'version' => $version,
            'configuration_hash' => app(CanonicalPayloadHasher::class)->hash(['sport' => $sport, 'version' => $version, 'elo' => config($sport.'.elo'), 'eligibility' => $eligibility]),
            'default' => config($sport.'.elo.default') ?? config($sport.'.elo.default_rating')];
    }
}
