# Virginia Tech–Boston College: weather evidence review

Reviewed September 26, 2026 from the production database. Game ID 283; pregame prediction ID 8509, revision 11, published at 10:37:05 a.m. Central, before the 11 a.m. kickoff.

| Measure | Pregame model / selection | Final result |
| --- | --- | --- |
| Winner | Virginia Tech | Virginia Tech won 21–14 |
| Spread | Model Virginia Tech −17.1; selection Virginia Tech −14.5 | Lost; won by 7 |
| Total | Model 56.6; selection Over 46.5 | Lost; 35 points |

The stored spread and total were captured at 9:58:09 a.m. Central. Total projection error was +21.6 points; projected Virginia Tech margin exceeded the actual margin by 10.1 points.

## Weather finding

There was no `cfb_game_weather` record for this game at review time. The immutable model input snapshot's `signal_context` contained market lines, conference status, and rest days, but no `weather_evidence`, temperature, wind, gusts, or precipitation inputs.

Classify this as **missing pregame weather evidence**. Weather is a hypothesis for review, not a demonstrated cause of either loss. No actual weather conditions or weather-adjusted counterfactual are established by the stored evidence. A forecast fetched after the game cannot be represented as information available to this pregame prediction.

## Board visibility

CFB prediction rows expose weather independently of bet approval: temperature (°F), wind and gusts (mph), precipitation (inches), and precipitation probability where available. Source details disclose forecast-valid time and receipt time in Central time.

Prefer the forecast preserved in the model's input snapshot. When only a separate current weather record exists, label it as the latest stored forecast and explicitly state that it was not included in that model snapshot. When neither exists, display “Weather forecast unavailable.” Missing values are not treated as calm winds or zero precipitation.
