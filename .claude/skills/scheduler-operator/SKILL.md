---
name: scheduler-operator
description: "Activate when working on scheduled sports pipelines, season-gated jobs, queue-backed sync workflows, alert digests, operational timing issues, or scheduler documentation. Use for changes or investigations involving routes/console.php, scheduled command ordering, heartbeat behavior, and whether generated sports data is stale because the right jobs did not run in the right order."
license: MIT
metadata:
  author: picksports
---

# Scheduler Operator

Use this skill when the task is operational: what runs, when it runs, whether jobs overlap, and why a pipeline did or did not produce the expected data.

## Read First

Start here:

- `SCHEDULER_REFERENCE.md` for the current scheduling map
- `routes/console.php` for the actual source of truth
- `docs/codebase-reference.md` when scheduler changes affect API consumers or data semantics
- `docs/calculations-reference.md` when pipeline timing affects generated metrics or predictions

If `SCHEDULER_REFERENCE.md` and `routes/console.php` disagree, trust `routes/console.php`.

## When To Activate

Use this skill when work touches:

- scheduled sync, metrics, prediction, grading, digest, or maintenance jobs
- season guards or month-based pipeline gating
- command ordering dependencies
- `withoutOverlapping()` or `runInBackground()` behavior
- heartbeat tracking and job health
- queue-backed commands that appear to run but do not produce results
- scheduler docs that must stay aligned with the real schedule

## Workflow

1. Identify the operational symptom.
   Examples:
   - predictions are stale
   - metrics are missing for one sport
   - live data updated but generated outputs did not
   - a job appears documented but is not actually scheduled
   - the schedule changed for one sport and docs drifted

2. Trace the owning schedule definition in `routes/console.php`.
   Check:
   - helper used to register the job
   - frequency
   - time window
   - season guard
   - command arguments
   - overlap/background behavior

3. Check upstream and downstream dependencies.
   Generated outputs usually depend on earlier ingest or grading steps.
   Verify whether the broken job depends on:
   - scoreboard sync
   - game detail sync
   - injuries
   - odds
   - player props
   - grading
   - queue workers

4. Confirm whether the issue is schedule, queue, or code.
   Separate:
   - job never scheduled
   - job scheduled but season-gated off
   - job scheduled but waiting on queue workers
   - job ran but command logic failed
   - docs drift only

5. Keep docs aligned.
   If behavior changes, update `SCHEDULER_REFERENCE.md` so it remains an operational map, but never treat it as more authoritative than `routes/console.php`.

6. Verify with the smallest operational check.
   Preferred checks:
   - `php artisan schedule:list`
   - targeted command tests if they already exist
   - focused feature tests around the affected workflow

## Required Checks

Before finalizing, explicitly verify:

- the scheduled job exists in `routes/console.php`
- the sport is in season for the intended run window
- upstream pipeline steps occur before downstream generation
- queue-backed work has a worker requirement when relevant
- heartbeat or operational tracking still reflects the intended job
- `SCHEDULER_REFERENCE.md` matches the code after the change

## Common Failure Modes

- changing documentation without changing the actual schedule
- changing one sport pipeline and forgetting shared helper behavior
- investigating stale outputs without checking season guards
- assuming a command completion means queued work also completed
- moving generation earlier than the ingest or grading steps it depends on
- forgetting that live scoreboard commands resolve dynamic date arguments

## Output Style

When reporting results, summarize:

- affected job or pipeline
- root cause category: schedule, queue, command logic, or docs drift
- affected sports
- whether docs were updated
- commands or tests used to verify
