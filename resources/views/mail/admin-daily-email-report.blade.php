@php
    $operations = $report['operations'] ?? [];
    $evidenceGaps = $operations['evidence_gaps'] ?? [];
    $actions = array_slice($operations['actions'] ?? [], 0, 3);
    $outcomes = collect($report['delivery_activity'] ?? []);
    $mailOutcomes = $outcomes->where('channel', 'mail');
    $mailBacklog = isset($report['delivery_backlog']) ? collect($report['delivery_backlog'])->where('channel', 'mail')->sum('count') : null;
    $queueSnapshot = $report['queue_snapshot'] ?? null;
    $digestEnabled = ($report['features']['daily_digest_enabled'] ?? false) && ($report['features']['digest_email_feature_enabled'] ?? false);
@endphp
<x-mail::message>
# {{ $operations['headline'] ?? 'Operational assessment unavailable' }}

**{{ ucfirst($operations['status'] ?? 'unknown') }}** · Health observed {{ $report['observed_at'] ?? 'Unavailable' }}

@if ($actions !== [])
## Review first

@foreach ($actions as $action)
{{ $loop->iteration }}. **{{ strtoupper($action['sport']) }} / {{ ucfirst($action['scope']) }}:** {{ $action['message'] }}<br>
Suggested: `{{ $action['action'] }}` · [Review evidence]({{ $action['url'] }})
@endforeach

Suggested actions have not been executed by this report.
@else
No prioritized remediation is established by the available assessment. Review coverage gaps below.
@endif

## Activity — {{ $report['date_label'] }}

From {{ $report['window']['start'] }} (included) to {{ $report['window']['end'] }} (excluded), Central. Current health is assessed separately from this activity window.

| Recorded activity | Count |
| :--- | ---: |
| Mail accepted by transport | {{ $mailOutcomes->where('status', 'accepted')->sum('count') }} |
| Mail failed | {{ $mailOutcomes->where('status', 'failed')->sum('count') }} |
| Mail suppressed | {{ $mailOutcomes->where('status', 'suppressed')->sum('count') }} |
| Mail dispatched | {{ $mailOutcomes->where('status', 'dispatched')->sum('count') }} |
| Legacy digest send completions | {{ $report['digests']['sent'] ?? 0 }} |
| Legacy betting opportunity dispatches, all channels | {{ $report['alerts']['sent'] ?? 0 }} |

Transport acceptance does not confirm inbox delivery. Transition counts overlap: one notification can be dispatched and accepted. Legacy counts can also overlap the audit; empty audit history does not establish no earlier sends.

**Current unresolved mail:** {{ $mailBacklog ?? 'Unavailable' }} planned, dispatched or sending records as of the operational snapshot. This is separate from activity-window transitions.

**Current controls:** Digest delivery {{ $digestEnabled ? 'enabled' : 'disabled' }} · Admin report {{ ($report['features']['admin_report_enabled'] ?? false) ? 'enabled' : 'disabled' }} · Validation notifications {{ ($report['features']['regression_alerts_enabled'] ?? false) ? 'enabled' : 'disabled' }}.

Betting opportunity checks: {{ ($report['features']['betting_alert_check_registered'] ?? false) ? 'a scheduled command is registered; execution is not confirmed here' : 'no automatic check command is registered' }}. Manual checks are separate.

@if ($outcomes->isNotEmpty())
@foreach ($outcomes->groupBy('type') as $type => $deliveries)
- {{ ucfirst(str_replace('_', ' ', $type)) }}: {{ $deliveries->map(fn ($delivery) => $delivery['channel'].' '.$delivery['status'].' '.$delivery['count'])->implode('; ') }}
@endforeach
@endif

## Current health by sport

@if (! empty($operations['sports']))
| Sport / scope | Latest completed result | Changes against baseline |
| :--- | :--- | :--- |
@foreach ($operations['sports'] as $sport)
@php
    $result = ucfirst($sport['status']);
    if ($sport['run_id'] ?? null) {
        $result .= ' · run '.$sport['run_id'].' · '.$sport['age_minutes'].' min old';
    }
    if (($sport['expected_hold_count'] ?? 0) > 0) {
        $result .= ' · '.$sport['expected_hold_count'].' expected forecast holds';
    }
    $metrics = [];
    foreach (($sport['findings'] ?? []) as $finding) {
        foreach (($finding['metrics'] ?? []) as $name => $count) {
            $metrics[] = str_replace('_', ' ', $name).': '.$count;
        }
    }
    if ($metrics !== []) {
        $result .= ' · '.implode('; ', array_slice($metrics, 0, 2));
    }
    $comparison = 'Comparison unavailable';
    if (($sport['baseline_kind'] ?? 'unavailable') !== 'unavailable') {
        $comparison = count($sport['changes']['new'] ?? []).' new; '.count($sport['changes']['worsened'] ?? []).' worsened; '.count($sport['changes']['persistent'] ?? []).' ongoing; '.count($sport['changes']['recovered'] ?? []).' recovered';
        if (!empty($sport['changes']['unobserved'])) {
            $comparison .= '; '.count($sport['changes']['unobserved']).' unobserved';
        }
        $comparison .= ' · '.($sport['baseline_kind'] === 'yesterday' ? 'yesterday baseline' : 'previous completed baseline');
    }
@endphp
| [{{ strtoupper($sport['sport']) }} / {{ ucfirst($sport['scope']) }}]({{ $sport['url'] ?? $dashboardUrl }}) | {{ $result }} | {{ $comparison }} |
@endforeach

Follow the sport links for finding details, affected records and baseline evidence. Unobserved findings do not establish recovery. Per-check counts can overlap; distinct affected games and users are not established. Reported impacts are advisory unless enforcement evidence is recorded.
@else
No sport assessment is available. [Review validation records]({{ $dashboardUrl }}).
@endif

@if (! empty($operations['inactivity']))
**Expected inactivity**

@foreach ($operations['inactivity'] as $item)
- {{ $item }}
@endforeach
@endif

## Queue and failures

@if ($queueSnapshot !== null)
@if ($queueSnapshot['queue']['available'] ?? false)
Current database queue: **{{ $queueSnapshot['queue']['totals']['waiting_count'] ?? 0 }} ready**, **{{ $queueSnapshot['queue']['totals']['delayed_count'] ?? 0 }} delayed**, **{{ $queueSnapshot['queue']['totals']['reserved_count'] ?? 0 }} reserved**.
@if (($queueSnapshot['queue']['totals']['oldest_waiting_age_seconds'] ?? null) !== null)
Oldest ready job: {{ number_format($queueSnapshot['queue']['totals']['oldest_waiting_age_seconds'] / 60, 0) }} minutes old.
@endif
@else
Queue backlog evidence unavailable for the configured driver. This does not establish an empty queue.
@endif

@if ($queueSnapshot['failures']['available'] ?? false)
Failures in activity window: **{{ $queueSnapshot['failures']['period_count'] ?? 0 }}** · All retained failed jobs: **{{ $queueSnapshot['failures']['retained_total'] ?? 0 }}**.

Job categories in the latest {{ $queueSnapshot['failures']['sampled_count'] ?? 0 }} retained failures (not activity-window totals):

@foreach (array_slice($queueSnapshot['failures']['top_categories'] ?? [], 0, 3) as $category)
- {{ $category['category'] }}: {{ $category['count'] }}; latest {{ $category['latest_failure_at'] ?? 'unavailable' }}.
@endforeach
@if ($queueSnapshot['failures']['sample_truncated'] ?? false)
Failure categories cover a bounded sample, not the entire backlog.
@endif
@else
Failed-job evidence unavailable.
@endif
@else
Matching pending notification jobs: **{{ $report['queue']['pending_mail_jobs'] ?? 0 }}** · Matching failures in activity window: **{{ $report['queue']['failed_mail_jobs_today'] ?? 0 }}** · All retained failed jobs: **{{ $report['queue']['failed_jobs_total'] ?? 0 }}**.
@endif

Queue reservations and retained failures do not establish worker health or inbox delivery. [Review operational evidence]({{ $dashboardUrl }}).

## Evidence gaps

@foreach (array_slice($evidenceGaps, 0, 5) as $gap)
- {{ $gap }}
@endforeach
@if (count($evidenceGaps) > 5)
{{ count($evidenceGaps) - 5 }} additional coverage gaps are available through the sport evidence links.
@endif
@if ($evidenceGaps === [])
{{ $operations['evidence_note'] ?? 'Coverage is limited to the stored assessments linked above.' }}
@endif

{{ $report['deployment']['note'] ?? 'Deployment metadata is unavailable; this report cannot correlate a release with these findings.' }}

@if (! empty($report['ai_publishing']['total']))
AI publishing records in activity window: **{{ $report['ai_publishing']['total'] }}** · Recorded guardrail mode: **{{ data_get($report, 'ai_publishing.enforcement.mode', 'unknown') }}**. [Review publishing evidence]({{ $dashboardUrl }}).
@endif

<x-mail::button :url="$dashboardUrl">
Open Admin Healthchecks
</x-mail::button>
</x-mail::message>
