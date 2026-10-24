<?php

namespace App\Services\NFL\Research;

use Illuminate\Support\Arr;

class ResearchClaimGrounding
{
    public function apply(array $decoded, array $input): array
    {
        $quality = ['version' => 1, 'verified_claims' => 0, 'rejected_claims' => 0, 'unverified_questions' => 0];
        foreach (['facts', 'supporting', 'opposing', 'prop_angles', 'unresolved'] as $section) {
            $rows = $section === 'facts' ? ($decoded['facts'] ?? []) : ($decoded['decision_research'][$section] ?? []);
            $kept = [];
            foreach ($rows as $row) {
                $valid = $this->verified($row, $input);
                if (! $valid) {
                    if ($section !== 'unresolved') {
                        $quality['rejected_claims']++;

                        continue;
                    }
                    $quality['unverified_questions']++;
                    $row['evidence_status'] = 'unverified_question';
                } else {
                    $quality['verified_claims']++;
                    $row['evidence_status'] = 'reference_verified';
                }
                // A numerical model forecast is context, never its own corroboration.
                if (in_array($section, ['supporting', 'opposing'], true)
                    && ($row['evidence_kind'] ?? null) === 'application'
                    && ! str_starts_with($row['evidence_reference'] ?? '', 'analysis_context.season_evidence.')) {
                    $decoded['decision_research']['model_context'][] = $row;

                    continue;
                }
                if ($section === 'unresolved' && $valid) {
                    $row = $this->quarterbackQuestion($row, $input);
                }
                $kept[] = $row;
            }
            if ($section === 'facts') {
                $decoded['facts'] = $kept;
            } else {
                $decoded['decision_research'][$section] = $kept;
            }
        }
        $decoded['analysis_quality'] = $quality;

        return $decoded;
    }

    private function verified(array $row, array $input): bool
    {
        if (($row['evidence_kind'] ?? null) === 'application') {
            $ref = $row['evidence_reference'] ?? '';
            $allowed = collect(['analysis_context.final_forecast.', 'analysis_context.season_evidence.', 'analysis_context.quarterbacks.', 'synced_market.', 'synced_weather.'])
                ->contains(fn ($prefix) => str_starts_with($ref, $prefix));

            return $allowed && Arr::has($input, $ref) && data_get($input, $ref) !== null;
        }
        $document = collect($input['official_documents'] ?? [])->first(fn ($doc) => (string) $doc['id'] === (string) ($row['document_id'] ?? ''));
        $excerpt = $this->text((string) ($row['evidence_excerpt'] ?? ''));
        $urls = $row['source_urls'] ?? [$row['source_url'] ?? null];

        return $document && in_array($document['url'], $urls, true) && mb_strlen($excerpt) >= 12
            && str_contains($this->text($document['text'] ?? ''), $excerpt);
    }

    private function quarterbackQuestion(array $row, array $input): array
    {
        if (! in_array((string) ($input['season_type'] ?? ''), ['2', '3'], true)
            || ! in_array($row['reason_code'] ?? null, ['model_input_conflict', 'material_availability'], true)) {
            return $row;
        }
        $projected = $row['projected_player'] ?? null;
        $reported = $row['reported_player'] ?? null;
        $matchesInput = collect(data_get($input, 'analysis_context.quarterbacks', []))
            ->contains(fn ($qb) => $projected && $this->text($qb['projected_player'] ?? '') === $this->text($projected));
        $quarterbacks = collect(data_get($input, 'analysis_context.quarterbacks', []));
        $qb = $quarterbacks->first(fn ($qb) => $projected && $this->text($qb['projected_player'] ?? '') === $this->text($projected));
        $injured = collect($input['synced_injuries'] ?? [])->contains(fn ($injury) => ! empty($qb['player_id'])
            && (string) ($injury['player_id'] ?? '') === (string) $qb['player_id']);
        $unavailable = collect($input['verified_availability'] ?? [])->contains(fn ($fact) => ! empty($qb['player_id'])
            && (string) ($fact['player_id'] ?? '') === (string) $qb['player_id'] && ($fact['status'] ?? null) === 'Out');
        if (! $injured && ! $unavailable && $matchesInput && ($row['concern_type'] ?? null) === 'routine_confirmation'
            && (! $reported || $this->text($projected) === $this->text($reported))) {
            $row['reason_code'] = 'routine_starter_confirmation';
        }

        return $row;
    }

    private function text(string $text): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $text) ?? $text));
    }
}
