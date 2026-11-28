# NFL historical game-context evidence

`nfl_game_context_facts` appends sourced game/team facts without updating predictions, current injuries, rosters, scores or coach fields. This is an explicitly authorized historical ingestion workflow, not an ordinary sports-analysis request. Resolve actual game/team identities and review the intended season scope before any provider acquisition or database write.

Each fact retains source identity, original publication/update time when known, import time and content hash. Reimporting identical evidence neither duplicates it nor moves the original import clock. Corrections append evidence; conflicting sources remain visible. Injury designation, participation and current roster health are distinct. Missing designation/position/relevance stays null or unknown; a missing box-score row cannot manufacture inactivity.

Historical consumers must use the existing `knownAt` admission, including both recorded and publication clocks. A later retrospective import was not known to this system at the old prediction instant. A coach CSV without publication time cannot establish that availability. This workflow does not activate historical model features or alter prop predictions.

## Reviewed-input operations

Prepare source files privately with their actual URLs, hashes, season and scope. Inspect command help and a dry run before apply. Schedule matching requires season, both teams and an unambiguous date; it never creates games. Injury matching follows the source archive's season/round/week/team through that schedule, not an assumed ESPN postseason week number.

The following paths are placeholders for reviewed inputs, not bundled evidence or commands to run automatically:

```sh
php artisan nfl:import-game-context evidence '/path/to/reviewed-context.json' --schedules='/path/to/reviewed-schedules.csv' --dry-run
php artisan nfl:prepare-injury-archive '/path/to/reviewed-official-manifest.json' '/path/to/prepared-context.json'
```

The archive preparation command can fetch the reviewed URLs. It validates report identity and extracted-evidence hashes before replacing its output. Original HTML hashes and injury-list text remain retained; a parser result does not infer participation or a missing injury reason. Review the actual supported flags and output before a separately authorized import.

Curated records use season, game type (`REG`, `WC`, `DIV`, `CON`, `SB`), archive week, team, kind, subject and source URL, plus relevant position, injury, designation, participation and explicit relevance. Known publication instants require an explicit timezone. Participation requires its own explicit evidence; an article's later update cannot become a pregame observation.

Historical example packets and private validation manifests are retained outside the public source distribution. They are not a current source feed, default model input or license to publish provider material. Create and review a new scoped packet rather than copying old game IDs or facts. Coverage counts mean team-games with at least one fact, not complete injury coverage. Manual historical imports remain separate from current injury-snapshot jobs.
