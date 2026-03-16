<?php

namespace App\Support;

use App\Models\CFB\Team;
use App\Models\CFB\TeamSeasonAffiliation;

class CfbSeasonAffiliationResolver
{
    public function ensureForSeason(Team $team, int $season): TeamSeasonAffiliation
    {
        return TeamSeasonAffiliation::query()->updateOrCreate(
            [
                'team_id' => $team->id,
                'season' => $season,
            ],
            $this->attributesForSeason($team, $season),
        );
    }

    /**
     * @return array{subdivision:?string,conference:?string,division:?string,source:string}
     */
    public function attributesForSeason(Team $team, int $season): array
    {
        $attributes = [
            'subdivision' => $this->normalizeSubdivision($team->division),
            'conference' => $team->conference,
            'division' => $team->division,
            'source' => 'team_snapshot',
        ];

        $override = $this->matchingOverride($team, $season);

        if ($override !== null) {
            $attributes = array_merge($attributes, array_filter([
                'subdivision' => $this->normalizeSubdivision($override['subdivision'] ?? null) ?? ($override['division'] ?? null),
                'conference' => $override['conference'] ?? $attributes['conference'],
                'division' => $override['division'] ?? $attributes['division'],
                'source' => 'config_override',
            ], fn ($value) => $value !== null));
        }

        if ($attributes['subdivision'] === null) {
            $attributes['subdivision'] = $this->inferSubdivisionFromDivision($attributes['division']);
        }

        return $attributes;
    }

    public function isFbs(Team $team, int $season): bool
    {
        return $this->ensureForSeason($team, $season)->isFbs();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function matchingOverride(Team $team, int $season): ?array
    {
        $overrides = (array) config('cfb.season_affiliations.overrides', []);
        $candidateKeys = array_values(array_unique(array_filter([
            (string) $team->espn_id,
            strtoupper(trim((string) $team->abbreviation)),
            strtolower(trim((string) $team->school)),
            strtolower(trim((string) $team->display_name)),
        ])));

        foreach ($candidateKeys as $key) {
            $rules = $overrides[$key] ?? null;
            if (! is_array($rules)) {
                continue;
            }

            foreach ($rules as $rule) {
                if (! is_array($rule)) {
                    continue;
                }

                $start = isset($rule['start_season']) ? (int) $rule['start_season'] : null;
                $end = isset($rule['end_season']) ? (int) $rule['end_season'] : null;

                if ($start !== null && $season < $start) {
                    continue;
                }

                if ($end !== null && $season > $end) {
                    continue;
                }

                return $rule;
            }
        }

        return null;
    }

    private function normalizeSubdivision(?string $value): ?string
    {
        $normalized = trim((string) $value);
        if ($normalized === '') {
            return null;
        }

        $upper = strtoupper($normalized);

        return match ($upper) {
            'FBS', 'BOWL SUBDIVISION', 'FOOTBALL BOWL SUBDIVISION' => config('cfb.teams.divisions.fbs', 'FBS'),
            'FCS', 'CHAMPIONSHIP SUBDIVISION', 'FOOTBALL CHAMPIONSHIP SUBDIVISION' => config('cfb.teams.divisions.fcs', 'FCS'),
            default => null,
        };
    }

    private function inferSubdivisionFromDivision(?string $value): ?string
    {
        $division = strtolower(trim((string) $value));

        return match (true) {
            $division === '' => null,
            str_contains($division, 'fbs') => config('cfb.teams.divisions.fbs', 'FBS'),
            str_contains($division, 'fcs') => config('cfb.teams.divisions.fcs', 'FCS'),
            default => null,
        };
    }
}
