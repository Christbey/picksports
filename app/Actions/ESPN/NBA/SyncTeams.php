<?php

namespace App\Actions\ESPN\NBA;

use App\Actions\ESPN\AbstractSyncTeams;
use App\DataTransferObjects\ESPN\TeamData;
use App\Models\NBA\Team;
use Illuminate\Support\Facades\Log;

class SyncTeams extends AbstractSyncTeams
{
    protected const TEAM_MODEL_CLASS = Team::class;

    protected const TEAM_DTO_CLASS = TeamData::class;

    /**
     * @var array<string, array{conference:string,division:string}>
     */
    protected array $alignmentByEspnId = [];

    protected bool $standingsLoaded = false;

    /**
     * @param  array<string, mixed>  $resolvedTeam
     * @param  array<string, mixed>  $rawTeam
     * @return array<string, mixed>
     */
    protected function mapTeamAttributes(object $dto, array $resolvedTeam, array $rawTeam): array
    {
        $attributes = parent::mapTeamAttributes($dto, $resolvedTeam, $rawTeam);

        $conference = $this->normalizeConference((string) ($attributes['conference'] ?? ''));
        $division = $this->normalizeDivision((string) ($attributes['division'] ?? ''));

        if ($conference !== null && $division !== null) {
            $attributes['conference'] = $conference;
            $attributes['division'] = $division;

            return $attributes;
        }

        $alignment = $this->resolveAlignment($resolvedTeam, $rawTeam, $dto);
        if ($alignment !== null) {
            $attributes['conference'] = $conference ?? $alignment['conference'];
            $attributes['division'] = $division ?? $alignment['division'];
        }

        return $attributes;
    }

    /**
     * @param  array<string, mixed>  $resolvedTeam
     * @param  array<string, mixed>  $rawTeam
     * @return array{conference:string,division:string}|null
     */
    protected function resolveAlignment(array $resolvedTeam, array $rawTeam, object $dto): ?array
    {
        $teamId = trim((string) ($resolvedTeam['id'] ?? $rawTeam['id'] ?? $dto->espnId ?? ''));
        $teamAbbreviation = strtoupper(trim((string) ($resolvedTeam['abbreviation'] ?? $rawTeam['abbreviation'] ?? $dto->abbreviation ?? '')));

        $this->loadStandingsAlignmentMap();

        if ($teamId !== '' && isset($this->alignmentByEspnId[$teamId])) {
            return $this->alignmentByEspnId[$teamId];
        }

        return $this->abbreviationAlignmentMap()[$teamAbbreviation] ?? null;
    }

    protected function loadStandingsAlignmentMap(): void
    {
        if ($this->standingsLoaded) {
            return;
        }

        $this->standingsLoaded = true;

        try {
            $standings = $this->espnService->getStandings();
        } catch (\Throwable $e) {
            Log::warning('NBA: Unable to fetch standings for team alignment backfill', [
                'error' => $e->getMessage(),
            ]);

            return;
        }

        if (! is_array($standings)) {
            return;
        }

        $root = $standings['sports'][0]['leagues'][0] ?? $standings;
        if (! is_array($root)) {
            return;
        }

        $this->parseStandingsNode($root, null, null);
    }

    /**
     * @param  array<string, mixed>  $node
     */
    protected function parseStandingsNode(array $node, ?string $conference, ?string $division): void
    {
        $name = trim((string) ($node['name'] ?? $node['abbreviation'] ?? ''));
        $conference = $this->normalizeConference($name) ?? $conference;

        $normalizedDivision = $this->normalizeDivision($name);
        if ($normalizedDivision !== null) {
            $division = $normalizedDivision;
            $conference ??= $this->conferenceFromDivision($normalizedDivision);
        }

        $teamId = $this->extractTeamId($node);
        if ($teamId !== null && $conference !== null && $division !== null) {
            $this->alignmentByEspnId[$teamId] = [
                'conference' => $conference,
                'division' => $division,
            ];
        }

        foreach ($node as $value) {
            if (! is_array($value)) {
                continue;
            }

            if (array_is_list($value)) {
                foreach ($value as $child) {
                    if (is_array($child)) {
                        $this->parseStandingsNode($child, $conference, $division);
                    }
                }

                continue;
            }

            $this->parseStandingsNode($value, $conference, $division);
        }
    }

    /**
     * @param  array<string, mixed>  $node
     */
    protected function extractTeamId(array $node): ?string
    {
        $teamNode = is_array($node['team'] ?? null) ? $node['team'] : $node;

        $id = trim((string) ($teamNode['id'] ?? ''));
        if ($id !== '') {
            return $id;
        }

        $ref = trim((string) ($teamNode['$ref'] ?? ''));
        if ($ref === '') {
            return null;
        }

        if (preg_match('/\/teams\/(\d+)(?:\/|$)/', $ref, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    protected function normalizeConference(string $value): ?string
    {
        $normalized = strtolower(trim($value));

        return match ($normalized) {
            'eastern', 'east', 'eastern conference', 'east conference', 'e' => 'Eastern',
            'western', 'west', 'western conference', 'west conference', 'w' => 'Western',
            default => null,
        };
    }

    protected function normalizeDivision(string $value): ?string
    {
        $normalized = strtolower(trim(str_replace('division', '', $value)));

        return match ($normalized) {
            'atlantic' => 'Atlantic',
            'central' => 'Central',
            'southeast' => 'Southeast',
            'northwest' => 'Northwest',
            'pacific' => 'Pacific',
            'southwest' => 'Southwest',
            default => null,
        };
    }

    protected function conferenceFromDivision(string $division): ?string
    {
        return match ($division) {
            'Atlantic', 'Central', 'Southeast' => 'Eastern',
            'Northwest', 'Pacific', 'Southwest' => 'Western',
            default => null,
        };
    }

    /**
     * @return array<string, array{conference:string,division:string}>
     */
    protected function abbreviationAlignmentMap(): array
    {
        return [
            'ATL' => ['conference' => 'Eastern', 'division' => 'Southeast'],
            'BOS' => ['conference' => 'Eastern', 'division' => 'Atlantic'],
            'BKN' => ['conference' => 'Eastern', 'division' => 'Atlantic'],
            'CHA' => ['conference' => 'Eastern', 'division' => 'Southeast'],
            'CHI' => ['conference' => 'Eastern', 'division' => 'Central'],
            'CLE' => ['conference' => 'Eastern', 'division' => 'Central'],
            'DET' => ['conference' => 'Eastern', 'division' => 'Central'],
            'IND' => ['conference' => 'Eastern', 'division' => 'Central'],
            'MIA' => ['conference' => 'Eastern', 'division' => 'Southeast'],
            'MIL' => ['conference' => 'Eastern', 'division' => 'Central'],
            'NY' => ['conference' => 'Eastern', 'division' => 'Atlantic'],
            'ORL' => ['conference' => 'Eastern', 'division' => 'Southeast'],
            'PHI' => ['conference' => 'Eastern', 'division' => 'Atlantic'],
            'TOR' => ['conference' => 'Eastern', 'division' => 'Atlantic'],
            'WSH' => ['conference' => 'Eastern', 'division' => 'Southeast'],
            'DAL' => ['conference' => 'Western', 'division' => 'Southwest'],
            'DEN' => ['conference' => 'Western', 'division' => 'Northwest'],
            'GS' => ['conference' => 'Western', 'division' => 'Pacific'],
            'HOU' => ['conference' => 'Western', 'division' => 'Southwest'],
            'LAC' => ['conference' => 'Western', 'division' => 'Pacific'],
            'LAL' => ['conference' => 'Western', 'division' => 'Pacific'],
            'MEM' => ['conference' => 'Western', 'division' => 'Southwest'],
            'MIN' => ['conference' => 'Western', 'division' => 'Northwest'],
            'NO' => ['conference' => 'Western', 'division' => 'Southwest'],
            'NOP' => ['conference' => 'Western', 'division' => 'Southwest'],
            'OKC' => ['conference' => 'Western', 'division' => 'Northwest'],
            'PHX' => ['conference' => 'Western', 'division' => 'Pacific'],
            'POR' => ['conference' => 'Western', 'division' => 'Northwest'],
            'SAC' => ['conference' => 'Western', 'division' => 'Pacific'],
            'SA' => ['conference' => 'Western', 'division' => 'Southwest'],
            'SAS' => ['conference' => 'Western', 'division' => 'Southwest'],
            'UTA' => ['conference' => 'Western', 'division' => 'Northwest'],
            'UTAH' => ['conference' => 'Western', 'division' => 'Northwest'],
        ];
    }
}
