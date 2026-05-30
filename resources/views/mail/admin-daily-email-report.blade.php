<x-mail::message>
# Admin Email Report

{{ $report['date_label'] }}

<x-mail::panel>
**User Email Sends**  
Daily digests: **{{ $report['digests']['sent'] }}**  
Digest users reached: **{{ $report['digests']['users'] }}**  
Predictions included: **{{ $report['digests']['predictions'] }}**  
Player props included: **{{ $report['digests']['player_props'] }}**
</x-mail::panel>

<x-mail::panel>
**Realtime Alerts**  
Betting value alerts: **{{ $report['alerts']['sent'] }}**  
Users reached: **{{ $report['alerts']['users'] }}**  
Average edge: **{{ $report['alerts']['average_edge'] }}**
</x-mail::panel>

<x-mail::panel>
**Queue / Mail Health**  
Pending mail jobs: **{{ $report['queue']['pending_mail_jobs'] }}**  
Failed mail jobs today: **{{ $report['queue']['failed_mail_jobs_today'] }}**  
Total failed jobs: **{{ $report['queue']['failed_jobs_total'] }}**
</x-mail::panel>

@if (! empty($report['alerts']['by_sport']))
## Alerts By Sport

@foreach ($report['alerts']['by_sport'] as $sport => $count)
- **{{ strtoupper($sport) }}:** {{ $count }}
@endforeach
@endif

@if (! empty($report['validation']))
## Latest Validation

Scope: **{{ $report['validation']['scope'] }}**  
Status: **{{ $report['validation']['status'] }}**  
Completed: **{{ $report['validation']['completed_at'] }}**

@if (! empty($report['validation']['summary']))
Failing: **{{ $report['validation']['summary']['failing'] ?? 0 }}**  
Warnings: **{{ $report['validation']['summary']['warning'] ?? 0 }}**  
Passing: **{{ $report['validation']['summary']['passing'] ?? 0 }}**
@endif
@endif

<x-mail::button :url="$dashboardUrl">
Open Admin Healthchecks
</x-mail::button>

Generated for PickSports admins.
</x-mail::message>
