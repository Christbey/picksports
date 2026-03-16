<?php

namespace App\Services\Settings;

use App\Http\Resources\Settings\CfbdTeamMappingResource;
use App\Http\Resources\Settings\EspnTeamOptionResource;
use App\Models\CfbdTeamMapping;
use App\Support\ResourcePayload;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;

class CfbdTeamMappingIndexDataService
{
    /**
     * @return array<string, int>
     */
    public function stats(string $sport): array
    {
        $baseQuery = CfbdTeamMapping::query()->where('sport', $sport);

        return [
            'total' => (clone $baseQuery)->count(),
            'mapped' => (clone $baseQuery)->whereNotNull('espn_team_name')->count(),
            'unmapped' => (clone $baseQuery)->whereNull('espn_team_name')->count(),
        ];
    }

    public function mappings(string $sport, string $filter, int $perPage = 50): LengthAwarePaginator
    {
        $query = CfbdTeamMapping::query()->where('sport', $sport);

        if ($filter === 'mapped') {
            $query->whereNotNull('espn_team_name');
        } elseif ($filter === 'unmapped') {
            $query->whereNull('espn_team_name');
        }

        $mappings = $query
            ->orderByRaw('espn_team_name IS NULL DESC')
            ->orderBy('cfbd_team_name')
            ->paginate($perPage)
            ->appends(['sport' => $sport, 'filter' => $filter]);

        $espnTeams = $this->espnTeamCandidates($this->cfbConfig());

        $mappings->through(
            fn (CfbdTeamMapping $mapping) => (new CfbdTeamMappingResource(
                $this->withSuggestedMapping($mapping, $espnTeams)
            ))->resolve()
        );

        return $mappings;
    }

    /**
     * @param  array{teamModel: class-string<\Illuminate\Database\Eloquent\Model>, teamField: string}  $config
     * @return array<int, array<string, mixed>>
     */
    public function espnTeams(array $config): array
    {
        $teamModel = $config['teamModel'];
        $teamField = $config['teamField'];
        $teams = $teamModel::query()
            ->select('id', $teamField.' as name', 'abbreviation', 'mascot')
            ->orderBy($teamField)
            ->get();

        return ResourcePayload::from(EspnTeamOptionResource::collection($teams));
    }

    /**
     * @return array{teamModel: class-string<Model>, teamField: string}
     */
    protected function cfbConfig(): array
    {
        return [
            'teamModel' => \App\Models\CFB\Team::class,
            'teamField' => 'school',
        ];
    }

    /**
     * @param  array{teamModel: class-string<Model>, teamField: string}  $config
     * @return array<int, array<string, mixed>>
     */
    protected function espnTeamCandidates(array $config): array
    {
        $teamModel = $config['teamModel'];
        $teamField = $config['teamField'];

        return $teamModel::query()
            ->select('id', $teamField.' as name', 'abbreviation', 'mascot')
            ->orderBy($teamField)
            ->get()
            ->map(fn ($team) => [
                'id' => $team->id,
                'name' => (string) $team->name,
                'abbreviation' => (string) ($team->abbreviation ?? ''),
                'mascot' => (string) ($team->mascot ?? ''),
            ])
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $espnTeams
     */
    protected function withSuggestedMapping(CfbdTeamMapping $mapping, array $espnTeams): CfbdTeamMapping
    {
        if ($mapping->espn_team_name) {
            return $mapping;
        }

        $suggestion = $this->bestSuggestion($mapping, $espnTeams);

        if ($suggestion === null) {
            return $mapping;
        }

        $mapping->setAttribute('suggested_espn_team_name', $suggestion['name']);
        $mapping->setAttribute('suggested_match_quality_score', $suggestion['score']);

        return $mapping;
    }

    /**
     * @param  array<int, array<string, mixed>>  $espnTeams
     * @return array{name: string, score: int}|null
     */
    protected function bestSuggestion(CfbdTeamMapping $mapping, array $espnTeams): ?array
    {
        $externalNames = collect([$mapping->cfbd_team_name, ...($mapping->alternate_names ?? [])])
            ->filter(fn ($name) => is_string($name) && trim($name) !== '')
            ->map(fn ($name) => trim((string) $name))
            ->unique()
            ->values()
            ->all();

        $best = null;

        foreach ($espnTeams as $team) {
            $score = $this->scoreSuggestion($mapping, $team, $externalNames);

            if ($score < 75) {
                continue;
            }

            if ($best === null || $score > $best['score']) {
                $best = [
                    'name' => $team['name'],
                    'score' => $score,
                ];
            }
        }

        return $best;
    }

    /**
     * @param  array<string, mixed>  $team
     * @param  array<int, string>  $externalNames
     */
    protected function scoreSuggestion(CfbdTeamMapping $mapping, array $team, array $externalNames): int
    {
        $externalAbbr = strtoupper(trim((string) ($mapping->cfbd_abbreviation ?? '')));
        $localAbbr = strtoupper(trim((string) ($team['abbreviation'] ?? '')));

        if ($externalAbbr !== '' && $externalAbbr === $localAbbr) {
            return 100;
        }

        $localName = $this->normalizeTeamName((string) $team['name']);
        $localDisplay = $this->normalizeTeamName(trim(((string) $team['name']).' '.((string) ($team['mascot'] ?? ''))));

        $best = 0;

        foreach ($externalNames as $externalName) {
            $normalizedExternal = $this->normalizeTeamName($externalName);

            if ($normalizedExternal === '' || $localName === '') {
                continue;
            }

            if ($normalizedExternal === $localName) {
                $best = max($best, 98);

                continue;
            }

            if ($normalizedExternal === $localDisplay) {
                $best = max($best, 96);

                continue;
            }

            if (str_contains($normalizedExternal, $localName) || str_contains($localName, $normalizedExternal)) {
                $best = max($best, 90);
            }

            similar_text($normalizedExternal, $localName, $namePercent);
            similar_text($normalizedExternal, $localDisplay, $displayPercent);
            $best = max($best, (int) round(max($namePercent, $displayPercent)));
        }

        return $best;
    }

    protected function normalizeTeamName(string $name): string
    {
        $name = mb_strtolower(trim($name));
        $name = preg_replace('/[.,"\']+/', '', $name);
        $name = preg_replace('/\s+/', ' ', $name ?? '');

        $replacements = [
            'state' => 'st',
            'saint' => 'st',
            'st.' => 'st',
            'university' => 'u',
            '&' => 'and',
        ];

        foreach ($replacements as $from => $to) {
            $name = str_replace($from, $to, $name);
        }

        return trim((string) preg_replace('/\s+/', ' ', $name));
    }
}
