<?php

namespace App\Console\Commands;

use App\Mail\AdminDailyEmailReportMail;
use App\Models\DailyDigestSend;
use App\Models\SportsAiPredictionAnalysis;
use App\Models\User;
use App\Models\UserAlertSent;
use App\Models\ValidationRun;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;

class SendAdminEmailReportCommand extends Command
{
    protected $signature = 'alerts:send-admin-email-report
        {--date= : Report date in YYYY-MM-DD format}
        {--to=* : Email recipient override. Defaults to admin users plus configured recipients}
        {--dry-run : Print the report without sending email}';

    protected $description = 'Send an admin report summarizing system email activity and alert health';

    public function handle(): int
    {
        $date = $this->reportDate();
        $report = $this->buildReport($date);
        $recipients = $this->recipients();

        if ($this->option('dry-run')) {
            $this->line(json_encode([
                'recipients' => $recipients,
                'report' => $report,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        if ($recipients === []) {
            $this->warn('No admin report recipients found.');

            return self::SUCCESS;
        }

        foreach ($recipients as $recipient) {
            Mail::to($recipient)->send(new AdminDailyEmailReportMail($report));
        }

        $this->info('Sent admin email report to '.count($recipients).' recipient(s).');

        return self::SUCCESS;
    }

    protected function reportDate(): CarbonImmutable
    {
        $date = $this->option('date');

        if (is_string($date) && $date !== '') {
            return CarbonImmutable::parse($date)->startOfDay();
        }

        return CarbonImmutable::today();
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildReport(CarbonImmutable $date): array
    {
        $start = $date->startOfDay();
        $end = $date->endOfDay();
        $digests = $this->digestStats($date);
        $alerts = $this->alertStats($start, $end);

        return [
            'date' => $date->toDateString(),
            'date_label' => $date->toFormattedDateString(),
            'digests' => $digests,
            'alerts' => $alerts,
            'queue' => $this->queueStats($start, $end),
            'validation' => $this->latestValidationRun(),
            'ai_publishing' => $this->aiPublishingReview($date),
        ];
    }

    /**
     * @return array{sent:int,users:int,predictions:int,player_props:int}
     */
    protected function digestStats(CarbonImmutable $date): array
    {
        $rows = DailyDigestSend::query()
            ->whereDate('digest_date', $date->toDateString());

        return [
            'sent' => (int) $rows->count(),
            'users' => (int) (clone $rows)->distinct('user_id')->count('user_id'),
            'predictions' => (int) (clone $rows)->sum('predictions_count'),
            'player_props' => (int) (clone $rows)->sum('player_props_count'),
        ];
    }

    /**
     * @return array{sent:int,users:int,average_edge:string,by_sport:array<string, int>}
     */
    protected function alertStats(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $rows = UserAlertSent::query()
            ->whereBetween('sent_at', [$start, $end]);

        $bySport = (clone $rows)
            ->select('sport', DB::raw('COUNT(*) as count'))
            ->groupBy('sport')
            ->orderBy('sport')
            ->pluck('count', 'sport')
            ->map(fn ($count) => (int) $count)
            ->all();

        $averageEdge = (clone $rows)->avg('expected_value');

        return [
            'sent' => (int) $rows->count(),
            'users' => (int) (clone $rows)->distinct('user_id')->count('user_id'),
            'average_edge' => $averageEdge === null ? 'N/A' : number_format((float) $averageEdge, 1).'%',
            'by_sport' => $bySport,
        ];
    }

    /**
     * @return array{pending_mail_jobs:int,failed_mail_jobs_today:int,failed_jobs_total:int}
     */
    protected function queueStats(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $mailPayloads = [
            '%DailyPredictionsDigestMail%',
            '%AdminDailyEmailReportMail%',
            '%BettingValueAlert%',
            '%ValidationRegressionAlert%',
        ];

        $pendingMailJobs = 0;
        if (Schema::hasTable('jobs')) {
            $pendingMailJobs = (int) DB::table('jobs')
                ->where(function ($query) use ($mailPayloads) {
                    foreach ($mailPayloads as $payload) {
                        $query->orWhere('payload', 'like', $payload);
                    }
                })
                ->count();
        }

        $failedMailJobsToday = 0;
        $failedJobsTotal = 0;
        if (Schema::hasTable('failed_jobs')) {
            $failedJobsTotal = (int) DB::table('failed_jobs')->count();
            $failedMailJobsToday = (int) DB::table('failed_jobs')
                ->whereBetween('failed_at', [$start, $end])
                ->where(function ($query) use ($mailPayloads) {
                    foreach ($mailPayloads as $payload) {
                        $query->orWhere('payload', 'like', $payload)
                            ->orWhere('exception', 'like', $payload);
                    }
                })
                ->count();
        }

        return [
            'pending_mail_jobs' => $pendingMailJobs,
            'failed_mail_jobs_today' => $failedMailJobsToday,
            'failed_jobs_total' => $failedJobsTotal,
        ];
    }

    /**
     * @return array{scope:string,status:string,completed_at:string,summary:array<string, mixed>,ai_summary:array<string, mixed>|null}|null
     */
    protected function latestValidationRun(): ?array
    {
        $run = ValidationRun::query()
            ->latest('completed_at')
            ->first();

        if (! $run) {
            return null;
        }

        return [
            'scope' => (string) $run->scope,
            'status' => (string) $run->status,
            'completed_at' => $run->completed_at?->toDayDateTimeString() ?? 'N/A',
            'summary' => is_array($run->summary) ? $run->summary : [],
            'ai_summary' => is_array($run->ai_summary) ? $run->ai_summary : null,
        ];
    }

    /**
     * @return array{total:int,decisions:array<string, int>,classifications:array<string, int>,enforcement:array{enabled:bool,mode:string},needs_attention:array<int, array<string, mixed>>}
     */
    protected function aiPublishingReview(CarbonImmutable $date): array
    {
        $enforcement = $this->aiPublishingEnforcement();

        if (! Schema::hasTable('sports_ai_prediction_analyses')) {
            return [
                'total' => 0,
                'decisions' => [],
                'classifications' => [],
                'enforcement' => $enforcement,
                'needs_attention' => [],
            ];
        }

        $analyses = SportsAiPredictionAnalysis::query()
            ->whereDate('as_of_date', $date->toDateString())
            ->latest('id')
            ->limit(100)
            ->get();

        $decisions = [];
        $classifications = [];
        $needsAttention = [];

        foreach ($analyses as $analysis) {
            $guardrail = data_get($analysis->metadata, 'shadow_agents.publishing_guardrail', []);
            $decision = (string) data_get($guardrail, 'decision', 'unknown');
            $classification = (string) data_get($guardrail, 'publishable_classification', 'unknown');

            $decisions[$decision] = ($decisions[$decision] ?? 0) + 1;
            $classifications[$classification] = ($classifications[$classification] ?? 0) + 1;

            if (! in_array($decision, ['downgrade', 'hold', 'block'], true)) {
                continue;
            }

            $needsAttention[] = [
                'sport' => strtoupper((string) $analysis->sport),
                'matchup' => (string) data_get($analysis->raw_payload, 'game.matchup', 'Game '.$analysis->game_id),
                'decision' => $decision,
                'publishable_classification' => $classification,
                'freshness_status' => (string) data_get($analysis->metadata, 'shadow_agents.data_freshness.freshness_status', 'unknown'),
                'market_status' => (string) data_get($analysis->metadata, 'shadow_agents.market_readiness.market_status', 'unknown'),
                'model_status' => (string) data_get($analysis->metadata, 'shadow_agents.model_audit.model_status', 'unknown'),
                'summary' => (string) data_get($guardrail, 'summary', ''),
                'required_actions' => array_values(array_filter(array_map(
                    'strval',
                    (array) data_get($guardrail, 'required_actions', [])
                ))),
            ];
        }

        return [
            'total' => $analyses->count(),
            'decisions' => $decisions,
            'classifications' => $classifications,
            'enforcement' => $enforcement,
            'needs_attention' => array_slice($needsAttention, 0, 8),
        ];
    }

    /**
     * @return array{enabled:bool,mode:string}
     */
    protected function aiPublishingEnforcement(): array
    {
        $enabled = (bool) config('ai.features.publishing_guardrail_review.enforced', false);

        return [
            'enabled' => $enabled,
            'mode' => $enabled ? 'enforced' : 'shadow',
        ];
    }

    /**
     * @return array<int, string>
     */
    protected function recipients(): array
    {
        $override = array_values(array_filter(array_map('strval', (array) $this->option('to'))));

        if ($override !== []) {
            return array_values(array_unique($override));
        }

        $configured = collect(explode(',', (string) config('alerts.admin_report.recipients', '')))
            ->map(fn (string $email) => trim($email))
            ->filter()
            ->values();

        $admins = User::query()
            ->where('is_admin', true)
            ->whereNotNull('email')
            ->pluck('email');

        return $configured
            ->merge($admins)
            ->unique()
            ->values()
            ->all();
    }
}
