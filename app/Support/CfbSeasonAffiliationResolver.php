<?php

namespace App\Support;

use App\Models\CFB\Team;
use App\Models\CFB\TeamSeasonAffiliation;

class CfbSeasonAffiliationResolver
{
    /** Explicit persistence only; historical seasons require provider-scoped attributes. */
    public function ensureForSeason(Team $team, int $season, ?array $attributes = null): TeamSeasonAffiliation
    {
        if ($attributes === null) {
            if ($season !== (int) now()->format('Y')) {
                throw new \InvalidArgumentException('Historical affiliations require season-specific provider evidence.');
            }
            $team = Team::query()->findOrFail($team->getKey());
            $attributes = $this->attributesForSeason($team, $season);
        }

        foreach (['subdivision', 'conference', 'division', 'source'] as $field) {
            if (! array_key_exists($field, $attributes)) {
                throw new \InvalidArgumentException("Affiliation synchronization requires {$field}.");
            }
        }

        return TeamSeasonAffiliation::query()->updateOrCreate(
            ['team_id' => $team->id, 'season' => $season],
            $attributes,
        );
    }

    /** @return array{subdivision:?string,conference:?string,division:?string,source:string} */
    public function attributesForSeason(Team $team, int $season): array
    {
        // Reload partial models without mutating them, and never persist during eligibility reads.
        if ($team->exists && array_diff(['division', 'conference', 'espn_id', 'school', 'display_name', 'abbreviation'], array_keys($team->getAttributes())) !== []) {
            $team = Team::query()->find($team->getKey()) ?? $team;
        }
        $stored = $team->seasonAffiliation($season);
        $trusted = $stored && ! in_array($stored->source, ['team_snapshot', 'config_override', null], true);
        if ($trusted) {
            return $stored->only(['subdivision', 'conference', 'division', 'source']);
        }

        $attributes = ['subdivision' => null, 'conference' => null, 'division' => null, 'source' => 'unknown'];
        if ($season === (int) now()->format('Y')) {
            $attributes = [
                'subdivision' => $this->normalizeSubdivision($team->division)
                    ?? $this->inferSubdivisionFromDivision($team->division)
                    ?? $this->inferSubdivisionFromDivision($team->conference),
                'conference' => $team->conference,
                'division' => $team->division,
                'source' => 'team_snapshot',
            ];
            // Legacy ESPN rows store the conference's East/West child as conference.
            if ($this->isFbsConference($team->division)) {
                $attributes['conference'] = $team->division;
                $attributes['division'] = $team->conference;
            } elseif ($this->normalizeSubdivision($team->division) !== null) {
                $attributes['division'] = null;
            }
        }

        $override = $this->matchingOverride($team, $season);
        if ($override !== null) {
            foreach (['conference', 'division'] as $field) {
                if (array_key_exists($field, $override)) {
                    $attributes[$field] = $override[$field];
                }
            }
            $attributes['subdivision'] = $this->normalizeSubdivision($override['subdivision'] ?? null)
                ?? $attributes['subdivision'];
            $attributes['source'] = 'config_override';
        }

        return $attributes;
    }

    public function isFbs(Team $team, int $season): bool
    {
        return $this->attributesForSeason($team, $season)['subdivision'] === config('cfb.teams.divisions.fbs', 'FBS');
    }

    private function isFbsConference(?string $value): bool
    {
        $conferences = array_merge(
            config('cfb.teams.power_conferences', []),
            config('cfb.teams.group_of_five_conferences', []),
            ['Pac-12 Conference', 'FBS Independents', 'Sun Belt', 'SEC', 'ACC', 'Big Ten', 'Big 12', 'American Athletic', 'Mid-American', 'Mountain West', 'Pac-12'],
        );

        return in_array(strtolower(trim((string) $value)), array_map('strtolower', $conferences), true);
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
            $this->isFbsConference($value) => config('cfb.teams.divisions.fbs', 'FBS'),
            str_contains($division, 'fbs') => config('cfb.teams.divisions.fbs', 'FBS'),
            str_contains($division, 'fcs') => config('cfb.teams.divisions.fcs', 'FCS'),
            default => null,
        };
    }
}
