<?php

namespace App\Console\Commands\MLB;

use App\Models\MLB\PickCandidate;
use App\Services\MLB\Picks\MlbDailyPickService;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class GenerateDailyPicksCommand extends Command
{
    protected $signature = 'mlb:generate-daily-picks
        {--date= : Slate date}
        {--season= : MLB season}
        {--limit= : Top pick limit}
        {--markets= : Comma-separated markets}
        {--today : Use today as the slate date}
        {--tomorrow : Use tomorrow as the slate date}
        {--dry-run : Build candidates without persisting rows}
        {--json : Output JSON}';

    protected $description = 'Generate immutable MLB daily pick candidates across supported markets';

    public function handle(MlbDailyPickService $service): int
    {
        $date = $this->date();
        $markets = collect(explode(',', (string) $this->option('markets')))->map(fn (string $market): string => trim($market))->filter()->values()->all();
        $report = $service->generateForDate(
            date: $date,
            markets: $markets,
            dryRun: (bool) $this->option('dry-run'),
            season: $this->option('season') ? (int) $this->option('season') : null,
            limit: $this->option('limit') ? (int) $this->option('limit') : null,
        );

        if ($this->option('json')) {
            $this->line(json_encode([
                ...collect($report)->except(['candidates', 'top_picks'])->all(),
                'top_picks' => $report['top_picks']->map(fn (PickCandidate $candidate): array => $candidate->toArray())->values()->all(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info('MLB Daily Picks Candidate Report');
        $this->line('Date: '.$report['date']);
        $this->line('Slate games: '.$report['slate_games']);
        $this->line('Priced games: '.$report['priced_games']);
        $this->line('Candidate rows: '.$report['candidate_count']);
        $this->line('Tracking-only rows: '.$report['tracking_only_count']);
        $this->line('Public promoted rows: '.$report['public_promoted_count']);

        $rows = $report['top_picks']->map(fn (PickCandidate $candidate, int $index): array => [
            $index + 1,
            $candidate->market_type,
            $this->label($candidate),
            $candidate->price !== null ? ($candidate->price > 0 ? '+'.$candidate->price : (string) $candidate->price) : 'n/a',
            $candidate->score,
            $candidate->status,
            implode(', ', array_slice($candidate->reason_codes ?? [], 0, 4)),
            implode(', ', array_slice($candidate->risk_flags ?? [], 0, 3)),
        ])->all();

        $this->newLine();
        $this->table(['#', 'Market', 'Pick', 'Price', 'Score', 'Status', 'Reasons', 'Risks'], $rows);

        if ($report['top_picks']->count() < (int) config('mlb.picks.daily.target_count', 3)) {
            $this->warn("Only {$report['top_picks']->count()} candidate(s) met today's minimum score/diversity rules.");
        }

        return self::SUCCESS;
    }

    private function date(): CarbonInterface
    {
        if ($this->option('tomorrow')) {
            return now(config('sports.business_timezone', config('app.timezone')))->addDay()->startOfDay();
        }

        if ($this->option('today') || ! $this->option('date')) {
            return now(config('sports.business_timezone', config('app.timezone')))->startOfDay();
        }

        return Carbon::parse((string) $this->option('date'), config('sports.business_timezone', config('app.timezone')))->startOfDay();
    }

    private function label(PickCandidate $candidate): string
    {
        $team = $candidate->team?->abbreviation;
        $side = $team ?: strtoupper($candidate->side);

        return trim($side.' '.($candidate->line !== null ? (string) $candidate->line : ''));
    }
}
