<?php

namespace App\Actions\ESPN\WNBA;

use App\Actions\ESPN\AbstractSyncTeams;
use App\DataTransferObjects\ESPN\TeamData;
use App\Models\WNBA\Team;
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
        $division = $this->normalizeConference((string) ($attributes['division'] ?? ''));

        if ($conference !== null) {
            $attributes['conference'] = $conference;
            $attributes['division'] = $division ?? $conference;

            return $attributes;
        }

        $alignment = $this->resolveAlignment($resolvedTeam, $rawTeam, $dto);
        if ($alignment !== null) {
            $attributes['conference'] = $alignment['conference'];
            $attributes['division'] = $alignment['division'];
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
            Log::warning('WNBA: Unable to fetch standings for team alignment backfill', [
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

        $this->parseStandingsNode($root, null);
    }

    /**
     * @param  array<string, mixed>  $node
     */
    protected function parseStandingsNode(array $node, ?string $conference): void
    {
        $name = trim((string) ($node['name'] ?? $node['abbreviation'] ?? ''));
        $conference = $this->normalizeConference($name) ?? $conference;

        $teamId = $this->extractTeamId($node);
        if ($teamId !== null && $conference !== null) {
            $this->alignmentByEspnId[$teamId] = [
                'conference' => $conference,
                'division' => $conference,
            ];
        }

        foreach ($node as $value) {
            if (! is_array($value)) {
                continue;
            }

            if (array_is_list($value)) {
                foreach ($value as $child) {
                    if (is_array($child)) {
                        $this->parseStandingsNode($child, $conference);
                    }
                }

                continue;
            }

            $this->parseStandingsNode($value, $conference);
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

    /**
     * @return array<string, array{conference:string,division:string}>
     */
    protected function abbreviationAlignmentMap(): array
    {
        return [
            'ATL' => ['conference' => 'Eastern', 'division' => 'Eastern'],
            'CHI' => ['conference' => 'Eastern', 'division' => 'Eastern'],
            'CON' => ['conference' => 'Eastern', 'division' => 'Eastern'],
            'IND' => ['conference' => 'Eastern', 'division' => 'Eastern'],
            'NY' => ['conference' => 'Eastern', 'division' => 'Eastern'],
            'TOR' => ['conference' => 'Eastern', 'division' => 'Eastern'],
            'WSH' => ['conference' => 'Eastern', 'division' => 'Eastern'],
            'WAS' => ['conference' => 'Eastern', 'division' => 'Eastern'],
            'DAL' => ['conference' => 'Western', 'division' => 'Western'],
            'GS' => ['conference' => 'Western', 'division' => 'Western'],
            'GSV' => ['conference' => 'Western', 'division' => 'Western'],
            'LA' => ['conference' => 'Western', 'division' => 'Western'],
            'LAS' => ['conference' => 'Western', 'division' => 'Western'],
            'LV' => ['conference' => 'Western', 'division' => 'Western'],
            'LVA' => ['conference' => 'Western', 'division' => 'Western'],
            'MIN' => ['conference' => 'Western', 'division' => 'Western'],
            'POR' => ['conference' => 'Western', 'division' => 'Western'],
            'PHX' => ['conference' => 'Western', 'division' => 'Western'],
            'SEA' => ['conference' => 'Western', 'division' => 'Western'],
        ];
    }
}
