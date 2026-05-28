@php
    $officialBets = collect($predictions)->filter(fn ($prediction) => ($prediction['pick_type'] ?? null) !== 'model_lean')->count();
    $modelLeans = max(0, count($predictions) - $officialBets);
    $waitingOnOdds = collect($predictions)->filter(fn ($prediction) => ($prediction['pick_type'] ?? null) === 'model_lean')->count();
@endphp

<x-mail::message>
# {{ $summary['headline'] ?? 'Today\'s Picks Watchlist' }}

Hi {{ $user->name }},

{{ $summary['intro'] ?? 'Here is the board worth checking today.' }}

<x-mail::panel>
**Action Board**  
Official bets: **{{ $officialBets }}**  
Model leans: **{{ $modelLeans }}**  
Waiting on odds: **{{ $waitingOnOdds }}**
</x-mail::panel>

@if (!empty($summary['highlights']))
@foreach ($summary['highlights'] as $highlight)
- {{ $highlight }}
@endforeach

@endif

@if (count($predictions) > 0)
## Today’s Watchlist

@foreach ($predictions as $prediction)
<x-mail::panel>
**{{ $prediction['sport'] }} | {{ $prediction['matchup'] }}**  
{{ $prediction['classification'] ?? 'Watchlist' }}

Lean: **{{ $prediction['bet_label'] ?? $prediction['pick'] }}**  
Confidence: **{{ number_format((float) $prediction['confidence'], 1) }}%**
@if(($prediction['edge'] ?? 0) > 0)
Edge: **{{ number_format((float) $prediction['edge'], 1) }}%**
@endif
@if($prediction['predicted_spread'] !== null)
Model margin: **{{ $prediction['predicted_spread'] > 0 ? '+' : '' }}{{ number_format((float) $prediction['predicted_spread'], 1) }}**
@endif
@if($prediction['predicted_total'] !== null)
Projected total: **{{ number_format((float) $prediction['predicted_total'], 1) }}**
@endif
@if($prediction['game_time'])
Game time: {{ $prediction['game_time'] }}
@endif

{{ $prediction['market_note'] ?? 'Review the matchup before betting.' }}

<x-mail::button :url="$prediction['url']">
Review Matchup
</x-mail::button>
</x-mail::panel>
@endforeach
@endif

@if (count($playerProps) > 0)
## Player Props

@foreach ($playerProps as $prop)
<x-mail::panel>
**{{ $prop['sport'] }} | {{ $prop['matchup'] }}**  
**{{ $prop['player_name'] }}**

Recommendation: **{{ $prop['recommendation'] }}**
@if($prop['odds'])
Odds: **{{ $prop['odds'] }}**
@endif
@if($prop['confidence'] !== null)
Confidence: **{{ number_format((float) $prop['confidence'], 0) }}%**
@endif
@if($prop['edge'] !== null)
Edge: **{{ number_format((float) $prop['edge'], 1) }}%**
@endif
@if($prop['game_time'])
Game time: {{ $prop['game_time'] }}
@endif

<x-mail::button :url="$prop['url']">
View Player Props
</x-mail::button>
</x-mail::panel>
@endforeach
@endif

<x-mail::button :url="$dashboardUrl">
Open Dashboard
</x-mail::button>

You’re receiving this because your alert preferences are set to **Daily Summary** with email enabled.
</x-mail::message>
