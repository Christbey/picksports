<?php

namespace App\Services\ESPN;

use App\Support\ESPN\ObservedStatValue as Value;

class FootballPlayerStatParser
{
    /** @return array<string,int|float> Unsupported categories return no observations. */
    public function parse(string $category, array $labels, array $stats, bool $extended = false): array
    {
        $mapped = Value::labeled($labels, $stats);
        $category = strtolower(trim($category));
        $updates = [];
        $integer = static function (array $keys, bool $signed = false) use ($mapped): ?int {
            $found = null;
            foreach ($keys as $key) {
                if (array_key_exists($key, $mapped)) {
                    $value = Value::integer($mapped[$key], $signed);
                    if ($found !== null && $found !== $value) {
                        throw new \DomainException('Conflicting source aliases.');
                    }
                    $found = $value;
                }
            }

            return $found;
        };
        $put = static function (string $field, array $keys, bool $signed = false) use (&$updates, $integer): void {
            $value = $integer($keys, $signed);
            if ($value !== null) {
                $updates[$field] = $value;
            }
        };
        if ($category === 'passing') {
            if (array_key_exists('C/ATT', $mapped)) {
                [$updates['passing_completions'], $updates['passing_attempts']] = Value::pair($mapped['C/ATT']);
            }
            $put('passing_yards', ['YDS'], true);
            $put('passing_touchdowns', ['TD']);
            $put('interceptions_thrown', ['INT']);
            if ($extended) {
                $put('sacks_taken', ['SACKS', 'SACK', 'SK']);
                $put('sack_yards_lost', ['SACK YDS LOST', 'SACKYDS', 'SKYDS', 'SACK_YARDS'], true);
                $put('passing_long', ['LONG', 'LNG'], true);
                $put('passing_two_point_conversions', ['2PT', '2PT PASS', '2PT PASS CONV', '2PT PASS CV']);
            }
        } elseif ($category === 'rushing') {
            $put('rushing_attempts', ['CAR', 'ATT']);
            $put('rushing_yards', ['YDS'], true);
            $put('rushing_touchdowns', ['TD']);
            if ($extended) {
                $put('rushing_long', ['LONG', 'LNG'], true);
                $put('rushing_two_point_conversions', ['2PT', '2PT RUSH', '2PT RUSH CONV', '2PT RUSH CV']);
            }
        } elseif ($category === 'receiving') {
            $put('receptions', ['REC']);
            $put('receiving_yards', ['YDS'], true);
            $put('receiving_touchdowns', ['TD']);
            $put('receiving_targets', ['TAR', 'TGTS']);
            if ($extended) {
                $put('receiving_long', ['LONG', 'LNG'], true);
                $put('receiving_two_point_conversions', ['2PT', '2PT REC', '2PT REC CONV', '2PT REC CV']);
            }
        } elseif ($extended && in_array($category, ['defensive', 'defense'], true)) {
            foreach (['tackles_total' => ['TOT', 'TOTAL'], 'tackles_solo' => ['SOLO'], 'tackles_assists' => ['AST'],
                'interceptions' => ['INT', 'INTS'], 'passes_defended' => ['PD', 'PDEF'],
                'fumbles_forced' => ['FF'], 'fumbles_recovered' => ['FR', 'REC']] as $field => $keys) {
                $put($field, $keys);
            }
            foreach (['SACKS', 'SACK'] as $key) {
                if (array_key_exists($key, $mapped)) {
                    $value = Value::decimal($mapped[$key], maximum: 99.9);
                    if (isset($updates['sacks']) && $updates['sacks'] !== $value) {
                        throw new \DomainException('Conflicting sack observations.');
                    }
                    $updates['sacks'] = $value;
                }
            }
        } elseif ($extended && $category === 'kicking') {
            foreach ([['field_goals', ['FG', 'FGM/FGA'], [['FGM', 'FGA']]],
                ['extra_points', ['XP', 'PAT', 'XP/PAT', 'XPM/XPA', 'PATM/PATA'], [['XPM', 'XPA'], ['PATM', 'PATA']]]] as [$prefix, $pairs, $singlePairs]) {
                $observation = [];
                foreach ($pairs as $key) {
                    if (array_key_exists($key, $mapped)) {
                        $pair = Value::pair($mapped[$key]);
                        if ($observation !== [] && $observation !== $pair) {
                            throw new \DomainException('Conflicting paired observations.');
                        }
                        $observation = $pair;
                    }
                }
                foreach ($singlePairs as [$made, $attempted]) {
                    $m = $integer([$made]);
                    $a = $integer([$attempted]);
                    if ($m !== null || $a !== null) {
                        if ($m !== null && $a !== null && $m > $a) {
                            throw new \DomainException('Invalid kicking observations.');
                        }
                        if ($observation !== [] && (($m !== null && $m !== $observation[0]) || ($a !== null && $a !== $observation[1]))) {
                            throw new \DomainException('Conflicting kicking observations.');
                        }
                        $observation = [$m, $a];
                    }
                }
                if (($observation[0] ?? null) !== null) {
                    $updates[$prefix.'_made'] = $observation[0];
                }
                if (($observation[1] ?? null) !== null) {
                    $updates[$prefix.'_attempted'] = $observation[1];
                }
            }
        } elseif ($extended && str_contains($category, 'return')) {
            foreach (['kickoff' => 'kick', 'punt' => 'punt'] as $prefix => $word) {
                $specific = str_contains($category, $word);
                $otherSpecific = str_contains($category, $word === 'kick' ? 'punt' : 'kick');
                if ($otherSpecific && ! $specific) {
                    continue;
                }
                $kick = $word === 'kick';
                $put($prefix.'_returns', [...($specific ? ['RET'] : []), ...($kick ? ['KR', 'KRET'] : ['PR', 'PRET'])]);
                $put($prefix.'_return_yards', [...($specific ? ['YDS'] : []), ...($kick ? ['KRYDS', 'KR YDS'] : ['PRYDS', 'PR YDS'])], true);
                $put($prefix.'_return_touchdowns', [...($specific ? ['TD'] : []), $kick ? 'KRTD' : 'PRTD']);
                $put($prefix.'_return_long', [...($specific ? ['LONG', 'LNG'] : []), $kick ? 'KR LONG' : 'PR LONG'], true);
                $put($prefix.'_return_fair_catches', [...($specific ? ['FC'] : []), ...($kick ? ['KRFAIR', 'KR FC'] : ['PRFAIR', 'PR FC'])]);
            }
        }

        return $updates;
    }
}
