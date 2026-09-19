<?php

namespace App\Console\Commands\CFB;

use App\Models\CFB\Team;
use App\Models\CFB\TeamSeasonAffiliation;
use App\Models\CfbdTeamMapping;
use App\Services\CollegeFootballData\CollegeFootballDataService;
use App\Support\CfbSeasonAffiliationResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncSeasonAffiliationsCommand extends Command
{
    protected $signature = 'cfb:sync-season-affiliations {--season= : Required provider season} {--minimum-teams=100 : Minimum complete provider roster size}';

    protected $description = 'Synchronize season-specific FBS membership atomically from CFBD';

    public function handle(CollegeFootballDataService $service, CfbSeasonAffiliationResolver $resolver): int
    {
        $season = (int) $this->option('season');
        if ($season < 1978 || $season > (int) now()->format('Y') + 1) {
            $this->error('An explicit valid season is required.');

            return self::FAILURE;
        }
        $rows = $service->getFbsTeams($season);
        if (count($rows) < max(1, (int) $this->option('minimum-teams'))) {
            $this->error('Provider membership response is empty or incomplete; no affiliations changed.');

            return self::FAILURE;
        }

        $teams = Team::query()->get();
        $byId = $teams->filter(fn (Team $team) => $team->cfbd_team_id !== null)->keyBy('cfbd_team_id');
        $names = [];
        foreach ($teams as $team) {
            foreach ([$team->school, $team->display_name, $team->abbreviation, $team->short_display_name] as $name) {
                if ($key = $this->normalize($name)) {
                    $names[$key][$team->id] = $team;
                }
            }
        }
        $mappings = CfbdTeamMapping::query()->whereIn('sport', ['cfb', 'americanfootball_ncaaf'])->get()->keyBy('cfbd_team_id');
        $resolved = [];
        $matchedIds = [];
        $errors = [];
        foreach ($rows as $row) {
            $id = $row['id'] ?? null;
            $team = $id !== null ? $byId->get($id) : null;
            if (! $team) {
                // Exact school identity outranks abbreviations shared with unrelated schools.
                $exact = $teams->filter(fn (Team $candidate) => $this->normalize($candidate->school) === $this->normalize($row['school'] ?? null));
                if ($exact->count() > 1 && ! empty($row['mascot'])) {
                    $exact = $exact->filter(fn (Team $candidate) => $this->normalize($candidate->mascot) === $this->normalize($row['mascot']));
                }
                $team = $exact->count() === 1 ? $exact->first() : null;
                if (! $team && $exact->isEmpty()) {
                    $mapping = $mappings->get($id);
                    $candidates = [];
                    foreach ([$row['school'] ?? null, $row['abbreviation'] ?? null, ...($row['alternateNames'] ?? []), $mapping?->espn_team_name, ...($mapping?->alternate_names ?? [])] as $name) {
                        foreach ($names[$this->normalize($name)] ?? [] as $candidate) {
                            $candidates[$candidate->id] = $candidate;
                        }
                    }
                    $team = count($candidates) === 1 ? reset($candidates) : null;
                }
            }
            if (! $team || ! is_numeric($id) || (int) $id <= 0 || isset($resolved[$team->id]) || empty($row['conference']) || (isset($row['classification']) && strtolower($row['classification']) !== 'fbs')) {
                $errors[] = (string) ($row['school'] ?? $id ?? 'invalid row');

                continue;
            }
            $matchedIds[$team->id] = (int) $id;
            $resolved[$team->id] = [
                'subdivision' => 'FBS',
                'conference' => $row['conference'],
                'division' => $row['division'] ?? null,
                'source' => 'cfbd_fbs_membership',
            ];
        }
        $lost = TeamSeasonAffiliation::query()->where('season', $season)
            ->where('source', 'cfbd_fbs_membership')->where('subdivision', 'FBS')
            ->whereNotIn('team_id', array_keys($resolved))->pluck('team_id');
        if ($lost->isNotEmpty()) {
            $errors[] = 'previously verified membership missing: '.$lost->implode(',');
        }
        if ($errors !== []) {
            $this->error('Unmatched, ambiguous, duplicate or invalid provider teams: '.implode(', ', $errors).'. No affiliations changed.');

            return self::FAILURE;
        }
        DB::transaction(function () use ($teams, $resolved, $matchedIds, $season, $resolver): void {
            foreach ($teams as $team) {
                if (isset($matchedIds[$team->id]) && (int) $team->cfbd_team_id !== $matchedIds[$team->id]) {
                    $team->forceFill(['cfbd_team_id' => $matchedIds[$team->id]])->saveQuietly();
                }
                $resolver->ensureForSeason($team, $season, $resolved[$team->id] ?? [
                    'subdivision' => 'NON_FBS', 'conference' => null, 'division' => null, 'source' => 'cfbd_fbs_membership',
                ]);
            }
        });
        $this->info(sprintf('Season %d: %d/%d provider FBS teams matched; %d other local teams explicitly excluded. No unknown membership remains.', $season, count($resolved), count($rows), $teams->count() - count($resolved)));

        return self::SUCCESS;
    }

    private function normalize(?string $value): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower((string) $value)) ?? '';
    }
}
