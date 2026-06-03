<?php

namespace App\Console\Commands\Sports;

use App\Models\SportsAiPredictionAnalysis;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class ReportAiPublishingGuardrailsCommand extends Command
{
    protected $signature = 'sports:report-ai-publishing-guardrails
        {--sport= : Optional sport filter}
        {--days=7 : Number of as-of days to include}
        {--json : Output machine-readable JSON}';

    protected $description = 'Report how AI publishing guardrails compare against saved recommendation classifications';

    public function handle(): int
    {
        if (! Schema::hasTable('sports_ai_prediction_analyses')) {
            $this->error('Missing sports_ai_prediction_analyses table. Run php artisan migrate first.');

            return self::FAILURE;
        }

        $sport = $this->option('sport') ? strtolower((string) $this->option('sport')) : null;
        $days = max(1, (int) $this->option('days'));
        $from = now()->subDays($days - 1)->toDateString();
        $to = now()->toDateString();

        $rows = SportsAiPredictionAnalysis::query()
            ->when($sport, fn ($query) => $query->where('sport', $sport))
            ->whereDate('as_of_date', '>=', $from)
            ->whereDate('as_of_date', '<=', $to)
            ->latest('as_of_date')
            ->latest('id')
            ->get();

        $report = $this->buildReport($rows, $from, $to, $sport);

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->renderReport($report);

        return self::SUCCESS;
    }

    /**
     * @param  Collection<int, SportsAiPredictionAnalysis>  $rows
     * @return array<string, mixed>
     */
    private function buildReport(Collection $rows, string $from, string $to, ?string $sport): array
    {
        $decisionCounts = [];
        $savedClassifications = [];
        $guardrailClassifications = [];
        $changedRows = [];

        foreach ($rows as $row) {
            $guardrail = data_get($row->metadata, 'shadow_agents.publishing_guardrail', []);
            $decision = (string) data_get($guardrail, 'decision', 'missing');
            $guardrailClassification = (string) data_get($guardrail, 'publishable_classification', 'missing');
            $savedClassification = (string) $row->bet_classification;

            $decisionCounts[$decision] = ($decisionCounts[$decision] ?? 0) + 1;
            $savedClassifications[$savedClassification] = ($savedClassifications[$savedClassification] ?? 0) + 1;
            $guardrailClassifications[$guardrailClassification] = ($guardrailClassifications[$guardrailClassification] ?? 0) + 1;

            if ($guardrailClassification === 'missing' || $guardrailClassification === $savedClassification) {
                continue;
            }

            $changedRows[] = [
                'date' => $row->as_of_date?->toDateString(),
                'sport' => strtoupper((string) $row->sport),
                'matchup' => (string) data_get($row->raw_payload, 'game.matchup', 'Game '.$row->game_id),
                'decision' => $decision,
                'saved_classification' => $savedClassification,
                'guardrail_classification' => $guardrailClassification,
                'recommendation' => (string) $row->recommendation,
                'summary' => (string) data_get($guardrail, 'summary', ''),
                'required_actions' => array_values(array_filter(array_map(
                    'strval',
                    (array) data_get($guardrail, 'required_actions', [])
                ))),
            ];
        }

        return [
            'scope' => [
                'sport' => $sport,
                'from' => $from,
                'to' => $to,
            ],
            'total' => $rows->count(),
            'changed_count' => count($changedRows),
            'changed_rate' => $rows->isEmpty() ? 0.0 : round(count($changedRows) / $rows->count(), 4),
            'decisions' => $decisionCounts,
            'saved_classifications' => $savedClassifications,
            'guardrail_classifications' => $guardrailClassifications,
            'changed_rows' => array_slice($changedRows, 0, 25),
        ];
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function renderReport(array $report): void
    {
        $scope = $report['scope'];
        $sport = $scope['sport'] ? strtoupper((string) $scope['sport']) : 'ALL';

        $this->line("AI Publishing Guardrail Report ({$sport})");
        $this->line('As-of range: '.$scope['from'].' through '.$scope['to']);
        $this->line('Total analyzed: '.$report['total']);
        $this->line('Classification changes: '.$report['changed_count'].' ('.number_format(((float) $report['changed_rate']) * 100, 1).'%)');
        $this->newLine();

        $this->table(
            ['Decision', 'Count'],
            collect($report['decisions'])->map(fn ($count, $decision) => [$decision, (string) $count])->values()->all()
        );

        if ($report['changed_rows'] === []) {
            $this->info('No saved classification mismatches found.');

            return;
        }

        $this->newLine();
        $this->table(
            ['Date', 'Sport', 'Matchup', 'Decision', 'Saved', 'Guardrail', 'Recommendation', 'Actions'],
            collect($report['changed_rows'])->map(fn (array $row) => [
                $row['date'],
                $row['sport'],
                $row['matchup'],
                $row['decision'],
                $row['saved_classification'],
                $row['guardrail_classification'],
                $row['recommendation'],
                implode(', ', $row['required_actions']),
            ])->all()
        );
    }
}
