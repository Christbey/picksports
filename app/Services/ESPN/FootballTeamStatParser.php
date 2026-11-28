<?php

namespace App\Services\ESPN;

use App\Support\ESPN\ObservedStatValue as Value;
use DomainException;

class FootballTeamStatParser
{
    public function parse(array $statistics): array
    {
        $parsed = [];
        $seen = [];
        $scalars = ['firstDowns', 'totalYards', 'rushingYards', 'rushingAttempts', 'interceptions', 'fumblesLost',
            'passingTouchdowns', 'rushingTouchdowns', 'redZoneScores', 'fumbles'];
        $assign = static function (string $field, int|string $value) use (&$parsed): void {
            if (array_key_exists($field, $parsed) && $parsed[$field] !== $value) {
                throw new DomainException('Conflicting canonical team observations.');
            }
            $parsed[$field] = $value;
        };
        foreach ($statistics as $stat) {
            if (! is_array($stat) || ! is_string($stat['name'] ?? null) || ! array_key_exists('displayValue', $stat)) {
                throw new DomainException('Missing named team observation.');
            }
            $name = $stat['name'];
            $value = $stat['displayValue'];
            if (isset($seen[$name])) {
                throw new DomainException('Ambiguous team observations.');
            }
            $seen[$name] = true;
            if (in_array($name, $scalars, true) || $name === 'netPassingYards') {
                $assign($name === 'netPassingYards' ? 'passingYards' : $name, Value::integer($value,
                    in_array($name, ['totalYards', 'netPassingYards', 'rushingYards'], true)));
            } elseif ($name === 'possessionTime') {
                if (! is_string($value) || preg_match('/\A[0-9]{1,3}:[0-5][0-9]\z/', $value) !== 1) {
                    throw new DomainException('Invalid possession clock observation.');
                }
                $assign($name, $value);
            } elseif (in_array($name, ['completionAttempts', 'thirdDownEff', 'fourthDownEff', 'redZoneEfficiency', 'totalPenaltiesYards', 'sacksYardsLost'], true)
                || ($name === 'redZoneAttempts' && is_string($value) && (str_contains($value, '/') || str_contains($value, '-')))) {
                $pair = Value::pair($value, is_string($value) && str_contains($value, '/') ? '/' : '-',
                    ! in_array($name, ['totalPenaltiesYards', 'sacksYardsLost'], true));
                [$first, $second] = match ($name) {
                    'completionAttempts' => ['completions', 'passingAttempts'],
                    'thirdDownEff' => ['thirdDownConversions', 'thirdDownAttempts'],
                    'fourthDownEff' => ['fourthDownConversions', 'fourthDownAttempts'],
                    'redZoneEfficiency', 'redZoneAttempts' => ['redZoneScores', 'redZoneAttempts'],
                    'totalPenaltiesYards' => ['penalties', 'penaltyYards'],
                    'sacksYardsLost' => ['sacksAllowed', null],
                };
                $assign($first, $pair[0]);
                if ($second !== null) {
                    $assign($second, $pair[1]);
                }
            } elseif ($name === 'redZoneAttempts') {
                $assign($name, Value::integer($value));
            }
        }

        return $parsed;
    }
}
