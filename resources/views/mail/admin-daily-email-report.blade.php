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

@if (! empty($report['validation']['ai_summary']))
@php($aiSummary = $report['validation']['ai_summary'])

**Latest Data Freshness**  
{{ $aiSummary['latest_data_fresh_at'] ?? 'N/A' }}

**Operational Status**  
{{ strtoupper((string) ($aiSummary['operational_status'] ?? 'unknown')) }} @if(isset($aiSummary['trust_score']))({{ $aiSummary['trust_score'] }}/100 trust)@endif

@if (! empty($aiSummary['blocked_outputs']))
**Blocked / Caveated Outputs**

@foreach ($aiSummary['blocked_outputs'] as $output)
- {{ $output }}
@endforeach
@endif

@if (! empty($aiSummary['safe_adjustments']))
**Safe Adjustments**

@foreach ($aiSummary['safe_adjustments'] as $adjustment)
- `{{ $adjustment }}`
@endforeach
@endif

@if (! empty($aiSummary['data_schedule_today']))
**Data Schedule Today**

@foreach ($aiSummary['data_schedule_today'] as $scheduleItem)
- {{ $scheduleItem }}
@endforeach
@endif

@if (! empty($aiSummary['tweak_recommendations']))
**Tweak Recommendations**

@foreach ($aiSummary['tweak_recommendations'] as $recommendation)
- {{ $recommendation }}
@endforeach
@endif
@endif
@endif

@if (! empty($report['ai_publishing']) && ($report['ai_publishing']['total'] ?? 0) > 0)
## AI Publishing Review

Analyzed picks: **{{ $report['ai_publishing']['total'] }}**

Guardrail mode: **{{ strtoupper((string) data_get($report, 'ai_publishing.enforcement.mode', 'shadow')) }}**

@if (! empty($report['ai_publishing']['decisions']))
**Guardrail Decisions**

@foreach ($report['ai_publishing']['decisions'] as $decision => $count)
- **{{ strtoupper((string) $decision) }}:** {{ $count }}
@endforeach
@endif

@if (! empty($report['ai_publishing']['needs_attention']))
**Needs Attention**

@foreach ($report['ai_publishing']['needs_attention'] as $item)
- **{{ $item['sport'] }} {{ $item['matchup'] }}:** {{ strtoupper($item['decision']) }} to {{ strtoupper($item['publishable_classification']) }}. Freshness: {{ $item['freshness_status'] }}. Market: {{ $item['market_status'] }}. Model: {{ $item['model_status'] }}. {{ $item['summary'] }}
@if (! empty($item['required_actions']))
  Actions: @foreach ($item['required_actions'] as $action)`{{ $action }}`@if(! $loop->last), @endif @endforeach
@endif
@endforeach
@endif
@endif

<x-mail::button :url="$dashboardUrl">
Open Admin Healthchecks
</x-mail::button>

Generated for PickSports admins.
</x-mail::message>
