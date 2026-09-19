# CFB signal use and grading

The canonical FPI-based calculator has nine auditable signal groups: FPI gap, home field, recent form, turnovers, rest/travel fatigue, injury rating, injury records, offensive scoring history, and defensive scoring history. Seven can change the spread; five can change the total. A game's active counts reflect nonzero changes to rounded predictions, not independent statistical votes.

`CfbSignalContributionGrader` replays the stored pregame prediction exactly before evaluating any signal. It removes one adjustment at a time while holding every other input fixed. Offense/defense use the configured league scoring prior as the replacement baseline. A grade is the absolute error without the signal minus the actual model's absolute error: positive helped, negative hurt, zero neutral. Pending games remain pending. The same comparison can show whether a pregame market selection changed from a loss to a win or vice versa when a quote existed at snapshot capture.

This is a descriptive within-model counterfactual. Correlated features—especially the two injury paths—are not independent bets or causal evidence. Grades do not automatically increase weights. Weight changes require later-game validation against the unchanged baseline. No signal gets an invented win rate or dollar value.

- `cfb:report-signal-contributions --season=2026 --days-forward=2`
- One latest stored pregame forecast per game, grouped by release/market/signal.
- Missing provenance, unsupported fallback calculations and replay mismatches are explicitly excluded.
- Current corrected final scores are used and their observation time retained in the report.
- Runs daily at 03:25 application schedule time, after canonical evaluation, with command heartbeat and output in `storage/logs/cfb-signal-contributions.log`.
- Prediction cards expose current per-signal point impacts under “Signal impact.” Eligibility-only and unused data retain separate roles.

Elo is a comparison/injury reference when FPI supplies the spread baseline. Personnel, WEPA, weather and timezone direction are not additional direct point adjustments in this calculation; storing these fields must never be described as applying them. Total impacts are combined-game points, not calibrated individual team-total probabilities.
