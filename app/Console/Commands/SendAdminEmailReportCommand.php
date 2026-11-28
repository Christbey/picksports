<?php

namespace App\Console\Commands;

use App\Mail\AdminDailyEmailReportMail;
use App\Models\DailyDigestSend;
use App\Models\NotificationDelivery;
use App\Models\SportsAiPredictionAnalysis;
use App\Models\User;
use App\Models\UserAlertSent;
use App\Services\AdminQueueSnapshot;
use App\Services\NotificationDeliveryService;
use App\Services\Validation\AdminOperationalAssessment;
use App\Services\Validation\ValidationReviewSummaryService;
use App\Services\Validation\ValidationRunRecorder;
use App\Support\SportCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;

class SendAdminEmailReportCommand extends Command
{
    protected $signature = 'alerts:send-admin-email-report
        {--date= : Activity date in YYYY-MM-DD format; defaults to yesterday in America/Chicago}
        {--to=* : Email recipient override. Defaults to admin users plus configured recipients}
        {--dry-run : Print the report without sending email}';

    protected $description = 'Send an admin report summarizing system email activity and alert health';

    public function handle(): int
    {
        try {
            $date = $this->reportDate();
        } catch (\InvalidArgumentException $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }
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

        $deliveries = app(NotificationDeliveryService::class);
        $sent = 0;
        $failed = 0;
        foreach ($recipients as $recipient) {
            $recipientKey = hash('sha256', strtolower(trim($recipient)));
            $key = 'admin_report:'.$date->toDateString().':'.$recipientKey;
            $delivery = $deliveries->record('admin_report', $recipientKey, 'mail', $key);
            $deliveries->markDispatched($delivery);
            if (! $deliveries->claim($delivery)) {
                continue;
            }
            try {
                $mail = (new AdminDailyEmailReportMail($report))->withSymfonyMessage(function ($message) use ($key): void {
                    $message->getHeaders()->addTextHeader('X-PickSports-Delivery-Key', $key);
                });
                Mail::to($recipient)->send($mail);
                $deliveries->markAccepted($delivery);
                $sent++;
            } catch (\Throwable $error) {
                $deliveries->markFailed($delivery, $error::class);
                report($error);
                $failed++;
            }
        }

        $this->info('Sent admin email report to '.$sent.' recipient(s).');

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    protected function reportDate(): CarbonImmutable
    {
        $date = $this->option('date');

        if (is_string($date) && $date !== '') {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
                throw new \InvalidArgumentException('--date must be a valid YYYY-MM-DD activity date.');
            }
            $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $date, 'America/Chicago');
            if ($parsed === false || $parsed->format('Y-m-d') !== $date || $parsed->isAfter(CarbonImmutable::today('America/Chicago'))) {
                throw new \InvalidArgumentException('--date must be a valid activity date no later than today.');
            }

            return $parsed;
        }

        return CarbonImmutable::yesterday('America/Chicago');
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildReport(CarbonImmutable $date): array
    {
        $start = $date->startOfDay();
        $observedAt = CarbonImmutable::now('America/Chicago');
        $end = $date->addDay()->startOfDay()->min($observedAt);
        $validations = $this->validationBySport();
        $digests = $this->digestStats($date);
        $alerts = $this->alertStats($start, $end);

        return [
            'date' => $date->toDateString(),
            'date_label' => $date->toFormattedDateString(),
            'observed_at' => $observedAt->toIso8601String(),
            'window' => ['start' => $start->toIso8601String(), 'end' => $end->toIso8601String(), 'end_exclusive' => true, 'timezone' => 'America/Chicago'],
            'features' => [
                'daily_digest_enabled' => (bool) config('alerts.daily_digest.enabled', true),
                'digest_email_feature_enabled' => (bool) config('subscriptions.features.email_alerts_enabled', true),
                'admin_report_enabled' => (bool) config('alerts.admin_report.enabled', true),
                'regression_alerts_enabled' => (bool) config('validation.regression_alerts.enabled', true),
                'admin_report_time' => (string) config('alerts.admin_report.daily_time', '07:30'),
                'betting_alert_check_registered' => collect(app(Schedule::class)->events())
                    ->contains(fn ($event): bool => is_string($event->command ?? null) && str_contains($event->command, 'alerts:check')),
            ],
            'delivery_activity' => $this->deliveryActivity($start, $end),
            'delivery_backlog' => NotificationDelivery::query()->whereNull('accepted_at')
                ->whereIn('status', ['planned', 'dispatched', 'sending', 'failed', 'outcome_unknown'])
                ->where('created_at', '<=', $observedAt)
                ->selectRaw('type, channel, status, COUNT(*) as aggregate')->groupBy('type', 'channel', 'status')
                ->orderBy('type')->orderBy('channel')->orderBy('status')->get()
                ->map(fn ($row): array => ['type' => $row->type, 'channel' => $row->channel, 'status' => $row->status, 'count' => (int) $row->aggregate])->all(),
            'digests' => $digests,
            'alerts' => $alerts,
            'queue' => $this->queueStats($start, $end),
            'queue_snapshot' => app(AdminQueueSnapshot::class)->snapshot($start, $end, $observedAt),
            'operations' => app(AdminOperationalAssessment::class)->build($observedAt),
            'deployment' => [
                'status' => 'unavailable',
                'note' => 'Verified deployment metadata is not recorded in this report; findings cannot be correlated with a release here.',
            ],
            'validations' => $validations,
            'ai_publishing' => $this->aiPublishingReview($date),
        ];
    }

    /** @return list<array{type:string,channel:string,status:string,count:int}> */
    protected function deliveryActivity(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $activity = [];
        foreach (['accepted' => 'accepted_at', 'dispatched' => 'dispatched_at', 'failed' => 'failed_at', 'suppressed' => 'suppressed_at'] as $status => $column) {
            $rows = NotificationDelivery::query()->where($column, '>=', $start)->where($column, '<', $end)
                ->selectRaw('type, channel, COUNT(*) as aggregate')->groupBy('type', 'channel')->get();
            foreach ($rows as $row) {
                $activity[] = ['type' => $row->type, 'channel' => $row->channel, 'status' => $status, 'count' => (int) $row->aggregate];
            }
        }

        return collect($activity)->sortBy(fn (array $row): string => $row['type'].'|'.$row['channel'].'|'.$row['status'])->values()->all();
    }

    /**
     * @return array{sent:int,users:int,predictions:int,player_props:int}
     */
    protected function digestStats(CarbonImmutable $date): array
    {
        $rows = DailyDigestSend::query()
            ->whereDate('digest_date', $date->toDateString())
            ->where('sent_at', '>=', $date->startOfDay())
            ->where('sent_at', '<', $date->addDay()->startOfDay()->min(CarbonImmutable::now('America/Chicago')));

        return [
            'sent' => (int) $rows->count(),
            'users' => (int) (clone $rows)->distinct('user_id')->count('user_id'),
            'predictions' => (int) (clone $rows)->sum('predictions_count'),
            'player_props' => (int) (clone $rows)->sum('player_props_count'),
        ];
    }

    /**
     * @return array{sent:int,users:int,average_edge:null,by_sport:array<string, int>}
     */
    protected function alertStats(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $rows = UserAlertSent::query()
            ->where('sent_at', '>=', $start)->where('sent_at', '<', $end);

        $bySport = (clone $rows)
            ->select('sport', DB::raw('COUNT(*) as count'))
            ->groupBy('sport')
            ->orderBy('sport')
            ->pluck('count', 'sport')
            ->map(fn ($count) => (int) $count)
            ->all();

        return [
            'sent' => (int) $rows->count(),
            'users' => (int) (clone $rows)->distinct('user_id')->count('user_id'),
            'average_edge' => null,
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
                ->where('failed_at', '>=', $start)->where('failed_at', '<', $end)
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
            'scope_note' => 'Pending and period failures match the four report notification classes across all their channels. They omit other email types and synchronous delivery failures. Total failures cover all retained job types.',
        ];
    }

    /** @return array<string, array<string, array<string, mixed>|null>> */
    protected function validationBySport(): array
    {
        $recorder = app(ValidationRunRecorder::class);
        $summaries = app(ValidationReviewSummaryService::class);
        $results = [];

        foreach (SportCatalog::ALL as $sport) {
            foreach (['full', 'data'] as $scope) {
                $run = $recorder->completedChecksQuery('sport:'.$sport, $scope)
                    ->latest('completed_at')->latest('id')->first();
                $results[$sport][$scope] = $run === null ? null : [
                    'id' => $run->id,
                    'status' => $run->status,
                    'completed_at' => $run->completed_at->copy()->timezone('America/Chicago')->toIso8601String(),
                    'age_minutes' => (int) $run->completed_at->diffInMinutes(now()),
                    'summary' => $run->summary,
                    'assessment' => $summaries->buildFallbackSummary($run->findings()->get(), $run),
                ];
            }
        }

        return $results;
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
