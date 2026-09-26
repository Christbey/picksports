# NFL pregame driver safeguards — September 25, 2026

Implemented locally in the historical-Elo pipeline audited for ATL–GB. Model suffix: `career-regular-v2-ats-v3`. No deployment, production forecast regeneration, or historical regrading was performed. The separate canonical football calculator was not rewritten by this change.

## Forecast changes

### Injury totals

`NflInjuryTotalAdjustment` separates the existing depth/position-weighted absence counts into offense, defense, and unknown. Existing margin injury penalties remain unchanged.

```text
offense reduction = offensive out × 0.30 + offensive questionable × 0.10
defense increase  = defensive out × 0.30 + defensive questionable × 0.10
total adjustment = clamp(defense increase − offense reduction, −3, +3)
```

Weights come from the existing injury-total config. The new cap is `NFL_INJURY_MAX_TOTAL_ADJUSTMENT` (default 3 points). Unknown positions and special teams are excluded from this total adjustment, not assumed to be offensive absences. Both snapshot and nflverse fallback paths retain unit attribution. Metadata preserves raw effect, capped effect, unit counts, and `calibrated=false`.

This is a directional heuristic, not fitted causal injury value. It does not yet estimate whether a long-term absence is already reflected in the team's observed baseline. Three points is a safety bound, not a backtested optimum.

### Starting-QB-aware offense

Resolve the game's projected QB once and share that identity with team profiles and the QB component. Scoring/efficiency and line profiles only use regular-season offensive history with a matching normalized starting-QB name when that identity is available. With fewer than the configured minimum games, use bounded prior-season history for that same team and QB, up to eight games by default. Missing/other starter identities are excluded rather than guessed.

Defense retains its existing team-history window. If usable offensive stats still fail the minimum, skip that adjustment rather than treating missing offense as zero. Metadata records projected QB, sample game IDs, excluded starts, prior-season fallback and sample limits. There is **no automatic QB-return points bonus**. Prior-season personnel and coaching may differ; starter names are not a snap-share or health assessment. This does not replace the separate team EPA stage.

### Historical context stays descriptive

Recent division/conference records, head-to-head records and same-week history remain in metadata. Their spread contributions are zero; the historical division-total contribution is also zero. Preserve former values as `descriptive_*`, set `affects_prediction=false`, and stop reporting their former adjustment reason codes as active forecast drivers. Weather, rest and other unrelated stages are unchanged.

### Spread edge discipline

```text
edge = projected home winning margin + sportsbook home handicap
pass when abs(edge) < configured minimum (default 2 points)
```

Winner confidence is not ATS confidence. The probability assessment labels small differences `pass_small_edge`; the pro-signal layer cannot promote that spread through winner/total scores, and the released-decision recorder independently rejects it. Crossing the threshold is necessary, not sufficient, for approval. A larger edge remains an unvalidated directional lean without held-out calibration.

The NFL board shows “Pass — small edge” instead of a spread recommendation. The V2 legacy-prediction resource and game-page adapter preserve the assessment, and the model card explains the threshold. Raw winner/margin/total forecasts remain visible. Historical saved decisions are not rewritten.

## Research and verification

See `outputs/nfl-qb-return-study-2026-09-25.md` and its JSON for the read-only production return-to-start study. It is not used as a forecast coefficient.

Regression coverage includes offensive/defensive/unknown injuries and caps; snapshot/fallback unit counts; returning-QB prior offense versus current defense; same-day result exclusion; missing-QB samples; descriptive history with zero forecast effect; small-edge release rejection; V2 payload preservation; and frontend rendering independent of winner probability. These tests verify behavior, not improved predictive accuracy. Frozen-input replay and held-out season validation are still required before making performance claims.
