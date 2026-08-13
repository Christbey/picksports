# Production Performance

## Forge Deployment

Use the release-owned deployment helper as the final Forge deployment step:

```bash
cd /home/forge/picksports.app/current
PHP_BINARY=php8.4 bash scripts/post-deploy.sh
```

The command migrates the database, reinstalls the release-matched MLB and NFL
Python packages when the shared runtime exists, builds Laravel's production
caches, and restarts queue workers.

Forge queue daemons should also recycle hourly so a long-running worker cannot
remain attached to a release after the `current` symlink advances. Keep
Supervisor's `autorestart=true` and add `--max-time=3600` to every Picksports
queue worker command, for example:

```ini
command=php8.4 /home/forge/picksports.app/current/artisan queue:work database --sleep=3 --daemon --quiet --delay=0 --queue=default --tries=3 --timeout=300 --max-time=3600
```

Apply the same option to the `sync` queue daemon. This bounds release pinning to
one hour even when a deployment does not explicitly restart Forge daemons.

## Immutable Frontend Assets

Install the versioned-asset Nginx location once per server:

```bash
sudo ln -sfn \
  /home/forge/picksports.app/current/deploy/nginx/immutable-assets.conf \
  /etc/nginx/forge-conf/3044094/server/immutable-assets.conf
sudo nginx -t
sudo systemctl reload nginx
```

Vite filenames contain a content hash, so `/build/assets/*` can be cached for a
year with `immutable`. HTML and API responses remain private and uncached.

## Measurement

`Server-Timing` reports total Laravel time plus database query count/time on
same-origin responses. Requests over `PERFORMANCE_SLOW_REQUEST_MS` are logged as
`Slow application request detected` with route, status, database timing, and
authenticated user ID.

The browser emits a `web_vitals` data-layer event after load with TTFB, LCP, CLS,
and INP when the browser supports the corresponding Performance APIs.

## Data-Heavy Routes

Player leaderboards, available player-stat seasons, and player-prop boards use
short-lived application caches. Prop sync, analysis, and grading commands bust
the prop-board cache automatically. The defaults can be tuned without a code
change:

```dotenv
PERFORMANCE_PLAYER_LEADERBOARD_CACHE_SECONDS=300
PERFORMANCE_PLAYER_STAT_SEASONS_CACHE_SECONDS=900
PERFORMANCE_PLAYER_PROPS_CACHE_SECONDS=60
```

Player profiles request a focused leaderboard response containing only the
selected player and computed rank metadata. Supplemental rankings and props are
scheduled after the player identity and game log render.
