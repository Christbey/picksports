<?php

namespace App\Services\CFB\Availability;

use App\Models\CFB\AvailabilityReport;
use App\Models\CFB\Game;
use App\Models\CFB\PlayerInjury;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class AvailabilityEvidence
{
    public function forGame(Game $game, int $teamId, ?CarbonInterface $asOf = null): array
    {
        $asOf = CarbonImmutable::instance($asOf ?? now())->setTimezone(config('app.timezone'));
        $kickoff = $game->sportEvent?->starts_at;
        $gameDate = $kickoff ? $kickoff->copy()->setTimezone('America/Chicago')->toDateString() : $game->game_date->toDateString();
        $injuries = PlayerInjury::query()->with('player')->where('team_id', $teamId)->where('is_active', true)
            ->where('created_at', '<=', $asOf)->where('updated_at', '<=', $asOf)
            ->where(fn ($q) => $q->whereNull('source_updated_at')->orWhere('source_updated_at', '<=', $asOf))
            ->where(fn ($q) => $q->whereNull('injury_date')->orWhereDate('injury_date', '<=', $gameDate))
            ->where(fn ($q) => $q->whereNull('return_date')->orWhereDate('return_date', '>=', $gameDate))
            ->get()->filter(fn ($i) => ! data_get($i->raw_payload, 'game_id') || (int) data_get($i->raw_payload, 'game_id') === $game->id)
            ->sortByDesc(fn ($i) => $i->source_updated_at?->getTimestamp() ?? $i->updated_at->getTimestamp())->unique('player_id')->keyBy('player_id');
        $report = AvailabilityReport::query()->where('game_id', $game->id)->where('team_id', $teamId)
            ->where('observed_at', '<=', $asOf)->where('reported_at', '<=', $asOf)->where('created_at', '<=', $asOf)
            ->when($kickoff, fn ($q) => $q->where('reported_at', '<', $kickoff))
            ->orderByDesc('reported_at')->orderByDesc('id')->first();
        if ($report) {
            $severity = ['available' => 0, 'probable' => 1, 'questionable' => 2, 'doubtful' => 3, 'out_first_half' => 4, 'out' => 5];
            $entries = collect($report->entries)->sortByDesc(fn ($entry) => $severity[$entry['status']] ?? 5)->unique('player_id');
            foreach ($entries as $entry) {
                if (! $id = ($entry['player_id'] ?? null)) {
                    continue;
                }
                $existing = $injuries->get($id);
                $existingAt = $existing?->source_updated_at ?? $existing?->updated_at;
                if ($existingAt && $existingAt->gt($report->reported_at)) {
                    continue;
                }
                if (in_array($entry['status'], ['available', 'probable'], true)) {
                    $injuries->forget($id);

                    continue;
                }
                $injuries->put($id, new PlayerInjury(['player_id' => $id, 'team_id' => $teamId, 'status' => $entry['status'],
                    'detail' => 'Official game availability: '.$entry['source_status'], 'is_active' => true,
                    'source_updated_at' => $report->reported_at, 'raw_payload' => ['source' => $report->source, 'source_url' => $report->source_url,
                        'game_id' => $game->id, 'availability_report_id' => $report->id]]));
            }
        }
        $fresh = $report && $report->reported_at->gte($asOf->subHours((int) config('cfb_availability.max_age_hours', 36)));
        $complete = $fresh && $report->state === 'complete';
        $coverage = ['state' => ! $report ? 'unknown' : (! $fresh ? 'stale' : $report->state), 'complete' => (bool) $complete,
            'report_id' => $report?->id, 'source' => $report?->source, 'source_url' => $report?->source_url,
            'reported_at' => $report?->reported_at?->toIso8601String(), 'observed_at' => $report?->observed_at?->toIso8601String(),
            'issues' => $report?->issues ?? [], 'interpretation' => 'Missing reports and empty ESPN responses do not establish a healthy roster.'];

        return ['injuries' => $injuries->filter(fn ($i) => ! in_array(strtolower($i->status), ['active', 'available', 'probable'], true))->values(),
            'coverage' => $coverage];
    }

    public function snapshotInjuries(Collection $injuries): array
    {
        return $injuries->map(fn ($i) => ['player_id' => $i->player_id, 'status' => strtolower($i->status), 'type' => $i->type,
            'detail' => $i->detail, 'source_updated_at' => $i->source_updated_at?->toIso8601String(),
            'source' => data_get($i->raw_payload, 'source', 'espn'), 'source_url' => data_get($i->raw_payload, 'source_url'),
            'availability_report_id' => data_get($i->raw_payload, 'availability_report_id')])->all();
    }
}
