<x-mail::message>
# Daily Picks Digest

Hi {{ $user->name }},

Here are a few picks for today from the boards you can access.

@if (count($predictions) > 0)
## Predictions

@foreach ($predictions as $prediction)
<x-mail::panel>
**{{ $prediction['sport'] }}**  
**{{ $prediction['matchup'] }}**

Pick: **{{ $prediction['pick'] }}**  
Confidence: **{{ number_format((float) $prediction['confidence'], 1) }}%**
@if($prediction['predicted_spread'] !== null)
Projected spread: **{{ $prediction['predicted_spread'] > 0 ? '+' : '' }}{{ number_format((float) $prediction['predicted_spread'], 1) }}**
@endif
@if($prediction['predicted_total'] !== null)
Projected total: **{{ number_format((float) $prediction['predicted_total'], 1) }}**
@endif
@if($prediction['game_time'])
Game time: {{ $prediction['game_time'] }}
@endif

<x-mail::button :url="$prediction['url']">
View Matchup
</x-mail::button>
</x-mail::panel>
@endforeach
@endif

@if (count($playerProps) > 0)
## Player Props

@foreach ($playerProps as $prop)
<x-mail::panel>
**{{ $prop['sport'] }}**  
**{{ $prop['player_name'] }}**  
{{ $prop['matchup'] }}

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
