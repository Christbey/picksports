# NFL boxscore archives

Scope: retained final-game evidence handled by `NflBoxscoreSourceArchive`; the November 27 private candidate adds logical JSON admission bounds. This is not a production rollout record.

`record()` requires explicit completion and matching event, season and both teams. It retains header/boxscore data, excluding plays and news, and deduplicates unchanged content. Missing categories remain missing; explicit zeros remain zero. Recording happens before stat replacement, so retention failure stops that import rather than replacing observations without retained evidence.

The private candidate admits at most 8 MiB compressed and decoded JSON, 1,000,000 lexical tokens and structural depth 64. Writer inspection occurs after encoding but before compression/archive; reader inspection occurs after size and both checksum checks but before decode. Source rows must match the requested game. A checksum alone does not establish bounded decoded allocation or producer authenticity.

On refusal, retain the original object and checksum/identity evidence. Investigate the specific failure; do not disable checks, manufacture missing player observations, edit retained hashes to pass, or force a paid refresh merely to answer an analysis question. Validate any limit adjustment against representative production payloads and focused regression controls before rollout.

Scoped source-bound verification passed 18 tests / 56 assertions with independent source review. It does not bound allocations already used to construct/encode a provider array, SDK read-ahead, database metadata hydration or operating-system memory. Production archive compatibility and native storage acceptance remain open.
