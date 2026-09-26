# NFL prediction formulas: executable model reference

Reviewed September 25, 2026. This describes the local working tree, not a claim
that its safeguards have been deployed. Preserve the findings in
[the review ledger](nfl-prediction-review-ledger.md) when continuing this work.
The `ats-v3` changes and their limits are summarized in
[pregame driver safeguards](nfl-pregame-driver-safeguards.md).

## Scope and sources

This reference covers the game prediction's margin, total, winner probability,
supporting profile calculations, analysis trust, and spread grading. It does not
claim to document the separate player-prop models, every upstream rating trainer,
or every Boolean research/approval rule. Those are separate systems.

Primary implementation:

- [Forecast and analysis action](../../app/Actions/NFL/GeneratePredictionFromHistoricalElo.php): `generate`, `calculateForecast`, and the methods named below.
- [Configuration](../../config/nfl.php): `elo`, `predictions`, `betting`.
- [Team profiles](../../app/Services/NFL/NflTeamHistory.php).
- [QB summary](../../app/Services/NFL/NflQuarterbackStatsSummary.php).
- [Custom EPA](../../app/Services/NFL/TrueEpaCalculator.php).
- [Spread probability](../../app/Services/NFL/NflSpreadProbability.php) and [grading](../../app/Services/NFL/NflSpreadBacktestEvaluator.php).

Numeric constants below are repository defaults unless marked otherwise. They
are not verified production overrides. `env(...)` settings and cached Laravel
configuration can change effective values. In particular, several `config()`
fallback arguments in the action differ from values defined in `config/nfl.php`;
the configured value takes precedence. Comments claiming calibration are not
evidence of a held-out calibration result.

## Notation and invariants

```text
m = predicted HOME score minus AWAY score, in points
t = predicted combined score, in points
p = probability of a HOME outright win, in [0,1]
L = bookmaker HOME handicap; home -3.5 means L = -3.5
b = market home margin = -L
H, A = home, away; PF/PA = points for/against

C(x,lo,hi) = max(lo,min(hi,x))
B(a,b,w) = (1-w)*a + w*b
CM(x) = C(x,-15,15)
CT(x) = C(x,28,66)
CP(x) = C(x,0.01,0.99)
P(m) = CP(1 / (1 + exp(-m/7)))
R(x,d) = PHP round(x,d), default half-away-from-zero behavior
```

Margin bounds, total bounds and probability coefficient are configurable. Stages
operate sequentially: each `m`, `t`, `p` on the right is the incoming value unless
explicitly labeled as a baseline. A disabled stage or failed input/sample gate
normally preserves its incoming values and records a reason. An `applied=false`
flag does NOT universally mean there was no rounding or clamping.

## 1. Historical Elo baseline — `generate`, `getEloAtDate`

Inputs: latest team Elo record dated strictly before the game's calendar date;
missing Elo falls back to `nfl.elo.default_rating` (1500). This is a date filter,
not proof that a historical rating was observed before kickoff.

```text
hfa = 0 at a neutral site, otherwise 25 Elo points
adjustedHomeElo = homeElo + hfa
dElo = adjustedHomeElo - awayElo
m0 = CM(dElo * 0.09)
p0 = 1 / (1 + 10^((awayElo-adjustedHomeElo)/400))
t0 = average_total + (homeElo + awayElo - 2*defaultElo)/100
average_total default = 46.5
```

The initial total uses combined team strength, not an explicit possession/scoring
model. Later `P(m)` conversions replace, rather than preserve, `p0`. For example,
100 adjusted Elo points imply approximately 64.0% here and margin +9, while
`P(9)` is approximately 78.3%. These are different probability mappings.

## 2. EPA blend — `applyTrueEpaBlend`

Local safeguard: `true_epa.custom_epa_quarantined=true` immediately returns
`m0,p0,t0`, records `custom_epa_quarantined`, and bypasses the nflverse fallback
inside this stage. It does not disable separate EPA data collection.

If quarantine is explicitly removed and the stage has eligible data:

```text
dEPA = homeNetEPA - awayNetEPA
epaMargin = dEPA * 14
m = B(m0,epaMargin,w)                 w default 0.35
deltaP = tanh(dEPA * 8) * 0.12
epaP = CP(p0 + deltaP)
p = B(p0,epaP,w)
homeTotalDelta = (homeOffEPA - awayDefEPA) * 20
awayTotalDelta = (awayOffEPA - homeDefEPA) * 20
epaTotal = t0 + homeTotalDelta + awayTotalDelta
t = CT(B(t0,epaTotal,w))
```

First-party branch clamps `m` to the margin bounds; its `p` is already bounded by
blending bounded inputs. If offensive/defensive components are missing but net
EPA exists, the branch can update margin/probability while preserving `t0`.
The nflverse branch additionally clamps the blended probability.

### EPA source formulas and limitations

`TrueEpaCalculator::calculateForGame` uses:

```text
state = bounded down | distance bucket | ten-yard field-position bucket
futurePoints(play) = first subsequent nonzero score change, from possession perspective
sameGameEP(state) = mean(futurePoints for plays in this game with that state)
EPbefore = historicalBaseline[state] ?? sameGameEP[state] ?? 0
EPafter = EP(next eligible state), sign-reversed if possession changes; 0 if none
EPA(play) = scoreDeltaForCurrentOffense + EPafter - EPbefore
stored EPbefore, EPafter, EPA = R(value,3)
```

Distance buckets: <=1, 2-3, 4-6, 7-10, 11-15, 16+. Down is clamped to 1-4;
yards-to-endzone to 1-99 before ten-yard bucketing. The state lacks clock/half,
and the future-score/next-eligible scans have no half-boundary stop. This
same-game fitting is the reason for quarantine; it is not an independently
validated expected-points model. Using a completed prior game's outcomes is not
itself target-game leakage, but fitting and evaluating EP on the same game is
a separate feature-quality problem.

For the alternative nflverse source:

```text
offEPA = mean(imported EPA on the team's eligible pass/run offense plays)
defEPA = mean(imported EPA allowed on its defense plays)
netEPA = offEPA - defEPA
```

It uses final regular-season games before the target date; the previous season
may replace an insufficient current-season sample if it has more games. The
fallback gate requires both teams to have at least 4 games and 200 offensive
plays by default. Availability is not equivalent to predictive validation.
The total formula above subtracts defensive EPA; its source sign convention
must be checked independently before this branch is approved.

## 3. Preseason fallback — `applyPreseasonSignalBlend`

Gate: enabled, EPA was not applied, both current-season regular-season
`TeamMetric.predictive_rating` values exist. Despite the name, this has no
explicit Week-1-only gate.

```text
signal = homePredictiveRating - awayPredictiveRating + hfa*0.09
m = CM(B(m,signal,0.25))
p = P(m)
t unchanged
```

The predictive ratings are inputs produced upstream, not raw EPA. They may
already incorporate injuries/continuity. Reusing injury adjustments later can
overlap that information. The live lookup uses mutable metrics, so historical
replay needs a frozen rating source rather than today's row.

## 4. Rolling efficiency — `applyRollingEfficiencyBlend`

Gate: enabled, at least 2 prior current-season regular games per team. Disabled
by repository default and in all 31 audited production forecasts.

```text
teamSignal = 0.55*avgMargin + 0.25*recentMargin
           + 0.12*(yardDiff/50) + 0.75*turnoverDiff
signal = C(homeSignal-awaySignal+hfa*0.09,-14,14)
n = min(homeGames,awayGames)
w = C(maximumWeight,0,1) * n/(n+8)      maximumWeight default 0.35
m = CM(B(m,signal,w))
p = P(m)
totalSignal = (homePF+awayPF+homePA+awayPA)/2
t = CT(B(t,totalSignal,min(w,0.25)))
```

The eight-game prior is a conservative local policy, not an optimized fitted
coefficient. Old production used the configured fixed weight when this feature
was enabled; changing its weight has no effect while it remains disabled.

## 5. Opponent-adjusted efficiency — `applyOpponentAdjustedEfficiencyBlend`

Gate: enabled and at least 3 prior current-season regular games per team.
Disabled by repository default and in all 31 audited forecasts.

```text
adjustedMargin(game) = actualTeamMargin + (priorOpponentElo-1500)*0.015
teamScore = 0.45*mean(adjustedMargin)
          + 0.08*mean((teamYards-opponentYards)/100)
          + 2*(offRedZoneRate-defRedZoneRate)
          + 2*(offThirdDownRate-defThirdDownRate)
signal = C(homeScore-awayScore,-5,5)
w = 0.18*n/(n+8)                       n = min team game sample
m = CM(B(m,m+signal,w))                additive, unlike rolling's absolute target
p = P(m)
t unchanged
```

Opponent Elo is looked up before each prior game's date, not at the target game.
Do not divide the yard difference by 100 a second time: the profile already does.

## 6. Total environment — `applyTotalEnvironmentBlend`

Gate: enabled, profile sample at least 2 games for both teams. Defaults use the
latest 8 games and may prepend prior-season games. The existing defensive sample
allows all season types. With a resolved starting QB, offensive rows are restricted
to regular-season starts by that QB on the same team. A bounded prior-season
fallback is allowed; insufficient usable offense skips the adjustment.

```text
scoring = (homePF+awayPF+homePA+awayPA)/2
plays = (homeOffPlays+awayOffPlays+homeDefPlays+awayDefPlays)/2
YPP, RZ, thirdDown, TO = mean(the two offensive and two corresponding defensive rates)
penalties = (homePenaltyYards+awayPenaltyYards)/2

raw = (scoring-average_total)*0.22
    + (plays-128)*0.11
    + (YPP-5.35)*3.8
    + (RZ-0.58)*4.5
    + (thirdDown-0.39)*2.5
    + (TO-0.020)*(-34)
    + (penalties-48)*(-0.018)
adjustment = C(raw,-4,4)
signalTotal = CT(t+adjustment)
t = CT(B(t,signalTotal,0.35))
m,p unchanged
```

The variable called `explosiveAdjustment` is based on yards/play, not measured
explosive-play frequency. Do not describe the proxy as actual explosive plays.

## 7. QB form — `applyQbFormBlend`

Gate: enabled, both QB identities known, each has at least 30 prior attempts.
Starter resolution can use local game identity, depth/prior-game projections,
or nflverse fallbacks. Historical eventual-starter identity is not proof of what
was known pregame. The performance summary uses prior regular-season games.

```text
YPA = sum(passYards)/sum(attempts)
TD% = sum(passTD)/sum(attempts)
INT% = sum(interceptions)/sum(attempts)
sackRate = sum(sacks)/(sum(attempts)+sum(sacks))
rushYardsPerGame = sum(rushYards)/gameRows
QBscore = C((YPA-6.9)*1.2 + (TD%-0.045)*28
          - (INT%-0.025)*35 - (sackRate-0.065)*18
          + rushYardsPerGame*0.03,-4,4)
experienceSignal = (homeExperienceScore-awayExperienceScore)*0.35
signal = C(homeQBscore-awayQBscore+experienceSignal,-6,6)
sampleWeight = C(min(minTeamAttempts/30,minTeamGames/1),0,1)
earlyMultiplier = configured early_season_weight in weeks 1-4 (default 1), else 1
w = C(0.22*sampleWeight*earlyMultiplier,0,1)
m = CM(B(m,m+signal,w))
p = P(m)
t unchanged
```

Experience scores: rookie -1, first-year starter -0.45, developing -0.15,
veteran +0.35, elite veteran +0.75, unknown 0. Classification is based on
experience years: <=0, 1, 2, >=3, >=8 respectively. A missing experience value
is `unknown_limited_starter` for <=3 prior games, otherwise `unknown`; both
receive zero experience score. These labels are heuristics, not talent grades.

Zero denominators yield zero rates. Current nflverse QB projection supplies
`sacks_taken=0`; do not interpret this fallback's zero sack rate as verified
absence of sacks.

## 8. Line matchup — `applyLineMatchupBlend`

Gate: enabled and at least 2 games with paired team stat rows. Prior-season
regular games are prepended when the current-season game count is below 2.
Rates use aggregate sums, not averages of per-game percentages.

```text
offSackAllowed = sum(sacksAllowed)/sum(passAttempts)
defSackRate = sum(opponentSacksAllowed)/sum(opponentPassAttempts)
offRushYPA = sum(rushYards)/sum(rushAttempts)
defRushYPAAllowed = sum(opponentRushYards)/sum(opponentRushAttempts)

reference = (homeOffRushYPA+awayOffRushYPA+homeDefRushYPAAllowed+awayDefRushYPAAllowed)/4
homeRunEdge = (homeOffRushYPA-reference)+(awayDefRushYPAAllowed-reference)
awayRunEdge = (awayOffRushYPA-reference)+(homeDefRushYPAAllowed-reference)
legacyHomeRunEdge = homeOffRushYPA-awayDefRushYPAAllowed
legacyAwayRunEdge = awayOffRushYPA-homeDefRushYPAAllowed
homePressure = awayDefSackRate+homeOffSackAllowed
awayPressure = homeDefSackRate+awayOffSackAllowed
homeScore = 1.35*homeRunEdge-34*homePressure
awayScore = 1.35*awayRunEdge-34*awayPressure
signal = C(homeScore-awayScore,-4,4)
w = 0.18
m = CM(B(m,m+signal,w))
p = P(m)
totalSignal = C((legacyHomeRunEdge+legacyAwayRunEdge)*0.8
              - max(0,homePressure+awayPressure)*14,-3,3)
t = CT(t+totalSignal*min(w,0.25))
```

Local `ats-v2` corrects the previous subtraction convention: increasing opponent
rushing yards allowed now increases offensive advantage. A three-yard increase
raises the home margin by `3*1.35*0.18 = 0.729` points before downstream stages,
away from caps. The common reference cancels from the margin comparison; it is
the matchup mean, not a measured league average. Both teams require finite rates
and positive finite offensive/defensive attempts; otherwise this layer is skipped.
The total calculation deliberately retains legacy edges to isolate this spread
correction. Weights remain unvalidated defaults, not newly fitted coefficients.
Direction, symmetry and missing-data tests cover this local, undeployed change.

These are sack/rushing proxies, not charted pressure or blocking win rates. The
sack denominator also differs from the QB stage's dropback denominator.

## 9. Situational context — `applyContextualFactorsBlend`

Active subadjustments below are combined; this is not an either/or selection.
Repository defaults are under `predictions.contextual_factors`. In `ats-v3`,
H2H/division/conference/same-week records are descriptive only, regardless of
their configured legacy weights.

### Home/away and rivalry

```text
venueAdjustment = (homeHomeAvgMargin-awayAwayAvgMargin)*0.06
  requires at least 2 games in each venue split
h2hAdjustment = 0      former recentH2HHomeAvgMargin*0.05 retained as descriptive metadata
divisionTotal = 0      former same-division -0.4 retained as descriptive metadata
```

H2H evidence can exist even when teams are not in the same division.
Venue split and H2H helpers do not universally share regular-season-only scope.

### Matchup and same-week records

```text
recordSignal(home,away,w) =
  (2*(homeWinFraction-awayWinFraction)
    +(homeAvgMargin-awayAvgMargin)/7)*w
```

Both records must have at least one game, otherwise signal = 0. Records use
`(wins+0.5*ties)/games` as a fraction, rounded to 3 decimals. The normal matchup
sum uses team/H2H, division and conference weights 0.40/0.30/0.20 and a latest-8
game lookback per scope. The same-week sum uses 0.25/0.18/0.12 with a 10-season
lookback, target week and matching season type. These scopes overlap; they are
not six independent observations. Prior-season pedigree is context-only and
its numerical spread adjustment is explicitly zero.
In `ats-v3`, both record sums above are also forced to zero in the forecast;
their former scores remain under `descriptive_spread_adjustment` and
`affects_prediction=false`.

### Schedule and coaching

```text
scheduleMargin = C((homeRest-awayRest)*0.09,-1,1)  if both rest values known
if homeRest <= 4: scheduleMargin += -0.25; scheduleTotal += -0.2
if awayRest <= 4: scheduleMargin -= -0.25; scheduleTotal += -0.2
if awayConsecutiveRoadGames >= 2: scheduleMargin -= -0.2

coachingMargin = (homeConfiguredPrior-awayConfiguredPrior)*0.12
               +(homeNewCoachPrior-awayNewCoachPrior)*0.20
```

Unknown coach ratings default to zero. Having a detected new coach can still
produce contextual flags without a numerical coaching adjustment.

### Weather proxy and combination

Outdoor location/month proxy: configured cold state during Nov/Dec/Jan/Feb adds
-0.6 to total; configured hot state during Sep/Oct adds -0.2. Indoor or unresolved
roof/exposure gates can suppress the proxy. The state lists live in config.

```text
deltaM = C(venueAdjustment+scheduleMargin+coachingMargin,-2,2)
deltaT = C(weatherProxy+scheduleTotal,-2.5,2.5)
m = CM(m+deltaM)
t = CT(t+deltaT)
p = P(m)
```

Subcontexts often return adjustments rounded to 3 decimals before summation.
This layer produced the largest observed pre-market increase in average margin
error in the first-two-week audit. That is sample evidence, not a causal proof
that all its subfeatures are harmful.

## 10. Actual weather — `applyActualWeatherBlend`

Requires an available, fresh weather row, usable roof/exposure classification,
outdoor venue, and numeric temperature, wind and precipitation. Freshness uses
`validation.thresholds.weather_completeness.stale_after_hours` (action fallback
8 hours), not a hardcoded prediction-wide 24-hour rule.

Defaults from the action's weather configuration:

```text
delta = 0
if wind >= 15 mph: delta += wind * -0.08
if gust >= 24 mph: delta += gust * -0.04
if precipitation >= 0.03 inches: delta += precipitation * -18
if temperature <= 32 F: delta += -1
if temperature >= 88 F: delta += -0.5
delta = C(delta,-4,4)
t = CT(t+delta)
m,p unchanged
```

Threshold branches use the full measurement, not only the excess over threshold.
Actual weather can apply after the location/month weather proxy: overlap needs
validation, not an assumption that only one weather layer ran.

## 11. Depth-chart injuries — `applyDepthChartInjuryAdjustments`

Inputs are scoped out/questionable availability counts weighted by depth/role,
with local and nflverse sources, deduplication and verified research availability
handled upstream. They are not simply counts of every name on the injury report.

```text
homePenalty = 0.50*homeWeightedOut + 0.20*homeWeightedQuestionable
awayPenalty = 0.50*awayWeightedOut + 0.20*awayWeightedQuestionable
deltaM = awayPenalty-homePenalty
offenseReduction = 0.30*bothTeamsOffensiveOut + 0.10*bothTeamsOffensiveQuestionable
defenseIncrease = 0.30*bothTeamsDefensiveOut + 0.10*bothTeamsDefensiveQuestionable
deltaT = C(defenseIncrease-offenseReduction,-3,3)
m = m+deltaM
t = t+deltaT
p = CP(p+deltaM*0.03)
```

`ats-v3` separates offense and defense, excluding unknown/special-team positions
from the total effect. The cap is `depth_chart_injuries.max_total_adjustment`.
Weights and cap are heuristics, not fitted injury values; baseline overlap with
long-term absences remains a limitation.

Local `ats-v2` removes this stage's early one-decimal rounding, including when
adjustments are zero, and records raw adjustments/outputs in metadata. It does not
apply `CM`/`CT` itself; later stages may clamp the results. Injury multipliers
include starter 1.35, rotation 1.10, starting QB at least 2.40, early-depth skill
positions at least 1.45, then configured role maxima for starters (e.g. QB 2.80).
See `nflverseDepthInjuryMultiplier` and the injected `DepthChartImpactService` for
source-specific role resolution; this reference does not equate their missing
depth cases with a verified starter. Multipliers/counts are rounded before use.

Known-return-before-game injuries are excluded. Unknown-return scoping uses the
configured horizon relative to `now()`, another reason not to regenerate past
forecasts from today's injury tables.

## 12. Adaptive point calibration — `applyAdaptivePointCalibration`

Select up to 384 prior final predictions before the target date; require 48
usable rows. Predictions and associated results must have spread/total values.

```text
marginResidual_i = predictedHomeMargin_i - actualHomeMargin_i
totalResidual_i = predictedTotal_i - actualTotal_i
trimmedMean(x): sort numeric x; remove floor(N*C(trimFraction,0,0.4)) per tail;
               mean remaining values; empty => 0
default trimFraction = 0.10
wM = 0 unless allow_legacy_spread_bias_calibration=true; legacy default = 0.35
wT = 0.45
deltaM = C(-trimmedMean(marginResidual)*wM,-2,2)
deltaT = C(-trimmedMean(totalResidual)*wT,-2.5,2.5)
m = CM(m+deltaM)
t = CT(t+deltaT)
p = incoming p when wM=0, otherwise P(m)
```

The local change disables the spread correction, not the total correction or
the entire query. It still clamps on a successful calibration path with zero
spread weight. The historical source is mutable and not restricted to a single
frozen model version; this is a validation limitation, not certified calibration.

## 13. Position-grade context — `applyPlayerPositionGradeContext`

```text
groupEdge = homeGroupGrade-awayGroupGrade, rounded to 2 decimals
overallEdge = homeOverallGrade-awayOverallGrade, rounded to 2 decimals
missing grade on either side => null
m,t,p unchanged
```

Groups include QB, OL, RB, WR/TE, DL/EDGE, LB, DB and ST. Upstream grade/report
generation is separate. Coverage/availability affect the displayed evidence,
not a direct numerical blend at this step.

## 14. Market blend — `applyMarketBlend`

```text
b = -bookmakerHomeHandicap
m = CM(wM*m+(1-wM)*b)                 wM default 0.50
t = CT(wT*t+(1-wT)*marketTotal)       wT default 0.60
p = P(m)
```

Each numerical blend requires its market; if either market exists the successful
branch recomputes `p`, even if only the total market is available. No markets =>
preserve incoming values.

`extractMarketSpreadAndTotal` takes the FIRST matching home spread and first
total encountered in the bookmaker payload. Despite a code comment saying
consensus, this method does not compute a multi-book consensus; spread and total
can come from different books. Live input is mutable `game.odds_data`, while
audits must use the frozen observed line.

Away from caps, `finalEdge = wM*(preMarketMargin-b)`. For positive model weight,
market blending shrinks the edge but does not repair its direction. A total-only
market can also overwrite an earlier separately adjusted winner probability.

## 15. Winner calibration — `applyAdaptiveWinProbabilityCalibration`

Skip when disabled or `abs(p-0.5)<=0.0005`. Read up to 512 prior final predictions.

```text
c = max(p,1-p)
bucketWidth = 0.05
bucketFloor = max(0.5,floor(c/bucketWidth)*bucketWidth)
bucketCeiling = min(1,bucketFloor+bucketWidth)
rows = forecasts with confidence >= floor and < ceiling, if >=30; else all history
observed = correct predicted outright winners / usable rows
target = C(observed,0.501,0.95)
adjusted = C(c+(target-c)*0.45,max(0.501,c-0.08),min(0.95,c+0.08))
p = CP(adjusted if incoming p>=0.5 else 1-adjusted)
m,t unchanged
```

The code compares `predictedHomeWin = p>=0.5` with `homeWon = homeScore>awayScore`.
Ties are not separately removed by this helper. This is not spread-cover
calibration. Its mutable-history and fallback-bucket behavior require review.

## 16. Automated forecast tweaks — `applyAutomatedCalibrationTweaks`

```text
shrink(p,s) = CP(0.5+(p-0.5)*(1-s))
weeks 1-4: p = shrink(p,0.07)
if t >= 47 and high-total adjustment enabled: t += 1.25
if high-trust regime applies: p = shrink(p,0.06)
m unchanged
```

The regime check uses current confidence >=85%, selects high-confidence prior
predictions from a latest-272 window, requires 40, and applies when outright
accuracy is below 68%. It uses mutable history. Shrinks are sequential, so when
both apply, the distance from 0.5 is multiplied by `0.93*0.94`, not reduced by
a combined 13 percentage points. The final high-total addition has no final
`CT` clamp in this method.

## 17. Analysis, trust and selection — `applyAnalysisLayer`

This runs after the numerical pipeline. Raw confidence is `100*max(p,1-p)`.

```text
spreadEdge = m-b = m+L
totalEdge = t-marketTotal
trust = C(100*max(p,1-p)
          + min(12,2*abs(spreadEdge))       if spread known
          + min(8,1.2*abs(totalEdge))       if total known
          - riskFlagCount*riskPenalty,0,100)
riskPenalty default = 4
```

Automated trust adjustments (when enabled), in order: subtract 5 in weeks 1-4;
optional big-margin/key-number boosts; subtract 5 for the active bad high-trust
regime; tight-margin cap; final clamp 0-100. Local
`allow_unvalidated_trust_boosts=false` blocks the big/key boosts. The big-margin
default is +4 at absolute margin >=7 when explicitly enabled; repository key
boost defaults are both zero (10 takes precedence over 7).

Tight cap: if `abs(m)<3`, trust <85, neither key-7 nor key-10 reason exists, and
trust >74.9, set trust to 74.9. Presence of those reasons bypasses that cap even
when numerical key boosts are disabled; reason presence is not proof of value.

```text
hasEdge = abs(spreadEdge)>=2 OR abs(totalEdge)>=3 (only known values qualify)
base classification = no_bet_no_edge if !hasEdge;
                      lean if trust>=66; otherwise no_bet_risk
model signal = strong at trust>=65; lean at >=55; otherwise pass
rule-play numerical gate = trust>=75 AND (abs(spreadEdge)>=2 OR abs(totalEdge)>=3)
```

The Boolean rule engine, matched signal combinations and research eligibility
can then change classification/hold status. They do not recompute `m,t,p` in
this method. Verified research availability can feed the earlier injury inputs;
research is therefore not universally disconnected from the numerical model.
Trust is a heuristic score, not a calibrated probability of covering.

### Separate spread probability — `NflSpreadProbability::estimate`

The live analysis currently supplies an empty residual list, so cover probability
is unavailable rather than borrowed from winner confidence. The shadow helper:

```text
side = home if m+L>0; away if m+L<0; none if abs(m+L)<0.0001
recommendation = pass_small_edge when abs(m+L)<analysis_layer.min_spread_edge (default 2)
residual_i = actualHomeMargin_i - predictedHomeMargin_i
simulatedCover_i = (R(m+residual_i,0)+L) * (1 for home, -1 for away)
Pcover = count(simulatedCover>0)/N
Ppush = count(abs(simulatedCover)<0.00001)/N
Ploss = max(0,1-Pcover-Ppush)
PcoverGivenNoPush = Pcover/(1-Ppush), null when all pushes
American-odds net payout = price/100 if price>=100, else 100/(-price) if price<=-100
expectedProfitPerUnit = Pcover*payout-Ploss
```

Requires N>=200 valid prior residuals, finite inputs, a half-point/integer line,
and a directional side. Even then it returns `shadow_uncalibrated`,
`calibrated=false`, `bet_eligible=false`. The chronological audit owns the
same-version/as-of filtering; this helper alone does not enforce provenance.
The pro-signal spread score and release recorder independently enforce the small-
edge gate. Winner/total scores cannot override it. The UI preserves the forecast
but labels a small spread difference as a pass.

The separate fixed benchmark is `(legacyMargin+b)/2`; it is saved as a shadow
candidate, not fed back into the active prediction.

## 18. Output precision and grading

```text
published margin = R(m,1)
published total = R(t,1)
published home-win probability = R(p,3)
published winner confidence = R(100*max(p,1-p),2)
published Elo values = R(Elo,1)

actualHomeMargin = homeScore-awayScore
home cover margin = actualHomeMargin+L
away cover margin = -(actualHomeMargin+L)
selected cover margin >0 => win; <0 => loss; abs(value)<0.0001 => push
zero projected edge => no pick, not an automatic home pick or a loss
W-L win fraction = W/(W+L); pushes and no-picks are separate
MAE = mean(abs(predictedHomeMargin-actualHomeMargin))
```

Grading must use the original frozen handicap, not a live/postgame overwrite.
Local `ats-v2` passes published one-decimal margins/totals to analysis so selection
agrees with the displayed forecast, while retaining final unrounded values under
`model_metadata.raw_outputs` and the selection policy under `numeric_policy`.
Other metadata is still rounded at individual stages (commonly 3-4 decimals), so
it is not a complete full-precision execution trace. Legacy injury-stage rounding
can amplify reconstruction differences. The replay excludes non-reproducing games;
do not loosen its checks merely to produce a complete record.

## Supporting team-profile calculations

`NflTeamHistory` shares reads but intentionally preserves differing sample rules:

```text
plays = max(0,passAttempts+rushAttempts+sacksAllowed)
turnovers = interceptions + (fumblesLost ?? fumbles ?? 0)
YPP = yards/plays; passRate = passAttempts/plays
redZoneRate = redZoneScores/redZoneAttempts
thirdDownRate = thirdDownConversions/thirdDownAttempts
turnoverRate = turnovers/plays
rate = 0 if denominator<=0
mean = sum(values)/count; empty =>0; usually R(mean,3)
```

Paired offense/opponent stat rows are required for stat-based means. With a
resolved QB, totals/line sample gates use the smaller usable offense/defense
sample; otherwise their legacy counting rules remain. QB-aware offense selects
same-team regular starts by the resolved starter; a missing name is not assumed
to match. Totals/line profiles can use the previous season, capped at eight
offensive games by default, while retaining the existing defensive sample.
Rolling/opponent-adjusted profiles also filter to that QB when known. These
disabled-by-default stages do not add a return bonus. Rolling `recentMargin`
uses the latest 5 selected current-season regular results. Its yard/turnover differences
are mean yards-for minus yards-against and opponent turnovers minus own turnovers.
Opponent adjustment uses yards difference /100. Total profiles average per-game
rates (TO/takeaway to 4 decimals), whereas line profiles divide aggregate totals
(sack rates to 4 decimals, rushing rates to 3). Missing statistics that become
zero and `fumbles` fallback do not establish verified zero events or lost fumbles.

## Maintenance checklist

- Update the relevant formula, config default, gate, precision and issue status
  together when changing code. Never mark a finding fixed by documentation alone.
- Keep the execution-order test and frozen replay fixtures passing, but do not
  treat correctness tests as predictive validation.
- Test monotonic direction, missing inputs, caps, rounding, zero edges and source
  timestamps. Use shadow/ablation comparisons before promoting numerical changes.
- Preserve historical forecasts. Separate original-all, matched-original and
  counterfactual results, plus exclusions and source snapshot IDs.
- Review and deploy deliberately. Local implementation and passing tests do not
  establish deployment or improved out-of-sample accuracy.
