# NFL historical game context (2023 onward)

`nfl_game_context_facts` stores sourced facts linked to the actual game and team: head coach, named injury report, and separately documented participation. `Game::contextFacts()` exposes them; `nfl:game-context-coverage --game=ID` prints the evidence. The importer appends evidence and does not update predictions, current injuries, player rosters, scores, or existing coach fields.

Every record retains its source URL, source row, publication/source-update time when available, import time, and content hash. Reimporting the same evidence does not duplicate it or move its import timestamp. Corrections create new evidence instead of erasing the old report. Conflicting sources remain visible; a consumer must resolve them explicitly.

Injury designation is not participation: Questionable can precede an inactive announcement. A blank report designation stays null, and absence from these records means **unknown**, never healthy. Archived practice-only reports are retained. All reported positions are included; `key_reason` is an explicitly sourced relevance annotation, not a guessed depth-chart ranking. A null key reason means unclassified, not unimportant. Missing box-score rows never manufacture inactive facts.

Historical model consumers must use `GameContextFact::knownAt($predictionTime)` (both recorded and published timestamps must qualify). Retrospective backfills are available for explaining past games but were not known to our system at historical prediction time. Coach CSVs have no publication timestamp and are excluded from this scope. This change does not activate new historical model features or change the existing prop model.

## Import

Download public source CSVs to the execution environment, preserving the URLs below. Use `--dry-run` before imports. Schedule matching requires season, both teams, and an unambiguous game date (US game date or next-day UTC date); it never creates games. Injury matching uses the archive's season/round/week/team through that schedule, avoiding ESPN postseason week-number differences.

```sh
curl -fL https://raw.githubusercontent.com/nflverse/nfldata/master/data/games.csv -o /tmp/nfl-context-games.csv
php artisan nfl:import-game-context schedules /tmp/nfl-context-games.csv --from-season=2023 --source-url=https://raw.githubusercontent.com/nflverse/nfldata/master/data/games.csv

curl -fL https://github.com/nflverse/nflverse-data/releases/download/injuries/injuries_2023.csv -o /tmp/nfl-context-injuries-2023.csv
php artisan nfl:import-game-context injuries /tmp/nfl-context-injuries-2023.csv --schedules=/tmp/nfl-context-games.csv --source-url=https://github.com/nflverse/nflverse-data/releases/download/injuries/injuries_2023.csv --from-season=2023 --to-season=2023

curl -fL https://github.com/nflverse/nflverse-data/releases/download/injuries/injuries_2024.csv -o /tmp/nfl-context-injuries-2024.csv
php artisan nfl:import-game-context injuries /tmp/nfl-context-injuries-2024.csv --schedules=/tmp/nfl-context-games.csv --source-url=https://github.com/nflverse/nflverse-data/releases/download/injuries/injuries_2024.csv --from-season=2024 --to-season=2024

php artisan nfl:import-game-context evidence database/data/nfl/2025-sf-lar-context.json --schedules=/tmp/nfl-context-games.csv
php artisan nfl:game-context-coverage --from-season=2023
```

Curated JSON uses `season`, `game_type` (`REG`, `WC`, `DIV`, `CON`, `SB`), archive `week`, `team`, `kind`, `subject`, `source_url`, optional `published_at` with explicit timezone, and relevant `position`, `injury`, `designation`, `participation`, `key_reason`. See the checked-in SF–LAR example. A participation fact needs an explicit participation value and its own source. Dates without a timezone are interpreted as UTC. Import validates all matched facts before one transaction writes them.

## Source limitations and outstanding coverage

[nflverse documents that its injury provider stopped after 2024](https://nflreadr.nflverse.com/articles/nflverse_data_schedule.html). The downloaded 2023 file contains regular season and Wild Card reports; the 2024 file includes all postseason rounds. Neither implies every injury or IR absence was reported. Preseason is absent from the schedule source. 2025 onward needs archived official team/NFL reports or another historically timestamped provider; do not backfill it from today's injury API.

`database/data/nfl/2025-sf-lar-context.json` supplies the verified October 2 and November 9, 2025 Purdy cases plus selected reported injuries. Purdy's October report says Out (toe). November's report says Questionable (toe), followed by an official inactive announcement. Each assertion has its own source URL and original timestamp. This is selected evidence, not complete 2025 league coverage.

Coverage counts are team-games with at least one fact, not completeness claims. The report separately counts team-games with no injury evidence, including preseason and missing seasons. Ongoing ingestion of these historical facts is manual; current injury snapshots continue through the existing ingestion jobs.
