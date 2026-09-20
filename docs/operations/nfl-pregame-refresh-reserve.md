# Controlled NFL pregame refresh reserve

Eight ordinary attempts per game per rolling 24 hours remain the default.
Within 12 hours of a known future kickoff, scheduled/delayed games can use four
additional attempts. These are not four per scheduler run: all attempts, including
failed and unpriced attempts, count toward a total ceiling of twelve.

- `NFL_RESEARCH_PREGAME_RESERVED_ATTEMPTS`: default 4 (0 disables the reserve).
- `NFL_RESEARCH_PREGAME_RESERVE_HOURS`: default 12.
- Overall production admission ceiling remains $50 per rolling 24 hours.
- Per-game ceiling remains $0.75; the next $0.15 reservation must fit both ceilings.
- Retry spacing, atomic reservation locks, provider locks, citations, candidate
  checks, source freshness and 90-minute late-pregame freshness are unchanged.
- No counters are reset and no old report timestamps are extended.

Attempt exhaustion now emits `research_game_attempt_limit_reached`; monetary
exhaustion retains `research_game_daily_budget_reached`. Old stored assessments
can still contain the formerly ambiguous budget code until reassessed.

The board separately reports expired evidence, changed predictions, blocked
refreshes, and a needed reassessment when an older revision lacks a comparison
snapshot. All simultaneous reasons remain visible in Details. A saved timestamp
alone does not prove a changed forecast. New assessments capture a semantic
stored-prediction hash (bucketed outputs, decision context and model versions).
Identical saves no longer invalidate a matching assessment. Material changes do.
Entering the six-hour window immediately applies the 90-minute age check even
if the original stored expiry was longer. The board is read-only and cannot
renew evidence or approve a wager.

Deployment verification: read runtime controls; run bounded single-game research
commands; inspect their saved report/revision and actual exit code; run
`nfl:research-readiness --days-forward=1 --json`. An attempt completing does not
guarantee a ready report: incomplete evidence and unresolved questions stay held.
