<?php

namespace App\Services\Api\V2;

use Illuminate\Support\Arr;

/** Explicit summary allowlist; raw prompts, documents and operational metadata stay private. */
class GameResearchPresentation
{
    /**
     * @param  array<string, mixed>  $research
     * @param  array<string, mixed>  $review
     * @return array<string, mixed>
     */
    public function present(array $research, array $review): array
    {
        $data = Arr::only($research, [
            'schema_version', 'sport', 'game_id', 'sport_event_id', 'status', 'applicability',
            'assessed_at', 'expires_at', 'report_id', 'reason_codes',
        ]);
        foreach (['supporting', 'concerns', 'facts', 'unresolved'] as $section) {
            $data[$section] = collect($research[$section] ?? [])->filter(fn ($finding): bool => is_array($finding))->map(function (array $finding): array {
                $finding = Arr::only($finding, ['claim', 'interpretation', 'topic', 'team_side', 'scope', 'reason_code', 'evidence_kind', 'evidence_reference', 'evidence_status', 'source_url']);
                if (isset($finding['source_url']) && ! $this->safeUrl($finding['source_url'])) {
                    $finding['source_url'] = null;
                }

                return $finding;
            })->values()->all();
        }
        $data['sources'] = collect($research['sources'] ?? [])->filter(fn ($source): bool => is_array($source))
            ->filter(fn (array $source): bool => $this->safeUrl($source['url'] ?? null))
            ->map(fn (array $source): array => Arr::only($source, ['url', 'title', 'published_at', 'observed_at']))
            ->values()->all();
        $data['review'] = Arr::only($review, ['status', 'reviewed_at', 'summary', 'context_summary', 'key_factors', 'risk_flags', 'reason_code']);
        $data['season_evidence'] = [];
        foreach (['home', 'away'] as $side) {
            $sample = data_get($research, 'season_evidence.'.$side)
                ?? data_get($research, 'season_evidence.teams.'.$side.'.last_five');
            if (is_array($sample) && is_numeric($sample['sample_games'] ?? $sample['games'] ?? null)) {
                $data['season_evidence'][$side] = Arr::only($sample, ['sample_games', 'games', 'wins', 'losses', 'ties', 'average_margin', 'points_per_game', 'points_allowed_per_game']);
            }
        }
        // Preserve the object contract even when neither team has a stored sample.
        $data['season_evidence'] = (object) $data['season_evidence'];

        return $data;
    }

    private function safeUrl(mixed $url): bool
    {
        return is_string($url) && filter_var($url, FILTER_VALIDATE_URL) !== false
            && parse_url($url, PHP_URL_SCHEME) === 'https'
            && ! parse_url($url, PHP_URL_USER) && ! parse_url($url, PHP_URL_PASS);
    }
}
