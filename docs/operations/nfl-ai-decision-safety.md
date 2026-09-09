# NFL AI Decision Safety

NFL AI analysis is a research and explanation layer. It is not allowed to invent a wager, switch the model to another market, or strengthen the deterministic classification.

## Decision order

1. The prediction model produces its spread, total, win probability, trust score, reason codes, and risk flags.
2. The NFL analysis layer and pro-signal layer must agree on the maximum publishable classification.
3. Sourced web context may apply only the bounded deterministic adjustment stored in `external_game_context`.
4. The normalized `decision_contract` selects the eligible market, side or total direction, line, edge, and maximum classification.
5. AI may explain the contract or downgrade it to a weaker classification. It cannot upgrade the tier or change the market, side, or line.
6. The publishing policy enforces the contract before the row is saved.
7. The API exposes an NFL AI analysis only while its `input_hash` matches the current prediction, odds, operational state, and sourced context.

## Normalized spread convention

NFL model spreads and `market_spread_home_margin` use a positive-home-margin convention:

- Positive means the home team is favored by that many points.
- Negative means the away team is favored by the absolute value.
- `spread_edge = predicted_spread - market_spread_home_margin`.
- A positive spread edge selects the home side; a negative spread edge selects the away side.
- The displayed sportsbook line reverses the home-margin sign for the selected team.

Totals use `total_edge = predicted_total - market_total`. Positive selects the over and negative selects the under.

## Publishing behavior

- `bet` and `lean` require an eligible deterministic market.
- `watch` and `pass` publish with `recommendation=pass`; they are not wager instructions.
- NFL moneyline cannot be selected while `NFL_MONEYLINE_PLAY_ENABLED=false`.
- Generated prose and reason codes cannot replace the canonical contract fields.
- The NFL AI packet contains compact model evidence and one compact prediction snapshot. Full `model_metadata` is not duplicated into the provider request.
- Stale rows remain stored for audit history but are withheld from the prediction API until analysis is regenerated from the current input hash.

After a payload schema or decision-policy release, regenerate NFL daily analysis in batches. Until a current hash exists for a game, the API intentionally returns no AI analysis for that prediction.
