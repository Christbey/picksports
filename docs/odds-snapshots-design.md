# Odds Snapshots Design

This document explains how PickSports stores betting odds now, why that is not enough for historical modeling, and how the shared odds snapshot layer works.

## Problem

Today each sport game table stores:

- `odds_api_event_id`
- `odds_data`
- `odds_updated_at`

That design is useful for current product views because the game record always contains the latest known market.

It is not enough for historical analysis.

When odds sync runs again, `odds_data` is overwritten in place. That means:

- old lines are lost
- pregame market state cannot be reconstructed reliably
- prediction snapshots can only see the latest stored line, not the line that existed at prediction time
- model-vs-market backtests risk leakage if they accidentally compare old predictions against newer lines

## Goals

The shared fix should:

- preserve odds history without breaking current pages
- work across all sports that already use `games.odds_data`
- keep the latest odds on the game row for existing consumers
- create a reusable history layer for future historical Odds API backfills

## Approach

We keep two storage layers:

### Current state

The game row still stores the latest market snapshot:

- `games.odds_api_event_id`
- `games.odds_data`
- `games.odds_updated_at`

This remains the source used by:

- game pages
- dashboard cards
- current recommendation logic
- current prediction Vegas blending

### Historical state

A new shared `game_odds_snapshots` table stores odds snapshots over time.

Each row represents one preserved market state for one game.

Key fields:

- `sport`
- `game_table`
- `game_id`
- `odds_api_event_id`
- `bookmaker_key`
- `bookmaker_title`
- `source`
- `commence_time`
- `captured_at`
- `payload_hash`
- `odds_data`
- `market_context`

Because multiple sports use separate game tables with overlapping ids, the history row stores both `game_table` and `game_id`.

## Recording behavior

Odds sync now runs in this order:

1. Match Odds API event to a local game
2. Extract normalized `odds_data`
3. Compare the new payload to the latest stored game payload
4. If the payload changed, append a snapshot row
5. Update the game row with the latest odds

This gives us a change-point history rather than a duplicate row every sync run.

If the same odds payload is returned repeatedly, no new snapshot is written.

## Why not replace `games.odds_data` entirely?

Too many current consumers expect a direct latest-market blob on the game model.

Replacing that everywhere would be a large system-wide refactor touching:

- predictions
- betting recommendations
- alerts
- dashboards
- APIs
- tests

The shared snapshot table fixes the historical gap now while keeping current behavior stable.

## What this unlocks

With historical odds snapshots we can later:

- reconstruct pregame market state for prediction snapshots
- build historical market-aware ML datasets
- backtest recommendation thresholds against real market context
- query lines at fixed checkpoints like 24h, 6h, 1h, or 15m before game time
- support historical Odds API backfills cleanly

## Current limitation

This change preserves history from now on and from any future historical backfill job.

It does not magically recover old overwritten `games.odds_data` states that were never saved before this design existed.
