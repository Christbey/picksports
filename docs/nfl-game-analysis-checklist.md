# NFL game analysis checklist

Reusable questions from the Seattle at Denver discussion on October 15, 2026. Use these for a complete pregame breakdown so the user does not need to request each comparison separately. Answer narrow follow-up questions directly without repeating the entire checklist.

## One request to reuse

“Run the full NFL game analysis checklist for tonight using PickSports production data. Cover the matchup, efficiency, venue splits, historical comparisons, schedule trends, weather, player props, sacks, offensive lines, and punts. Finish with ranked leans, playable lines and prices, and the strongest opposing evidence. Use outside research only if I explicitly request it.”

## Predictions and team quality

- Who wins outright? Who covers the stored spread? Does the total lean over or under?
- What are the model margin, total, market line, price, and difference between them?
- Which team has performed better this season, beyond its win–loss record?
- Compare points scored/allowed, scoring margin, offensive and defensive yards/play, passing and rushing EPA, success rate, third downs, red-zone results, sacks, and turnovers. Include sample sizes and league ranks when available.
- Is there a meaningful advantage in net yards/play or net EPA? Has that metric actually been validated historically, or is it descriptive evidence?
- Have the teams faced common opponents? Compare results, efficiency, location, timing, and opponent strength.

## Venue and individual game review

- Compare the visiting team on the road with the host at home this season.
- Show the actual opponents and scores behind each split, not just averages.
- Break down each relevant home/away game: yards, yards/play, EPA, protection, third downs, turnovers, and scoring opportunities.
- Were wins supported by sustained efficiency, or by turnovers, field position, finishing drives, or non-offensive scores? Attribute causes only when the stored evidence supports them.
- Does one outlier game explain the average? How much does opponent quality affect the comparison?

## Schedule and historical trends

- What are each team's Thursday-night records, home/away splits, recent results, and average scores?
- What is the league-wide average total for all Thursday games and for Thursday evening games separately?
- What happened in this season's completed Thursday-night games? List scores, mean, median, and sample size.
- What are the head coaches' short-rest records and the starting quarterbacks' short-rest and Thursday-night records?
- Define short rest explicitly, distinguish it from a season opener, and separate career coverage from current-team results.
- Count overlapping coach/QB games once when assessing the strength of the evidence.

## Totals and historical comparisons

- Does the matchup favor scoring because of efficient offense, weak defense, pace, possessions, explosive plays, or finishing drives?
- Which team might fail to contribute enough points for the over?
- How did historical games with similar pregame offensive and defensive ratings finish?
- Define comparison criteria before inspecting results. Use only information available before each historical game; avoid end-of-season ratings that include the result.
- Report sample size, average total, and results against verified historical closing totals. Separately report outcomes above tonight's threshold.
- Show whether comparable games near tonight's market total tell a different story. Do not equate an exploratory hit rate with tonight's probability.
- Clearly distinguish scoring-based ratings from EPA, Elo, and opponent-adjusted ratings.

## Weather and availability

- Is weather stored for this game? Show provider, retrieval time, forecast hour/timezone, temperature, wind, gusts, precipitation, and roof status.
- Did the prediction use the latest stored weather or an older snapshot?
- Which confirmed injuries or availability changes materially affect the matchup, offensive line, coverage, or workload?
- Are those effects already included in the model? Avoid counting them twice.
- When outside research is explicitly requested, verify material availability, weather, and market claims with dated sources and distinguish them from stored system evidence.

## Player props

- Which props have the strongest current-season usage and matchup support?
- Cover relevant passing attempts, completions, passing yards, rushing yards, receiving yards, receptions, and touchdown scorers.
- Show exact line, price, chronological game logs, hit count, sample size, and main concern.
- Separate actual zero production from missing data, partial games, and absences. Do not omit zero-stat appearances to improve a hit record.
- Explain recent workload changes and conflicts between model direction and game logs.
- Identify correlated selections and rank a short list rather than recommending every available market.

## Sacks and offensive lines

- Are individual or team sack markets stored? If not, provide candidates and request the offered lines/prices rather than inventing quotes.
- Compare sacks allowed, sacks generated, rates using attempts plus sacks, recent game logs, and relevant venue splits.
- Compare individual pass-rusher sack totals and games with a sack; review available pressure and blocking evidence.
- Distinguish charted blocking grades from unit-level statistical proxies, and quarterback effects from offensive-line performance.
- Evaluate user-supplied prices with break-even probabilities without treating small historical hit samples as true probabilities.

## Punts and team totals

- How often does each team punt, and how many punts does its opponent force?
- Show each game's team punts, opponent punts, combined punts, and average; verify play coverage and exclude nullified plays.
- Compare offered team and combined punt thresholds. Label hypothetical target lines separately from stored quotes.
- How often did nine or more combined punts occur together with over 40 points? Also distinguish exactly nine punts when relevant.
- For another offered threshold, calculate that exact overlap and identify whether the sample is team-specific or league-wide.
- Explain how turnovers, field goals, fourth-down attempts, and late-game score situations can change punt opportunities.
- Evaluate team totals when quotes are available; implied team scores from spread/total are not actual team-total offers.

## Finish with a decision

Lead with the preferred winner, spread direction, total direction, and strongest props. Include prices, the strongest supporting evidence, the strongest opposing evidence, and what changed the assessment. Keep descriptive leans separate from formal approval status. A pass is appropriate when the evidence or price does not justify a selection.

Use production API data first, then authorized read-only production database access when needed. Follow AGENTS.md for authentication, source attribution, the 36-hour odds policy, and user exclusions. Reuse applicable retrieved data; this checklist does not authorize provider refreshes, jobs, code changes, or deployments.
