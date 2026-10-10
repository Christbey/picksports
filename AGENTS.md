# PickSports agent instructions

Read [CLAUDE.md](CLAUDE.md) for engineering conventions. The following rules govern sports analysis and the use of this system.

## Help the user make decisions

- Requests for picks, handicaps, predictions, props, results, or team comparisons are analysis tasks. Use the existing system and answer the question; do not turn them into coding, deployments, migrations, calibration projects, or provider refreshes.
- Model calibration is background work. Its absence does not block descriptive analysis or clearly labeled betting leans. Never invent cover probabilities, call an unvalidated probability calibrated, or change an approval status to make a selection look approved.
- Present the selection, stored playable line and price when available, supporting system facts, and the main concern. Keep technical diagnostics out of ordinary betting answers unless they materially affect the interpretation or the user asks.

## Use PickSports data as the source of truth

1. Prefer authenticated, read-only requests to the PickSports production API for sports analysis. The local development database is not a substitute for production results unless explicitly requested.
2. If API access is unavailable or an endpoint omits required evidence, use existing authorized read-only production database access. State that fallback. Do not bypass authentication, mint credentials, or expose tokens. A 401/403 is an access issue, not evidence that no data exists.
3. Do not search the web or use outside sports articles, provider APIs, remembered stats, or general sports knowledge to supply or corroborate sports facts unless the user explicitly requests external research. The internal `/research` endpoint contains stored system material; its name is not authorization to browse externally.
4. If the system lacks a fact, say it is unavailable in the system. Do not silently fill gaps. Distinguish no data, stale data, incomplete coverage, and access failure.
5. Give the source and relevant data timestamps briefly. Example: "Source: PickSports production API; lines updated October 10 at 2:10 p.m. Central." Only claim API provenance when the data actually came through the API. Retain endpoint/query or record IDs in working evidence so claims can be traced.

## Read-only API starting points

Use the actual configured production host with base path `/api/v2/sports/nfl`. Discover team and game IDs from the API rather than guessing them. These are GET routes; do not trigger research retries, syncs, or generation jobs to answer an ordinary analysis question.

| Need | Relative endpoint |
| --- | --- |
| Date-specific slate | `/games` |
| Stored predictions for a slate | `/predictions` |
| Full game-page data | `/games/{game}/page` |
| Individual stored prediction | `/games/{game}/prediction` |
| Current-season independent matchup evidence | `/games/{game}/matchup-signals?window=season_to_date` |
| Situational trends | `/games/{game}/trends` |
| Stored historical spread/market evidence | `/games/{game}/market-history` |
| Team lookup | `/teams` |
| Team results | `/teams/{team}/games` |
| Team metrics | `/teams/{team}/metrics` |
| Team season stat averages | `/teams/{team}/stats/season-averages` |
| League team comparison | `/metrics/teams` |
| Stored player props | `/games/{game}/player-props` |
| Stored injuries | `/injuries` |
| Existing research revisions, when needed | `/games/{game}/research` |

For slate requests, specify `from_date`, `to_date`, `season`, and supported pagination parameters; consume all relevant pages. Resolve "today" and "tomorrow" in America/Chicago. For current-season comparisons, select the requested season and regular season (`season_type=2` for NFL), and completed games before the target kickoff. Check endpoint-specific filters rather than assuming all routes accept the same query parameters.

Use existing approved authentication with `Accept: application/json`. See [API v2 reference](docs/api-v2-reference.md), [endpoint contracts](docs/api-v2-endpoint-contracts.md), and [OpenAPI](docs/openapi-v2.json). Confirm uncertain routes/filters against `routes/api-v2.php` and their request classes. Do not store credentials in documentation or source control.

## Evidence and freshness

- Stored odds older than 36 hours are stale. Use source observation timestamps where available, not the time this conversation queried the API. Clearly label stored quotes; do not call them live or guarantee availability.
- Reuse data already retrieved for the same analysis when still applicable. Do not repeatedly check odds or call paid providers. An analysis request does not authorize forcing a refresh.
- For "who has been better this season," lead with completed-game results and current-season performance: sample size, scoring margin, offense/defense, turnovers, and opponent quality where available. Separate prior-season history and model assumptions from this evidence.
- A model's direction is not independent evidence if it blends in the market line. Explain material historical-rating, home-field, market, injury, or small-sample effects when they explain disagreement with current-season results.
- Separate predicted winner, spread-cover lean, total lean, and any stored approval status. A bigger model/market disagreement alone does not establish a stronger bet.
- Respect exclusions such as "do not check props" or "ignore injuries." Counterfactual exercises should use non-persisting previews, compare the same inputs/settings except the requested change, and disclose retained indirect effects. Do not overwrite stored forecasts or change production settings for an exercise.
