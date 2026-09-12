# Historical CFB team totals

The independent `cfb-historical-team-total-v1` model is available through:

```
php artisan cfb:team-totals --date=2026-09-12
php artisan cfb:team-totals --backtest-season=2025
```

These commands do not modify game forecasts, grades, markets, or affiliation records. The new model is experimental and does not automatically replace the production game-total model or issue betting recommendations.

## Historical model

Only completed, scored games strictly before the target UTC calendar date are used. Same-day results are deliberately excluded, including for upcoming evening games. Use the target season and two prior seasons, with a floor of 2023. Each current-season game has weight 1, previous-season game .65, and two-season-old game .35. These initial weights are modeling choices, not optimized parameters.

Only games with both teams classified FBS for that season enter the fit. Read existing season affiliation records, with the resolver's configured season overrides/current-team fallback when absent. That fallback is a provenance limitation for historical subdivision changes.

Calculate a weighted league scoring mean and a capped empirical home/away effect (zero for neutral sites). Fit offense and opposing-defense residual ratings by 12 alternating ridge iterations. Each rating shrinks toward league average with eight weighted games of regularization. The final team estimate is league mean plus offensive strength plus opposing defensive allowance plus venue effect, floored at zero. This adjusts for the defenses and offenses each team previously faced without using the target game's score.

Both teams need at least six qualifying historical games and two weighted games of information, and the league fit needs 30 games. Otherwise withhold both outputs. Report raw sample counts by season, weighted sample mass (not statistical effective sample size), fitted components, and cutoff with every result. Overtime points remain included because the target is full-game scoring.

## Validation and limits

Chronological evaluation refits using only dates before each evaluated game and reports team-point mean absolute error and signed bias against a historical league-mean baseline. This is not an against-the-market backtest: historical team-total prices and timestamped availability context are not yet incorporated. Current provider records may reflect corrections published after a historical cutoff. The weights and regularization must remain fixed when interpreting this first evaluation.

Coaching changes, quarterback/injury availability, and possession/pace adjustments are explicitly not modeled in v1. Do not describe estimates as injury-adjusted or publish calibrated Over probabilities. Prior-season performance is blended rather than discarded, but roster turnover remains a limitation. The old spread/total split and new historical model can be compared without altering pregame history.
