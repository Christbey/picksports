# CFB signal training recovery

Signal detections and model contributions are different quantities. A missing training artifact must be reported as `missing_training_artifact`, not interpreted as absence of relevant football conditions. Per-market validation status remains visible. A totals model that fails held-out validation remains inactive even when a spread model passes.

`cfb:train-football-signals` selects the same approved frozen release as production predictions through `CalculationReleaseSelector`. It no longer trains the potentially different in-code release definition. The artifact retains its exact configuration key, source games, cutoff and retrospective limitations. It is written to `cfb.data.source_disk` before being cached; forecasts can recover it after cache eviction or deployment. Empty training cannot overwrite working evidence.

Training saves progress every 100 games on the same durable disk. A restart within 24 hours resumes that configuration and season range using the original source cutoff. Checkpoints never become prediction evidence; only a complete artifact is published. A shared lock prevents the daily and recovery commands from training concurrently. The existing daily run remains at 03:15 Central, with an hourly `--if-missing` recovery check at :05 from 06:00–23:00. The recovery job reuses a compatible artifact up to eight days old and records scheduler success/failure. A successful training clears the selected release's derived evidence cache so new forecasts use it immediately.

`cfb:daily-board` now includes the frozen signal summary per game. Historical forecasts and their grades are not rewritten. Fresh pregame revisions are required to apply newly available evidence.

Validation remains chronological, with at least 100 training games and 50 held-out games. The configured capped joint model must improve held-out absolute error by more than 1.96 standard errors. Prior-season FPI reconstruction is retrospective evidence, not proof of historical quote availability, calibrated cover probabilities, or live betting profitability.
