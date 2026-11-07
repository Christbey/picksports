<?php

namespace App\Services\CFB\Availability;

use App\Models\CFB\AvailabilityReport;
use App\Models\CFB\Game;
use App\Models\CFB\Player;
use App\Models\CFB\PlayerInjury;
use App\Models\CFB\Team;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class OfficialAvailabilityIngestor
{
    public const ENDPOINT = 'https://app.hdintelligence.com/api/get-publish-public';

    public function sync(Collection $games): array
    {
        $summary = ['reports' => 0, 'partial_reports' => 0, 'source_failures' => []];
        foreach (config('cfb_availability.conferences', []) as $conference => $source) {
            try {
                $payload = Http::acceptJson()->connectTimeout(5)->timeout(30)
                    ->post(self::ENDPOINT, ['sport' => 'Football', 'organization' => $source['code'], 'conference' => $source['code']])
                    ->throw()->json();
                if (! is_array($payload)) {
                    throw new RuntimeException('Official report response was not an object.');
                }
                foreach ($payload as $key => $report) {
                    if (! is_array($report)) {
                        continue;
                    }
                    try {
                        $result = $this->ingest((string) $key, $report, $source, $games);
                        $summary['reports'] += $result['reports'];
                        $summary['partial_reports'] += $result['partial_reports'];
                    } catch (\Throwable $error) {
                        report($error);
                        $summary['source_failures'][$conference.':'.$key] = get_class($error);
                    }
                }
            } catch (\Throwable $error) {
                report($error);
                $summary['source_failures'][$conference] = get_class($error);
            }
        }

        return $summary;
    }

    public function ingest(string $key, array $report, array $source, Collection $games): array
    {
        $result = ['reports' => 0, 'partial_reports' => 0];
        $sections = $report['games'] ?? null;
        $date = data_get($report, 'footer.date');
        if (! is_array($sections) || count($sections) !== 2 || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $date)
            || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($report['publishDate'] ?? ''))
            || ! preg_match('/^\d{1,2}:\d{2}:\d{2}$/', (string) ($report['postedTime'] ?? ''))) {
            throw new RuntimeException('Official report is missing game/date metadata.');
        }
        $timezone = match ($report['conferenceTimeZone'] ?? '') {
            'ET' => 'America/New_York', 'CT' => 'America/Chicago', 'MT' => 'America/Denver', 'PT' => 'America/Los_Angeles',
            default => $source['timezone'],
        };
        $reported = CarbonImmutable::parse($report['publishDate'].' '.$report['postedTime'], $timezone)->setTimezone(config('app.timezone'));
        if ($reported->isFuture()) {
            throw new RuntimeException('Official report publication time is in the future.');
        }
        $matched = $games->filter(function (Game $game) use ($sections, $date, $timezone): bool {
            $kickoff = $game->sportEvent?->starts_at;
            if (! $kickoff || $kickoff->copy()->setTimezone($timezone)->toDateString() !== $date) {
                return false;
            }
            $names = [$this->teamMatches($game->homeTeam, $sections[0]), $this->teamMatches($game->awayTeam, $sections[1])];
            $reverse = [$this->teamMatches($game->awayTeam, $sections[0]), $this->teamMatches($game->homeTeam, $sections[1])];

            return ! in_array(false, $names, true) || ! in_array(false, $reverse, true);
        });
        if ($matched->count() !== 1) {
            return $result;
        }
        $game = $matched->first();
        if ($reported->gte($game->sportEvent->starts_at)) {
            throw new RuntimeException('Report is not a pregame publication.');
        }
        foreach ($sections as $section) {
            if (! isset($section['rows']) || ! is_array($section['rows']) || $section['rows'] === []) {
                throw new RuntimeException('Missing player rows cannot establish availability coverage.');
            }
        }
        foreach ($sections as $section) {
            $team = $this->teamMatches($game->homeTeam, $section) ? $game->homeTeam : $game->awayTeam;
            if (! isset($section['rows']) || ! is_array($section['rows']) || $section['rows'] === []) {
                throw new RuntimeException('Missing player rows cannot establish availability coverage.');
            }
            $players = Player::query()->where('team_id', $team->id)->get()->groupBy(fn ($p) => $this->normalize($p->full_name));
            $entries = [];
            $issues = [];
            foreach ($section['rows'] as $row) {
                if (! is_array($row) || ! is_string($row['name'] ?? null) || ! is_string($row['status'] ?? null)) {
                    $issues[] = 'malformed_player_row';

                    continue;
                }
                $name = trim(preg_replace('/^[A-Za-z]+\s+#\d+\s+/', '', $row['name']));
                $status = $this->status($row['status']);
                if ($status === null) {
                    $issues[] = 'unknown_status:'.$row['status'];

                    continue;
                }
                if ($status === 'exempt') {
                    $issues[] = 'exempt_player_availability_unknown';

                    continue;
                }
                $matches = $players->get($this->normalize($name), collect());
                $playerId = $matches->count() === 1 ? $matches->first()->id : null;
                if (! $playerId && ! in_array($status, ['available', 'probable'], true)) {
                    $issues[] = 'unmatched_player:'.$name;
                }
                $entries[] = ['player_id' => $playerId, 'name' => $name, 'status' => $status, 'source_status' => $row['status']];
            }
            $duplicate = collect($entries)->whereNotNull('player_id')->groupBy('player_id')->contains(fn ($rows) => $rows->pluck('status')->unique()->count() > 1);
            if ($duplicate) {
                $issues[] = 'conflicting_player_statuses';
            }
            $hash = hash('sha256', json_encode([$reported->toIso8601String(), $entries, $issues], JSON_THROW_ON_ERROR));
            $storedReport = AvailabilityReport::firstOrCreate(['game_id' => $game->id, 'team_id' => $team->id, 'source' => 'official_'.$source['code'],
                'report_key' => $key, 'payload_hash' => $hash], ['source_url' => $source['url'], 'state' => $issues === [] ? 'complete' : 'partial',
                    'reported_at' => $reported, 'observed_at' => now(), 'entries' => $entries, 'issues' => array_values(array_unique($issues))]);
            $this->mirrorCurrentInjuries($storedReport, $game->sportEvent->starts_at->copy()->setTimezone(config('app.timezone'))->toDateString());
            $result['reports']++;
            $result['partial_reports'] += $issues === [] ? 0 : 1;
        }

        return $result;
    }

    private function mirrorCurrentInjuries(AvailabilityReport $report, string $gameDate): void
    {
        if (AvailabilityReport::query()->where('game_id', $report->game_id)->where('team_id', $report->team_id)
            ->where('reported_at', '>', $report->reported_at)->exists()) {
            return;
        }
        $severity = ['available' => 0, 'probable' => 1, 'questionable' => 2, 'doubtful' => 3, 'out_first_half' => 4, 'out' => 5];
        $entries = collect($report->entries)->whereNotNull('player_id')->sortByDesc(fn ($e) => $severity[$e['status']] ?? 5)->unique('player_id');
        $currentByPlayer = PlayerInjury::query()->where('team_id', $report->team_id)->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('raw_payload->game_id')->orWhere('raw_payload->game_id', $report->game_id))->get()->groupBy('player_id');
        foreach ($entries as $entry) {
            $key = $report->source.':game:'.$report->game_id;
            $existing = $currentByPlayer->get($entry['player_id'], collect());
            $newer = $existing->contains(fn ($i) => ($i->source_updated_at ?? $i->updated_at)->gt($report->reported_at));
            foreach ($existing as $injury) {
                if (($injury->source_updated_at ?? $injury->updated_at)->lte($report->reported_at)) {
                    $injury->update(['is_active' => false]);
                }
            }
            if (in_array($entry['status'], ['available', 'probable'], true) || $newer) {
                continue;
            }
            PlayerInjury::updateOrCreate(['player_id' => $entry['player_id'], 'injury_key' => $key], [
                'team_id' => $report->team_id, 'status' => $entry['status'], 'is_active' => true,
                'injury_date' => $gameDate, 'return_date' => $gameDate, 'source_updated_at' => $report->reported_at,
                'detail' => 'Official game availability: '.$entry['source_status'],
                'raw_payload' => ['source' => $report->source, 'source_url' => $report->source_url, 'game_id' => $report->game_id,
                    'availability_report_id' => $report->id, 'scope' => 'Game-specific availability; return_date is scope end, not medical clearance.'],
            ]);
        }
    }

    private function teamMatches(Team $team, array $section): bool
    {
        $names = [$this->normalize($team->school), $this->normalize($team->abbreviation)];
        foreach (['teamDisplayName', 'teamName'] as $field) {
            if (! empty($section[$field]) && in_array($this->normalize($section[$field]), $names, true)) {
                return true;
            }
        }

        return false;
    }

    private function normalize(string $value): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower(Str::ascii($value)));
    }

    private function status(string $value): ?string
    {
        return match (strtolower(trim($value))) {
            'available', 'active' => 'available', 'probable' => 'probable', 'questionable', 'game time decision' => 'questionable',
            'doubtful' => 'doubtful', 'out' => 'out', 'out - (1st half)', 'out (1st half)' => 'out_first_half', 'exempt' => 'exempt',
            default => null,
        };
    }
}
