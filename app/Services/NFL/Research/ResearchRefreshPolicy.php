<?php

namespace App\Services\NFL\Research;

use App\Models\NFL\Game;
use App\Models\NFL\PlayerInjury;
use App\Models\SportsGameContextReport;
use App\Services\NFL\GameWeatherService;
use App\Services\Sports\SportsDateWindowService;
use Carbon\CarbonInterface;

class ResearchRefreshPolicy
{
    public function freshnessMinutes(Game $game): int
    {
        $kickoff = app(SportsDateWindowService::class)->gameDateTimeUtc($game->game_date, $game->game_time);
        $hours = $kickoff ? now()->diffInHours($kickoff, false) : 0;
        $key = $hours > 48 ? 'early_minutes' : ($hours > 6 ? 'standard_minutes' : 'pregame_minutes');

        return max(1, (int) config('nfl_research.cost_control.'.$key, 360));
    }

    public function expiresAt(Game $game): CarbonInterface
    {
        $expiry = now()->addMinutes($this->freshnessMinutes($game));
        $kickoff = app(SportsDateWindowService::class)->gameDateTimeUtc($game->game_date, $game->game_time);

        return $kickoff && $kickoff->lt($expiry) ? $kickoff : $expiry;
    }

    public function current(?SportsGameContextReport $report, Game $game, string $fingerprint, ?string $candidateHash = null): bool
    {
        return $report && $report->status === 'ready'
            && $report->expires_at?->isFuture()
            && $report->researched_at?->gt(now()->subMinutes($this->freshnessMinutes($game)))
            && data_get($report->raw_payload, 'research_fingerprint') === $fingerprint
            && ($candidateHash === null || data_get($report->raw_payload, 'candidate_hash') === $candidateHash);
    }

    public function fingerprint(Game $game, array $packet): string
    {
        $weather = $game->weather;
        $weatherValues = [];
        // Ignore retrieval timestamps and insignificant forecast noise, not threshold crossings.
        foreach (['temperature_f' => 5, 'wind_speed_mph' => 5, 'wind_gust_mph' => 5, 'precipitation_inches' => 0.03, 'precipitation_probability' => 20] as $key => $step) {
            $value = $weather?->$key;
            $weatherValues[$key] = is_numeric($value) ? floor((float) $value / $step) : null;
        }
        $weatherValues['freezing'] = is_numeric($weather?->temperature_f) ? $weather->temperature_f <= 32 : null;
        $weatherValues['heat'] = is_numeric($weather?->temperature_f) ? $weather->temperature_f >= 88 : null;
        $weatherValues['high_gust'] = is_numeric($weather?->wind_gust_mph) ? $weather->wind_gust_mph >= 24 : null;

        return hash('sha256', json_encode([
            'version' => 1,
            'prompt_version' => config('ai.features.nfl_game_context_research.prompt_version'),
            'uncertainty_policy' => ResearchUncertaintyPolicy::VERSION,
            'documents' => collect(app(EvidencePacket::class)->researchDocuments($packet))
                ->map(fn ($d) => [$d['id'], $d['content_hash'] ?? null])->sortBy(fn ($d) => $d[0])->values()->all(),
            'availability' => collect($packet['availability'])->map(fn ($f) => [$f['player_id'], $f['status'], $f['document_id']])
                ->sortBy(fn ($f) => $f[0])->values()->all(),
            'injuries' => $this->injuries($game),
            'game' => [$game->home_team_id, $game->away_team_id, $game->season, $game->season_type, $game->game_date?->toDateString(), $game->game_time, $game->venue_name, $game->home_qb_name, $game->away_qb_name, $game->home_coach, $game->away_coach],
            'weather' => [$weatherValues, $weather?->is_indoor, app(GameWeatherService::class)->roofStatus($game)],
        ], JSON_THROW_ON_ERROR));
    }

    public function injuries(Game $game): array
    {
        return PlayerInjury::query()->whereIn('team_id', [$game->home_team_id, $game->away_team_id])
            ->where('is_active', true)->orderBy('player_id')->orderBy('injury_key')
            ->get(['player_id', 'team_id', 'injury_key', 'status', 'detail', 'type', 'injury_date', 'return_date'])->toArray();
    }
}
