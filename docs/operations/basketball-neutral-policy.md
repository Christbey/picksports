# Basketball neutral-site policy

Incoming NBA/CBB/WNBA/WCBB ESPN boolean neutral observations are stored on games and canonical events. Missing/null preserves known values and is unknown for new games; malformed explicit values decline the affected source game. WNBA requires the additive nullable neutral-site migration. Provider ingestion does not backdate a fact's availability to kickoff.

Future legacy NBA/WNBA generation uses `rules-observed-neutral-v2` and `core-neutral-observation-v2`. Observed true removes explicit home advantage; NBA also removes home efficiency/form points and venue splits/components. Unknown uses the previous home-context assumption and records `neutral_site_unknown`. Snapshots retain nullable observation, effective HCA, fallback, capture and unverified historical-availability metadata. Historical reconstruction ignores current neutral observations because their pregame availability is unknown. Existing forecasts/artifacts are not automatically rewritten or retrained.

Existing canonical approved releases retain their frozen policy. To prepare an inactive candidate locally or in an explicitly authorized release workflow:

```sh
php artisan nba:register-calculation-release --neutral-aware-draft
php artisan cbb:register-calculation-release --neutral-aware-draft
php artisan wnba:register-calculation-release --neutral-aware-draft
```

Each creates or reuses a matching draft1.1.0 with the new nullable schema and neutral-aware policy, without approval or activation. Do not combine with `--effective-at` or a conflicting explicit `--release-version`. Existing non-draft1.1.0 or conflicting frozen parameters refuse; do not delete/rewrite them to force registration. Ordinary registration defaults retain their previous behavior and can approve releases; the new flag is the draft-only path.

Candidate activation, effective-window coordination, validation/calibration and deployment review remain separate controlled work. Preparing this candidate does not retire the active release, promote artifacts, publish new picks or backfill history. The compiled schema adapters support explicit reviewed activation later, preserving old schema outputs. See the consumer review (private verification record retained separately) and producer review (private verification record retained separately) for evidence and limits.
