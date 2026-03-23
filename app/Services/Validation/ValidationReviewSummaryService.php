<?php

namespace App\Services\Validation;

use App\Models\ValidationFinding;
use App\Models\ValidationRun;
use App\Services\AI\SportsAiContentService;
use Illuminate\Support\Collection;

class ValidationReviewSummaryService
{
    public function __construct(
        private readonly SportsAiContentService $sportsAiContentService,
    ) {}

    /**
     * @return array{headline:string,intro:string,highlights:array<int,string>,recommended_actions:array<int,string>,generated_by:string}
     */
    public function summarizeRun(ValidationRun $run): array
    {
        $findings = $run->findings()->orderByDesc('status')->orderBy('sport')->get();

        $aiSummary = $this->sportsAiContentService->generateValidationReviewSummary($findings);

        if ($aiSummary !== null) {
            return $aiSummary;
        }

        return $this->buildFallbackSummary($findings, $run);
    }

    /**
     * @param  Collection<int, ValidationFinding>  $findings
     * @return array{headline:string,intro:string,highlights:array<int,string>,recommended_actions:array<int,string>,generated_by:string}
     */
    public function buildFallbackSummary(Collection $findings, ValidationRun $run): array
    {
        $failing = $findings->where('status', 'failing');
        $warning = $findings->where('status', 'warning');
        $sports = $findings->pluck('sport')->filter()->unique()->values()->all();
        $sportLabel = $sports === [] ? 'validation run' : strtoupper(implode(', ', $sports));

        $grouped = $findings
            ->groupBy('check_type')
            ->map(fn (Collection $items, string $checkType) => [
                'check_type' => $checkType,
                'count' => $items->count(),
                'sample_message' => (string) $items->first()?->message,
            ])
            ->sortByDesc('count')
            ->values();

        $highlights = $grouped
            ->take(4)
            ->map(function (array $group): string {
                return sprintf(
                    '%s: %d finding%s. %s',
                    str_replace('_', ' ', ucfirst((string) preg_replace('/^validation_/', '', $group['check_type']))),
                    $group['count'],
                    $group['count'] === 1 ? '' : 's',
                    $group['sample_message']
                );
            })
            ->all();

        $recommendedActions = $findings
            ->pluck('recommended_action')
            ->filter(fn ($action) => is_string($action) && trim($action) !== '')
            ->unique()
            ->values()
            ->take(4)
            ->all();

        if ($recommendedActions === []) {
            $recommendedActions = ['Re-run healthcheck:validate-data after correcting the underlying data pipeline issues.'];
        }

        return [
            'headline' => sprintf('%s validation summary', $sportLabel),
            'intro' => sprintf(
                'This run recorded %d failing and %d warning findings across %d total checks.',
                $failing->count(),
                $warning->count(),
                $findings->count()
            ),
            'highlights' => $highlights,
            'recommended_actions' => array_map(fn ($action) => (string) $action, $recommendedActions),
            'generated_by' => 'template-validation-summary-v1',
        ];
    }
}
