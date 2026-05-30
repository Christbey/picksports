@php
    $officialPredictions = collect($predictions)
        ->filter(fn ($prediction) => ($prediction['pick_type'] ?? null) !== 'model_lean')
        ->values();
    $watchlistPredictions = collect($predictions)
        ->filter(fn ($prediction) => ($prediction['pick_type'] ?? null) === 'model_lean')
        ->values();
    $officialPredictionsBySport = $officialPredictions->groupBy(fn ($prediction) => (string) ($prediction['sport'] ?? 'Other'));
    $watchlistPredictionsBySport = $watchlistPredictions->groupBy(fn ($prediction) => (string) ($prediction['sport'] ?? 'Other'));
    $playerPropsBySport = collect($playerProps)->groupBy(fn ($prop) => (string) ($prop['sport'] ?? 'Other'));
    $sportColor = fn (?string $sport): string => match (strtolower((string) $sport)) {
        'mlb' => '#1d9a6c',
        'nba' => '#d94f2b',
        'wnba' => '#7c4dff',
        'nfl' => '#246bfe',
        'cbb', 'wcbb' => '#8f5f00',
        'cfb' => '#5b6c2f',
        default => '#6b7280',
    };
    $officialBets = $officialPredictions->count();
    $modelLeans = $watchlistPredictions->count();
@endphp

<x-mail::message>
# {{ $summary['headline'] ?? 'Today\'s Picks' }}

Hi {{ $user->name }},

{{ $summary['intro'] ?? 'Here is the board worth checking today.' }}

<x-mail::panel>
**Today’s board**  
Official bets: **{{ $officialBets }}**  
Watchlist leans: **{{ $modelLeans }}**
</x-mail::panel>

@if ($officialPredictions->isNotEmpty())
## Official Bets

@foreach ($officialPredictionsBySport as $sport => $sportPredictions)
### {{ $sport }}

@foreach ($sportPredictions as $prediction)
<x-mail::panel>
<div style="border-left: 6px solid {{ $sportColor($prediction['sport'] ?? null) }}; padding-left: 14px;">

**{{ $prediction['sport'] }} | {{ $prediction['matchup'] }}**  
**{{ $prediction['bet_label'] ?? $prediction['pick'] }}**

Confidence: **{{ number_format((float) ($prediction['confidence'] ?? 0), 1) }}%**
@if(($prediction['edge'] ?? 0) > 0)
Edge: **{{ number_format((float) $prediction['edge'], 1) }}%**
@endif
@if(($prediction['predicted_spread'] ?? null) !== null)
Model margin: **{{ $prediction['predicted_spread'] > 0 ? '+' : '' }}{{ number_format((float) $prediction['predicted_spread'], 1) }}**
@endif
@if($prediction['game_time'] ?? null)
Game: {{ $prediction['game_time'] }}
@endif

{{ $prediction['market_note'] ?? 'Review the matchup before betting.' }}

<x-mail::button :url="$prediction['url']">
Review Matchup
</x-mail::button>

</div>
</x-mail::panel>
@endforeach
@endforeach
@endif

@if ($watchlistPredictions->isNotEmpty())
## Watchlist Leans

@foreach ($watchlistPredictionsBySport as $sport => $sportPredictions)
### {{ $sport }}

@foreach ($sportPredictions as $prediction)
<x-mail::panel>
<div style="border-left: 6px solid {{ $sportColor($prediction['sport'] ?? null) }}; padding-left: 14px;">

**{{ $prediction['sport'] }} | {{ $prediction['matchup'] }}**  
**{{ $prediction['bet_label'] ?? $prediction['pick'] }}**

Confidence: **{{ number_format((float) ($prediction['confidence'] ?? 0), 1) }}%**
@if(($prediction['predicted_total'] ?? null) !== null)
Projected total: **{{ number_format((float) $prediction['predicted_total'], 1) }}**
@endif
@if($prediction['game_time'] ?? null)
Game: {{ $prediction['game_time'] }}
@endif

Waiting on fresh market odds before this becomes an official bet.

<x-mail::button :url="$prediction['url']">
Review Matchup
</x-mail::button>

</div>
</x-mail::panel>
@endforeach
@endforeach
@endif

@if (count($playerProps) > 0)
## Player Props

@foreach ($playerPropsBySport as $sport => $sportProps)
### {{ $sport }}

@foreach ($sportProps as $prop)
<x-mail::panel>
<div style="border-left: 6px solid {{ $sportColor($prop['sport'] ?? null) }}; padding-left: 14px;">

**{{ $prop['sport'] }} | {{ $prop['matchup'] }}**  
**{{ $prop['player_name'] }} {{ $prop['recommendation'] }}**
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
Game: {{ $prop['game_time'] }}
@endif

<x-mail::button :url="$prop['url']">
View Player Props
</x-mail::button>

</div>
</x-mail::panel>
@endforeach
@endforeach
@endif

<x-mail::button :url="$dashboardUrl">
Open Dashboard
</x-mail::button>

You’re receiving this because Daily Summary emails are enabled.
</x-mail::message>
